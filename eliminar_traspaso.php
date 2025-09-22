<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$rol = $_SESSION['rol'] ?? '';
$idTraspaso = intval($_POST['id_traspaso'] ?? 0);

if ($idTraspaso <= 0) {
    die("Traspaso no válido.");
}

// 🔹 Verificar si el traspaso existe, es pendiente y fue generado por la misma sucursal
$stmt = $conn->prepare("
    SELECT id_sucursal_origen, estatus 
    FROM traspasos 
    WHERE id=? LIMIT 1
");
$stmt->bind_param("i", $idTraspaso);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("El traspaso no existe.");
}

$data = $result->fetch_assoc();
$stmt->close();

if ($data['estatus'] !== 'Pendiente') {
    die("Solo se pueden eliminar traspasos en estatus 'Pendiente'.");
}

if ($data['id_sucursal_origen'] != $idSucursal && !in_array($rol, ['Admin'])) {
    die("No tienes permiso para eliminar este traspaso.");
}

// 🔹 Obtener los equipos del traspaso
$idsInventario = [];
$result = $conn->query("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=$idTraspaso");
while ($row = $result->fetch_assoc()) {
    $idsInventario[] = $row['id_inventario'];
}

// 🔹 Volver a poner los equipos como 'Disponible'
if (count($idsInventario) > 0) {
    $ids = implode(',', array_map('intval', $idsInventario));
    $conn->query("UPDATE inventario SET estatus='Disponible' WHERE id IN ($ids)");
}

// 🔹 Eliminar detalle y traspaso
$conn->query("DELETE FROM detalle_traspaso WHERE id_traspaso=$idTraspaso");
$conn->query("DELETE FROM traspasos WHERE id=$idTraspaso");

header("Location: traspasos_salientes.php?msg=eliminado");
exit();
