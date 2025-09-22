<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$idVenta    = (int)($_POST['id_venta'] ?? 0);
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';

if ($idVenta <= 0) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Venta inválida"));
    exit();
}

/** Calcula inicio/fin de la semana actual (martes-lunes) */
function obtenerSemanaActual() : array {
    $hoy = new DateTime();
    $n   = (int)$hoy->format('N'); // 1=lun..7=dom
    $dif = $n - 2;                 // martes=2
    if ($dif < 0) $dif += 7;
    $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
    $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
    return [$inicio, $fin];
}

/* 1) Cargar venta SIM */
$st = $conn->prepare("SELECT id, id_usuario, fecha_venta FROM ventas_sims WHERE id=? LIMIT 1");
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Venta de SIM no encontrada"));
    exit();
}

/* 2) Permisos
   - Admin: puede eliminar cualquier venta (sin restricción de semana ni dueño)
   - Otros roles: solo su propia venta y además debe ser de la semana actual
*/
$esAdmin = ($rolUsuario === 'Admin');
$puedeEliminar = false;

if ($esAdmin) {
    $puedeEliminar = true; // sin más validaciones
} else {
    // Dueño de la venta
    if ((int)$venta['id_usuario'] === $idUsuario) {
        // Además: la venta debe pertenecer a la semana actual
        list($inicioSemana, $finSemana) = obtenerSemanaActual();
        $fechaVenta = new DateTime($venta['fecha_venta']);
        if ($fechaVenta >= $inicioSemana && $fechaVenta <= $finSemana) {
            $puedeEliminar = true;
        }
    }
}

if (!$puedeEliminar) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ No tienes permiso para eliminar esta venta"));
    exit();
}

/* 3) Operación: devolver SIMs (si los hay) y borrar venta */
$conn->begin_transaction();

try {
    // Devolver SIMs al inventario
    $st = $conn->prepare("SELECT id_sim FROM detalle_venta_sims WHERE id_venta=?");
    $st->bind_param("i", $idVenta);
    $st->execute();
    $res = $st->get_result();

    $upd = $conn->prepare("UPDATE inventario_sims SET estatus='Disponible' WHERE id=?");
    while ($row = $res->fetch_assoc()) {
        $idSim = (int)$row['id_sim'];
        if ($idSim > 0) {
            $upd->bind_param("i", $idSim);
            $upd->execute();
        }
    }
    $upd->close();
    $st->close();

    // Borrar detalle
    $delDet = $conn->prepare("DELETE FROM detalle_venta_sims WHERE id_venta=?");
    $delDet->bind_param("i", $idVenta);
    $delDet->execute();
    $delDet->close();

    // Borrar venta
    $delVen = $conn->prepare("DELETE FROM ventas_sims WHERE id=?");
    $delVen->bind_param("i", $idVenta);
    $delVen->execute();
    $delVen->close();

    $conn->commit();

    header("Location: historial_ventas_sims.php?msg=" . urlencode("✅ Venta de SIM eliminada correctamente"));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    // Opcional: error_log($e->getMessage());
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Ocurrió un error al eliminar la venta"));
    exit();
}
