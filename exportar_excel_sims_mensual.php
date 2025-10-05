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
   Helpers anti-fórmulas y formato texto
======================== */
function xls_escape($s) {
    $s = (string)$s;
    if ($s !== '' && preg_match('/^[=+\-@]/', $s) === 1) {
        $s = "'".$s; // evita ejecución de fórmulas
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function td_text($s) {
    $safe = xls_escape($s);
    return "<td style=\"mso-number-format:'\\@';\">{$safe}</td>";
}
function td($s) {
    return "<td>".htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8')."</td>";
}

/* ========================
   ENTRADAS (mes/año)
======================== */
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

if ($mes < 1 || $mes > 12)  $mes = (int)date('n');
if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');

// Rango del mes completo
$inicioMesObj = new DateTime("$anio-$mes-01 00:00:00");
$finMesObj    = (clone $inicioMesObj)->modify('last day of this month')->setTime(23,59,59);
$inicioMes    = $inicioMesObj->format('Y-m-d');
$finMes       = $finMesObj->format('Y-m-d');

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
   CONSULTA (LEFT JOIN para incluir eSIM/ventas sin inventario)
   - Operador consolidado: COALESCE(i.operador, vs.tipo_sim)
======================== */
$sql = "
    SELECT 
        vs.id               AS id_venta,
        vs.fecha_venta,
        s.nombre            AS sucursal,
        u.nombre            AS usuario,
        vs.es_esim,                            -- marca si es eSIM
        i.iccid,                               -- ICCID físico (puede venir NULL)
        COALESCE(i.operador, vs.tipo_sim) AS operador,  -- operador final
        vs.tipo_venta,
        vs.modalidad,                          -- para pospago
        vs.nombre_cliente,
        vs.numero_cliente,                     -- teléfono cliente (pospago)
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios
    FROM ventas_sims vs
    INNER JOIN usuarios   u ON vs.id_usuario  = u.id
    INNER JOIN sucursales s ON vs.id_sucursal = s.id
    LEFT JOIN detalle_venta_sims d ON vs.id   = d.id_venta
    LEFT JOIN inventario_sims    i ON d.id_sim = i.id
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
            <th colspan='14'>Historial de Ventas SIM — {$mesNombre} {$anio}</th>
        </tr>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>
            <th>Teléfono</th>           <!-- nuevo en mensual para paridad con semanal -->
            <th>ICCID / Tipo</th>
            <th>Operador</th>           <!-- columna operador -->
            <th>Tipo Venta</th>
            <th>Modalidad</th>
            <th>Precio Total Venta</th>
            <th>Comisión Ejecutivo</th>
            <th>Comisión Gerente</th>
            <th>Comentarios</th>
        </tr>
      </thead>
      <tbody>";

$sumPrecio = 0.0;
$sumComEje = 0.0;
$sumComGer = 0.0;

while ($row = $res->fetch_assoc()) {
    $tipoVenta  = (string)($row['tipo_venta'] ?? '');
    $modalidad  = ($tipoVenta === 'Pospago') ? ($row['modalidad'] ?? '') : '';
    $cliente    = $row['nombre_cliente'] ?? '';
    $telefono   = ($tipoVenta === 'Pospago') ? (string)($row['numero_cliente'] ?? '') : '';
    $coment     = $row['comentarios'] ?? '';

    // Sumas
    $sumPrecio += (float)$row['precio_total'];
    $sumComEje += (float)$row['comision_ejecutivo'];
    $sumComGer += (float)$row['comision_gerente'];

    // ICCID / eSIM como TEXTO (preserva ceros y evita fórmulas)
    if (!empty($row['es_esim'])) {
        $iccidCell = td_text('eSIM');
    } else {
        $iccidVal  = (string)($row['iccid'] ?? '');
        $iccidCell = $iccidVal !== '' ? td_text($iccidVal) : td('');
    }

    // Operador consolidado
    $operador   = trim((string)($row['operador'] ?? ''));
    $operadorCell = $operador !== '' ? td_text($operador) : td('—');

    // Teléfono como TEXTO (si aplica)
    $telefonoCell = $telefono !== '' ? td_text($telefono) : td('');

    echo "<tr>";
    echo td($row['id_venta']);
    echo td($row['fecha_venta']);
    echo td($row['sucursal']);
    echo td($row['usuario']);
    echo td($cliente);
    echo $telefonoCell;
    echo $iccidCell;
    echo $operadorCell;
    echo td($tipoVenta);
    echo td($modalidad);
    echo td(number_format((float)$row['precio_total'], 2, '.', ''));
    echo td(number_format((float)$row['comision_ejecutivo'], 2, '.', ''));
    echo td(number_format((float)$row['comision_gerente'], 2, '.', ''));
    echo td($coment);
    echo "</tr>";
}

// Totales al final
echo "<tr style='background:#f9f9f9;font-weight:bold'>
        <td colspan='10' style='text-align:right'>Totales:</td>
        <td>".number_format($sumPrecio, 2, '.', '')."</td>
        <td>".number_format($sumComEje, 2, '.', '')."</td>
        <td>".number_format($sumComGer, 2, '.', '')."</td>
        <td></td>
      </tr>";

echo "</tbody></table>";
exit;
