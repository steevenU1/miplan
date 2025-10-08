<?php
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__.'/db.php';

$id_usuario  = (int)($_POST['id_usuario'] ?? $_SESSION['id_usuario']);
$id_sucursal = (int)($_POST['id_sucursal'] ?? $_SESSION['id_sucursal']);
$nombre      = trim($_POST['nombre_cliente'] ?? '');
$tel         = trim($_POST['telefono_cliente'] ?? '');
$forma       = $_POST['forma_pago'] ?? 'Efectivo';
$pagoEf      = (float)($_POST['pago_efectivo'] ?? 0);
$pagoTj      = (float)($_POST['pago_tarjeta'] ?? 0);
$totalForm   = (float)($_POST['total'] ?? 0);
$coment      = trim($_POST['comentarios'] ?? '');

$ids   = $_POST['id_producto']    ?? [];
$cants = $_POST['cantidad']        ?? [];
$prices= $_POST['precio_unitario'] ?? [];

$err = [];

// Validaciones básicas
if ($id_sucursal<=0) $err[]='Sucursal inválida.';
if (!in_array($forma, ['Efectivo','Tarjeta','Mixto'], true)) $err[]='Forma de pago inválida.';
if (empty($ids)) $err[]='Agrega al menos un accesorio.';
for($i=0; $i<count($ids); $i++){
  if ((int)$ids[$i] <= 0) $err[]='Ítem inválido.';
  if ((int)$cants[$i] <= 0) $err[]='Cantidad inválida.';
  if ((float)$prices[$i] < 0) $err[]='Precio inválido.';
}
if ($forma==='Mixto' && round($pagoEf+$pagoTj,2) !== round($totalForm,2)) {
  $err[]='En pago Mixto, efectivo+tarjeta debe igualar al total.';
}
if ($forma!=='Mixto') {
  // Forzar consistencia por si mandan montos diferentes
  if ($forma==='Efectivo'){ $pagoEf=$totalForm; $pagoTj=0; }
  if ($forma==='Tarjeta'){  $pagoTj=$totalForm; $pagoEf=0; }
}

if ($err){
  header("Location: nueva_venta_accesorio.php?err=".urlencode(implode(' ', $err)));
  exit;
}

try{
  $conn->begin_transaction();

  // 1) Bloquear stock y validar cantidades (SELECT ... FOR UPDATE)
  $lineas = [];
  for($i=0; $i<count($ids); $i++){
    $idp  = (int)$ids[$i];
    $qty  = (int)$cants[$i];
    $pu   = (float)$prices[$i];

    // Además validamos que el producto sea Accesorio
    $stmtP = $conn->prepare("SELECT tipo_producto, precio_lista FROM productos WHERE id=? LIMIT 1");
    $stmtP->bind_param("i", $idp);
    $stmtP->execute();
    $rowP = $stmtP->get_result()->fetch_assoc();
    $stmtP->close();
    if (!$rowP || $rowP['tipo_producto']!=='Accesorio') {
      throw new RuntimeException("Producto $idp no es accesorio.");
    }
    if ($pu<=0) $pu = (float)$rowP['precio_lista'];

    $stmt = $conn->prepare("SELECT id, cantidad FROM inventario WHERE id_producto=? AND id_sucursal=? AND estatus='Disponible' FOR UPDATE");
    $stmt->bind_param("ii", $idp, $id_sucursal);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stock = (int)($inv['cantidad'] ?? 0);
    if ($stock < $qty) {
      throw new RuntimeException("Stock insuficiente para el producto $idp (disponible: $stock, solicitado: $qty).");
    }

    $lineas[] = ['idp'=>$idp, 'qty'=>$qty, 'pu'=>$pu, 'subtotal'=>round($qty*$pu,2), 'inv_id'=>(int)$inv['id']];
  }

  // 2) Calcular total real
  $total = array_sum(array_column($lineas,'subtotal'));
  if (round($total,2) !== round($totalForm,2)) {
    // alineamos a lo calculado en servidor
    $pagoEf = ($forma==='Efectivo'||$forma==='Mixto') ? min($pagoEf, $total) : 0;
    $pagoTj = ($forma==='Tarjeta' ||$forma==='Mixto') ? ($total - $pagoEf) : 0;
  }

  // 3) Insert header
  $stmtV = $conn->prepare("
    INSERT INTO ventas_accesorios
      (id_usuario, id_sucursal, nombre_cliente, telefono_cliente, forma_pago, pago_efectivo, pago_tarjeta, total, comentarios)
    VALUES (?,?,?,?,?,?,?,?,?)
  ");
  $stmtV->bind_param("iisssddds", $id_usuario, $id_sucursal, $nombre, $tel, $forma, $pagoEf, $pagoTj, $total, $coment);
  $stmtV->execute();
  $id_venta = (int)$stmtV->insert_id;
  $stmtV->close();

  // 4) Insert detalle + descontar stock
  $stmtD = $conn->prepare("
    INSERT INTO detalle_ventas_accesorios (id_venta_accesorio, id_producto, cantidad, precio_unitario, subtotal)
    VALUES (?,?,?,?,?)
  ");
  $stmtU = $conn->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?");

  foreach ($lineas as $ln){
    $stmtD->bind_param("iiidd", $id_venta, $ln['idp'], $ln['qty'], $ln['pu'], $ln['subtotal']);
    $stmtD->execute();

    $stmtU->bind_param("ii", $ln['qty'], $ln['inv_id']);
    $stmtU->execute();
  }
  $stmtD->close();
  $stmtU->close();

  $conn->commit();

  header("Location: historial_ventas_accesorios.php?msg=".urlencode("Venta de accesorios #$id_venta registrada. Total $".number_format($total,2)));
  exit;
}catch(Throwable $e){
  $conn->rollback();
  header("Location: nueva_venta_accesorio.php?err=".urlencode("Error: ".$e->getMessage()));
  exit;
}
