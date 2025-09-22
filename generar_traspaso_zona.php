<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['GerenteZona','Admin'])) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';

/* ===============================
   Helpers
=============================== */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===============================
   ID de "Eulalia"
=============================== */
$idEulalia = 0;
if ($st = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")) {
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $idEulalia = (int)($row['id'] ?? 0);
  $st->close();
}

/* ===============================
   Zona del Gerente de Zona
=============================== */
$zonaUsuario = null;
if ($rolUsuario === 'GerenteZona') {
  $st = $conn->prepare("
    SELECT s.zona
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id=? LIMIT 1
  ");
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $zonaUsuario = $st->get_result()->fetch_assoc()['zona'] ?? null;
  $st->close();

  if (!$zonaUsuario) {
    echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se pudo determinar tu zona.</div></div>";
    exit();
  }
}

/* ===============================
   Listas de ORÍGENES y DESTINOS
=============================== */
// ORIGEN: GZ solo sucursales de su zona (sin Eulalia). Admin: todas.
if ($rolUsuario === 'GerenteZona') {
  $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE zona=? AND tipo_sucursal='Tienda' ORDER BY nombre");
  $stmt->bind_param("s", $zonaUsuario);
  $stmt->execute();
  $origens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  // por si acaso, filtrar Eulalia si apareciera
  if ($idEulalia > 0) $origens = array_values(array_filter($origens, fn($s)=> (int)$s['id'] !== $idEulalia));
} else {
  $res = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  $origens = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// DESTINO: todas las sucursales (incluida Eulalia para devoluciones)
$res = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
$destinos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

/* ===============================
   Origen seleccionado (GET)
=============================== */
$idOrigen = isset($_GET['origen']) ? (int)$_GET['origen'] : 0;
// si no viene, usar el primero disponible
if ($idOrigen === 0 && !empty($origens)) $idOrigen = (int)$origens[0]['id'];

// Validar permiso de origen para GZ
$origenPermitido = true;
if ($rolUsuario === 'GerenteZona') {
  $idsPermitidos = array_map(fn($s)=>(int)$s['id'], $origens);
  $origenPermitido = in_array($idOrigen, $idsPermitidos, true);
}
if (!$origenPermitido) {
  echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>Este origen no está permitido para tu zona.</div></div>";
  exit();
}

/* ===============================
   Inventario DISPONIBLE del ORIGEN
=============================== */
$inventario = [];
if ($idOrigen > 0) {
  $sqlInv = "
    SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal=? AND i.estatus='Disponible'
    ORDER BY i.fecha_ingreso ASC, i.id ASC
  ";
  $stmt = $conn->prepare($sqlInv);
  $stmt->bind_param("i", $idOrigen);
  $stmt->execute();
  $inventario = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ===============================
   POST: Generar TRASPASO
=============================== */
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipos'])) {
  $equiposSeleccionados = array_map('intval', $_POST['equipos'] ?? []);
  $idSucursalDestino    = (int)($_POST['sucursal_destino'] ?? 0);
  $idOrigenPost         = (int)($_POST['id_origen'] ?? 0);

  // Validar origen permitido para GZ
  if ($rolUsuario === 'GerenteZona') {
    $idsPermitidos = array_map(fn($s)=>(int)$s['id'], $origens);
    if (!in_array($idOrigenPost, $idsPermitidos, true)) {
      $mensaje = "<div class='alert alert-danger alert-dismissible fade show shadow-sm' role='alert'>
        Origen no permitido para tu zona.
        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
      </div>";
      $idOrigen = $idOrigenPost; // conservar selección
    }
  }

  if (empty($mensaje)) {
    if ($idSucursalDestino <= 0) {
      $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                    Selecciona una sucursal <b>destino</b>.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    } elseif ($idOrigenPost <= 0) {
      $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                    Selecciona una sucursal <b>origen</b>.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    } elseif ($idOrigenPost === $idSucursalDestino) {
      $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                    El origen y el destino no pueden ser la misma sucursal.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    } elseif (count($equiposSeleccionados) === 0) {
      $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                    No seleccionaste ningún equipo para traspasar.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    } else {
      // Insert dinámico en traspasos (manejo de fecha_traspaso/fecha_creacion opcional)
      $cols  = "id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus";
      $vals  = "?,?,?,'Pendiente'";
      $types = "iii";
      $bind  = [$idOrigenPost, $idSucursalDestino, $idUsuario];

      if (hasColumn($conn, 'traspasos', 'fecha_traspaso')) {
        $cols .= ", fecha_traspaso"; $vals .= ", NOW()";
      } elseif (hasColumn($conn, 'traspasos', 'fecha_creacion')) {
        $cols .= ", fecha_creacion"; $vals .= ", NOW()";
      }

      $sqlIns = "INSERT INTO traspasos ($cols) VALUES ($vals)";
      $stmt = $conn->prepare($sqlIns);
      $stmt->bind_param($types, ...$bind);
      $stmt->execute();
      $idTraspaso = $stmt->insert_id;
      $stmt->close();

      // Detalle + inventario a "En tránsito"
      $stmtDet = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");
      $stmtUp  = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=?");

      foreach ($equiposSeleccionados as $idInv) {
        $stmtDet->bind_param("ii", $idTraspaso, $idInv);
        $stmtDet->execute();

        $stmtUp->bind_param("i", $idInv);
        $stmtUp->execute();
      }
      $stmtDet->close();
      $stmtUp->close();

      // ==== FLASH DE CONFIRMACIÓN (mostrado tras redirect) ====
      // nombres amigables de origen/destino
      $nombreOrigen  = '';
      foreach ($origens as $o) { if ((int)$o['id'] === $idOrigenPost) { $nombreOrigen = $o['nombre']; break; } }
      $nombreDestino = '';
      if ($st = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1")) {
        $st->bind_param("i", $idSucursalDestino);
        $st->execute();
        $nombreDestino = $st->get_result()->fetch_assoc()['nombre'] ?? '';
        $st->close();
      }

      $_SESSION['flash_traspaso'] = [
        'id'             => (int)$idTraspaso,
        'cant'           => (int)count($equiposSeleccionados),
        'origen_nombre'  => (string)$nombreOrigen,
        'destino_nombre' => (string)$nombreDestino,
        'zona'           => (string)($zonaUsuario ?? ''),
        'ts'             => time()
      ];

      // Redirigimos para evitar reenvío de formulario y disparar el modal
      header("Location: generar_traspaso_zona.php?origen=".(int)$idOrigenPost."&ok=1");
      exit();
    }
  }
}

/* ===============================
   Cargar flash (si existe)
=============================== */
$flash = $_SESSION['flash_traspaso'] ?? null;
if ($flash) { unset($_SESSION['flash_traspaso']); }

/* ===============================
   HTML
=============================== */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Traspaso (Zona)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

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
    .search-wrap{ position:sticky; top:82px; z-index:7; background:linear-gradient(180deg,#ffffff 40%,rgba(255,255,255,.7)); padding:10px; border-bottom:1px solid #eef0f6; border-radius:12px; }
    #tablaInventario thead.sticky{ position:sticky; top:0; z-index:5; background:#fff; }
    #tablaInventario tbody tr:hover{ background:#f1f5ff !important; }
    #tablaInventario th{ white-space:nowrap; }
    .chip{ border:1px solid #e6e9f2; border-radius:999px; padding:.25rem .6rem; background:#fff; font-size:.8rem; }
    .sticky-aside{ position:sticky; top:92px; }
    .btn-soft{ background:#eef4ff; color:#2c5bff; border:1px solid #dfe8ff; }
    .btn-soft:hover{ background:#e5eeff; }

    /* Toast/Modal helpers */
    .toast-container{ position: fixed; top: 1rem; right: 1rem; z-index: 1081; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container my-4">

  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1"><i class="bi bi-arrow-left-right me-2"></i>Generar traspaso (Zona)</h1>
      <div class="muted">
        <?php if($rolUsuario==='GerenteZona'): ?>
          <span class="badge rounded-pill text-bg-light border"><i class="bi bi-geo-alt me-1"></i>Zona: <?= h($zonaUsuario) ?></span>
          <span class="badge rounded-pill text-bg-light border"><i class="bi bi-house-door me-1"></i>Origen: Sucursales de mi zona</span>
        <?php else: ?>
          <span class="badge rounded-pill text-bg-light border"><i class="bi bi-shield-lock me-1"></i>Admin</span>
        <?php endif; ?>
        <span class="badge rounded-pill text-bg-light border"><i class="bi bi-box-seam me-1"></i>Destino: Cualquier sucursal</span>
      </div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="traspasos_pendientes_zona.php">
      <i class="bi bi-clock-history me-1"></i>Pendientes de zona
    </a>
  </div>

  <?= $mensaje ?>

  <div class="row g-4">
    <!-- Origen/Destino -->
    <div class="col-lg-8">
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-geo-alt text-primary"></i>
            <strong>Seleccionar origen y destino</strong>
          </div>
        </div>
        <div class="card-body">
          <form id="formOrigen" method="GET" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Sucursal <b>Origen</b></label>
              <select name="origen" class="form-select" onchange="this.form.submit()">
                <?php foreach ($origens as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $idOrigen==(int)$s['id']?'selected':'' ?>>
                    <?= h($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if($idEulalia>0): ?>
                <div class="form-text">* Una de tus sucursales</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label">Sucursal <b>Destino</b> o almacén</label>
              <select name="sucursal_destino_ui" id="sucursal_destino_ui" class="form-select">
                <option value="">— Selecciona —</option>
                <?php foreach ($destinos as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">No puede ser la misma que el origen.</div>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-box-seam text-primary"></i>
            <span><strong>Inventario disponible</strong> en <b>
              <?php
                $n = '';
                foreach($origens as $o){ if((int)$o['id']===$idOrigen){ $n=$o['nombre']; break; } }
                echo h($n);
              ?>
            </b></span>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="checkAll">
            <label class="form-check-label" for="checkAll">Seleccionar todos (visibles)</label>
          </div>
        </div>

        <div class="search-wrap rounded-3 mb-2">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI, marca o modelo...">
            <button type="button" class="btn btn-outline-secondary" id="btnLimpiarBusqueda">
              <i class="bi bi-x-circle"></i>
            </button>
          </div>
        </div>

        <form id="formTraspaso" method="POST">
          <input type="hidden" name="id_origen" value="<?= (int)$idOrigen ?>">
          <input type="hidden" name="sucursal_destino" id="sucursal_destino" value="">
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
                  <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inboxes me-1"></i>Sin equipos disponibles</td></tr>
                <?php else: foreach ($inventario as $row): ?>
                  <tr data-id="<?= (int)$row['id'] ?>">
                    <td class="text-center">
                      <input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo form-check-input">
                    </td>
                    <td class="td-id fw-semibold"><?= (int)$row['id'] ?></td>
                    <td class="td-marca"><?= h($row['marca']) ?></td>
                    <td class="td-modelo"><?= h($row['modelo']) ?></td>
                    <td class="td-color"><span class="chip"><?= h($row['color']) ?></span></td>
                    <td class="td-imei1"><code><?= h($row['imei1']) ?></code></td>
                    <td class="td-imei2"><?= $row['imei2'] ? "<code>".h($row['imei2'])."</code>" : "—" ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
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
                  <div class="alert alert-warning py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Los equipos <b>marcados</b> saldrán de <b id="resOrigen"></b> y se enviarán a <b id="resSucursal"></b>, quedando <b>En tránsito</b> hasta ser aceptados en el destino.
                  </div>
                  <div class="row g-3 mb-2">
                    <div class="col-md-4">
                      <div class="small text-uppercase text-muted">Origen</div>
                      <div class="fw-semibold" id="resOrigen2">—</div>
                    </div>
                    <div class="col-md-4">
                      <div class="small text-uppercase text-muted">Destino</div>
                      <div class="fw-semibold" id="resSucursal2">—</div>
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
                  <button type="submit" class="btn btn-primary" id="btnSubmitForm"><i class="bi bi-send-check me-1"></i>Generar traspaso</button>
                </div>
              </div>
            </div>
          </div>
          <!-- /modal -->
        </form>
      </div>
    </div>

    <!-- Carrito -->
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
          <button class="btn btn-primary btn-sm" id="btnAbrirModal">
            <i class="bi bi-clipboard-check me-1"></i>Confirmar (<span id="btnCount">0</span>)
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal de ÉXITO tras generar (Flash) -->
<?php if ($flash): ?>
<div class="modal fade" id="modalExito" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-success">
      <div class="modal-header bg-success-subtle">
        <h5 class="modal-title">
          <i class="bi bi-check-circle-fill me-2 text-success"></i>
          ¡Traspaso generado!
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Se generó correctamente el traspaso:</p>
        <ul class="list-unstyled mb-2">
          <li><strong>Folio:</strong> <span id="folioTraspaso"><?= (int)$flash['id'] ?></span></li>
          <li><strong>Origen:</strong> <?= h($flash['origen_nombre']) ?></li>
          <li><strong>Destino:</strong> <?= h($flash['destino_nombre']) ?></li>
          <li><strong>Cantidad:</strong> <?= (int)$flash['cant'] ?> equipo(s)</li>
        </ul>
        <div class="small text-muted">
          Los equipos quedaron <b>En tránsito</b>. Podrán aceptarse desde <em>Pendientes de zona</em> en la sucursal destino.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Aceptar</button>
        <button type="button" class="btn btn-outline-primary" id="btnCopiarFolio">
          <i class="bi bi-clipboard me-1"></i>Copiar folio
        </button>
        <a href="traspasos_pendientes_zona.php" class="btn btn-success">
          <i class="bi bi-clock-history me-1"></i>Ir a pendientes
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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

// ------- Seleccionar todos (solo visibles) -------
document.getElementById('checkAll').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr=>{
    if (tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-equipo'); if (chk) chk.checked = checked;
    }
  });
  rebuildSelection();
});

// ------- Carrito -------
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
  document.getElementById('btnCount').textContent  = count;
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

// ------- Destino hidden + textos -------
const selDestino = document.getElementById('sucursal_destino_ui');
selDestino.addEventListener('change', function(){
  document.getElementById('sucursal_destino').value = this.value || '';
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestinoFooter').textContent = `Destino: ${txt}`;
});

// ------- Modal resumen -------
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen(){
  const sel = document.getElementById('sucursal_destino_ui');
  const destinoVal = sel.value;
  const destinoTxt = sel.options[sel.selectedIndex]?.text || '—';
  if (!destinoVal){ alert('Selecciona una sucursal destino.'); sel.focus(); return; }

  const seleccionados = document.querySelectorAll('.chk-equipo:checked');
  if (seleccionados.length === 0){ alert('Selecciona al menos un equipo.'); return; }

  // Origen texto
  const origenTxt = document.querySelector('select[name="origen"] option:checked')?.text || '';

  document.getElementById('resSucursal').textContent = destinoTxt;
  document.getElementById('resSucursal2').textContent = destinoTxt;
  document.getElementById('resOrigen').textContent   = origenTxt;
  document.getElementById('resOrigen2').textContent  = origenTxt;
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

// ------- Evitar doble submit -------
const formTraspaso = document.getElementById('formTraspaso');
const btnSubmitForm = document.getElementById('btnSubmitForm');
formTraspaso.addEventListener('submit', () => {
  if (btnSubmitForm) {
    btnSubmitForm.disabled = true;
    btnSubmitForm.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Procesando...';
  }
});

// ------- Mostrar modal de ÉXITO si hay flash -------
<?php if ($flash): ?>
  const modalExito = new bootstrap.Modal(document.getElementById('modalExito'));
  modalExito.show();

  // Copiar folio
  document.getElementById('btnCopiarFolio').addEventListener('click', async ()=>{
    const folio = document.getElementById('folioTraspaso').textContent.trim();
    try {
      await navigator.clipboard.writeText(folio);
      const b = document.getElementById('btnCopiarFolio');
      const prev = b.innerHTML;
      b.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>¡Copiado!';
      setTimeout(()=> b.innerHTML = prev, 1500);
    } catch(e) {}
  });
<?php endif; ?>
</script>
</body>
</html>
