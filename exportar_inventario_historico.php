<?php
// exportar_inventario_historico.php (versión robusta con columnas dinámicas)
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') === '') {
  header("Location: index.php"); exit();
}
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

// ===== Parámetros =====
$fFecha       = $_GET['fecha']          ?? date('Y-m-d', strtotime('-1 day'));
$fSucursal    = $_GET['sucursal']       ?? '';
$fImei        = $_GET['imei']           ?? '';
$fTipo        = $_GET['tipo_producto']  ?? '';
$fEstatus     = $_GET['estatus']        ?? '';
$fAntiguedad  = $_GET['antiguedad']     ?? '';
$fPrecioMin   = $_GET['precio_min']     ?? '';
$fPrecioMax   = $_GET['precio_max']     ?? '';

// ===== Util =====
function nf($n){ return number_format((float)$n, 2, '.', ''); }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ===== Descubrir columnas disponibles =====
$cols = [];
$q = $conn->query("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_snapshot'
");
while ($r = $q->fetch_assoc()) $cols[$r['COLUMN_NAME']] = true;
$q->close();
$has = fn($c) => isset($cols[$c]);

// ===== Definir orden deseado (campo => etiqueta) =====
$wanted = [
  'snapshot_date'     => 'Fecha Snapshot',
  'id_inventario'     => 'ID Inv',
  'id_sucursal'       => 'ID Sucursal',
  'sucursal_nombre'   => 'Sucursal',
  'id_producto'       => 'ID Producto',
  'codigo_producto'   => 'Código',
  'marca'             => 'Marca',
  'modelo'            => 'Modelo',
  'nombre_comercial'  => 'Nombre comercial',
  'color'             => 'Color',
  'ram'               => 'RAM',
  'capacidad'         => 'Capacidad',
  'imei1'             => 'IMEI1',
  'imei2'             => 'IMEI2',
  'tipo_producto'     => 'Tipo',
  'subtipo'           => 'Subtipo',
  'gama'              => 'Gama',
  'operador'          => 'Operador',
  'resurtible'        => 'Resurtible',
  'proveedor'         => 'Proveedor',
  'compania'          => 'Compañía',
  'financiera'        => 'Financiera',
  'fecha_lanzamiento' => 'Fecha lanzamiento',
  'costo'             => 'Costo',
  'costo_con_iva'     => 'Costo c/IVA',
  'precio_lista'      => 'Precio Lista',
  'profit'            => 'Profit',
  'estatus'           => 'Estatus',
  'fecha_ingreso'     => 'Fecha Ingreso',
  'antiguedad_dias'   => 'Antigüedad (días)',
];

// ===== Armar SELECT dinámico y encabezados =====
$selectParts = [];
$headers     = [];
$outFields   = []; // nombres tal como vendrán en el result set

foreach ($wanted as $col => $label) {
  if ($has($col)) {
    if ($col === 'sucursal_nombre') {
      $selectParts[] = "sucursal_nombre AS sucursal";
      $headers[]     = $label;
      $outFields[]   = 'sucursal';
    } else {
      $selectParts[] = $col;
      $headers[]     = $label;
      $outFields[]   = $col;
    }
  } else {
    // Fallbacks calculados si faltan columnas clave
    if ($col === 'profit' && $has('precio_lista') && $has('costo_con_iva')) {
      $selectParts[] = "(COALESCE(precio_lista,0)-COALESCE(costo_con_iva,0)) AS profit";
      $headers[]     = $label;
      $outFields[]   = 'profit';
    }
    if ($col === 'antiguedad_dias' && $has('fecha_ingreso') && $has('snapshot_date')) {
      $selectParts[] = "DATEDIFF(snapshot_date, DATE(fecha_ingreso)) AS antiguedad_dias";
      $headers[]     = $label;
      $outFields[]   = 'antiguedad_dias';
    }
  }
}

if (empty($selectParts)) {
  http_response_code(500);
  exit('No hay columnas disponibles para exportar.');
}

// ===== Query principal + filtros =====
$sql    = "SELECT ".implode(', ', $selectParts)." FROM inventario_snapshot WHERE snapshot_date = ?";
$params = [$fFecha]; $types = "s";

if ($fSucursal !== '' && $has('id_sucursal')) {
  $sql .= " AND id_sucursal = ?"; $params[] = (int)$fSucursal; $types .= "i";
}
if ($fImei !== '' && ($has('imei1') || $has('imei2'))) {
  if ($has('imei1') && $has('imei2')) {
    $sql .= " AND (imei1 LIKE ? OR imei2 LIKE ?)";
    $like = "%{$fImei}%"; $params[] = $like; $params[] = $like; $types .= "ss";
  } elseif ($has('imei1')) {
    $sql .= " AND imei1 LIKE ?"; $params[] = "%{$fImei}%"; $types .= "s";
  } else {
    $sql .= " AND imei2 LIKE ?"; $params[] = "%{$fImei}%"; $types .= "s";
  }
}
if ($fTipo !== '' && $has('tipo_producto')) {
  $sql .= " AND tipo_producto = ?"; $params[] = $fTipo; $types .= "s";
}
if ($fEstatus !== '' && $has('estatus')) {
  $sql .= " AND estatus = ?"; $params[] = $fEstatus; $types .= "s";
}

if ($fAntiguedad !== '') {
  if ($has('antiguedad_dias')) {
    if ($fAntiguedad === '<30')       $sql .= " AND antiguedad_dias < 30";
    elseif ($fAntiguedad === '30-90') $sql .= " AND antiguedad_dias BETWEEN 30 AND 90";
    elseif ($fAntiguedad === '>90')   $sql .= " AND antiguedad_dias > 90";
  } elseif ($has('fecha_ingreso')) {
    // Fallback usando DATEDIFF(snapshot_date, fecha_ingreso)
    if     ($fAntiguedad === '<30')   { $sql .= " AND DATEDIFF(?, DATE(fecha_ingreso)) < 30"; $params[] = $fFecha; $types .= "s"; }
    elseif ($fAntiguedad === '30-90'){ $sql .= " AND DATEDIFF(?, DATE(fecha_ingreso)) BETWEEN 30 AND 90"; $params[] = $fFecha; $types .= "s"; }
    elseif ($fAntiguedad === '>90')  { $sql .= " AND DATEDIFF(?, DATE(fecha_ingreso)) > 90";  $params[] = $fFecha; $types .= "s"; }
  }
}

if ($fPrecioMin !== '' && $has('precio_lista')) {
  $sql .= " AND precio_lista >= ?"; $params[] = (float)$fPrecioMin; $types .= "d";
}
if ($fPrecioMax !== '' && $has('precio_lista')) {
  $sql .= " AND precio_lista <= ?"; $params[] = (float)$fPrecioMax; $types .= "d";
}

$ordSucursal = in_array('sucursal', $outFields, true) ? 'sucursal' : ( $has('id_sucursal') ? 'id_sucursal' : $outFields[0] );
$ordFechaIng = $has('fecha_ingreso') ? 'fecha_ingreso' : (in_array('id_inventario',$outFields,true) ? 'id_inventario' : $outFields[0]);
$sql .= " ORDER BY {$ordSucursal}, {$ordFechaIng} DESC";

// ===== Ejecutar =====
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit("Error SQL: ".$conn->error); }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// ===== Headers Excel (HTML .xls con BOM) =====
$filename = "inventario_historico_{$fFecha}.xls";
while (ob_get_level()) { ob_end_clean(); }
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF";

// ===== Render =====
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";

// Encabezados
echo "<tr style='background:#222;color:#fff;font-weight:bold'>";
foreach ($headers as $th) echo "<td>".h($th)."</td>";
echo "</tr>";

// Filas
while ($row = $res->fetch_assoc()) {
  echo "<tr>";
  foreach ($outFields as $field) {
    $val = $row[$field] ?? '';
    // IMEIs como texto
    if ($field === 'imei1' || $field === 'imei2') {
      echo "<td>'".h($val)."</td>";
    }
    // Números con 2 decimales
    elseif (in_array($field, ['costo','costo_con_iva','precio_lista','profit'], true)) {
      echo "<td>".nf($val)."</td>";
    } else {
      echo "<td>".h($val)."</td>";
    }
  }
  echo "</tr>";
}

echo "</table></body></html>";

$stmt->close();
$conn->close();
