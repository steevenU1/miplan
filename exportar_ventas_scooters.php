<?php
// exportar_ventas_scooters.php — Exporta ventas de scooters a Excel (HTML table)

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ===== Datos sesión =====
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? 'Ejecutivo';

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, '.', ','); }
function fmtFecha($dt){
  if (!$dt) return '';
  $t = strtotime($dt);
  if (!$t) return $dt;
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

// ===== Construcción WHERE (igual que historial) =====
$where = "1=1";

if ($desde) {
  $desdeEsc = $conn->real_escape_string($desde);
  $where .= " AND DATE(v.fecha_venta) >= '{$desdeEsc}'";
}
if ($hasta) {
  $hastaEsc = $conn->real_escape_string($hasta);
  $where .= " AND DATE(v.fecha_venta) <= '{$hastaEsc}'";
}

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
    break;
}

if ($f_sucursal > 0) {
  $where .= " AND v.id_sucursal = " . (int)$f_sucursal;
}

if ($f_usuario > 0) {
  $where .= " AND v.id_usuario = " . (int)$f_usuario;
}

if ($f_tipo !== '') {
  $tipoEsc = $conn->real_escape_string($f_tipo);
  $where .= " AND v.tipo_venta = '{$tipoEsc}'";
}

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

// ===== Consulta (sin LIMIT, con IMEIs concatenados) =====
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
    COUNT(dv.id) AS piezas,
    GROUP_CONCAT(DISTINCT dv.imei1 ORDER BY dv.imei1 SEPARATOR ' | ') AS imeis
  FROM ventas_scooter v
  INNER JOIN usuarios u   ON v.id_usuario  = u.id
  INNER JOIN sucursales s ON v.id_sucursal = s.id
  LEFT JOIN detalle_venta_scooter dv ON dv.id_venta = v.id
  WHERE $where
  GROUP BY v.id
  ORDER BY v.fecha_venta DESC
";

$res = $conn->query($sql);
$rows = [];
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }
}

// ===== Headers Excel =====
$filename = 'ventas_scooters_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM para UTF-8
echo "\xEF\xBB\xBF";
?>
<table border="1">
  <thead>
    <tr>
      <th>ID Venta</th>
      <th>Fecha venta</th>
      <th>Sucursal</th>
      <th>Ejecutivo</th>
      <th>Tipo venta</th>
      <th>TAG</th>
      <th>Cliente</th>
      <th>Teléfono</th>
      <th>Precio venta</th>
      <th>Enganche</th>
      <th>Forma pago enganche</th>
      <th>Plazo semanas</th>
      <th>Financiera</th>
      <th>Piezas</th>
      <th>IMEIs</th>
      <th>Comentarios</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="16">Sin datos para exportar.</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h(fmtFecha($r['fecha_venta'])) ?></td>
        <td><?= h($r['sucursal']) ?></td>
        <td><?= h($r['ejecutivo']) ?></td>
        <td><?= h($r['tipo_venta']) ?></td>
        <td><?= h($r['tag']) ?></td>
        <td><?= h($r['nombre_cliente']) ?></td>
        <td><?= h($r['telefono_cliente']) ?></td>
        <td><?= fmtMoney($r['precio_venta']) ?></td>
        <td><?= fmtMoney($r['enganche']) ?></td>
        <td><?= h($r['forma_pago_enganche']) ?></td>
        <td><?= (int)$r['plazo_semanas'] ?></td>
        <td><?= h($r['financiera']) ?></td>
        <td><?= (int)$r['piezas'] ?></td>
        <td><?= h($r['imeis']) ?></td>
        <td><?= h($r['comentarios']) ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
