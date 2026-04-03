<?php
// historial_ventas_scooters.php — Historial de ventas de SCOOTERS
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// ===== Datos de sesión =====
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, '.', ','); }
function fmtFecha($dt){
  if (!$dt) return '—';
  $t = strtotime($dt);
  if (!$t) return h($dt);
  return date('Y-m-d H:i', $t);
}

// ===== Filtros (GET) =====
$hoy = date('Y-m-d');
$primerDiaMes = date('Y-m-01');

$desde = $_GET['desde'] ?? $primerDiaMes;
$hasta = $_GET['hasta'] ?? $hoy;

$f_sucursal = (int)($_GET['sucursal'] ?? 0);
$f_usuario  = (int)($_GET['usuario']  ?? 0);
$f_tipo     = trim($_GET['tipo_venta'] ?? '');
$f_q        = trim($_GET['q'] ?? '');

// Normalizar fechas
if ($desde === '') $desde = $primerDiaMes;
if ($hasta === '') $hasta = $hoy;

// ===== Catálogos: sucursales y usuarios =====
$sucursales = [];
$rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($rs) {
  while ($row = $rs->fetch_assoc()) {
    $sucursales[] = $row;
  }
}

$usuarios = [];
$rsu = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre");
if ($rsu) {
  while ($row = $rsu->fetch_assoc()) {
    $usuarios[] = $row;
  }
}

// ===== Construcción del WHERE (mismas reglas que export) =====
$where = "1=1";

// Rango de fechas (por fecha_venta)
if ($desde) {
  $desdeEsc = $conn->real_escape_string($desde);
  $where .= " AND DATE(v.fecha_venta) >= '{$desdeEsc}'";
}
if ($hasta) {
  $hastaEsc = $conn->real_escape_string($hasta);
  $where .= " AND DATE(v.fecha_venta) <= '{$hastaEsc}'";
}

// Restricciones por rol
switch ($rol) {
  case 'Ejecutivo':
    $where .= " AND v.id_usuario = " . (int)$idUsuario;
    break;
  case 'Gerente':
  case 'GerenteZona':
    $where .= " AND v.id_sucursal = " . (int)$idSucursal;
    break;
  case 'Admin':
  case 'SuperAdmin':
  case 'MasterAdmin':
  default:
    // Sin restricción extra
    break;
}

// Filtro sucursal (solo aplica realmente a roles globales, pero no estorba)
if ($f_sucursal > 0) {
  $where .= " AND v.id_sucursal = " . (int)$f_sucursal;
}

// Filtro usuario
if ($f_usuario > 0) {
  $where .= " AND v.id_usuario = " . (int)$f_usuario;
}

// Filtro tipo_venta
if ($f_tipo !== '') {
  $tipoEsc = $conn->real_escape_string($f_tipo);
  $where .= " AND v.tipo_venta = '{$tipoEsc}'";
}

// Búsqueda general (TAG, cliente, teléfono, IMEI)
if ($f_q !== '') {
  $qEsc = $conn->real_escape_string($f_q);
  $like = "'%" . $qEsc . "%'";
  $where .= "
    AND (
      v.tag LIKE {$like}
      OR v.nombre_cliente LIKE {$like}
      OR v.telefono_cliente LIKE {$like}
      OR dv.imei1 LIKE {$like}
    )";
}

// ===== Consulta principal =====
$sql = "
  SELECT
    v.id,
    v.tag,
    v.nombre_cliente,
    v.telefono_cliente,
    v.tipo_venta,
    v.precio_venta,
    v.fecha_venta,
    v.enganche,
    v.forma_pago_enganche,
    v.plazo_semanas,
    v.financiera,
    v.comentarios,
    u.nombre AS ejecutivo,
    s.nombre AS sucursal,
    COUNT(DISTINCT dv.id_producto) AS piezas
  FROM ventas_scooter v
  INNER JOIN usuarios u   ON v.id_usuario  = u.id
  INNER JOIN sucursales s ON v.id_sucursal = s.id
  LEFT JOIN detalle_venta_scooter dv ON dv.id_venta = v.id
  WHERE $where
  GROUP BY v.id
  ORDER BY v.fecha_venta DESC
  LIMIT 500
";

$result = $conn->query($sql);
$ventas = [];
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $ventas[] = $row;
  }
}

// ===== Mensajes (ok / err) =====
$msg_ok  = isset($_GET['ok'])  ? trim((string)$_GET['ok'])  : '';
$msg_err = isset($_GET['err']) ? trim((string)$_GET['err']) : '';

// ===== URL de export con mismos filtros =====
$exportParams = [
  'desde'      => $desde,
  'hasta'      => $hasta,
  'sucursal'   => $f_sucursal,
  'usuario'    => $f_usuario,
  'tipo_venta' => $f_tipo,
  'q'          => $f_q,
];
$export_url = 'exportar_ventas_scooters.php?' . http_build_query($exportParams);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial Ventas Scooters</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    body.bg-light {
      background:
        radial-gradient(1200px 400px at 100% -50%, rgba(13,110,253,.06), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(16,185,129,.04), transparent),
        #f8fafc;
    }
    .page-title { font-weight: 700; letter-spacing: .3px; }
    .card-elev {
      border: 0;
      box-shadow: 0 10px 24px rgba(15,23,42,.08), 0 2px 6px rgba(15,23,42,.06);
      border-radius: 1rem;
    }
    .badge-soft {
      background: #eef2ff;
      color: #1e40af;
      border: 1px solid #dbeafe;
    }
    .table-sm td, .table-sm th {
      padding: .4rem .5rem;
      vertical-align: middle;
    }
    .filters-label { font-size: .8rem; text-transform: uppercase; color: #64748b; letter-spacing: .05em; }
    .search-pill {
      border-radius: 999px;
    }
    @media (max-width: 768px) {
      .table-responsive { font-size: .85rem; }
    }
  </style>
</head>
<body class="bg-light">

<div class="container my-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h1 class="page-title mb-1">
        <i class="bi bi-clipboard2-check me-2"></i>Historial Ventas Scooters
      </h1>
      <div class="text-muted" style="font-size:.9rem;">
        Consulta las ventas de scooters por rango de fechas, sucursal, ejecutivo o búsqueda general.
      </div>
    </div>
    <div class="text-end">
      <span class="badge rounded-pill text-bg-primary me-1">
        <i class="bi bi-person-badge me-1"></i><?= h($nombreUser) ?>
      </span>
      <span class="badge rounded-pill badge-soft">
        <i class="bi bi-shield-check me-1"></i><?= h($rol) ?>
      </span>
    </div>
  </div>

  <?php if ($msg_ok !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle me-1"></i><?= h($msg_ok) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($msg_err !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($msg_err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <!-- Filtros -->
  <div class="card card-elev mb-4">
    <div class="card-body">
      <form class="row g-3 align-items-end" method="get">
        <div class="col-6 col-md-3">
          <label class="filters-label mb-1">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="filters-label mb-1">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>">
        </div>

        <div class="col-12 col-md-3">
          <label class="filters-label mb-1">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">Todas</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>"
                <?= $f_sucursal === (int)$s['id'] ? 'selected' : '' ?>>
                <?= h($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="filters-label mb-1">Ejecutivo</label>
          <select name="usuario" class="form-select">
            <option value="0">Todos</option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?= (int)$u['id'] ?>"
                <?= $f_usuario === (int)$u['id'] ? 'selected' : '' ?>>
                <?= h($u['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3">
          <label class="filters-label mb-1">Tipo de venta</label>
          <select name="tipo_venta" class="form-select">
            <option value="">Todos</option>
            <?php
              $tipos = ['Contado','Financiamiento','Financiamiento+Combo'];
              foreach ($tipos as $tv):
            ?>
              <option value="<?= h($tv) ?>" <?= $f_tipo === $tv ? 'selected' : '' ?>>
                <?= h($tv) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-5">
          <label class="filters-label mb-1">Búsqueda (TAG, cliente, teléfono, IMEI)</label>
          <input type="text"
                 name="q"
                 class="form-control search-pill"
                 placeholder="Ej. TAG, nombre cliente, 10 dígitos o parte del IMEI"
                 value="<?= h($f_q) ?>">
        </div>

        <div class="col-12 col-md-4 d-flex flex-wrap gap-2 justify-content-start justify-content-md-end">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-funnel me-1"></i> Aplicar filtros
          </button>
          <a href="historial_ventas_scooters.php" class="btn btn-outline-secondary">
            <i class="bi bi-x-circle me-1"></i> Limpiar
          </a>
          <!-- Botón Exportar (mismos filtros y reglas de rol) -->
          <a href="<?= h($export_url) ?>" class="btn btn-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Exportar
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- Resumen -->
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="text-muted" style="font-size:.9rem;">
      Mostrando <strong><?= count($ventas) ?></strong> ventas (máx. 500 resultados).
    </div>
  </div>

  <!-- Tabla -->
  <div class="card card-elev">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col">Fecha</th>
              <th scope="col">Sucursal</th>
              <th scope="col">Ejecutivo</th>
              <th scope="col">Tipo</th>
              <th scope="col">TAG</th>
              <th scope="col">Cliente</th>
              <th scope="col">Teléfono</th>
              <th scope="col" class="text-end">Precio venta</th>
              <th scope="col" class="text-end">Enganche</th>
              <th scope="col">Financiera</th>
              <th scope="col" class="text-center">Pzs</th>
              <th scope="col">Comentarios</th>
              <?php if ($rol === 'Admin'): ?>
                <th scope="col" class="text-center">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (!$ventas): ?>
            <tr>
              <td colspan="<?= $rol === 'Admin' ? 13 : 12 ?>" class="text-center text-muted py-4">
                <i class="bi bi-info-circle me-1"></i>
                No se encontraron ventas de scooters con los filtros seleccionados.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($ventas as $v): ?>
              <tr>
                <td><?= h(fmtFecha($v['fecha_venta'])) ?></td>
                <td><?= h($v['sucursal']) ?></td>
                <td><?= h($v['ejecutivo']) ?></td>
                <td>
                  <?php if ($v['tipo_venta'] === 'Financiamiento'): ?>
                    <span class="badge bg-warning text-dark"><?= h($v['tipo_venta']) ?></span>
                  <?php elseif ($v['tipo_venta'] === 'Contado'): ?>
                    <span class="badge bg-success"><?= h($v['tipo_venta']) ?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><?= h($v['tipo_venta']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= h($v['tag']) ?></td>
                <td><?= h($v['nombre_cliente']) ?></td>
                <td><?= h($v['telefono_cliente']) ?></td>
                <td class="text-end">$<?= fmtMoney($v['precio_venta']) ?></td>
                <td class="text-end">$<?= fmtMoney($v['enganche']) ?></td>
                <td><?= h($v['financiera'] ?: 'N/A') ?></td>
                <td class="text-center"><?= (int)($v['piezas'] ?? 0) ?></td>
                <td style="max-width:220px;">
                  <span class="d-inline-block text-truncate" style="max-width: 220px;" title="<?= h($v['comentarios']) ?>">
                    <?= h($v['comentarios']) ?>
                  </span>
                </td>
                <?php if ($rol === 'Admin'): ?>
                  <td class="text-center">
                    <form method="post"
                          action="eliminar_venta_scooter.php"
                          onsubmit="return confirm('¿Seguro que deseas eliminar esta venta de scooter y devolver el inventario a Disponible?');">
                      <input type="hidden" name="id_venta" value="<?= (int)$v['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
