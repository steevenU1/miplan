<?php
// cancelar_recarga.php — Cancela recarga y elimina su cobro SOLO si no está ligado a un corte

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$id_usuario_admin = (int)($_SESSION['id_usuario'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: historial_recargas.php?err=" . urlencode('Método no permitido.'));
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: historial_recargas.php?err=" . urlencode('ID de recarga inválido.'));
    exit();
}

try {
    $conn->begin_transaction();

    // Traer recarga
    $stmt = $conn->prepare("
        SELECT id, id_cobro, estatus
        FROM recargas_tiempo_aire
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $recarga = $res->fetch_assoc();
    $stmt->close();

    if (!$recarga) {
        throw new Exception('Recarga no encontrada.');
    }
    if ($recarga['estatus'] === 'Cancelada') {
        throw new Exception('La recarga ya estaba cancelada.');
    }

    $id_cobro = $recarga['id_cobro'] ? (int)$recarga['id_cobro'] : null;

    // ==============================
    // Validar que el cobro NO esté ligado a un corte
    // ==============================
    if ($id_cobro) {
        $stmt = $conn->prepare("SELECT id_corte FROM cobros WHERE id = ?");
        $stmt->bind_param('i', $id_cobro);
        $stmt->execute();
        $stmt->bind_result($id_corte);
        $stmt->fetch();
        $stmt->close();

        if (!is_null($id_corte)) {
            // Tiene corte → NO PUEDE CANCELARSE
            $conn->rollback();
            header("Location: historial_recargas.php?err=" . urlencode('No se puede cancelar esta recarga porque su cobro ya está incluido en un corte.'));
            exit();
        }
    }

    // ==============================
    // Borrar cobro (solo si no está en corte)
    // ==============================
    if ($id_cobro) {
        $stmt = $conn->prepare("DELETE FROM cobros WHERE id = ?");
        $stmt->bind_param('i', $id_cobro);
        $stmt->execute();
        $stmt->close();
    }

    // ==============================
    // Marcar recarga como cancelada
    // ==============================
    $stmt = $conn->prepare("
        UPDATE recargas_tiempo_aire
        SET estatus = 'Cancelada',
            fecha_cancelacion = NOW(),
            id_usuario_cancela = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ii', $id_usuario_admin, $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    header("Location: historial_recargas.php?msg=" . urlencode('Recarga cancelada correctamente.'));
    exit();

} catch (Exception $e) {
    if ($conn && $conn->errno) {
        $conn->rollback();
    }
    header("Location: historial_recargas.php?err=" . urlencode('Error al cancelar: '.$e->getMessage()));
    exit();
}
