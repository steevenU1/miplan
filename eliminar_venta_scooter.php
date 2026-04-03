<?php
// eliminar_venta_scooter.php — Elimina venta scooter y regresa inventario a Disponible (solo Admin)
session_start();
if (!isset($_SESSION['id_usuario']) || (($_SESSION['rol'] ?? '') !== 'Admin')) {
  header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idVenta = (int)($_POST['id_venta'] ?? 0);
if ($idVenta <= 0) {
  header("Location: historial_ventas_scooters.php?err=" . urlencode("ID de venta inválido"));
  exit();
}

try {
  $conn->begin_transaction();

  // 1) Obtener la venta (para saber sucursal)
  $stV = $conn->prepare("SELECT id, id_sucursal FROM ventas_scooter WHERE id=? LIMIT 1");
  $stV->bind_param("i", $idVenta);
  $stV->execute();
  $venta = $stV->get_result()->fetch_assoc();
  $stV->close();

  if (!$venta) {
    $conn->rollback();
    header("Location: historial_ventas_scooters.php?err=" . urlencode("La venta no existe"));
    exit();
  }

  $idSucursalVenta = (int)$venta['id_sucursal'];

  // 2) Obtener productos ligados a la venta
  $stD = $conn->prepare("SELECT id_producto, imei1 FROM detalle_venta_scooter WHERE id_venta=?");
  $stD->bind_param("i", $idVenta);
  $stD->execute();
  $rsD = $stD->get_result();

  $items = [];
  while ($r = $rsD->fetch_assoc()) {
    $items[] = $r;
  }
  $stD->close();

  // 3) Regresar inventario a Disponible
  //    Priorizamos por IMEI si viene, para no tocar otros registros
  $updByImei = $conn->prepare("
    UPDATE inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    SET i.estatus='Disponible', i.id_sucursal=?
    WHERE p.imei1=? AND i.estatus <> 'Disponible'
    LIMIT 1
  ");

  $updByProd = $conn->prepare("
    UPDATE inventario
    SET estatus='Disponible', id_sucursal=?
    WHERE id_producto=? AND estatus <> 'Disponible'
    ORDER BY id DESC
    LIMIT 1
  ");

  foreach ($items as $it) {
    $idProd = (int)($it['id_producto'] ?? 0);
    $imei1  = trim((string)($it['imei1'] ?? ''));

    if ($imei1 !== '') {
      $updByImei->bind_param("is", $idSucursalVenta, $imei1);
      $updByImei->execute();
      if ($updByImei->affected_rows > 0) continue;
      // si no afectó nada, cae al fallback por id_producto
    }

    if ($idProd > 0) {
      $updByProd->bind_param("ii", $idSucursalVenta, $idProd);
      $updByProd->execute();
    }
  }

  $updByImei->close();
  $updByProd->close();

  // 4) Borrar detalle
  $delD = $conn->prepare("DELETE FROM detalle_venta_scooter WHERE id_venta=?");
  $delD->bind_param("i", $idVenta);
  $delD->execute();
  $delD->close();

  // 5) Borrar venta
  $delV = $conn->prepare("DELETE FROM ventas_scooter WHERE id=? LIMIT 1");
  $delV->bind_param("i", $idVenta);
  $delV->execute();
  $delV->close();

  $conn->commit();
  header("Location: historial_ventas_scooters.php?ok=" . urlencode("Venta eliminada y scooter(s) regresado(s) a inventario Disponible"));
  exit();

} catch (Throwable $e) {
  $conn->rollback();
  header("Location: historial_ventas_scooters.php?err=" . urlencode("Error al eliminar: " . $e->getMessage()));
  exit();
}
