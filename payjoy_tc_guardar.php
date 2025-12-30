<?php
// payjoy_tc_guardar.php — Guardar venta PayJoy TC + descuento de inventario
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_features.php';

// Modo excepciones para mysqli (opcional pero práctico)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Bandera efectiva (por si cierras el módulo)
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

try {
    $conn->begin_transaction();

    /* ==========================================================
       1) Validar inventario de TC para la sucursal
       ========================================================== */
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN tipo = 'INGRESO' THEN cantidad ELSE -cantidad END
        ),0) AS saldo
        FROM payjoy_tc_kardex
        WHERE id_sucursal = ?
    ");
    $stmt->bind_param("i", $idSucursal);
    $stmt->execute();
    $stmt->bind_result($saldoActual);
    $stmt->fetch();
    $stmt->close();

    if ($saldoActual <= 0) {
        $conn->rollback();
        header("Location: payjoy_tc_nueva.php?err=" . urlencode("❌ La sucursal no tiene tarjetas PayJoy disponibles en inventario."));
        exit();
    }

    /* ==========================================================
       2) Guardar la venta
       ========================================================== */
    $stmt = $conn->prepare("
        INSERT INTO ventas_payjoy_tc
        (id_usuario, id_sucursal, nombre_cliente, tag, comision, comentarios, fecha_venta)
        VALUES (?,?,?,?,?,?,NOW())
    ");
    $stmt->bind_param("iissds",
        $idUsuario,
        $idSucursal,
        $nombreCliente,
        $tag,
        $comision,
        $comentarios
    );
    $stmt->execute();
    $idVenta = $conn->insert_id;
    $stmt->close();

    /* ==========================================================
       3) Registrar SALIDA en kardex (1 tarjeta)
       ========================================================== */
    $sqlMov = "
        INSERT INTO payjoy_tc_kardex
        (id_sucursal, tipo, concepto, cantidad, id_usuario, comentario)
        VALUES (?,?,?,?,?,?)
    ";
    $stmt = $conn->prepare($sqlMov);
    $tipo        = 'SALIDA';
    $concepto    = 'VENTA';
    $cantidad    = 1;
    $comentMov   = "Entrega TC PayJoy (venta ID {$idVenta}, TAG {$tag})";
    $stmt->bind_param("issiis",
        $idSucursal,
        $tipo,
        $concepto,
        $cantidad,
        $idUsuario,
        $comentMov
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // 👇 En vez de ir al historial, regresamos a la captura con ok=1 para mostrar el modal
    header("Location: payjoy_tc_nueva.php?ok=1&msg=" . urlencode("✅ Venta registrada correctamente"));
    exit();
} catch (Throwable $e) {
    // Si algo truena, revertimos
    try { $conn->rollback(); } catch (Throwable $e2) {}
    header("Location: payjoy_tc_nueva.php?err=" . urlencode("Error al guardar la venta: " . $e->getMessage()));
    exit();
}
