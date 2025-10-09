<?php
// exportar_inventario_eulalia.php — Exporta las MISMAS columnas y filtros que la vista inventario_eulalia.php
session_start();

if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }
$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona','Super'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

// Helper seguro
if (!function_exists('h')) { function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); } }

// ===============================
//   Resolver sucursal de Almacén (mismo criterio que la vista)
// ===============================
$nombreAlmacen = null; $idAlmacen = 0;
$intentos = ['Almacen', 'Almacén', 'Eulalia'];
foreach ($intentos as $try) {
  if ($stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE nombre = ? LIMIT 1")) {
    $stmt->bind_param("s", $try);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) { $idAlmacen=(int)$row['id']; $nombreAlmacen=$row['nombre']; $stmt->close(); break; }
    $stmt->close();
  }
}
if ($idAlmacen <= 0) {
  $likes = ['%Almac%', '%Eulalia%'];
  foreach ($likes as $lk) {
    if ($stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE nombre LIKE ? LIMIT 1")) {
      $stmt->bind_param("s", $lk);
      $stmt->execute();
      if ($row = $stmt->get_result()->fetch_assoc()) { $idAlmacen=(int)$row['id']; $nombreAlmacen=$row['nombre']; $stmt->close(); break; }
      $stmt->close();
    }
  }
}
$noEncontrado = ($idAlmacen <= 0);
if ($noEncontrado) { $nombreAlmacen = 'Almacén (no encontrado)'; }

// ===============================
//   Filtros (igual que la vista)
// ===============================
$fImei        = $_GET['imei']          ?? '';
$fTipo        = $_GET['tipo_producto'] ?? '';
$fEstatus     = $_GET['estatus']       ?? '';
$fAntiguedad  = $_GET['antiguedad']    ?? '';
$fPrecioMin   = $_GET['precio_min']    ?? '';
$fPrecioMax   = $_GET['precio_max']    ?? '';

// ===============================
//   Query principal (igual columnas que la tabla de la vista)
// ===============================
$sql = "
SELECT 
  i.id AS id_inv,
  p.marca, p.modelo, p.color, p.capacidad,
  p.imei1, p.imei2,
  COALESCE(p.costo_con_iva, p.costo, 0) AS costo_mostrar,
  p.precio_lista,
  (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
  p.tipo_producto,
  i.estatus, i.fecha_ingreso,
  TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE 1=1
";

$params = []; $types = "";
if ($noEncontrado) {
  $sql .= " AND i.id_sucursal = -1";
} else {
  $sql .= " AND i.id_sucursal = ?"; $params[] = $idAlmacen; $types .= "i";
}
if ($fImei !== '') {
  $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)"; $like = "%".$fImei."%";
  $params[] = $like; $params[] = $like; $types .= "ss";
}
if ($fTipo !== '') {
  $sql .= " AND p.tipo_producto = ?"; $params[] = $fTipo; $types .= "s";
}
if ($fEstatus !== '') {
  $sql .= " AND i.estatus = ?"; $params[] = $fEstatus; $types .= "s";
}
if ($fAntiguedad === '<30') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($fAntiguedad === '30-90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($fAntiguedad === '>90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($fPrecioMin !== '') {
  $sql .= " AND p.precio_lista >= ?"; $params[] = (float)$fPrecioMin; $types .= "d";
}
if ($fPrecioMax !== '') {
  $sql .= " AND p.precio_lista <= ?"; $params[] = (float)$fPrecioMax; $types .= "d";
}
$sql .= " ORDER BY i.fecha_ingreso ASC";

$stmt = $conn->prepare($sql);
if ($types !== "") { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// ===============================
//   Headers Excel (HTML-table; Excel lo abre perfecto)
// ===============================
$filename = "inventario_almacen_".date('Ymd_His').".xls";
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// ===============================
//   Render
// ===============================
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";
echo "<tr><th colspan='14' align='left'>Inventario — ".h($nombreAlmacen)."</th></tr>";
echo "<tr>";
echo "<th>ID Inv</th>";
echo "<th>Marca</th>";
echo "<th>Modelo</th>";
echo "<th>Color</th>";
echo "<th>Capacidad</th>";
echo "<th>IMEI1</th>";
echo "<th>IMEI2</th>";
echo "<th>Tipo</th>";
echo "<th>Costo c/IVA</th>";
echo "<th>Precio Lista</th>";
echo "<th>Profit</th>";
echo "<th>Estatus</th>";
echo "<th>Fecha Ingreso</th>";
echo "<th>Antigüedad (días)</th>";
echo "</tr>";

while ($row = $result->fetch_assoc()) {
  // números como punto decimal para Excel
  $costo   = number_format((float)$row['costo_mostrar'], 2, '.', '');
  $precio  = number_format((float)$row['precio_lista'], 2, '.', '');
  $profit  = number_format((float)$row['profit'], 2, '.', '');
  $dias    = (int)$row['antiguedad_dias'];

  echo "<tr>";
  echo "<td>".(int)$row['id_inv']."</td>";
  echo "<td>".h($row['marca'])."</td>";
  echo "<td>".h($row['modelo'])."</td>";
  echo "<td>".h($row['color'])."</td>";
  echo "<td>".h($row['capacidad'])."</td>";
  echo "<td>".h($row['imei1'])."</td>";
  echo "<td>".h($row['imei2'])."</td>";
  echo "<td>".h($row['tipo_producto'])."</td>";
  echo "<td>{$costo}</td>";
  echo "<td>{$precio}</td>";
  echo "<td>{$profit}</td>";
  echo "<td>".h($row['estatus'])."</td>";
  echo "<td>".h($row['fecha_ingreso'])."</td>";
  echo "<td>{$dias}</td>";
  echo "</tr>";
}
echo "</table>";
echo "</body></html>";

$stmt->close();
$conn->close();
