<?php
// procesar_retiro.php — Multi-sucursal (con control de concurrencia y mensajes claros)
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

// ====== Inputs ======
$items      = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
$idSucursal = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : 0;
$motivo     = trim($_POST['motivo']  ?? '');
$destino    = trim($_POST['destino'] ?? '');
$nota       = trim($_POST['nota']    ?? '');

// Sanitiza/normaliza items
$items = array_values(array_unique(array_map('intval', $items)));

// Validación básica
if ($idSucursal <= 0 || empty($items) || $motivo === '') {
    header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Datos incompletos.")); exit();
}

// ====== Validar y obtener nombre de la sucursal de trabajo ======
$stSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id=? LIMIT 1");
$stSuc->bind_param("i", $idSucursal);
$stSuc->execute();
$rsSuc = $stSuc->get_result();
if (!$rsSuc || $rsSuc->num_rows === 0) {
    $stSuc->close();
    header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Sucursal inválida.")); exit();
}
$rowSuc = $rsSuc->fetch_assoc();
$nombreSucursal = $rowSuc['nombre'] ?? ('Sucursal #'.$idSucursal);
$stSuc->close();

// ====== Generar folio ======
$folio = sprintf("RIT-%s-%d", date('Ymd-His'), $idUsuario);

$conn->begin_transaction();
try {
    // ====== Cabecera del retiro ======
    $stmt = $conn->prepare("
        INSERT INTO inventario_retiros (folio, id_usuario, id_sucursal, motivo, destino, nota)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param("siisss", $folio, $idUsuario, $idSucursal, $motivo, $destino, $nota);
    $stmt->execute();
    $retiroId = (int)$stmt->insert_id;
    $stmt->close();

    // ====== Preparar statements de trabajo ======
    // Bloquea la fila de inventario que vamos a tocar para evitar carreras
    $qCheck = $conn->prepare("
        SELECT inv.id            AS id_inventario,
               inv.id_sucursal   AS id_suc,
               inv.id_producto   AS id_prod,
               inv.estatus       AS est,
               p.imei1           AS imei
        FROM inventario inv
        INNER JOIN productos p ON p.id = inv.id_producto
        WHERE inv.id = ?
        FOR UPDATE
    ");

    // Actualiza solo si sigue en la misma sucursal y disponible
    $qUpdate = $conn->prepare("
        UPDATE inventario
        SET estatus = 'Retirado'
        WHERE id = ? AND id_sucursal = ? AND estatus = 'Disponible'
    ");

    $qDet = $conn->prepare("
        INSERT INTO inventario_retiros_detalle (retiro_id, id_inventario, id_producto, imei1)
        VALUES (?,?,?,?)
    ");

    foreach ($items as $invId) {
        // 1) Verificar estado actual (con lock)
        $qCheck->bind_param("i", $invId);
        $qCheck->execute();
        $res = $qCheck->get_result();
        if (!$res || $res->num_rows === 0) {
            throw new Exception("Inventario $invId no existe.");
        }
        $row = $res->fetch_assoc();

        if ((int)$row['id_suc'] !== $idSucursal) {
            throw new Exception("Inventario {$row['id_inventario']} no pertenece a la sucursal seleccionada ({$nombreSucursal}).");
        }
        if ($row['est'] !== 'Disponible') {
            throw new Exception("Inventario {$row['id_inventario']} no está Disponible (estatus actual: {$row['est']}).");
        }

        // 2) Actualizar con guardas (evita que otro proceso lo cambie entre tanto)
        $qUpdate->bind_param("ii", $invId, $idSucursal);
        $qUpdate->execute();
        if ($qUpdate->affected_rows !== 1) {
            throw new Exception("No se pudo actualizar inventario {$invId} (condiciones no válidas).");
        }

        // 3) Detalle
        $idProd = (int)$row['id_prod'];
        $imei1  = (string)($row['imei'] ?? '');
        $qDet->bind_param("iiis", $retiroId, $invId, $idProd, $imei1);
        $qDet->execute();
    }

    $qCheck->close();
    $qUpdate->close();
    $qDet->close();

    $conn->commit();
    header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=ok"); exit();

} catch (Throwable $e) {
    $conn->rollback();
    $err = urlencode($e->getMessage());
    header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail={$err}"); exit();
}
