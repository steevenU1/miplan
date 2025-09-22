<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

$idUsuario        = (int)$_SESSION['id_usuario'];
$idSucursalOrigen = (int)$_SESSION['id_sucursal'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ======================
// Datos de usuario y sucursal origen
// ======================
$usuarioNombre = 'Usuario #'.$idUsuario;
$stU = $conn->prepare("SELECT nombre FROM usuarios WHERE id=? LIMIT 1");
$stU->bind_param("i", $idUsuario);
$stU->execute();
if ($ru = $stU->get_result()->fetch_assoc()) { $usuarioNombre = $ru['nombre']; }
$stU->close();

$sucOrigenNombre = '#'.$idSucursalOrigen;
$stSO = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stSO->bind_param("i", $idSucursalOrigen);
$stSO->execute();
if ($ro = $stSO->get_result()->fetch_assoc()) { $sucOrigenNombre = $ro['nombre']; }
$stSO->close();

// ======================
// Sucursales destino
// ======================
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre";
$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("i", $idSucursalOrigen);
$stmt->execute();
$res = $stmt->get_result();
$sucursales = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ======================
// Cajas 100% disponibles (sin ninguna SIM en tránsito)
// ======================
$sqlCajas = "
  SELECT 
    caja_id,
    SUM(CASE WHEN estatus = 'Disponible'  THEN 1 ELSE 0 END) AS total_sims,
    SUM(CASE WHEN estatus = 'En transito' THEN 1 ELSE 0 END) AS en_transito
  FROM inventario_sims
  WHERE id_sucursal = ?
  GROUP BY caja_id
  HAVING en_transito = 0 AND total_sims > 0
  ORDER BY caja_id
";
$stmt = $conn->prepare($sqlCajas);
$stmt->bind_param("i", $idSucursalOrigen);
$stmt->execute();
$res = $stmt->get_result();
$cajas = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalCajas = count($cajas);
$totalSIMs  = array_sum(array_map(fn($c)=>(int)$c['total_sims'], $cajas));

// ======================
// POST: generar traspaso múltiple
// ======================
$mensaje = '';
$acuseIdGenerado = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['caja_ids'], $_POST['id_sucursal_destino'])) {
    $cajaIdsPost = $_POST['caja_ids'];
    if (!is_array($cajaIdsPost)) $cajaIdsPost = [$cajaIdsPost];

    $cajaIds = array_values(array_unique(array_filter(array_map('trim', $cajaIdsPost))));
    $idSucursalDestino = (int)$_POST['id_sucursal_destino'];

    if (!$cajaIds || $idSucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ Selecciona al menos una caja y una sucursal destino.</div>";
    } else {
        $conn->begin_transaction();
        try {
            // 1) Crear traspaso
            $stT = $conn->prepare("
                INSERT INTO traspasos_sims (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus, fecha_traspaso)
                VALUES (?, ?, ?, 'Pendiente', NOW())
            ");
            $stT->bind_param("iii", $idSucursalOrigen, $idSucursalDestino, $idUsuario);
            $stT->execute();
            $idTraspaso = $stT->insert_id;
            $stT->close();

            // 2) Statements reusables
            $stGet = $conn->prepare("
                SELECT id FROM inventario_sims
                WHERE id_sucursal=? AND estatus='Disponible' AND caja_id=? FOR UPDATE
            ");
            $stDet = $conn->prepare("INSERT INTO detalle_traspaso_sims (id_traspaso, id_sim) VALUES (?, ?)");
            $stUpd = $conn->prepare("UPDATE inventario_sims SET estatus='En transito' WHERE id=?");

            $totalMovidas  = 0;
            $cajasVacias   = [];
            $cajasOK       = [];

            foreach ($cajaIds as $cajaId) {
                $stGet->bind_param("is", $idSucursalOrigen, $cajaId);
                $stGet->execute();
                $rs = $stGet->get_result();

                if ($rs->num_rows === 0) {
                    $cajasVacias[] = $cajaId;
                    continue;
                }

                while ($row = $rs->fetch_assoc()) {
                    $idSim = (int)$row['id'];
                    $stDet->bind_param("ii", $idTraspaso, $idSim);
                    $stDet->execute();
                    $stUpd->bind_param("i", $idSim);
                    $stUpd->execute();
                    $totalMovidas++;
                }
                $cajasOK[] = $cajaId;
            }

            $stGet->close(); $stDet->close(); $stUpd->close();

            if ($totalMovidas === 0) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ Ninguna de las cajas seleccionadas tiene SIMs disponibles.</div>";
            } else {
                $conn->commit();
                $acuseIdGenerado = (int)$idTraspaso;

                $extra = '';
                if ($cajasVacias) {
                    $extra = "<br><small class='text-muted'>Omitidas por estar vacías: ".h(implode(', ', $cajasVacias))."</small>";
                }
                $btn = '<button type="button" class="btn btn-outline-primary btn-sm ms-2" onclick="openAcuse('.$acuseIdGenerado.')">
                          <i class=\"bi bi-file-earmark-text\"></i> Ver acuse
                        </button>';
                $mensaje = "<div class='alert alert-success card-surface mt-3'>✅ Traspaso <b>#{$idTraspaso}</b> generado. SIMs en transito: <b>{$totalMovidas}</b>.{$extra} {$btn}</div>";
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $mensaje = "<div class='alert alert-danger card-surface mt-3'>❌ Error al generar el traspaso. Intenta nuevamente.</div>";
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
    .caja-row{ cursor:pointer; }
    .caja-row.active{ outline:2px solid #1a56db33; background:#f3f6ff; }
    .num{ font-variant-numeric: tabular-nums; }
    .th-check, .td-check { width: 42px; text-align:center; }
    .actionbar { position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 1040; display: none;
      background:#fff; border:1px solid rgba(0,0,0,.06); box-shadow:0 10px 30px rgba(16,24,40,.12); border-radius:16px; padding:.6rem .8rem; }
    .actionbar .summary { font-weight: 600; }
    @media (max-width:576px){ .actionbar { left:10px; right:10px; bottom:10px; } .container { padding-inline:8px; } .table { font-size:12px; } .table td, .table th{ padding:.35rem .45rem; } .page-header h1{ font-size:1.2rem; } }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-3">
  <div class="page-header">
    <div>
      <h1 class="page-title">🚚 Generar Traspaso de SIMs <span class="text-muted">(múltiples cajas)</span></h1>
      <div class="small-muted">Sucursal origen: <strong><?= h($sucOrigenNombre) ?> (ID <?= (int)$idSucursalOrigen ?>)</strong></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip"><i class="bi bi-box-seam"></i> Cajas con SIM: <?= (int)$totalCajas ?></span>
      <span class="chip"><i class="bi bi-sim"></i> SIMs disponibles: <?= (int)$totalSIMs ?></span>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- Formulario -->
  <form id="formTraspaso" method="POST" class="card card-surface p-3 mt-3">
    <div class="row g-3 filters">
      <div class="col-12 col-lg-7">
        <label class="small-muted mb-1">Agregar por ID (pega una lista)</label>
        <div class="input-group">
          <input id="bulkInput" class="form-control" placeholder="Ej. CAJ-001, CAJ-002 CAJ-010...">
          <button type="button" id="bulkAdd" class="btn btn-soft">Agregar</button>
        </div>
        <div id="chipsSel" class="mt-2"></div>
      </div>

      <div class="col-12 col-lg-3">
        <label class="small-muted mb-1">Sucursal destino</label>
        <select name="id_sucursal_destino" id="sucursalSelect" class="form-select" required <?= empty($sucursales)?'disabled':'' ?>>
          <option value="">-- Selecciona sucursal --</option>
          <?php foreach ($sucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-2 d-flex align-items-end">
        <button type="button" id="btnConfirmar" class="btn btn-primary w-100" <?= ($totalCajas===0 || empty($sucursales))?'disabled':'' ?>>
          <i class="bi bi-arrow-right-circle"></i> Generar
        </button>
      </div>
    </div>

    <div id="hiddenInputs"></div>
    <div class="small-muted mt-2">Tip: usa la tabla de abajo; marca/desmarca filas o usa “Seleccionar todo lo visible”.</div>
  </form>

  <!-- Listado de cajas -->
  <div class="card card-surface mt-3 mb-5">
    <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-table me-2"></i>Cajas disponibles</h5>
      <div class="d-flex align-items-center gap-2">
        <input id="qFront" class="form-control" placeholder="Buscar por ID de caja…" style="height:42px; width:220px;">
      </div>
    </div>
    <div class="p-3 pt-2 tbl-wrap">
      <table id="tablaCajas" class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="th-check"><input type="checkbox" id="checkAllVisible" title="Seleccionar todo lo visible"></th>
            <th style="min-width:160px;">ID Caja</th>
            <th style="min-width:140px;">SIMs disponibles</th>
            <th style="min-width:220px;">Seleccionar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cajas as $c): ?>
            <tr class="caja-row" data-caja="<?= h($c['caja_id']) ?>" data-sims="<?= (int)$c['total_sims'] ?>">
              <td class="td-check"><input type="checkbox" class="row-check" data-caja="<?= h($c['caja_id']) ?>" data-sims="<?= (int)$c['total_sims'] ?>"></td>
              <td class="fw-semibold"><span class="badge text-bg-secondary"><?= h($c['caja_id']) ?></span></td>
              <td><?= (int)$c['total_sims'] ?></td>
              <td><button type="button" class="btn btn-soft btn-sm pick-caja" data-caja="<?= h($c['caja_id']) ?>" data-sims="<?= (int)$c['total_sims'] ?>"><i class="bi bi-check2-circle"></i> Agregar/Quitar</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($totalCajas===0): ?><tr><td colspan="4" class="text-center small-muted">Sin cajas disponibles.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Barra de acción flotante -->
<div id="actionBar" class="actionbar d-flex align-items-center justify-content-between gap-2">
  <div class="summary"><span id="selCount">0</span> cajas · <span id="selSims">0</span> SIMs</div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-soft btn-sm" id="clearSel"><i class="bi bi-x-circle"></i> Limpiar</button>
    <button type="button" class="btn btn-primary btn-sm" id="fabGenerate"><i class="bi bi-arrow-right-circle"></i> Generar</button>
  </div>
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

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function(){
  const sucursalSelect = document.getElementById('sucursalSelect');
  const btnConfirmar   = document.getElementById('btnConfirmar');
  const form           = document.getElementById('formTraspaso');
  const hiddenInputs   = document.getElementById('hiddenInputs');

  const tableBody      = document.querySelector('#tablaCajas tbody');
  const getVisibleRows = () => Array.from(tableBody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none');
  const checkAll       = document.getElementById('checkAllVisible');
  const q              = document.getElementById('qFront');
  const bulkInput      = document.getElementById('bulkInput');
  const bulkAdd        = document.getElementById('bulkAdd');
  const chipsSel       = document.getElementById('chipsSel');
  const actionBar      = document.getElementById('actionBar');
  const selCount       = document.getElementById('selCount');
  const selSims        = document.getElementById('selSims');
  const clearSel       = document.getElementById('clearSel');
  const fabGen         = document.getElementById('fabGenerate');
  const modalAcuse     = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const acuseFrame     = document.getElementById('acuseFrame');
  const hdrAcuseId     = document.getElementById('hdrAcuseId');
  const btnPrintAcuse  = document.getElementById('btnPrintAcuse');
  const btnNuevaPest   = document.getElementById('btnNuevaPestana');

  const sel = new Map();

  function cssEscape(s){ if (window.CSS && CSS.escape) return CSS.escape(s); return s.replace(/"/g,'\\"').replace(/'/g,"\\'"); }
  function setRowSelected(id, sims, on){
    const tr  = tableBody.querySelector(`tr[data-caja="${cssEscape(id)}"]`);
    const chk = tr?.querySelector('.row-check');
    if (on){ sel.set(id, Number(sims||0)); chk && (chk.checked = true); tr?.classList.add('active'); }
    else   { sel.delete(id); chk && (chk.checked = false); tr?.classList.remove('active'); }
    renderUI();
  }
  function renderUI(){
    if (!sel.size){ chipsSel.innerHTML = ''; }
    else {
      chipsSel.innerHTML =
        '<div class="d-flex flex-wrap gap-2 mt-2">' +
        Array.from(sel.entries()).map(([id, sims]) =>
          `<span class="chip"><i class="bi bi-box-seam"></i> ${id} (${sims})
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-remove="${id}" title="Quitar">
              <i class="bi bi-x-circle"></i></button></span>`).join('') +
        '</div>';
      chipsSel.querySelectorAll('[data-remove]').forEach(btn => btn.addEventListener('click', () => setRowSelected(btn.getAttribute('data-remove'), 0, false)));
    }
    const totalCajas = sel.size;
    const totalSims  = Array.from(sel.values()).reduce((a,b)=>a+b,0);
    selCount.textContent = totalCajas; selSims.textContent = totalSims;
    actionBar.style.display = totalCajas ? 'flex' : 'none';

    const visible = getVisibleRows();
    const allVisibleSelected = visible.length && visible.every(tr => sel.has(tr.dataset.caja));
    checkAll.checked = allVisibleSelected;
    checkAll.indeterminate = !allVisibleSelected && visible.some(tr => sel.has(tr.dataset.caja));
  }

  tableBody.addEventListener('click', (ev)=>{
    const tr   = ev.target.closest('tr'); if (!tr) return;
    const id   = tr.dataset.caja; const sims = tr.dataset.sims || '0';
    if (ev.target.classList.contains('row-check'))      setRowSelected(id, sims, ev.target.checked);
    else if (ev.target.classList.contains('pick-caja')) setRowSelected(id, sims, !sel.has(id));
    else if (!ev.target.closest('.td-check'))           setRowSelected(id, sims, !sel.has(id));
  });
  checkAll.addEventListener('change', ()=>{ getVisibleRows().forEach(tr => setRowSelected(tr.dataset.caja, tr.dataset.sims, checkAll.checked)); });
  const allRows = Array.from(tableBody.querySelectorAll('tr'));
  const norm = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
  q?.addEventListener('input', () => {
    const val = norm(q.value);
    allRows.forEach(tr => { tr.style.display = !val || norm(tr.getAttribute('data-caja')).includes(val) ? '' : 'none'; });
    renderUI();
  });
  function parseIds(txt){ return Array.from(new Set((txt||'').split(/[\s,;]+/).map(s=>s.trim()).filter(Boolean))); }
  function addBulk(){
    const ids = parseIds(bulkInput.value); if (!ids.length) return;
    const missing = [];
    ids.forEach(id=>{
      const tr = tableBody.querySelector(`tr[data-caja="${cssEscape(id)}"]`);
      if (tr) setRowSelected(id, tr.dataset.sims || '0', true); else missing.push(id);
    });
    if (missing.length) alert('No encontradas: ' + missing.join(', '));
    bulkInput.value = '';
  }
  bulkAdd.addEventListener('click', addBulk);
  bulkInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addBulk(); }});
  clearSel.addEventListener('click', ()=>{ Array.from(sel.keys()).forEach(id => setRowSelected(id, 0, false)); });

  function openConfirm(){
    if (!sel.size) return alert('Selecciona al menos una caja.');
    if (!sucursalSelect.value){ sucursalSelect.classList.add('is-invalid'); return; }
    hiddenInputs.innerHTML = '';
    Array.from(sel.keys()).forEach(id=>{
      const input = document.createElement('input'); input.type='hidden'; input.name='caja_ids[]'; input.value=id; hiddenInputs.appendChild(input);
    });
    form.submit();
  }
  document.getElementById('fabGenerate').addEventListener('click', openConfirm);
  btnConfirmar.addEventListener('click', openConfirm);

  // ==== ACUSE en modal ====
  window.openAcuse = function(id){
    const url = 'acuse_traspaso_sims.php?id=' + encodeURIComponent(id);
    acuseFrame.src = url;
    hdrAcuseId.textContent = '#' + id;
    btnNuevaPest.href = url;
    modalAcuse.show();
  };
  btnPrintAcuse.addEventListener('click', ()=> {
    try { acuseFrame.contentWindow.print(); } catch(e){ alert('No se pudo imprimir el acuse.'); }
  });

  renderUI();

  <?php if ($acuseIdGenerado): ?>
    window.addEventListener('DOMContentLoaded', ()=> openAcuse(<?= (int)$acuseIdGenerado ?>));
  <?php endif; ?>
})();
</script>
</body>
</html>
