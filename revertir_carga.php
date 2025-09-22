<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID de carga inválido.");
}

$idCarga = (int)$_GET['id'];

// 1️⃣ Obtener información de la carga
$stmt = $conn->prepare("SELECT * FROM cargas_masivas_ventas WHERE id=? LIMIT 1");
$stmt->bind_param("i", $idCarga);
$stmt->execute();
$carga = $stmt->get_result()->fetch_assoc();

if (!$carga) {
    die("Carga masiva no encontrada.");
}

if ($carga['estado'] == 'Revertida') {
    die("Esta carga ya fue revertida previamente.");
}

$conn->begin_transaction();

try {
    // 2️⃣ Buscar ventas insertadas en ese lote
    // Usamos id_carga_masiva en ventas
    $ventas = $conn->query("SELECT id FROM ventas WHERE id_carga_masiva=$idCarga");
    $ventasEliminadas = 0;
    $productosRestaurados = 0;

    while ($venta = $ventas->fetch_assoc()) {
        $idVenta = $venta['id'];

        // Obtener todos los productos de detalle_venta
        $detalles = $conn->query("SELECT id_producto, imei1 FROM detalle_venta WHERE id_venta=$idVenta");

        while ($detalle = $detalles->fetch_assoc()) {
            $idProducto = $detalle['id_producto'];
            $imei = $detalle['imei1'];

            // Restaurar inventario a Disponible
            $stmtInv = $conn->prepare("
                UPDATE inventario i
                INNER JOIN productos p ON p.id=i.id_producto
                SET i.estatus='Disponible'
                WHERE (p.imei1=? OR p.imei2=?)
                  AND i.estatus='Vendido'
                LIMIT 1
            ");
            $stmtInv->bind_param("ss", $imei, $imei);
            $stmtInv->execute();

            if ($stmtInv->affected_rows > 0) {
                $productosRestaurados++;
            }
        }

        // 3️⃣ Eliminar detalle_venta y venta
        $conn->query("DELETE FROM detalle_venta WHERE id_venta=$idVenta");
        $conn->query("DELETE FROM ventas WHERE id=$idVenta");

        $ventasEliminadas++;
    }

    // 4️⃣ Actualizar estado de la carga
    $stmt = $conn->prepare("
        UPDATE cargas_masivas_ventas 
        SET estado='Revertida', 
            observaciones=CONCAT(IFNULL(observaciones,''), '; Revertida el ', NOW()) 
        WHERE id=?
    ");
    $stmt->bind_param("i", $idCarga);
    $stmt->execute();

    $conn->commit();

    echo "<div style='padding:20px; font-family:Arial;'>
            <h2>✅ Carga #$idCarga revertida con éxito</h2>
            <p>Ventas eliminadas: <b>$ventasEliminadas</b></p>
            <p>Productos restaurados a inventario: <b>$productosRestaurados</b></p>
            <a href='carga_masiva_ventas.php'>← Volver a Cargas Masivas</a>
          </div>";

} catch (Exception $e) {
    $conn->rollback();
    die("Error revirtiendo carga: " . $e->getMessage());
}
?>
