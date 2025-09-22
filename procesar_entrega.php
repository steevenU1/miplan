<?php
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'GerenteZona') {
    echo json_encode(['status'=>'error','msg'=>'Acceso no autorizado']);
    exit;
}

include 'db.php';

$idGerente = $_SESSION['id_usuario'];
$idSucursal = intval($_POST['id_sucursal'] ?? 0);
$monto = floatval($_POST['monto_entregado'] ?? 0);
$observaciones = trim($_POST['observaciones'] ?? '');

if ($idSucursal <= 0 || $monto <= 0) {
    echo json_encode(['status'=>'error','msg'=>'Debes seleccionar una sucursal y un monto válido.']);
    exit;
}

// 🔹 Validar saldo pendiente actual
$sqlSaldo = "
    SELECT 
        IFNULL(SUM(c.comision_especial),0) - IFNULL((
            SELECT SUM(monto_entregado) 
            FROM entregas_comisiones_especiales e 
            WHERE e.id_sucursal = c.id_sucursal
        ),0) AS saldo_pendiente
    FROM cobros c
    WHERE c.comision_especial > 0 AND c.id_sucursal = ?
";
$stmt = $conn->prepare($sqlSaldo);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$saldoPendiente = floatval($stmt->get_result()->fetch_assoc()['saldo_pendiente'] ?? 0);
$stmt->close();

if ($monto > $saldoPendiente) {
    echo json_encode([
        'status'=>'error',
        'msg'=>'El monto excede el saldo pendiente. Saldo actual: $'.number_format($saldoPendiente,2)
    ]);
    exit;
}

// 🔹 Insertar entrega
$sqlInsert = "
    INSERT INTO entregas_comisiones_especiales (id_sucursal, id_gerentezona, monto_entregado, fecha_entrega, observaciones)
    VALUES (?, ?, ?, NOW(), ?)
";
$stmtIns = $conn->prepare($sqlInsert);
$stmtIns->bind_param("iids", $idSucursal, $idGerente, $monto, $observaciones);
$stmtIns->execute();
$stmtIns->close();

// 🔹 Después de guardar, regeneramos el HTML de tablas y formulario
ob_start();
include __DIR__.'/recoleccion_render.php';
$htmlTablas = ob_get_clean();

echo json_encode([
    'status'=>'ok',
    'msg'=>'✅ Entrega registrada correctamente.',
    'html'=>$htmlTablas
]);
