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
@set_time_limit(300);

$LOG = __DIR__ . '/export_debug.log';
function logx($m){ @error_log("[".date('c')."] ".$m."\n", 3, $GLOBALS['LOG']); }
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
function semana_martes_lunes($offset=0){
  $hoy=new DateTime(); $dif=(int)$hoy->format('N')-2; if($dif<0)$dif+=7; // martes=2
  $ini=(new DateTime())->modify("-{$dif} days")->setTime(0,0,0);
  if($offset>0)$ini->modify('-'.(7*$offset).' days');
  $fin=(clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini->format('Y-m-d'),$fin->format('Y-m-d')];
}

/* ========= Filtros (idénticos a la vista) ========= */
$rol   = $_SESSION['rol'] ?? '';
$idSuc = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsr = (int)($_SESSION['id_usuario'] ?? 0);

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($fechaInicio, $fechaFin) = semana_martes_lunes($semana);

$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ? ";
$params = [$fechaInicio,$fechaFin];
$types  = "ss";

if ($rol === 'Ejecutivo'){ $where.=" AND v.id_usuario = ? ";  $params[]=$idUsr; $types.="i"; }
elseif ($rol === 'Gerente'){ $where.=" AND v.id_sucursal = ? "; $params[]=$idSuc; $types.="i"; }

if (!empty($_GET['tipo_venta'])){ $where.=" AND v.tipo_venta = ? "; $params[]=(string)$_GET['tipo_venta']; $types.="s"; }
if (!empty($_GET['usuario']))   { $where.=" AND v.id_usuario = ? "; $params[]=(int)$_GET['usuario'];     $types.="i"; }
if (!empty($_GET['buscar'])) {
  $q = "%".$_GET['buscar']."%";
  $where .= " AND (v.nombre_cliente LIKE ? OR v.telefono_cliente LIKE ? OR v.tag LIKE ?
                   OR EXISTS(SELECT 1 FROM detalle_venta dv2 WHERE dv2.id_venta=v.id AND dv2.imei1 LIKE ?))";
  array_push($params,$q,$q,$q,$q); $types.="ssss";
}

/* ========= Consulta: agrega ROW_NUMBER para imprimir precio_venta solo en 1 fila ========= */
$sql = "
SELECT
  v.id AS id_venta, v.fecha_venta, v.tag, v.nombre_cliente, v.telefono_cliente,
  s.nombre AS sucursal, u.nombre AS usuario,
  v.tipo_venta, v.precio_venta, v.comision AS comision_venta,
  v.enganche, v.forma_pago_enganche, v.enganche_efectivo, v.enganche_tarjeta,
  v.comentarios,

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
  $err = htmlspecialchars($conn->error ?? 'prepare failed', ENT_QUOTES, 'UTF-8');
  if ($debug) { header("Content-Type: text/html; charset=UTF-8"); }
  echo "<html><head><meta charset='UTF-8'></head><body>
        <h3>Error preparando SQL</h3><pre>{$err}</pre></body></html>";
  logx("prepare error: ".$conn->error);
  exit;
}
if ($params){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

/* ========= Headers según modo ========= */
if (!$debug) {
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=historial_ventas.xls");
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
  <th>Marca</th><th>Modelo</th><th>Color</th>
  <th>Código</th><th>Descripción</th><th>Nombre comercial</th>
  <th>IMEI</th><th>Comisión Regular</th><th>Comisión Especial</th><th>Total Comisión Equipo</th>
</tr></thead><tbody>";

while ($r = $res->fetch_assoc()) {
  $imei = ($r['imei1']!==null && $r['imei1']!=='') ? '="'.e($r['imei1']).'"' : '';

  // Mostrar precio_venta solo en la PRIMERA fila de cada venta (rn=1)
  $precioVenta = ($r['rn'] == 1)
      ? e($r['precio_venta'])
      : '';

  echo "<tr>
    <td>".e($r['id_venta'])."</td>
    <td>".e($r['fecha_venta'])."</td>
    <td>".e($r['tag'])."</td>
    <td>".e($r['nombre_cliente'])."</td>
    <td>".e($r['telefono_cliente'])."</td>
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
