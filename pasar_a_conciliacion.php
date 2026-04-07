<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = trim($_SESSION['rol'] ?? '');

$rolesPermitidos = ['Gerente', 'Admin', 'Super', 'SuperAdmin', 'Gerente General', 'GerenteZona'];
if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    die('No tienes permisos para realizar esta acción.');
}

$idInventario = (int)($_POST['id_inventario'] ?? $_GET['id'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$inventario = obtenerInventarioCiclicoPorId($idInventario);
if (!$inventario) {
    die('No se encontró el inventario.');
}

$rolesAdminLike = ['Admin', 'Super', 'SuperAdmin', 'Gerente General'];
$esAdminLike = in_array($rol, $rolesAdminLike, true);

if (!$esAdminLike && (int)$inventario['id_sucursal'] !== $idSucursal) {
    http_response_code(403);
    die('No puedes modificar un inventario de otra sucursal.');
}

if ($inventario['estatus'] !== 'EnCaptura') {
    die('Este inventario ya no puede pasar a conciliación.');
}

$sql = "
    UPDATE inventarios_ciclicos
    SET estatus = 'EnConciliacion'
    WHERE id = ?
      AND estatus = 'EnCaptura'
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('No se pudo preparar la actualización.');
}

$stmt->bind_param("i", $idInventario);
$stmt->execute();
$afectadas = (int)$stmt->affected_rows;
$stmt->close();

if ($afectadas <= 0) {
    die('No se pudo cambiar a conciliación.');
}

registrarBitacoraInventarioCiclico(
    $idInventario,
    'paso_conciliacion',
    'El inventario pasó a conciliación. La captura quedó bloqueada.',
    null,
    $idUsuario
);

header("Location: inventario_ciclico_conciliacion.php?id=" . $idInventario);
exit();