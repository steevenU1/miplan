<?php
/* traspaso_sims.php — Traspaso por Caja, Pieza (ICCID) o Lote (FIX: mostrar cajas/lotes con disponibles>0 aunque haya en tránsito) */
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

$idUsuario        = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursalOrigen = (int)($_SESSION['id_sucursal'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm_list($txt){
    $parts = preg_split('/[\s,;]+/u', (string)$txt, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_map('trim', $parts);
    $parts = array_values(array_unique($parts));
    return $parts;
}

/* ======================
   Datos de usuario/sucursal
====================== */
$usuarioNombre = 'Usuario #'.$idUsuario;
if ($st = $conn->prepare("SELECT nombre FROM usuarios WHERE id=? LIMIT 1")) {
    $st->bind_param("i", $idUsuario); $st->execute();
    if ($ru = $st->get_result()->fetch_assoc()) { $usuarioNombre = $ru['nombre']; }
    $st->close();
}
$sucOrigenNombre = '#'.$idSucursalOrigen;
if ($st = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1")) {
    $st->bind_param("i", $idSucursalOrigen); $st->execute();
    if ($ro = $st->get_result()->fetch_assoc()) { $sucOrigenNombre = $ro['nombre']; }
    $st->close();
}

/* ======================
   Sucursales destino
====================== */
$sucursales = [];
if ($st = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre")) {
    $st->bind_param("i", $idSucursalOrigen);
    $st->execute();
    $sucursales = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

/* ======================
   Cajas con >=1 Disponible (aunque haya en tránsito)
====================== */
$cajas = [];
if ($st = $conn->prepare("
    SELECT 
      caja_id,
      SUM(CASE WHEN estatus='Disponible'  THEN 1 ELSE 0 END) AS total_sims,
      SUM(CASE WHEN estatus='En transito' THEN 1 ELSE 0 END) AS en_transito
    FROM inventario_sims
    WHERE id_sucursal = ?
    GROUP BY caja_id
    HAVING total_sims > 0
    ORDER BY caja_id
")) {
    $st->bind_param("i", $idSucursalOrigen);
    $st->execute();
    $cajas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$totalCajas = count($cajas);
$totalSIMsCajas = array_sum(array_map(fn($c)=>(int)$c['total_sims'], $cajas));

/* ======================
   Lotes con >=1 Disponible (aunque haya en tránsito)
====================== */
$lotes = [];
if ($st = $conn->prepare("
    SELECT 
      lote,
      SUM(CASE WHEN estatus='Disponible'  THEN 1 ELSE 0 END) AS total_sims,
      SUM(CASE WHEN estatus='En transito' THEN 1 ELSE 0 END) AS en_transito
    FROM inventario_sims
    WHERE id_sucursal = ? AND lote IS NOT NULL AND lote <> ''
    GROUP BY lote
    HAVING total_sims > 0
    ORDER BY lote
")) {
    $st->bind_param("i", $idSucursalOrigen);
    $st->execute();
    $lotes = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$totalLotes = count($lotes);
$totalSIMsLotes = array_sum(array_map(fn($c)=>(int)$c['total_sims'], $lotes));

/* ======================
   POST: Generar traspaso
====================== */
$mensaje = '';
$acuseIdGenerado = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo = $_POST['modo'] ?? 'caja'; // caja | pieza | lote
    $idSucursalDestino = (int)($_POST['id_sucursal_destino'] ?? 0);

    if ($idSucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ Selecciona una sucursal destino.</div>";
    } else {
        $conn->begin_transaction();
        try {
            // Crear encabezado
            $stT = $conn->prepare("
                INSERT INTO traspasos_sims (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus, fecha_traspaso)
                VALUES (?, ?, ?, 'Pendiente', NOW())
            ");
            $stT->bind_param("iii", $idSucursalOrigen, $idSucursalDestino, $idUsuario);
            $stT->execute();
            $idTraspaso = (int)$stT->insert_id;
            $stT->close();

            $totalMovidas = 0;
            $omitidosInfo = '';

            if ($modo === 'caja') {
                $cajaIds = $_POST['caja_ids'] ?? [];
                if (!is_array($cajaIds)) $cajaIds = [$cajaIds];
                $cajaIds = array_values(array_unique(array_filter(array_map('trim', $cajaIds))));

                if (!$cajaIds) { throw new Exception('Sin cajas.'); }

                $stGet = $conn->prepare("
                    SELECT id FROM inventario_sims
                    WHERE id_sucursal = ? AND estatus='Disponible' AND caja_id = ? FOR UPDATE
                ");
                $stDet = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
                $stUpd = $conn->prepare("UPDATE inventario_sims SET estatus='En transito' WHERE id=?");

                $cajasVacias=[];
                foreach ($cajaIds as $cajaId) {
                    $stGet->bind_param("is", $idSucursalOrigen, $cajaId);
                    $stGet->execute();
                    $rs = $stGet->get_result();
                    if ($rs->num_rows===0){ $cajasVacias[]=$cajaId; continue; }
                    while($row=$rs->fetch_assoc()){
                        $idSim = (int)$row['id'];
                        $stDet->bind_param("ii",$idTraspaso,$idSim); $stDet->execute();
                        $stUpd->bind_param("i",$idSim); $stUpd->execute();
                        $totalMovidas++;
                    }
                }
                $stGet->close(); $stDet->close(); $stUpd->close();
                if ($cajasVacias) $omitidosInfo = " Cajas sin disponibles: ".h(implode(', ', $cajasVacias)).".";

            } elseif ($modo === 'lote') {
                $loteIds = $_POST['lote_ids'] ?? [];
                if (!is_array($loteIds)) $loteIds = [$loteIds];
                $loteIds = array_values(array_unique(array_filter(array_map('trim', $loteIds))));

                if (!$loteIds) { throw new Exception('Sin lotes.'); }

                $stGet = $conn->prepare("
                    SELECT id FROM inventario_sims
                    WHERE id_sucursal = ? AND estatus='Disponible' AND lote = ? FOR UPDATE
                ");
                $stDet = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
                $stUpd = $conn->prepare("UPDATE inventario_sims SET estatus='En transito' WHERE id=?");

                $lotesVacios=[];
                foreach ($loteIds as $loteId) {
                    $stGet->bind_param("is", $idSucursalOrigen, $loteId);
                    $stGet->execute();
                    $rs = $stGet->get_result();
                    if ($rs->num_rows===0){ $lotesVacios[]=$loteId; continue; }
                    while($row=$rs->fetch_assoc()){
                        $idSim = (int)$row['id'];
                        $stDet->bind_param("ii",$idTraspaso,$idSim); $stDet->execute();
                        $stUpd->bind_param("i",$idSim); $stUpd->execute();
                        $totalMovidas++;
                    }
                }
                $stGet->close(); $stDet->close(); $stUpd->close();
                if ($lotesVacios) $omitidosInfo = " Lotes sin disponibles: ".h(implode(', ', $lotesVacios)).".";

            } else { // pieza (ICCID)
                $raw = trim($_POST['iccid_bulk'] ?? '');
                $iccids = norm_list($raw);
                if (!$iccids) { throw new Exception('Sin ICCIDs.'); }

                $placeholders = implode(',', array_fill(0, count($iccids), '?'));
                $types = str_repeat('s', count($iccids));
                $sql = "
                    SELECT id, iccid
                    FROM inventario_sims
                    WHERE id_sucursal = ? AND estatus='Disponible' AND iccid IN ($placeholders)
                    FOR UPDATE
                ";
                $stmt = $conn->prepare($sql);
                $bindParams = [];
                $bindTypes  = 'i'.$types;
                $bindParams[] = &$bindTypes;
                $arg1 = $idSucursalOrigen; $bindParams[] = &$arg1;
                foreach ($iccids as $k=>$v) { $bindParams[] = &$iccids[$k]; }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);

                $stmt->execute();
                $res = $stmt->get_result();
                $encontradas = [];
                while($r = $res->fetch_assoc()){ $encontradas[] = $r; }
                $stmt->close();

                $encontradasMap = array_column($encontradas, 'id', 'iccid');
                $noEncontradas  = array_values(array_diff($iccids, array_keys($encontradasMap)));

                if (!$encontradas) { throw new Exception('Ningún ICCID disponible en esta sucursal.'); }

                $stDet = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
                $stUpd = $conn->prepare("UPDATE inventario_sims SET estatus='En transito' WHERE id=?");

                foreach ($encontradas as $row) {
                    $idSim = (int)$row['id'];
                    $stDet->bind_param("ii",$idTraspaso,$idSim); $stDet->execute();
                    $stUpd->bind_param("i",$idSim); $stUpd->execute();
                    $totalMovidas++;
                }
                $stDet->close(); $stUpd->close();

                if ($noEncontradas) {
                    $omitidosInfo = " No encontradas/No disponibles: ".h(implode(', ', array_slice($noEncontradas,0,30))).(count($noEncontradas)>30?'…':'');
                }
            }

            if ($totalMovidas === 0) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ No hubo SIMs para mover.{$omitidosInfo}</div>";
            } else {
                $conn->commit();
                $acuseIdGenerado = $idTraspaso;
                $btn = '<button type="button" class="btn btn-outline-primary btn-sm ms-2" onclick="openAcuse('.$acuseIdGenerado.')">
                          <i class=\"bi bi-file-earmark-text\"></i> Ver acuse
                        </button>';
                $mensaje = "<div class='alert alert-success card-surface mt-3'>✅ Traspaso <b>#{$idTraspaso}</b> generado. SIMs en tránsito: <b>{$totalMovidas}</b>.{$omitidosInfo} {$btn}</div>";
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ Error al generar el traspaso. ".h($e->getMessage())."</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Generar Traspaso de SIMs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid #cbd8ff; background:#e8f0fe; color:#1a56db; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .row-sel{ cursor:pointer; }
    .row-sel.active{ outline:2px solid #1a56db33; background:#f3f6ff; }
    .num{ font-variant-numeric: tabular-nums; }
    .th-check, .td-check { width:42px; text-align:center; }
    .nav-pills .nav-link{ border-radius:999px; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-3">
  <div class="page-header">
    <div>
      <h1 class="page-title">🚚 Generar Traspaso de SIMs</h1>
      <div class="small-muted">Sucursal origen: <strong><?= h($sucOrigenNombre) ?> (ID <?= (int)$idSucursalOrigen ?>)</strong></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip"><i class="bi bi-box-seam"></i> Cajas: <?= (int)$totalCajas ?> · SIMs: <?= (int)$totalSIMsCajas ?></span>
      <span class="chip"><i class="bi bi-collection"></i> Lotes: <?= (int)$totalLotes ?> · SIMs: <?= (int)$totalSIMsLotes ?></span>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- Form principal -->
  <form id="formTraspaso" method="POST" class="card card-surface p-3 mt-3">
    <input type="hidden" name="modo" id="modo" value="caja" />
    <div class="row g-3 filters align-items-center">
      <div class="col-12 col-lg-5">
        <label class="small-muted mb-1">Sucursal destino</label>
        <select name="id_sucursal_destino" id="sucursalSelect" class="form-select" required <?= empty($sucursales)?'disabled':'' ?>>
          <option value="">-- Selecciona sucursal --</option>
          <?php foreach ($sucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-7">
        <div class="d-flex gap-2 flex-wrap">
          <div class="nav nav-pills">
            <button class="nav-link active" id="tab-caja"  type="button" data-mode="caja"><i class="bi bi-box-seam me-1"></i>Por caja</button>
            <button class="nav-link"        id="tab-lote"  type="button" data-mode="lote"><i class="bi bi-collection me-1"></i>Por lote</button>
            <button class="nav-link"        id="tab-pieza" type="button" data-mode="pieza"><i class="bi bi-sim me-1"></i>Por pieza (ICCID)</button>
          </div>
          <button type="button" id="btnGenerar" class="btn btn-primary ms-auto">
            <i class="bi bi-arrow-right-circle"></i> Generar
          </button>
        </div>
      </div>
    </div>

    <!-- Panel CAJA -->
    <div class="mt-3" id="panel-caja">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="small-muted">Cajas con al menos 1 SIM disponible.</div>
        <input id="qCaja" class="form-control" placeholder="Buscar ID de caja…" style="height:42px; width:220px;">
      </div>
      <div class="tbl-wrap">
        <table class="table table-hover align-middle mb-0" id="tablaCajas">
          <thead class="table-light">
            <tr>
              <th class="th-check"><input type="checkbox" id="checkCajaVisible" title="Seleccionar todo lo visible"></th>
              <th>ID Caja</th>
              <th>SIMs disponibles</th>
              <th>Seleccionar</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cajas as $c): ?>
            <tr class="row-sel" data-id="<?= h($c['caja_id']) ?>" data-sims="<?= (int)$c['total_sims'] ?>">
              <td class="td-check"><input type="checkbox" class="row-check"></td>
              <td class="fw-semibold"><span class="badge text-bg-secondary"><?= h($c['caja_id']) ?></span></td>
              <td><?= (int)$c['total_sims'] ?><?= ((int)$c['en_transito']>0?' <small class="text-muted">(+'.$c['en_transito'].' en tránsito)</small>':'') ?></td>
              <td><button type="button" class="btn btn-soft btn-sm pick"><i class="bi bi-check2-circle"></i> Agregar/Quitar</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$cajas): ?><tr><td colspan="4" class="text-center small-muted">Sin cajas con disponibles.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div id="chipsCaja" class="mt-2"></div>
      <div id="hiddenCaja"></div>
    </div>

    <!-- Panel LOTE -->
    <div class="mt-3 d-none" id="panel-lote">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="small-muted">Lotes con al menos 1 SIM disponible.</div>
        <input id="qLote" class="form-control" placeholder="Buscar lote…" style="height:42px; width:220px;">
      </div>
      <div class="tbl-wrap">
        <table class="table table-hover align-middle mb-0" id="tablaLotes">
          <thead class="table-light">
            <tr>
              <th class="th-check"><input type="checkbox" id="checkLoteVisible" title="Seleccionar todo lo visible"></th>
              <th>Lote</th>
              <th>SIMs disponibles</th>
              <th>Seleccionar</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lotes as $l): ?>
            <tr class="row-sel" data-id="<?= h($l['lote']) ?>" data-sims="<?= (int)$l['total_sims'] ?>">
              <td class="td-check"><input type="checkbox" class="row-check"></td>
              <td class="fw-semibold"><span class="badge text-bg-secondary"><?= h($l['lote']) ?></span></td>
              <td><?= (int)$l['total_sims'] ?><?= ((int)$l['en_transito']>0?' <small class="text-muted">(+'.$l['en_transito'].' en tránsito)</small>':'') ?></td>
              <td><button type="button" class="btn btn-soft btn-sm pick"><i class="bi bi-check2-circle"></i> Agregar/Quitar</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lotes): ?><tr><td colspan="4" class="text-center small-muted">Sin lotes con disponibles.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div id="chipsLote" class="mt-2"></div>
      <div id="hiddenLote"></div>
    </div>

    <!-- Panel PIEZA -->
    <div class="mt-3 d-none" id="panel-pieza">
      <label class="small-muted mb-1">Pega una lista de ICCIDs (separados por coma, espacio o salto de línea)</label>
      <textarea name="iccid_bulk" id="iccidBulk" class="form-control" rows="6" placeholder="8940..., 8952..., 895202..., etc."></textarea>
      <div class="small-muted mt-1">Solo se mueven los que estén <b>Disponibles</b> en esta sucursal; los demás se reportan como omitidos.</div>
    </div>
  </form>
</div>

<!-- MODAL: Acuse -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de Traspaso <span id="hdrAcuseId" class="text-muted"></span></h5>
        <div class="d-flex gap-2">
          <a id="btnNuevaPestana" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
          <button id="btnPrintAcuse" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Imprimir</button>
          <button class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
      <div class="modal-body p-0">
        <iframe id="acuseFrame" src="" style="width:100%; height:75vh; border:0;"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Modo tabs
  const modoInput = document.getElementById('modo');
  const tabs = document.querySelectorAll('[data-mode]');
  const panels = {
    caja:  document.getElementById('panel-caja'),
    lote:  document.getElementById('panel-lote'),
    pieza: document.getElementById('panel-pieza')
  };
  tabs.forEach(btn => btn.addEventListener('click', () => {
    tabs.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const mode = btn.getAttribute('data-mode');
    modoInput.value = mode;
    Object.values(panels).forEach(p => p.classList.add('d-none'));
    panels[mode].classList.remove('d-none');
  }));

  function makeSelector(tableId, chipsId, hiddenId, inputName){
    const tableBody = document.querySelector('#'+tableId+' tbody');
    if (!tableBody) return null;
    const chips = document.getElementById(chipsId);
    const hidden = document.getElementById(hiddenId);
    const checkAll = document.querySelector('#'+tableId).querySelector('.th-check input[type=checkbox]');
    const sel = new Map();

    function cssEsc(s){ if (window.CSS && CSS.escape) return CSS.escape(s); return s.replace(/"/g,'\\"').replace(/'/g,"\\'"); }
    function getVisibleRows(){ return Array.from(tableBody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none'); }

    function setSelected(id, sims, on){
      const tr  = tableBody.querySelector(`tr[data-id="${cssEsc(id)}"]`);
      const chk = tr?.querySelector('.row-check');
      if (on){ sel.set(id, Number(sims||0)); if(chk) chk.checked = true; tr?.classList.add('active'); }
      else   { sel.delete(id); if(chk) chk.checked = false; tr?.classList.remove('active'); }
      renderUI();
    }
    function renderUI(){
      if (!sel.size){ chips.innerHTML = ''; hidden.innerHTML=''; }
      else {
        chips.innerHTML =
          '<div class="d-flex flex-wrap gap-2 mt-2">' +
          Array.from(sel.entries()).map(([id, sims]) =>
            `<span class="chip"><i class="bi bi-check2-circle"></i> ${id} (${sims})
              <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-remove="${id}" title="Quitar">
                <i class="bi bi-x-circle"></i></button></span>`).join('') +
          '</div>';
        hidden.innerHTML = Array.from(sel.keys()).map(id => `<input type="hidden" name="${inputName}[]" value="${id}">`).join('');
        chips.querySelectorAll('[data-remove]').forEach(btn => btn.addEventListener('click', () => setSelected(btn.getAttribute('data-remove'), 0, false)));
      }
      const visible = getVisibleRows();
      const allVisSel = visible.length && visible.every(tr => sel.has(tr.dataset.id));
      if (checkAll){ checkAll.checked = allVisSel; checkAll.indeterminate = !allVisSel && visible.some(tr => sel.has(tr.dataset.id)); }
    }

    tableBody.addEventListener('click', (ev)=>{
      const tr   = ev.target.closest('tr'); if (!tr) return;
      const id   = tr.dataset.id; const sims = tr.dataset.sims || '0';
      if (ev.target.classList.contains('row-check'))      setSelected(id, sims, ev.target.checked);
      else if (ev.target.classList.contains('pick'))      setSelected(id, sims, !sel.has(id));
      else if (!ev.target.closest('.td-check'))           setSelected(id, sims, !sel.has(id));
    });
    if (checkAll) checkAll.addEventListener('change', ()=>{ getVisibleRows().forEach(tr => setSelected(tr.dataset.id, tr.dataset.sims, checkAll.checked)); });

    return {
      search: (inputId) => {
        const q = document.getElementById(inputId);
        const allRows = Array.from(tableBody.querySelectorAll('tr'));
        const norm = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
        q?.addEventListener('input', () => {
          const val = norm(q.value);
          allRows.forEach(tr => { tr.style.display = !val || norm(tr.getAttribute('data-id')).includes(val) ? '' : 'none'; });
        });
      }
    };
  }

  const selCajas = makeSelector('tablaCajas','chipsCaja','hiddenCaja','caja_ids');
  const selLotes = makeSelector('tablaLotes','chipsLote','hiddenLote','lote_ids');
  selCajas?.search('qCaja');
  selLotes?.search('qLote');

  // Submit
  const btnGenerar = document.getElementById('btnGenerar');
  const form = document.getElementById('formTraspaso');
  btnGenerar.addEventListener('click', () => {
    const modo = document.getElementById('modo').value;
    const sucSel = document.getElementById('sucursalSelect');
    if (!sucSel.value){ sucSel.classList.add('is-invalid'); sucSel.focus(); return; }
    if (modo === 'caja' && !document.querySelectorAll('input[name="caja_ids[]"]').length) { alert('Selecciona al menos una caja.'); return; }
    if (modo === 'lote' && !document.querySelectorAll('input[name="lote_ids[]"]').length) { alert('Selecciona al menos un lote.'); return; }
    if (modo === 'pieza'){
      const ta = document.getElementById('iccidBulk');
      if (!ta.value.trim()){ alert('Pega al menos un ICCID.'); ta.focus(); return; }
    }
    form.submit();
  });

  // ACUSE
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const acuseFrame = document.getElementById('acuseFrame');
  const hdrAcuseId = document.getElementById('hdrAcuseId');
  const btnNuevaP  = document.getElementById('btnNuevaPestana');
  document.getElementById('btnPrintAcuse').addEventListener('click', ()=> {
    try { acuseFrame.contentWindow.print(); } catch(e){ alert('No se pudo imprimir el acuse.'); }
  });
  window.openAcuse = function(id){
    const url = 'acuse_traspaso_sims.php?id=' + encodeURIComponent(id);
    acuseFrame.src = url; hdrAcuseId.textContent = '#'+id; btnNuevaP.href = url; modalAcuse.show();
  };
  <?php if ($acuseIdGenerado): ?>
    window.addEventListener('DOMContentLoaded', ()=> openAcuse(<?= (int)$acuseIdGenerado ?>));
  <?php endif; ?>
})();
</script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
