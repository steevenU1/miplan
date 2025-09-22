<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'] ?? null;

if (!$idUsuario) {
    header("Location: index.php");
    exit();
}

$stmt = $conn->prepare("SELECT activo FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$stmt->bind_result($activo);
$stmt->fetch();
$stmt->close();

// Si el usuario está dado de baja (activo = 0), destruir sesión y redirigir
if ((int)$activo !== 1) {
    session_destroy();
    header("Location: index.php?error=baja");
    exit();
}
?>
