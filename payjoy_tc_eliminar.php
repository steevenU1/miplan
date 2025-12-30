<?php
// payjoy_tc_eliminar.php — Eliminar registro de ventas_payjoy_tc (solo Admin)

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin','SuperAdmin','RH'], true)) {
    header("Location: 403.php");
    exit();
}

// Solo aceptamos POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: historial_payjoy_tc.php");
    exit();
}

require_once __DIR__ . '/db.php';

// Opcional: activar errores estrictos de MySQLi en este script
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header("Location: historial_payjoy_tc.php?err=1");
    exit();
}

try {
    $stmt = $conn->prepare("DELETE FROM ventas_payjoy_tc WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $afectadas = $stmt->affected_rows ?? 0;
    $stmt->close();

    if ($afectadas > 0) {
        // Eliminado ok
        header("Location: historial_payjoy_tc.php?deleted=1");
    } else {
        // No existía o no se pudo borrar
        header("Location: historial_payjoy_tc.php?err=1");
    }
    exit();

} catch (Throwable $e) {
    // Si algo truena, lo mandamos con error genérico
    // (Si quieres loguear el error, aquí es buen lugar)
    header("Location: historial_payjoy_tc.php?err=1");
    exit();
}
