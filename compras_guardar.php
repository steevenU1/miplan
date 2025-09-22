<?php
// compras_guardar.php
// Guarda encabezado y renglones por MODELO del catálogo + otros cargos + pago contado opcional
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
include 'db.php';

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

// ---------- Encabezado (POST) ----------
$id_proveedor      = (int)($_POST['id_proveedor'] ?? 0);
$num_factura       = trim($_POST['num_factura'] ?? '');
$id_sucursal       = (int)($_POST['id_sucursal'] ?? 0);
$fecha_factura     = $_POST['fecha_factura'] ?? date('Y-m-d');
$fecha_venc        = $_POST['fecha_vencimiento'] ?? null;
$condicion_pago    = $_POST['condicion_pago'] ?? 'Contado'; // 'Contado' | 'Crédito'
$dias_vencimiento  = isset($_POST['dias_vencimiento']) && $_POST['dias_vencimiento'] !== ''
                     ? (int)$_POST['dias_vencimiento'] : null;
$notas             = trim($_POST['notas'] ?? '');

// ---------- Validaciones mínimas ----------
if ($id_proveedor<=0 || $num_factura==='' || $id_sucursal<=0) {
  die("Parámetros inválidos.");
}

// ---------- Utilidades fechas ----------
function is_valid_date($s){
  if (!$s) return false;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}
function add_days($base, $days){
  $d = DateTime::createFromFormat('Y-m-d', $base);
  if (!$d) return null;
  $d->modify('+' . (int)$days . ' days');
  return $d->format('Y-m-d');
}
function diff_days($from, $to){
  $df = DateTime::createFromFormat('Y-m-d', $from);
  $dt = DateTime::createFromFormat('Y-m-d', $to);
  if (!$df || !$dt) return null;
  return (int)$df->diff($dt)->format('%r%a');
}

// ---------- Lógica de vencimiento ----------
if ($condicion_pago === 'Contado') {
  if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');
  $fecha_venc = $fecha_factura;
  $dias_vencimiento = 0;
} else {
  if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');

  if ($dias_vencimiento !== null) {
    if ($dias_vencimiento < 0) $dias_vencimiento = 0;
    $fv = add_days($fecha_factura, $dias_vencimiento);
    $fecha_venc = $fv ?: null;
  } elseif ($fecha_venc && is_valid_date($fecha_venc)) {
    $d = diff_days($fecha_factura, $fecha_venc);
    if ($d !== null && $d >= 0) {
      $dias_vencimiento = $d;
    } else {
      $dias_vencimiento = 0;
      $fecha_venc = $fecha_factura;
    }
  } else {
    $dias_vencimiento = 0;
    $fecha_venc = $fecha_factura;
  }
}

// ---------- Detalle (indexados por fila) ----------
$id_modelo   = $_POST['id_modelo'] ?? [];         // [idx] => id
$color       = $_POST['color'] ?? [];             // [idx] => str
$ram         = $_POST['ram'] ?? [];               // [idx] => str
$capacidad   = $_POST['capacidad'] ?? [];         // [idx] => str
$cantidad    = $_POST['cantidad'] ?? [];          // [idx] => int
$precio      = $_POST['precio_unitario'] ?? [];   // [idx] => float
$iva_pct     = $_POST['iva_porcentaje'] ?? [];    // [idx] => float
$requiereMap = $_POST['requiere_imei'] ?? [];     // [idx] => "0" | "1"

if (empty($id_modelo)) { die("Debes incluir al menos un renglón."); }

// ---------- Otros cargos (NUEVO) ----------
$extra_desc = $_POST['extra_desc'] ?? [];                    // [i] => str
$extra_monto = $_POST['extra_monto'] ?? [];                  // [i] => float (base sin IVA)
$extra_iva_porcentaje = $_POST['extra_iva_porcentaje'] ?? [];// [i] => float

$subtotal = 0.0; $iva = 0.0; $total = 0.0;
$rows = [];

// Construcción de renglones (modelos) y cálculo de totales
foreach ($id_modelo as $idx => $idmRaw) {
  $idm = (int)$idmRaw;
  if ($idm<=0) continue;

  // Snapshot del catálogo
  $st = $conn->prepare("SELECT marca, modelo, codigo_producto FROM catalogo_modelos WHERE id=? AND activo=1");
  $st->bind_param("i", $idm);
  $st->execute();
  $st->bind_result($marca, $modelo, $codigoCat);
  $ok = $st->fetch(); $st->close();
  if (!$ok) continue;

  $col = substr(trim($color[$idx] ?? ''), 0, 40);
  $ramv= substr(trim($ram[$idx] ?? ''),    0, 40);
  $cap = substr(trim($capacidad[$idx] ?? ''), 0, 40);
  $qty = max(0, (int)($cantidad[$idx] ?? 0));
  $pu  = max(0, (float)($precio[$idx] ?? 0));
  $ivp = max(0, (float)($iva_pct[$idx] ?? 0));
  $req = (int)($requiereMap[$idx] ?? 1); // default 1

  if ($marca==='' || $modelo==='' || $col==='' || $cap==='' || $qty<=0) continue;

  $rsub = $qty * $pu;
  $riva = $rsub * ($ivp/100.0);
  $rtot = $rsub + $riva;

  $subtotal += $rsub; $iva += $riva; $total += $rtot;

  $rows[] = [
    'id_modelo'=>$idm, 'marca'=>$marca, 'modelo'=>$modelo,
    'color'=>$col, 'ram'=>$ramv, 'capacidad'=>$cap,
    'cantidad'=>$qty, 'precio_unitario'=>$pu, 'iva_porcentaje'=>$ivp,
    'subtotal'=>$rsub, 'iva'=>$riva, 'total'=>$rtot,
    'requiere_imei'=>$req,
    'codigo_producto'=>$codigoCat
  ];
}

if (empty($rows)) { die("Debes incluir al menos un renglón válido."); }

// ====== Calcular extras y sumarlos a los totales (NUEVO) ======
$extraSub = 0.0; $extraIVA = 0.0;
if (!empty($extra_desc) && is_array($extra_desc)) {
  foreach ($extra_desc as $i => $descRaw) {
    $desc = trim((string)$descRaw);
    $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
    $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
    if ($desc === '' || $monto <= 0) { continue; }
    $extraSub += $monto;
    $extraIVA += $monto * ($ivaP/100.0);
  }
}
$subtotal += $extraSub;
$iva      += $extraIVA;
$total     = $subtotal + $iva; // robusto

// ---------- Transacción ----------
$conn->begin_transaction();
try {
  // Encabezado (estatus se fija a 'Pendiente')
  $sqlC = "INSERT INTO compras
            (num_factura, id_proveedor, id_sucursal, fecha_factura, fecha_vencimiento,
             condicion_pago, dias_vencimiento,
             subtotal, iva, total, estatus, notas, creado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,'Pendiente',?,?)";
  $stmtC = $conn->prepare($sqlC);
  if (!$stmtC) { throw new Exception("Prepare compras: ".$conn->error); }

  $stmtC->bind_param(
    'siisssidddsi',
    $num_factura,       // s
    $id_proveedor,      // i
    $id_sucursal,       // i
    $fecha_factura,     // s
    $fecha_venc,        // s (NULL permitido)
    $condicion_pago,    // s
    $dias_vencimiento,  // i (o NULL)
    $subtotal,          // d
    $iva,               // d
    $total,             // d
    $notas,             // s
    $ID_USUARIO         // i
  );

  if (!$stmtC->execute()) { throw new Exception("Insert compras: ".$stmtC->error); }
  $id_compra = $stmtC->insert_id;
  $stmtC->close();

  // Detalle (incluye RAM)
  $sqlD = "INSERT INTO compras_detalle
            (id_compra, id_modelo, marca, modelo, color, ram, capacidad, requiere_imei, descripcion,
             cantidad, precio_unitario, iva_porcentaje, subtotal, iva, total)
           VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)";
  $stmtD = $conn->prepare($sqlD);
  if (!$stmtD) { throw new Exception("Prepare detalle: ".$conn->error); }

  $stmtD_types = 'iisssssiiddddd';
  foreach ($rows as $r) {
    $stmtD->bind_param(
      $stmtD_types,
      $id_compra,                  // i
      $r['id_modelo'],             // i
      $r['marca'],                 // s
      $r['modelo'],                // s
      $r['color'],                 // s
      $r['ram'],                   // s
      $r['capacidad'],             // s
      $r['requiere_imei'],         // i
      $r['cantidad'],              // i
      $r['precio_unitario'],       // d
      $r['iva_porcentaje'],        // d
      $r['subtotal'],              // d
      $r['iva'],                   // d
      $r['total']                  // d
    );
    if (!$stmtD->execute()) { throw new Exception("Insert detalle: ".$stmtD->error); }
  }
  $stmtD->close();

  // ====== Guardar otros cargos (NUEVO) ======
  if (!empty($extra_desc) && is_array($extra_desc)) {
    $sqlX = "INSERT INTO compras_cargos
              (id_compra, descripcion, monto, iva_porcentaje, iva_monto, total, afecta_costo)
             VALUES (?,?,?,?,?,?,?)";
    $stmtX = $conn->prepare($sqlX);
    if (!$stmtX) { throw new Exception("Prepare cargos: ".$conn->error); }

    foreach ($extra_desc as $i => $descRaw) {
      $desc  = trim((string)$descRaw);
      $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
      $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
      if ($desc === '' || $monto <= 0) { continue; }

      $ivaMonto = round($monto * ($ivaP/100.0), 2);
      $totCargo = round($monto + $ivaMonto, 2);
      $afecta   = 0; // si luego prorrateas al costo, pon 1

      $stmtX->bind_param("isddddi",
        $id_compra, $desc, $monto, $ivaP, $ivaMonto, $totCargo, $afecta
      );
      if (!$stmtX->execute()) { throw new Exception("Insert cargos: ".$stmtX->error); }
    }
    $stmtX->close();
  }

  // ---------- Pago contado opcional ----------
  $registrarPago = ($_POST['registrar_pago'] ?? '0') === '1';
  if ($registrarPago && $condicion_pago === 'Contado') {
    $pago_monto  = isset($_POST['pago_monto']) ? (float)$_POST['pago_monto'] : 0.0;
    $pago_metodo = substr(trim($_POST['pago_metodo'] ?? ''), 0, 40);
    $pago_ref    = substr(trim($_POST['pago_referencia'] ?? ''), 0, 120);
    $pago_fecha  = $_POST['pago_fecha'] ?? date('Y-m-d');
    $pago_notas  = substr(trim($_POST['pago_nota'] ?? ''), 0, 1000);

    if ($pago_monto < 0) $pago_monto = 0.0;

    $sqlP = "INSERT INTO compras_pagos
             (id_compra, fecha_pago, monto, metodo_pago, referencia, notas)
             VALUES (?,?,?,?,?,?)";
    $stP = $conn->prepare($sqlP);
    if (!$stP) { throw new Exception('Prepare pago: '.$conn->error); }
    $stP->bind_param("isdsss",
      $id_compra, $pago_fecha, $pago_monto, $pago_metodo, $pago_ref, $pago_notas
    );
    if (!$stP->execute()) { throw new Exception('Insert pago: '.$stP->error); }
    $stP->close();

    // Marcar compra como Pagada si el pago cubre el total (incluye cargos)
    if ($pago_monto >= $total) {
      $conn->query("UPDATE compras SET estatus='Pagada' WHERE id=".$id_compra);
    }
  }

  $conn->commit();
  header("Location: compras_ver.php?id=".$id_compra);
  exit();

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar la compra: ".$e->getMessage();
}
