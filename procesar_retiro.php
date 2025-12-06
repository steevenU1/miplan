<?php
// procesar_retiro.php — v2
// - Soporta piezas seriadas (items[]) y accesorios sin serie por cantidad (cant[] / qty[] / acc_cant[])
// - Actualiza inventario.cantidad y estatus
// - Detalle compatible con inventario_retiros_detalle con o sin columna "cantidad"

require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

/* =======================
   Inputs (seriados + sin serie)
   ======================= */

// Piezas seriadas (equipos / módem / accesorios con IMEI) por ID de inventario
$itemsSerie = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];
$itemsSerie = array_values(array_unique(array_map('intval', $itemsSerie)));

// Accesorios sin serie por cantidad (id_inventario => cantidad a retirar)
// Soporta name="acc_cant[ID_INV]" (vista v2), name="cant[ID_INV]" o name="qty[ID_INV]"
$rawCant = [];

// 🔹 PRIORIDAD: acc_cant (inventario_retiros_v2)
if (isset($_POST['acc_cant']) && is_array($_POST['acc_cant'])) {
    $rawCant = $_POST['acc_cant'];
} elseif (isset($_POST['cant']) && is_array($_POST['cant'])) {
    $rawCant = $_POST['cant'];
} elseif (isset($_POST['qty']) && is_array($_POST['qty'])) {
    $rawCant = $_POST['qty'];
}

$itemsCant = []; // id_inventario => cantidad (>0)
foreach ($rawCant as $invId => $val) {
    $invId = (int)$invId;
    $c     = (int)$val;
    if ($invId > 0 && $c > 0) {
        $itemsCant[$invId] = $c;
    }
}

// Si alguna pieza aparece en ambos (por error), le damos prioridad al modo cantidad
if (!empty($itemsCant) && !empty($itemsSerie)) {
    $itemsSerie = array_values(array_filter($itemsSerie, function($id) use ($itemsCant) {
        return !isset($itemsCant[$id]);
    }));
}

$idSucursal = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : 0;
$motivo     = trim($_POST['motivo']  ?? '');
$destino    = trim($_POST['destino'] ?? '');
$nota       = trim($_POST['nota']    ?? '');

/* =======================
   Validación básica
   ======================= */
if ($idSucursal <= 0 || $motivo === '' || (empty($itemsSerie) && empty($itemsCant))) {
    header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Datos incompletos o sin piezas seleccionadas.")); 
    exit();
}

/* =======================
   Validar sucursal
   ======================= */
$stSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id=? LIMIT 1");
$stSuc->bind_param("i", $idSucursal);
$stSuc->execute();
$rsSuc = $stSuc->get_result();
if (!$rsSuc || $rsSuc->num_rows === 0) {
    $stSuc->close();
    header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Sucursal inválida.")); 
    exit();
}
$rowSuc = $rsSuc->fetch_assoc();
$nombreSucursal = $rowSuc['nombre'] ?? ('Sucursal #'.$idSucursal);
$stSuc->close();

/* =======================
   Detectar si detalle tiene columna 'cantidad'
   ======================= */
$detHasCantidad = false;
if ($resCol = $conn->query("SHOW COLUMNS FROM inventario_retiros_detalle LIKE 'cantidad'")) {
    if ($resCol->num_rows > 0) {
        $detHasCantidad = true;
    }
    $resCol->close();
}

/* =======================
   Folio y transacción
   ======================= */
$folio = sprintf("RIT-%s-%d", date('Ymd-His'), $idUsuario);
$conn->begin_transaction();

try {
    // Cabecera
    $stmt = $conn->prepare("
        INSERT INTO inventario_retiros (folio, id_usuario, id_sucursal, motivo, destino, nota)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param("siisss", $folio, $idUsuario, $idSucursal, $motivo, $destino, $nota);
    $stmt->execute();
    $retiroId = (int)$stmt->insert_id;
    $stmt->close();

    // Consulta de inventario con lock
    $qCheck = $conn->prepare("
        SELECT inv.id            AS id_inventario,
               inv.id_sucursal   AS id_suc,
               inv.id_producto   AS id_prod,
               inv.estatus       AS est,
               inv.cantidad      AS cant,
               p.imei1           AS imei
        FROM inventario inv
        INNER JOIN productos p ON p.id = inv.id_producto
        WHERE inv.id = ?
        FOR UPDATE
    ");

    // Update general (cantidad + estatus)
    $qUpdate = $conn->prepare("
        UPDATE inventario
        SET cantidad = ?, estatus = ?
        WHERE id = ? AND id_sucursal = ? AND estatus = 'Disponible'
    ");

    // Detalle (con o sin columna cantidad)
    if ($detHasCantidad) {
        $qDet = $conn->prepare("
            INSERT INTO inventario_retiros_detalle (retiro_id, id_inventario, id_producto, imei1, cantidad)
            VALUES (?,?,?,?,?)
        ");
    } else {
        $qDet = $conn->prepare("
            INSERT INTO inventario_retiros_detalle (retiro_id, id_inventario, id_producto, imei1)
            VALUES (?,?,?,?)
        ");
    }

    /* --------- Helper para procesar UNA línea (seriadas y sin serie) --------- */
    $procesarLinea = function(int $invId, int $cantRetiro) use (
        $conn, $idSucursal, $nombreSucursal, $retiroId,
        $qCheck, $qUpdate, $qDet, $detHasCantidad
    ) {
        // 1) Leer inventario con FOR UPDATE
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

        $stockActual = (int)$row['cant'];
        if ($cantRetiro <= 0) {
            throw new Exception("Cantidad inválida para inventario {$row['id_inventario']}.");
        }
        if ($stockActual < $cantRetiro) {
            throw new Exception("Inventario {$row['id_inventario']} no tiene suficientes piezas (stock actual: {$stockActual}, solicitado: {$cantRetiro}).");
        }

        // 2) Calcular nueva cantidad y estatus
        $nuevoStock = $stockActual - $cantRetiro;
        $nuevoStatus = ($nuevoStock > 0) ? 'Disponible' : 'Retirado';

        // 3) Actualizar inventario
        $qUpdate->bind_param("isii", $nuevoStock, $nuevoStatus, $invId, $idSucursal);
        $qUpdate->execute();
        if ($qUpdate->affected_rows !== 1) {
            throw new Exception("No se pudo actualizar inventario {$invId} (condiciones no válidas).");
        }

        // 4) Registrar detalle
        $idProd = (int)$row['id_prod'];
        $imei1  = (string)($row['imei'] ?? '');

        if ($detHasCantidad) {
            // Un renglón con la cantidad total
            $qDet->bind_param("iiisi", $retiroId, $invId, $idProd, $imei1, $cantRetiro);
            $qDet->execute();
        } else {
            // Sin columna cantidad: repetimos renglón por pieza
            $qDet->bind_param("iiis", $retiroId, $invId, $idProd, $imei1);
            for ($i = 0; $i < $cantRetiro; $i++) {
                $qDet->execute();
            }
        }
    };

    /* =======================
       1) Procesar piezas seriadas (siempre cantidad = 1)
       ======================= */
    foreach ($itemsSerie as $invId) {
        $invId = (int)$invId;
        if ($invId <= 0) continue;
        $procesarLinea($invId, 1);
    }

    /* =======================
       2) Procesar accesorios sin serie (por cantidad)
       ======================= */
    foreach ($itemsCant as $invId => $cantRetiro) {
        $invId      = (int)$invId;
        $cantRetiro = (int)$cantRetiro;
        if ($invId <= 0 || $cantRetiro <= 0) continue;
        $procesarLinea($invId, $cantRetiro);
    }

    $qCheck->close();
    $qUpdate->close();
    $qDet->close();

    $conn->commit();
    header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=ok");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    $err = urlencode($e->getMessage());
    header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail={$err}");
    exit();
}
