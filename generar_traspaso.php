<?php
// generar_traspaso_eulalia.php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin','Gerente'], true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';

$mensaje    = '';
$acuseUrl   = '';     // URL del acuse con print=1
$acuseReady = false;  // bandera para disparar el modal en el front

// ===============================
// 1) Resolver ID de "Eulalia" (Almacén central) de forma tolerante
// ===============================
$idEulalia = 0;

// a) intento exacto con las variantes más comunes
if ($stmt = $conn->prepare("SELECT id FROM sucursales WHERE LOWER(nombre) IN ('eulalia','luga eulalia') LIMIT 1")) {
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $idEulalia = (int)$row['id'];
  $stmt->close();
}

// b) fallback: por LIKE insensible a mayúsculas
if ($idEulalia <= 0) {
  $rs = $conn->query("SELECT id FROM sucursales WHERE LOWER(nombre) LIKE '%eulalia%' ORDER BY LENGTH(nombre) ASC LIMIT 1");
  if ($rs && $r = $rs->fetch_assoc()) $idEulalia = (int)$r['id'];
}

if ($idEulalia <= 0) {
  echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se encontró la sucursal de inventario central “Eulalia”. Verifica el catálogo de sucursales.</div></div>";
  exit();
}

// ===============================
// 2) Procesar TRASPASO (POST)
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $equiposSeleccionados = isset($_POST['equipos']) && is_array($_POST['equipos']) ? $_POST['equipos'] : [];
  $idSucursalDestino    = (int)($_POST['sucursal_destino'] ?? 0);
  $idUsuario            = (int)($_SESSION['id_usuario'] ?? 0);

  if ($idSucursalDestino <= 0) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  Selecciona una sucursal destino.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } elseif (empty($equiposSeleccionados)) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  No seleccionaste ningún equipo para traspasar.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } elseif ($idSucursalDestino === $idEulalia) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  El destino no puede ser Eulalia.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } else {
    // Normaliza IDs
    $idsInv = array_values(array_unique(array_map('intval', $equiposSeleccionados)));
    if (!empty($idsInv)) {
      $conn->begin_transaction();
      try {
        // CABECERA del traspaso (fecha_traspaso = DEFAULT current_timestamp())
        $stmt = $conn->prepare("
          INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus)
          VALUES (?,?,?, 'Pendiente')
        ");
        $stmt->bind_param("iii", $idEulalia, $idSucursalDestino, $idUsuario);
        $stmt->execute();
        $idTraspaso = (int)$stmt->insert_id;
        $stmt->close();

        // Validar que TODOS los inventarios sigan en Eulalia y 'Disponible'
        $placeholders = implode(',', array_fill(0, count($idsInv), '?'));
        $typesIds = str_repeat('i', count($idsInv));
        $sqlVal = "
          SELECT i.id
          FROM inventario i
          WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($placeholders)
        ";
        $stmtVal = $conn->prepare($sqlVal);
        $typesFull = 'i' . $typesIds; // primero id_sucursal, luego lista de IDs
        $stmtVal->bind_param($typesFull, $idEulalia, ...$idsInv);
        $stmtVal->execute();
        $rsVal = $stmtVal->get_result();
        $validos = [];
        while ($r = $rsVal->fetch_assoc()) $validos[] = (int)$r['id'];
        $stmtVal->close();

        if (count($validos) !== count($idsInv)) {
          $conn->rollback();
          $mensaje = "<div class='alert alert-danger alert-dismissible fade show shadow-sm' role='alert'>
                        Algunos equipos ya no están disponibles en Eulalia o cambiaron de estatus. Refresca e intenta de nuevo.
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                      </div>";
        } else {
          // Inserta detalle y pone En tránsito (blindando id_sucursal y estatus)
          $stmtDet = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?,?)");
          $stmtUpd = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=? AND id_sucursal=? AND estatus='Disponible'");

          foreach ($validos as $idInv) {
            $stmtDet->bind_param("ii", $idTraspaso, $idInv);
            $stmtDet->execute();

            $stmtUpd->bind_param("ii", $idInv, $idEulalia);
            $stmtUpd->execute();
            if ($stmtUpd->affected_rows !== 1) {
              throw new Exception("Fallo al actualizar inventario #$idInv");
            }
          }
          $stmtDet->close();
          $stmtUpd->close();

          $conn->commit();

          // URL del acuse (auto-impresión en iframe) + bandera para modal
          $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&print=1";
          $acuseReady = true;

          // Mensaje de éxito (solo informativo; el modal se abrirá solo)
          $mensaje = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='bi bi-check-circle me-1'></i>
                        <strong>Traspaso #{$idTraspaso}</strong> generado con éxito. Los equipos ahora están <b>En tránsito</b>.
                        <div class='small text-muted mt-1'>Se abrirá el acuse en una vista previa para imprimir.</div>
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                      </div>";
        }
      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "<div class='alert alert-danger alert-dismissible fade show shadow-sm' role='alert'>
                      Error al generar traspaso: ".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."
                      <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                    </div>";
      }
    }
  }
}

// ===============================
// 3) Inventario DISPONIBLE en Eulalia
// ===============================
$sql = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? AND i.estatus='Disponible'
ORDER BY i.fecha_ingreso ASC, i.id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idEulalia);
$stmt->execute();
$result = $stmt->get_result();
$inventario = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===============================
// 4) Sucursales DESTINO (solo Tiendas; excluir Eulalia)
// ===============================
$sucursales = [];
$resSuc = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE LOWER(tipo_sucursal)='tienda' AND id <> {$idEulalia}
  ORDER BY nombre ASC
");
while ($row = $resSuc->fetch_assoc()) $sucursales[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Traspaso (Eulalia)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap / Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    body { background:#f7f8fb; }
    .page-header{
      background:linear-gradient(180deg,#ffffff,#f4f6fb);
      border:1px solid #eef0f6; border-radius:18px; padding:18px 20px;
      box-shadow:0 6px 20px rgba(18,38,63,.06);
    }
    .muted{ color:#6c757d; }
    .card{ border:1px solid #eef0f6; border-radius:16px; overflow:hidden; }
    .card-header{ background:#fff; border-bottom:1px solid #eef0f6; }
    .form-select,.form-control{ border-radius:12px; border-color:#e6e9f2; }
    .form-select:focus,.form-control:focus{ box-shadow:0 0 0 .25rem rgba(13,110,253,.08); border-color:#c6d4ff; }
    .search-wrap{
      position:sticky; top:82px; z-index:7;
      background:linear-gradient(180deg,#ffffff 40%,rgba(255,255,255,.7));
      padding:10px; border-bottom:1px solid #eef0f6; border-radius:12px;
    }
    #tablaInventario thead.sticky{ position:sticky; top:0; z-index:5; background:#fff; }
    #tablaInventario tbody tr:hover{ background:#f1f5ff !important; }
    #tablaInventario th{ white-space:nowrap; }
    .chip{ border:1px solid #e6e9f2; border-radius:999px; padding:.25rem .6rem; background:#fff; font-size:.8rem; }
    .table code{ color:inherit; background:#f8fafc; padding:2px 6px; border-radius:6px; }
    .sticky-aside{ position:sticky; top:92px; }

    /* Modal del acuse */
    .modal-xxl { max-width: 1200px; }
    #frameAcuse { width:100%; min-height:72vh; border:0; background:#fff; }
  </style>
</head>
<body>

<?php include __DIR__.'/navbar.php'; ?>

<div class="container my-4">

  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1"><i class="bi bi-arrow-left-right me-2"></i>Generar traspaso</h1>
      <div class="muted">
        <span class="badge rounded-pill text-bg-light border"><i class="bi bi-house-gear me-1"></i>Origen: Eulalia</span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($acuseUrl): ?>
        <a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?= htmlspecialchars($acuseUrl) ?>">
          <i class="bi bi-printer me-1"></i> Imprimir acuse
        </a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="traspasos_salientes.php">
        <i class="bi bi-clock-history me-1"></i>Histórico
      </a>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-4">
    <!-- Izquierda -->
    <div class="col-lg-8">
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-geo-alt text-primary"></i>
            <strong>Seleccionar sucursal destino</strong>
          </div>
          <span class="muted small">Requerido</span>
        </div>
        <div class="card-body">
          <form id="formTraspaso" method="POST">
            <div class="row g-2 mb-2">
              <div class="col-md-8">
                <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
                  <option value="">— Selecciona Sucursal —</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 d-flex align-items-center">
                <div class="muted small" id="miniDestino">Destino: —</div>
              </div>
            </div>

            <!-- Buscador sticky -->
            <div class="search-wrap rounded-3 mb-2">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI, marca o modelo...">
                <button type="button" class="btn btn-outline-secondary" id="btnLimpiarBusqueda">
                  <i class="bi bi-x-circle"></i>
                </button>
              </div>
            </div>

            <!-- Inventario -->
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-box-seam text-primary"></i>
                  <span><strong>Inventario disponible</strong> en Eulalia</span>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="checkAll">
                  <label class="form-check-label" for="checkAll">Seleccionar visibles</label>
                </div>
              </div>

              <div class="table-responsive" style="max-height:520px; overflow:auto;">
                <table class="table table-hover align-middle mb-0" id="tablaInventario">
                  <thead class="sticky">
                    <tr>
                      <th class="text-center">Sel</th>
                      <th>ID Inv</th>
                      <th>Marca</th>
                      <th>Modelo</th>
                      <th>Color</th>
                      <th>IMEI1</th>
                      <th>IMEI2</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($inventario)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inboxes me-1"></i>Sin equipos disponibles en Eulalia</td></tr>
                    <?php else: foreach ($inventario as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>">
                        <td class="text-center">
                          <input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo form-check-input">
                        </td>
                        <td class="td-id fw-semibold"><?= (int)$row['id'] ?></td>
                        <td class="td-marca"><?= htmlspecialchars($row['marca']) ?></td>
                        <td class="td-modelo"><?= htmlspecialchars($row['modelo']) ?></td>
                        <td class="td-color"><span class="chip"><?= htmlspecialchars($row['color']) ?></span></td>
                        <td class="td-imei1"><code><?= htmlspecialchars($row['imei1']) ?></code></td>
                        <td class="td-imei2"><?= $row['imei2'] ? "<code>".htmlspecialchars($row['imei2'])."</code>" : "—" ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button type="button" id="btnConfirmar" class="btn btn-primary">
                <i class="bi bi-shuffle me-1"></i>Confirmar traspaso
              </button>
              <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-eraser me-1"></i>Limpiar
              </button>
            </div>

            <!-- Modal confirmación -->
            <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check2-square me-1"></i>Confirmar traspaso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-3 mb-2">
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Origen</div>
                        <div class="fw-semibold">Eulalia</div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Destino</div>
                        <div class="fw-semibold" id="resSucursal">—</div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Cantidad</div>
                        <div class="fw-semibold"><span id="resCantidad">0</span> equipos</div>
                      </div>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-striped align-middle mb-0">
                        <thead><tr><th>ID</th><th>Marca</th><th>Modelo</th><th>IMEI1</th><th>IMEI2</th></tr></thead>
                        <tbody id="resTbody"></tbody>
                      </table>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>Generar traspaso</button>
                  </div>
                </div>
              </div>
            </div>

          </form>
        </div>
      </div>
    </div>

    <!-- Derecha / Carrito sticky -->
    <div class="col-lg-4">
      <div class="card shadow-sm sticky-aside">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check2-square text-info"></i><strong>Selección actual</strong>
          </div>
        <span class="badge bg-dark" id="badgeCount">0</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:360px; overflow:auto;">
            <table class="table table-sm mb-0" id="tablaSeleccion">
              <thead class="table-light">
                <tr><th>ID</th><th>Modelo</th><th>IMEI</th><th></th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted" id="miniDestinoFooter">Revisa la selección antes de confirmar</small>
          <button class="btn btn-primary btn-sm" id="btnAbrirModal" disabled>
            <i class="bi bi-clipboard-check me-1"></i>Confirmar (<span id="btnCount">0</span>)
          </button>
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

<!-- Bootstrap JS (bundle para Modal/Toast/Collapse) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
// ------- Filtro -------
const buscador = document.getElementById('buscadorIMEI');
buscador.addEventListener('keyup', () => {
  const f = buscador.value.toLowerCase();
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(f) ? '' : 'none';
  });
});
document.getElementById('btnLimpiarBusqueda').addEventListener('click', () => {
  buscador.value = ''; buscador.dispatchEvent(new Event('keyup')); buscador.focus();
});

// ------- Seleccionar visibles -------
document.getElementById('checkAll').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr=>{
    if (tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-equipo'); if (chk) chk.checked = checked;
    }
  });
  rebuildSelection();
});

// ------- Reconstruir carrito -------
function rebuildSelection(){
  const tbody = document.querySelector('#tablaSeleccion tbody');
  tbody.innerHTML = '';
  let count = 0;
  document.querySelectorAll('.chk-equipo:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei  = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td class="fw-semibold">${id}</td><td>${marca} ${modelo}</td><td><code>${imei}</code></td>
                     <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-id="${id}">
                       <i class="bi bi-x-lg"></i></button></td>`;
    tbody.appendChild(row);
    count++;
  });
  document.getElementById('badgeCount').textContent = count;
  document.getElementById('btnCount').textContent = count;
  document.getElementById('btnAbrirModal').disabled = (count === 0);
}
document.querySelectorAll('.chk-equipo').forEach(chk => chk.addEventListener('change', rebuildSelection));
document.querySelector('#tablaSeleccion tbody').addEventListener('click', (e)=>{
  const btn = e.target.closest('button[data-id]'); if (!btn) return;
  const id = btn.getAttribute('data-id');
  const chk = document.querySelector(`.chk-equipo[value="${id}"]`);
  if (chk) chk.checked = false;
  rebuildSelection();
});

// ------- Destino en textos auxiliares -------
document.getElementById('sucursal_destino').addEventListener('change', function(){
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestino').textContent = `Destino: ${txt}`;
  document.getElementById('miniDestinoFooter').textContent = `Destino: ${txt}`;
});

// ------- Modal resumen -------
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen(){
  const sel = document.getElementById('sucursal_destino');
  const seleccionados = document.querySelectorAll('.chk-equipo:checked');

  if (!sel.value){ alert('Selecciona una sucursal destino.'); sel.focus(); return; }
  if (seleccionados.length === 0){ alert('Selecciona al menos un equipo.'); return; }

  document.getElementById('resSucursal').textContent = sel.options[sel.selectedIndex].text;
  document.getElementById('resCantidad').textContent = seleccionados.length;

  const tbody = document.getElementById('resTbody'); tbody.innerHTML = '';
  seleccionados.forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const imei2 = tr.querySelector('.td-imei2').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>${id}</td><td>${marca}</td><td>${modelo}</td><td>${imei1}</td><td>${imei2 || '—'}</td>`;
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
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>
