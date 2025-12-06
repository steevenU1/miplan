<?php
// exportar_ventas_mes.php — Exporta todas las ventas de un mes completo
// Alineado en columnas/estructura con el export semanal (exportar_excel.php)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

/* ========= Normaliza collation de la conexión ========= */
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ========= Helpers ========= */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = '{$t}'
            AND COLUMN_NAME  = '{$c}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* ========= Parámetros: mes / año / sucursal ========= */
$rol   = $_SESSION['rol'] ?? '';
$idSuc = (int)($_SESSION['id_sucursal'] ?? 0);

// Solo Admin usa este export mensual (si quieres luego ampliamos)
if ($rol !== 'Admin') {
  die("No autorizado.");
}

$mes      = (int)($_GET['mes'] ?? 0);
$anio     = (int)($_GET['anio'] ?? 0);
$sucursal = (int)($_GET['sucursal'] ?? 0); // 0 = todas

if ($mes < 1 || $mes > 12 || $anio < 2000) {
  die("Parámetros de mes/año inválidos.");
}

// Rango del mes completo
try {
  $inicio = new DateTime(sprintf('%04d-%02d-01 00:00:00', $anio, $mes));
  $fin    = clone $inicio;
  $fin->modify('last day of this month')->setTime(23,59,59);
} catch (Exception $e) {
  die("Error al calcular rango de fechas.");
}

$fechaInicio = $inicio->format('Y-m-d');
$fechaFin    = $fin->format('Y-m-d');

/* ========= WHERE base (igual que semanal pero por mes) ========= */
$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$fechaInicio,$fechaFin];
$types  = "ss";

/* Filtro por sucursal (si viene distinta de 0) */
if ($sucursal > 0) {
  $where .= " AND v.id_sucursal = ? ";
  $params[] = $sucursal;
  $types   .= "i";
}

/* (Opcional) Si más adelante quieres respetar tipo_venta, usuario y buscar,
   podríamos copiar los mismos filtros del export semanal aquí.
*/

/* ========= Select dinámico para Referencias (compat si faltan columnas) ========= */
$hasR1N = hasColumn($conn,'ventas','referencia1_nombre');
$hasR1T = hasColumn($conn,'ventas','referencia1_telefono');
$hasR2N = hasColumn($conn,'ventas','referencia2_nombre');
$hasR2T = hasColumn($conn,'ventas','referencia2_telefono');

$selectRefs = implode(",\n  ", [
  $hasR1N ? "v.referencia1_nombre AS referencia1_nombre" : "'' AS referencia1_nombre",
  $hasR1T ? "v.referencia1_telefono AS referencia1_telefono" : "'' AS referencia1_telefono",
  $hasR2N ? "v.referencia2_nombre AS referencia2_nombre" : "'' AS referencia2_nombre",
  $hasR2T ? "v.referencia2_telefono AS referencia2_telefono" : "'' AS referencia2_telefono",
]);

/* ========= Consulta: misma estructura que el semanal ========= */
$sql = "
SELECT
  v.id AS id_venta, v.fecha_venta, v.tag, v.nombre_cliente, v.telefono_cliente,
  s.nombre AS sucursal, u.nombre AS usuario,
  v.tipo_venta, v.precio_venta, v.comision AS comision_venta,
  v.enganche, v.forma_pago_enganche, v.enganche_efectivo, v.enganche_tarjeta,
  v.comentarios,

  {$selectRefs},

  p.marca, p.modelo, p.color,

  COALESCE(cm1.codigo_producto, cm2.codigo_producto, p.codigo_producto) AS codigo,
  COALESCE(cm1.descripcion,     cm2.descripcion)                         AS descripcion,
  COALESCE(cm1.nombre_comercial,cm2.nombre_comercial)                    AS nombre_comercial,

  dv.id AS id_detalle,
  dv.imei1,
  dv.comision_regular, dv.comision_especial, dv.comision AS comision_equipo,

  /* fila 1 por venta */
  ROW_NUMBER() OVER (PARTITION BY v.id ORDER BY dv.id) AS rn

FROM ventas v
INNER JOIN usuarios   u ON v.id_usuario  = u.id
INNER JOIN sucursales s ON v.id_sucursal = s.id
LEFT  JOIN detalle_venta dv ON dv.id_venta    = v.id
LEFT  JOIN productos     p  ON dv.id_producto = p.id

/* join por código: collation igual en ambos lados */
LEFT  JOIN catalogo_modelos cm1
       ON CONVERT(cm1.codigo_producto USING utf8mb4) COLLATE utf8mb4_general_ci
        = CONVERT(p.codigo_producto  USING utf8mb4) COLLATE utf8mb4_general_ci
      AND cm1.codigo_producto IS NOT NULL
      AND cm1.codigo_producto <> ''

/* fallback por clave compuesta */
LEFT  JOIN catalogo_modelos cm2
       ON ( (p.codigo_producto IS NULL OR p.codigo_producto = '')
            AND CONVERT(cm2.marca     USING utf8mb4) COLLATE utf8mb4_general_ci
              = CONVERT(p.marca       USING utf8mb4) COLLATE utf8mb4_general_ci
            AND CONVERT(cm2.modelo    USING utf8mb4) COLLATE utf8mb4_general_ci
              = CONVERT(p.modelo      USING utf8mb4) COLLATE utf8mb4_general_ci
            AND (CONVERT(cm2.color    USING utf8mb4) COLLATE utf8mb4_general_ci
              <=> CONVERT(p.color     USING utf8mb4) COLLATE utf8mb4_general_ci)
            AND (CONVERT(cm2.ram      USING utf8mb4) COLLATE utf8mb4_general_ci
              <=> CONVERT(p.ram       USING utf8mb4) COLLATE utf8mb4_general_ci)
            AND (CONVERT(cm2.capacidad USING utf8mb4) COLLATE utf8mb4_general_ci
              <=> CONVERT(p.capacidad  USING utf8mb4) COLLATE utf8mb4_general_ci)
          )

{$where}
ORDER BY v.fecha_venta DESC, v.id DESC, dv.id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  $err = e($conn->error ?? 'prepare failed');
  echo "<html><head><meta charset='UTF-8'></head><body>
        <h3>Error preparando SQL</h3><pre>{$err}</pre></body></html>";
  exit;
}
if ($params){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

/* ========= Headers para Excel ========= */
while (ob_get_level()) { ob_end_clean(); }

$nombreArchivo = sprintf("ventas_mes_%04d_%02d.xls", $anio, $mes);
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename={$nombreArchivo}");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* ========= Salida HTML (compatible con Excel) ========= */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1'><thead><tr style='background:#f2f2f2'>
  <th>ID Venta</th><th>Fecha</th><th>TAG</th><th>Cliente</th><th>Teléfono</th><th>Sucursal</th><th>Usuario</th>
  <th>Tipo Venta</th><th>Precio Venta</th><th>Comisión Total Venta</th>
  <th>Enganche</th><th>Forma Enganche</th><th>Enganche Efectivo</th><th>Enganche Tarjeta</th>
  <th>Comentarios</th>
  <th>Ref1 Nombre</th><th>Ref1 Teléfono</th><th>Ref2 Nombre</th><th>Ref2 Teléfono</th>
  <th>Marca</th><th>Modelo</th><th>Color</th>
  <th>Código</th><th>Descripción</th><th>Nombre comercial</th>
  <th>IMEI</th><th>Comisión Regular</th><th>Comisión Especial</th><th>Total Comisión Equipo</th>
</tr></thead><tbody>";

while ($r = $res->fetch_assoc()) {
  // Excel como texto (evitar pérdida de 0s/precisión)
  $imei = ($r['imei1']!==null && $r['imei1']!=='') ? '="'.e($r['imei1']).'"' : '';

  // Mostrar solo en PRIMERA fila por venta
  $soloPrimera = ((int)$r['rn'] === 1);

  $precioVenta = $soloPrimera ? e($r['precio_venta']) : '';

  // Teléfonos como texto para Excel
  $telCliente = $soloPrimera && $r['telefono_cliente'] !== null && $r['telefono_cliente'] !== ''
      ? '="'.e($r['telefono_cliente']).'"' : '';

  $ref1Nombre = $soloPrimera ? e($r['referencia1_nombre']) : '';
  $ref1Tel    = $soloPrimera && $r['referencia1_telefono'] !== '' ? '="'.e($r['referencia1_telefono']).'"' : '';
  $ref2Nombre = $soloPrimera ? e($r['referencia2_nombre']) : '';
  $ref2Tel    = $soloPrimera && $r['referencia2_telefono'] !== '' ? '="'.e($r['referencia2_telefono']).'"' : '';

  echo "<tr>
    <td>".e($r['id_venta'])."</td>
    <td>".e($r['fecha_venta'])."</td>
    <td>".e($r['tag'])."</td>
    <td>".e($r['nombre_cliente'])."</td>
    <td>{$telCliente}</td>
    <td>".e($r['sucursal'])."</td>
    <td>".e($r['usuario'])."</td>
    <td>".e($r['tipo_venta'])."</td>
    <td>{$precioVenta}</td>
    <td>".e($r['comision_venta'])."</td>
    <td>".e($r['enganche'])."</td>
    <td>".e($r['forma_pago_enganche'])."</td>
    <td>".e($r['enganche_efectivo'])."</td>
    <td>".e($r['enganche_tarjeta'])."</td>
    <td>".e($r['comentarios'])."</td>

    <td>{$ref1Nombre}</td>
    <td>{$ref1Tel}</td>
    <td>{$ref2Nombre}</td>
    <td>{$ref2Tel}</td>

    <td>".e($r['marca'])."</td>
    <td>".e($r['modelo'])."</td>
    <td>".e($r['color'])."</td>
    <td>".e($r['codigo'])."</td>
    <td>".e($r['descripcion'])."</td>
    <td>".e($r['nombre_comercial'])."</td>
    <td>{$imei}</td>
    <td>".e($r['comision_regular'])."</td>
    <td>".e($r['comision_especial'])."</td>
    <td>".e($r['comision_equipo'])."</td>
  </tr>";
}

echo "</tbody></table></body></html>";
exit;
