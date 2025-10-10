<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once 'db.php';

/* ========= Normaliza collation de la conexión (clave para prod) ========= */
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* ========= Diagnóstico rápido =========
   - ?ping=1  -> responde "pong" (sin tocar DB)
   - ?debug=1 -> muestra tabla HTML (no descarga) y errores visibles
*/
$ping  = isset($_GET['ping']);
$debug = isset($_GET['debug']);
if ($ping) { header("Content-Type: text/plain; charset=UTF-8"); echo "pong"; exit; }

/* ========= Harden: tiempo/memoria + logging de errores ========= */
@ini_set('zlib.output_compression','Off');
@ini_set('output_buffering','0');
@ini_set('memory_limit','1024M');
@set_time_limit(600); // por si hay muuuchas ventas

$LOG = __DIR__ . '/export_debug.log';
function logx($m){ @error_log("[".date('c')."] ".$m."\n", 3, $GLOBALS['LOG']); }
if ($debug) { error_reporting(E_ALL); ini_set('display_errors','1'); }
set_error_handler(function($no,$str,$file,$line){ logx("PHP[$no] $str @ $file:$line"); });
set_exception_handler(function($ex){ logx("EXC ".$ex->getMessage()."\n".$ex->getTraceAsString()); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    logx("FATAL {$e['message']} @ {$e['file']}:{$e['line']}");
    if (isset($_GET['debug'])) {
      header("Content-Type: text/html; charset=UTF-8");
      echo "<h3>Fatal error</h3><pre>".htmlspecialchars($e['message'].' @ '.$e['file'].':'.$e['line'],ENT_QUOTES,'UTF-8')."</pre>";
    }
  }
});

/* ========= Helpers ========= */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/* ¿Existe columna? (para compatibilidad) */
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

/* ========= Consulta SIN filtros de fecha/usuario/sucursal/búsqueda =========
   - Mantiene ROW_NUMBER para imprimir precio_venta y refs solo en la 1a fila por venta.
*/
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

  ROW_NUMBER() OVER (PARTITION BY v.id ORDER BY dv.id) AS rn

FROM ventas v
INNER JOIN usuarios   u ON v.id_usuario  = u.id
INNER JOIN sucursales s ON v.id_sucursal = s.id
LEFT  JOIN detalle_venta dv ON dv.id_venta    = v.id
LEFT  JOIN productos     p  ON dv.id_producto = p.id

LEFT  JOIN catalogo_modelos cm1
       ON CONVERT(cm1.codigo_producto USING utf8mb4) COLLATE utf8mb4_general_ci
        = CONVERT(p.codigo_producto  USING utf8mb4) COLLATE utf8mb4_general_ci
      AND cm1.codigo_producto IS NOT NULL
      AND cm1.codigo_producto <> ''

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

ORDER BY v.fecha_venta DESC, v.id DESC, dv.id ASC
";

/* ========= Ejecutar (unbuffered si no debug para ahorrar memoria) ========= */
if ($debug) {
  $res = $conn->query($sql); // buffered (para poder debuggear)
  if (!$res) {
    header("Content-Type: text/html; charset=UTF-8");
    echo "<h3>Error SQL</h3><pre>".htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8')."</pre>";
    exit;
  }
} else {
  $res = $conn->query($sql, MYSQLI_USE_RESULT); // stream de filas grandes
  if (!$res) {
    while (ob_get_level()) { ob_end_clean(); }
    header("Content-Type: text/html; charset=UTF-8");
    echo "<h3>Error SQL</h3><pre>".htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8')."</pre>";
    exit;
  }
}

/* ========= Headers según modo ========= */
if (!$debug) {
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=ventas_todas.xls");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
} else {
  header("Content-Type: text/html; charset=UTF-8");
}

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

/* ========= Stream de filas ========= */
while ($r = $res->fetch_assoc()) {
  // Excel como texto (evitar pérdida de 0s/precisión)
  $imei = ($r['imei1']!==null && $r['imei1']!=='') ? '="'.e($r['imei1']).'"' : '';

  // Mostrar solo en PRIMERA fila por venta (gracias a rn)
  $soloPrimera = ((int)$r['rn'] === 1);

  $precioVenta = $soloPrimera ? e($r['precio_venta']) : '';

  // Teléfono cliente como texto sólo en la primera fila
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

if (!$debug) { $res->free(); }
exit;
