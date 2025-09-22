<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 📄 Encabezados para exportar a Excel
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims_mensual.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM UTF-8

date_default_timezone_set('America/Mexico_City');

/* ========================
   ENTRADAS (mes/año)
======================== */
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

if ($mes < 1 || $mes > 12)  $mes = (int)date('n');
if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');

// Rango del mes completo
$inicioMes = (new DateTime("$anio-$mes-01 00:00:00"))->format('Y-m-d');
$finMesObj = new DateTime("$anio-$mes-01 00:00:00");
$finMesObj->modify('last day of this month')->setTime(23,59,59);
$finMes    = $finMesObj->format('Y-m-d');

/* ========================
   FILTROS (como en la vista)
======================== */
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);

$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ? ";
$params = [$inicioMes, $finMes];
$types  = "ss";

// Filtro por rol
if ($rol === 'Ejecutivo') {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types   .= "i";
} elseif ($rol === 'Gerente') {
    $where   .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Filtros GET adicionales
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario'] ?? '';
if (!empty($tipoVentaGet)) {
    $where   .= " AND vs.tipo_venta=? ";
    $params[] = $tipoVentaGet;
    $types   .= "s";
}
if (!empty($usuarioGet)) {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = (int)$usuarioGet;
    $types   .= "i";
}

/* ========================
   CONSULTA (por SIM)
======================== */
$sql = "
    SELECT 
        vs.id AS id_venta,
        vs.fecha_venta,
        s.nombre AS sucursal,
        u.nombre AS usuario,
        i.iccid,
        vs.tipo_venta,
        vs.modalidad,            -- para pospago
        vs.nombre_cliente,       -- cliente
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios
    FROM ventas_sims vs
    INNER JOIN usuarios u           ON vs.id_usuario   = u.id
    INNER JOIN sucursales s         ON vs.id_sucursal  = s.id
    INNER JOIN detalle_venta_sims d ON vs.id           = d.id_venta
    INNER JOIN inventario_sims i    ON d.id_sim        = i.id
    $where
    ORDER BY vs.fecha_venta DESC, vs.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ========================
   GENERAR TABLA XLS
======================== */
$mesNombre = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
][$mes] ?? "$mes";

echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#e8f5e9'>
            <th colspan='12'>Historial de Ventas SIM — {$mesNombre} {$anio}</th>
        </tr>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>
            <th>ICCID</th>
            <th>Tipo Venta</th>
            <th>Modalidad</th>
            <th>Precio Total Venta</th>
            <th>Comisión Ejecutivo</th>
            <th>Comisión Gerente</th>
            <th>Comentarios</th>
        </tr>
      </thead>
      <tbody>";

while ($row = $res->fetch_assoc()) {
    $iccid      = htmlspecialchars($row['iccid']);
    $tipoVenta  = (string)$row['tipo_venta'];
    $modalidad  = ($tipoVenta === 'Pospago') ? ($row['modalidad'] ?? '') : '';

    echo "<tr>
            <td>{$row['id_venta']}</td>
            <td>{$row['fecha_venta']}</td>
            <td>".htmlspecialchars($row['sucursal'])."</td>
            <td>".htmlspecialchars($row['usuario'])."</td>
            <td>".htmlspecialchars($row['nombre_cliente'] ?? '')."</td>
            <td>=\"{$iccid}\"</td>
            <td>".htmlspecialchars($tipoVenta)."</td>
            <td>".htmlspecialchars($modalidad)."</td>
            <td>{$row['precio_total']}</td>
            <td>{$row['comision_ejecutivo']}</td>
            <td>{$row['comision_gerente']}</td>
            <td>".htmlspecialchars($row['comentarios'])."</td>
          </tr>";
}

echo "</tbody></table>";
exit;
