<?php
// ajax_productos_por_sucursal.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ⛔ Evitar caché agresivo (navegador/CDN)
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// --- Helpers ---
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  return $rs && $rs->num_rows > 0;
}
function qparam(string $k, $def=null) {
  return $_POST[$k] ?? $_GET[$k] ?? $def;
}

// --- Input ---
$id_sucursal = (int) qparam('id_sucursal', 0);
if ($id_sucursal <= 0) {
  echo '<option value="">Sucursal inválida</option>';
  exit;
}

// --- Columnas variables ---
$has_i_imei1 = hasColumn($conn,'inventario','imei1');
$has_i_imei2 = hasColumn($conn,'inventario','imei2');
$has_p_imei1 = hasColumn($conn,'productos','imei1');
$has_p_imei2 = hasColumn($conn,'productos','imei2');

$sel_imei1 = $has_i_imei1 ? 'i.imei1' : ($has_p_imei1 ? 'p.imei1' : "''");
$sel_imei2 = $has_i_imei2 ? 'i.imei2' : ($has_p_imei2 ? 'p.imei2' : "''");

$col_tipo   = hasColumn($conn,'productos','tipo_producto') ? 'tipo_producto'
           : (hasColumn($conn,'productos','tipo') ? 'tipo' : null);
$col_color  = hasColumn($conn,'productos','color') ? 'color' : null;
$col_precio = hasColumn($conn,'productos','precio_lista') ? 'precio_lista' : null;

// --- SELECT dinámico ---
$cols = [
  "i.id AS id_inventario",
  "p.marca",
  "p.modelo",
  $sel_imei1 . " AS imei1",
  $sel_imei2 . " AS imei2",
];
$cols[] = $col_color  ? "p.`$col_color` AS color"       : "'' AS color";
$cols[] = $col_precio ? "p.`$col_precio` AS precio_lista" : "0 AS precio_lista";
$cols[] = $col_tipo   ? "LOWER(p.`$col_tipo`) AS tipo"   : "'' AS tipo";

// ⚠️ Normalizamos estatus: TRIM + LOWER
$sql = "
  SELECT ".implode(", ", $cols)."
  FROM inventario i
  INNER JOIN productos p ON i.id_producto = p.id
  WHERE i.id_sucursal = ?
    AND TRIM(LOWER(i.estatus)) = 'disponible'
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

/*
 * ⚙️ Fallback sin mysqlnd:
 * Si tu PHP no tiene mysqlnd, $stmt->get_result() devuelve false.
 * En ese caso, usamos bind_result + fetch().
 */
$res = $stmt->get_result();
$options = '<option value="">Seleccione un equipo...</option>';

if ($res instanceof mysqli_result) {
  // Camino mysqlnd
  if ($res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
      $options .= pintarOpcion($row);
    }
  } else {
    $options .= '<option value="">Sin inventario disponible en esta sucursal</option>';
  }
} else {
  // Camino sin mysqlnd
  $stmt->store_result();
  $binds = [];
  // orden debe coincidir con $cols:
  $binds['id_inventario'] = null;
  $binds['marca']         = null;
  $binds['modelo']        = null;
  $binds['imei1']         = null;
  $binds['imei2']         = null;
  $binds['color']         = null;
  $binds['precio_lista']  = null;
  $binds['tipo']          = null;

  $stmt->bind_result(
    $binds['id_inventario'],
    $binds['marca'],
    $binds['modelo'],
    $binds['imei1'],
    $binds['imei2'],
    $binds['color'],
    $binds['precio_lista'],
    $binds['tipo']
  );

  $hay = false;
  while ($stmt->fetch()) {
    $hay = true;
    $row = $binds; // copia por valor
    $options .= pintarOpcion($row);
  }
  if (!$hay) {
    $options .= '<option value="">Sin inventario disponible en esta sucursal</option>';
  }
}

echo $options;

// --- helper para pintar la <option> ---
function pintarOpcion(array $row): string {
  $idInv  = (int)($row['id_inventario'] ?? 0);
  $tipo   = strtoupper((string)($row['tipo'] ?? ''));
  $marca  = (string)($row['marca'] ?? '');
  $modelo = (string)($row['modelo'] ?? '');
  $color  = (string)($row['color'] ?? '');
  $imei1  = trim((string)($row['imei1'] ?? ''));
  $imei2  = trim((string)($row['imei2'] ?? ''));
  $precio = (float)($row['precio_lista'] ?? 0);

  $imeiTxt = $imei1 !== '' ? "IMEI1: $imei1" : "IMEI1: —";
  if ($imei2 !== '') $imeiTxt .= " | IMEI2: $imei2";

  $piezaColor = $color !== '' ? " ({$color})" : "";
  $tipoTxt = $tipo !== '' ? "{$tipo} | " : '';
  $texto = "{$tipoTxt}{$marca} {$modelo}{$piezaColor} — {$imeiTxt} - $" . number_format($precio, 2);

  return '<option value="'.$idInv.'">'.htmlspecialchars($texto, ENT_QUOTES, 'UTF-8').'</option>';
}
