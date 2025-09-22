<?php
// export_payjoy_tc.php — Export CSV PayJoy TC (scope=week|month)
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php"); exit();
}
require_once __DIR__ . '/db.php';

$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$isAdmin    = in_array($ROL, ['Admin','SuperAdmin','RH'], true);
$isGerente  = in_array($ROL, ['Gerente','Gerente General','GerenteZona','GerenteSucursal'], true);

$scope = $_GET['scope'] ?? 'week';
$q     = trim($_GET['q'] ?? '');
$fil_suc = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;
$fil_user= isset($_GET['id_usuario'])  ? (int)$_GET['id_usuario']  : 0;

/* Rango */
if ($scope === 'month') {
  $ini = $_GET['m_ini'] ?? ''; $fin = $_GET['m_fin'] ?? '';
} else {
  $ini = $_GET['ini'] ?? '';   $fin = $_GET['fin'] ?? '';
}
if (!$ini || !$fin) { http_response_code(400); echo "Falta rango"; exit(); }

/* SQL base */
$sql = "
  SELECT v.id, v.fecha_venta, s.nombre AS sucursal, u.nombre AS usuario,
         v.nombre_cliente, v.tag, v.comision
  FROM ventas_payjoy_tc v
  INNER JOIN usuarios u   ON u.id = v.id_usuario
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.fecha_venta BETWEEN ? AND ?
";
$params = [$ini,$fin]; $types="ss";

/* filtros por rol (seguro server-side) */
if ($isGerente) {
  $sql .= " AND v.id_sucursal=? "; $params[] = $idSucursal; $types.="i";
  if ($fil_user>0) { $sql.=" AND v.id_usuario=? "; $params[]=$fil_user; $types.="i"; }
} elseif (!$isAdmin) {
  // Ejecutivo
  $sql .= " AND v.id_usuario=? AND v.id_sucursal=? ";
  $params[]=$idUsuario; $params[]=$idSucursal; $types.="ii";
} else {
  // Admin
  if ($fil_suc>0)  { $sql.=" AND v.id_sucursal=? "; $params[]=$fil_suc;  $types.="i"; }
  if ($fil_user>0) { $sql.=" AND v.id_usuario=? ";  $params[]=$fil_user; $types.="i"; }
}

/* búsqueda */
if ($q!=='') { $sql.=" AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) "; $params[]=$q; $params[]=$q; $types.="ss"; }
$sql.=" ORDER BY v.fecha_venta DESC, v.id DESC ";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* Headers CSV */
$fname = "payjoy_tc_".$scope."_".date('Ymd_His').".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Fecha','Sucursal','Usuario','Cliente','TAG','Comision']);

$total = 0.0; $count=0;
while ($r = $res->fetch_assoc()) {
  fputcsv($out, [
    $r['id'],
    (new DateTime($r['fecha_venta']))->format('Y-m-d H:i'),
    $r['sucursal'],
    $r['usuario'],
    $r['nombre_cliente'],
    $r['tag'],
    number_format((float)$r['comision'],2,'.','')
  ]);
  $total += (float)$r['comision']; $count++;
}
fputcsv($out, []);
fputcsv($out, ['Total filas', $count, '', '', '', 'Total comisión', number_format($total,2,'.','')]);
fclose($out);
exit();
