<?php
// payjoy_tc_guardar.php — Guardar venta PayJoy TC
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_features.php';

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Bandera efectiva
$flagOpen = PAYJOY_TC_CAPTURE_OPEN || ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW);

// Bloquea si no está habilitado
if (!$flagOpen) {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("❌ La captura de PayJoy TC aún no está habilitada."));
  exit();
}

// Datos
$nombreCliente = trim($_POST['nombre_cliente'] ?? '');
$tag           = trim($_POST['tag'] ?? '');
$comentarios   = trim($_POST['comentarios'] ?? '');
$comision      = 100.00; // fija

if ($nombreCliente === '' || $tag === '') {
  header("Location: payjoy_tc_nueva.php?err=" . urlencode("Faltan datos obligatorios"));
  exit();
}

$stmt = $conn->prepare("INSERT INTO ventas_payjoy_tc (id_usuario,id_sucursal,nombre_cliente,tag,comision,comentarios,fecha_venta) VALUES (?,?,?,?,?,?,NOW())");
$stmt->bind_param("iissds", $idUsuario, $idSucursal, $nombreCliente, $tag, $comision, $comentarios);
$stmt->execute();
$stmt->close();

header("Location: historial_payjoy_tc.php?msg=" . urlencode("✅ Venta registrada correctamente"));
exit();
