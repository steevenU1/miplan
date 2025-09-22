<?php
// traspaso_nuevo.php — Traspaso entre sucursales (origen = sucursal actual; destino = otra sucursal o Eulalia)
// Versión con modal de ACUSE auto-impreso al generar el traspaso
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');

$mensaje     = "";
$acuseUrl    = "";   // ← URL del acuse a cargar en el modal (con print=1)
$acuseReady  = false; // ← flag para disparar el modal en el front

// Helper seguro
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================
   Procesar traspaso (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos'], $_POST['sucursal_destino'])) {
    $sucursalDestino = (int)$_POST['sucursal_destino'];
    $equiposSeleccionados = is_array($_POST['equipos']) ? $_POST['equipos'] : [];

    if ($sucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>Selecciona una sucursal destino.</div>";
    } elseif ($sucursalDestino === $idSucursal) {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>El destino no puede ser la misma sucursal de origen.</div>";
    } elseif (!empty($equiposSeleccionados)) {
        // Normalizar IDs
        $idsInv = array_values(array_unique(array_map('intval', $equiposSeleccionados)));

        if (count($idsInv) === 0) {
            $mensaje = "<div class='alert alert-warning card-surface mt-3'>⚠️ Debes seleccionar al menos un equipo.</div>";
        } else {
            $conn->begin_transaction();
            try {
                // Validar que TODOS siguen en la sucursal origen y 'Disponible'
                $placeholders = implode(',', array_fill(0, count($idsInv), '?'));
                $typesIds     = str_repeat('i', count($idsInv));
                $sqlVal = "
                  SELECT i.id
                  FROM inventario i
                  WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($placeholders)
                ";
                $stmtVal = $conn->prepare($sqlVal);
                $typesFull = 'i' . $typesIds;
                $stmtVal->bind_param($typesFull, $idSucursal, ...$idsInv);
                $stmtVal->execute();
                $rsVal = $stmtVal->get_result();
                $validos = [];
                while ($r = $rsVal->fetch_assoc()) $validos[] = (int)$r['id'];
                $stmtVal->close();

                if (count($validos) !== count($idsInv)) {
                    $conn->rollback();
                    $mensaje = "<div class='alert alert-danger card-surface mt-3'>Algunos equipos ya no están disponibles en esta sucursal o cambiaron de estatus. Refresca e intenta de nuevo.</div>";
                } else {
                    // 1) Cabecera (Pendiente)
                    $stmtCab = $conn->prepare("
                        INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, fecha_traspaso, estatus, usuario_creo)
                        VALUES ( ?, ?, NOW(), 'Pendiente', ? )
                    ");
                    $stmtCab->bind_param("iii", $idSucursal, $sucursalDestino, $idUsuario);
                    $stmtCab->execute();
                    $idTraspaso = (int)$stmtCab->insert_id;
                    $stmtCab->close();

                    // 2) Detalle + actualizar inventario a “En tránsito” (blindando origen + estatus)
                    $stmtDet = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
                    $stmtUpd = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=? AND id_sucursal=? AND estatus='Disponible'");

                    foreach ($validos as $idInv) {
                        $stmtDet->bind_param("ii", $idTraspaso, $idInv);
                        $stmtDet->execute();

                        $stmtUpd->bind_param("ii", $idInv, $idSucursal);
                        $stmtUpd->execute();
                        if ($stmtUpd->affected_rows !== 1) {
                            throw new Exception("Fallo al actualizar inventario #$idInv");
                        }
                    }
                    $stmtDet->close();
                    $stmtUpd->close();

                    $conn->commit();

                    // Preparar el modal del ACUSE (con auto-print en el iframe)
                    $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&print=1";
                    $acuseReady = true;

                    // Mensaje (dejamos como info secundaria)
                    $mensaje = "<div class='alert alert-success card-surface mt-3'>
                        ✅ <strong>Traspaso #{$idTraspaso}</strong> generado. Los equipos ahora están <b>En tránsito</b>.
                        <div class='small text-muted mt-1'>El acuse se abrirá en un cuadro de vista previa para imprimir.</div>
                    </div>";
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger card-surface mt-3'>Error al generar traspaso: ".h($e->getMessage())."</div>";
            }
        }
    } else {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>⚠️ Debes seleccionar al menos un equipo.</div>";
    }
}

/* =========================
   Sucursales destino (todas menos origen)
========================= */
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre";
$stmtSuc = $conn->prepare($sqlSucursales);
$stmtSuc->bind_param("i", $idSucursal);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtSuc->close();

/* =========================
   Inventario disponible en origen
========================= */
$sqlInventario = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ? AND i.estatus = 'Disponible'
    ORDER BY p.marca, p.modelo, i.id ASC
";
$stmtInv = $conn->prepare($sqlInventario);
$stmtInv->bind_param("i", $idSucursal);
$stmtInv->execute();
$resInv      = $stmtInv->get_result();
$inventario  = $resInv->fetch_all(MYSQLI_ASSOC);
$stmtInv->close();

$totalDisponibles = count($inventario);
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Nuevo Traspaso</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .row-selected{ background:#f3f6ff !important; }

    /* Modal acuse */
    .modal-xxl { max-width: 1200px; }
    #frameAcuse{ width:100%; min-height:72vh; border:0; background:#fff; }
  </style>
</head>
<body>

<?php include __DIR__.'/navbar.php'; ?>

<div class="container py-3">
  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🚚 Generar Traspaso de Equipos</h1>
      <div class="small-muted">Sucursal origen: <strong>#<?= (int)$idSucursal ?></strong> &middot; Usuario: <strong><?= h($nombreUser) ?></strong></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip chip-info"><i class="bi bi-box-seam me-1"></i> Disponibles: <?= (int)$totalDisponibles ?></span>
      <span class="chip chip-success"><i class="bi bi-check2-circle me-1"></i> Seleccionados: <span id="chipSel">0</span></span>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-3 mt-1">
    <div class="col-lg-8">
      <form id="formTraspaso" method="POST" class="card card-surface p-3">

        <div class="row g-3 filters">
          <div class="col-md-6">
            <label class="small-muted">Sucursal destino</label>
            <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="small-muted">Búsqueda rápida</label>
            <input type="text" id="buscarIMEI" class="form-control" placeholder="IMEI, marca o modelo…">
          </div>
        </div>

        <div class="d-flex align-items-center justify-content-between mt-3">
          <h5 class="m-0"><i class="bi bi-phone me-2"></i>Selecciona equipos a traspasar</h5>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="checkAll">
            <label class="form-check-label" for="checkAll">Seleccionar todos (visibles)</label>
          </div>
        </div>

        <div class="mt-2 tbl-wrap">
          <table class="table table-hover align-middle mb-0" id="tablaInventario">
            <thead class="table-light">
              <tr>
                <th style="width:44px"></th>
                <th>ID Inv</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Color</th>
                <th>Capacidad</th>
                <th>IMEI1</th>
                <th>IMEI2</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($totalDisponibles > 0): ?>
                <?php foreach ($inventario as $row): ?>
                  <tr data-id="<?= (int)$row['id'] ?>">
                    <td><input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo"></td>
                    <td class="td-id"><?= (int)$row['id'] ?></td>
                    <td class="td-marca"><?= h($row['marca']) ?></td>
                    <td class="td-modelo"><?= h($row['modelo']) ?></td>
                    <td><?= h($row['color']) ?></td>
                    <td><?= h($row['capacidad'] ?: '-') ?></td>
                    <td class="td-imei1"><span class="font-monospace"><?= h($row['imei1']) ?></span></td>
                    <td class="td-imei2"><span class="font-monospace"><?= h($row['imei2'] ?: '-') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center small-muted">No hay equipos disponibles en esta sucursal.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="text-end mt-3 d-flex justify-content-end gap-2">
          <button type="button" id="btnConfirmar" class="btn btn-primary">
            <i class="bi bi-arrow-right-circle"></i> Confirmar traspaso
          </button>
        </div>

        <!-- Modal de confirmación -->
        <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check2-square me-2"></i>Confirmar traspaso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">
                <p class="mb-1"><b>Destino:</b> <span id="resSucursal">—</span></p>
                <p class="mb-3"><b>Cantidad:</b> <span id="resCantidad">0</span></p>
                <div class="tbl-wrap">
                  <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>ID</th><th>Marca</th><th>Modelo</th><th>IMEI1</th><th>IMEI2</th>
                      </tr>
                    </thead>
                    <tbody id="resTbody"></tbody>
                  </table>
                </div>
                <div class="alert alert-warning mt-3 mb-0">
                  <i class="bi bi-info-circle"></i> Los equipos seleccionados cambiarán a <b>“En tránsito”</b>.
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-send-check"></i> Generar traspaso
                </button>
              </div>
            </div>
          </div>
        </div>

      </form>
    </div>

    <!-- Panel lateral de seleccionados -->
    <div class="col-lg-4">
      <div class="card card-surface sticky-top" style="top: 90px;">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-list-check"></i>
            <strong>Selección actual</strong>
          </div>
          <span class="badge text-bg-secondary" id="badgeCount">0</span>
        </div>
        <div class="card-body p-0">
          <div class="tbl-wrap" style="max-height: 360px;">
            <table class="table table-sm mb-0" id="tablaSeleccion">
              <thead class="table-light">
                <tr><th>ID</th><th>Modelo</th><th>IMEI</th><th></th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted" id="miniDestino">Destino: —</small>
          <button type="button" class="btn btn-soft btn-sm" id="btnAbrirModal" disabled>Confirmar (0)</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal ACUSE (iframe) -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de entrega</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="frameAcuse" src="about:blank"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary">
          <i class="bi bi-box-arrow-up-right me-1"></i> Abrir en pestaña
        </button>
        <button type="button" id="btnPrintAcuse" class="btn btn-primary">
          <i class="bi bi-printer me-1"></i> Reimprimir
        </button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Helpers front
const norm = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
function esc(s){return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}

const buscar   = document.getElementById('buscarIMEI');
const tabla    = document.getElementById('tablaInventario');
const rows     = Array.from(tabla.querySelectorAll('tbody tr'));
const checkAll = document.getElementById('checkAll');
const chipSel  = document.getElementById('chipSel');
const badgeSel = document.getElementById('badgeCount');
const btnModal = document.getElementById('btnAbrirModal');
const miniDest = document.getElementById('miniDestino');
const selDest  = document.getElementById('sucursal_destino');

// Búsqueda rápida
function applyFilter(){
  const q = norm(buscar.value);
  let visibles = 0, visiblesChecked = 0;
  rows.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    tr.style.display = match ? '' : 'none';
    if (match) {
      visibles++;
      if (tr.querySelector('.chk-equipo').checked) visiblesChecked++;
    }
  });
  checkAll.indeterminate = visiblesChecked>0 && visiblesChecked<visibles;
  checkAll.checked = visibles>0 && visiblesChecked===visibles;
}
buscar?.addEventListener('input', applyFilter);

// Seleccionar todos (visibles)
checkAll?.addEventListener('change', () => {
  const q = norm(buscar.value);
  rows.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    if (match && tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-equipo');
      chk.checked = checkAll.checked;
      tr.classList.toggle('row-selected', chk.checked);
    }
  });
  rebuildSelection();
});

// Toggle por fila / checkbox
rows.forEach(tr => {
  const chk = tr.querySelector('.chk-equipo');
  tr.addEventListener('click', (e) => {
    if (e.target.tagName.toLowerCase() === 'input') return;
    chk.checked = !chk.checked;
    tr.classList.toggle('row-selected', chk.checked);
    rebuildSelection();
    applyFilter();
  });
  chk.addEventListener('change', () => {
    tr.classList.toggle('row-selected', chk.checked);
    rebuildSelection();
    applyFilter();
  });
});

// Construir panel lateral
function rebuildSelection(){
  const tbody = document.querySelector('#tablaSeleccion tbody');
  tbody.innerHTML = '';
  let count = 0;
  rows.forEach(tr => {
    const chk = tr.querySelector('.chk-equipo');
    if (chk.checked) {
      const id     = tr.querySelector('.td-id').textContent.trim();
      const marca  = tr.querySelector('.td-marca').textContent.trim();
      const modelo = tr.querySelector('.td-modelo').textContent.trim();
      const imei   = tr.querySelector('.td-imei1').textContent.trim();
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${esc(id)}</td>
        <td>${esc(marca)} ${esc(modelo)}</td>
        <td class="font-monospace">${esc(imei)}</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-id="${esc(id)}"><i class="bi bi-x"></i></button></td>
      `;
      tbody.appendChild(row);
      count++;
    }
  });
  chipSel.textContent  = count;
  badgeSel.textContent = count;
  btnModal.textContent = `Confirmar (${count})`;
  btnModal.disabled    = (count === 0);
}
document.querySelector('#tablaSeleccion tbody').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-id]');
  if (!btn) return;
  const id = btn.getAttribute('data-id');
  const chk = document.querySelector(`.chk-equipo[value="${CSS.escape(id)}"]`);
  if (chk){ chk.checked = false; chk.closest('tr').classList.remove('row-selected'); }
  rebuildSelection();
  applyFilter();
});

// Destino mini-label
selDest?.addEventListener('change', () => {
  const txt = selDest.value ? selDest.options[selDest.selectedIndex].text : '—';
  miniDest.textContent = `Destino: ${txt}`;
});

// Modal de resumen
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen() {
  const seleccionados = Array.from(document.querySelectorAll('.chk-equipo:checked'));
  if (!selDest.value) { alert('Selecciona una sucursal destino.'); selDest.focus(); return; }
  if (seleccionados.length === 0) { alert('Selecciona al menos un equipo.'); return; }

  document.getElementById('resSucursal').textContent = selDest.options[selDest.selectedIndex].text;
  document.getElementById('resCantidad').textContent = seleccionados.length;

  const tbody = document.getElementById('resTbody');
  tbody.innerHTML = '';
  seleccionados.forEach(chk => {
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const imei2 = tr.querySelector('.td-imei2').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>${esc(id)}</td><td>${esc(marca)}</td><td>${esc(modelo)}</td><td class="font-monospace">${esc(imei1)}</td><td class="font-monospace">${esc(imei2)}</td>`;
    tbody.appendChild(row);
  });

  modalResumen.show();
}
document.getElementById('btnAbrirModal').addEventListener('click', openResumen);
document.getElementById('btnConfirmar').addEventListener('click', openResumen);

// ===== Modal ACUSE: auto-apertura al terminar el traspaso =====
const ACUSE_URL = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL; // incluye &print=1 → acuse_traspaso.php disparará window.print() dentro del iframe
  // Fallback: si el navegador bloquea, dejamos botones en el footer
  frame.addEventListener('load', () => {
    try { frame.contentWindow.focus(); } catch(e){}
  });
  modalAcuse.show();

  // Botones footer
  document.getElementById('btnOpenAcuse').onclick = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>
