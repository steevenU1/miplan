<?php
include 'db.php';
header('Content-Type: text/html; charset=utf-8');

$id_sucursal = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : 0;
if ($id_sucursal <= 0) {
  echo '<option value="">Sucursal inválida</option>';
  exit;
}

// Helper para detectar columnas y evitar SQL que truene en blanco
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}

$has_i_imei1 = hasColumn($conn,'inventario','imei1');
$has_i_imei2 = hasColumn($conn,'inventario','imei2');
$has_p_imei1 = hasColumn($conn,'productos','imei1');
$has_p_imei2 = hasColumn($conn,'productos','imei2');

// Expresiones seguras para seleccionar IMEIs de donde existan
$sel_imei1 = $has_i_imei1 ? 'i.imei1' : ($has_p_imei1 ? 'p.imei1' : "''");
$sel_imei2 = $has_i_imei2 ? 'i.imei2' : ($has_p_imei2 ? 'p.imei2' : "''");

$sql = "
  SELECT 
    i.id AS id_inventario,
    p.marca, p.modelo, p.color,
    $sel_imei1 AS imei1,
    $sel_imei2 AS imei2,
    p.precio_lista,
    LOWER(p.tipo_producto) AS tipo
  FROM inventario i
  INNER JOIN productos p ON i.id_producto = p.id
  WHERE i.id_sucursal = ?
    AND i.estatus = 'Disponible'
  ORDER BY p.marca, p.modelo, i.id
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo '<option value="">Error SQL: '.htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8').'</option>';
  exit;
}

$stmt->bind_param("i", $id_sucursal);
if (!$stmt->execute()) {
  echo '<option value="">Error ejecutando: '.htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8').'</option>';
  exit;
}

$res = $stmt->get_result();

$options = '<option value="">Seleccione un equipo...</option>';

if ($res && $res->num_rows > 0) {
  while ($row = $res->fetch_assoc()) {
    $idInv  = (int)$row['id_inventario'];
    $tipo   = strtoupper($row['tipo'] ?? '');
    $marca  = (string)($row['marca'] ?? '');
    $modelo = (string)($row['modelo'] ?? '');
    $color  = (string)($row['color'] ?? '');
    $imei1  = trim((string)($row['imei1'] ?? ''));
    $imei2  = trim((string)($row['imei2'] ?? ''));
    $precio = (float)($row['precio_lista'] ?? 0);

    // Texto visible (incluye IMEI1 e IMEI2)
    $imeiTxt = $imei1 !== '' ? "IMEI1: $imei1" : "IMEI1: —";
    if ($imei2 !== '') $imeiTxt .= " | IMEI2: $imei2";

    $texto = "{$tipo} | {$marca} {$modelo} ({$color}) — {$imeiTxt} - $" . number_format($precio, 2);

    $options .= '<option value="'.$idInv.'">'.htmlspecialchars($texto, ENT_QUOTES, 'UTF-8').'</option>';
  }
} else {
  $options .= '<option value="">Sin inventario disponible en esta sucursal</option>';
}

echo $options;
