<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   TZ MX para semana Mar→Lun
======================== */
date_default_timezone_set('America/Mexico_City');

/* ========================
   Encabezados Excel
======================== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims.xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF"; // BOM UTF-8

/* ========================
   Helpers anti-fórmulas y texto
======================== */
function xls_escape($s) {
    // Evita ejecución de fórmulas (=, +, -, @) y escapa HTML
    $s = (string)$s;
    if ($s !== '' && preg_match('/^[=+\-@]/', $s) === 1) {
        $s = "'".$s;
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function td_text($s) {
    // Fuerza a Excel a tratar el contenido como texto (preserva ceros)
    $safe = xls_escape($s);
    return "<td style=\"mso-number-format:'\\@';\">{$safe}</td>";
}

function td($s) {
    // Celda normal (numérica o string sin formato especial)
    return "<td>".htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8')."</td>";
}

/* ========================
   Semana martes-lunes (inmutable)
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTimeImmutable('now'); // TZ ya seteada
    $diaSemana = (int)$hoy->format('N'); // 1=lun ... 7=dom
    $dif = $diaSemana - 2;               // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = $hoy->sub(new DateInterval('P'.$dif.'D'))->setTime(0,0,0);
    if ($offset > 0) {
        $inicio = $inicio->sub(new DateInterval('P'.(7*$offset).'D'));
    }
    $fin = $inicio->add(new DateInterval('P6D'))->setTime(23,59,59);

    return [$inicio, $fin];
}

/* ========================
   Filtros (idénticos a la vista)
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$nombreSesion= $_SESSION['nombre'] ?? 'Usuario';

$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

// Rol
if ($rol === 'Ejecutivo') {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types   .= "i";
} elseif ($rol === 'Gerente') {
    $where   .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types   .= "i";
}

// Filtros GET opcionales
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario'] ?? '';
if ($tipoVentaGet !== '') {
    $where   .= " AND vs.tipo_venta=? ";
    $params[] = $tipoVentaGet;
    $types   .= "s";
}
if ($usuarioGet !== '') {
    $where   .= " AND vs.id_usuario=? ";
    $params[] = (int)$usuarioGet;
    $types   .= "i";
}

/* ========================
   Consulta (LEFT JOIN para eSIM)
   - Operador consolidado: COALESCE(i.operador, vs.tipo_sim)
======================== */
$sql = "
    SELECT 
        vs.id               AS id_venta,
        vs.fecha_venta,
        s.nombre            AS sucursal,
        u.nombre            AS usuario,
        vs.es_esim,                 
        i.iccid,                    
        COALESCE(i.operador, vs.tipo_sim) AS operador,  -- operador final
        vs.tipo_venta,
        vs.modalidad,               
        vs.nombre_cliente,          
        vs.numero_cliente,          
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
$stmt->close();

/* ========================
   Datos para encabezado
======================== */
// Nombre de sucursal del usuario (para mostrar si es Gerente)
$nomSucursalActual = '';
if ($rol === 'Gerente') {
    if ($q = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1")) {
        $q->bind_param("i", $id_sucursal);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $nomSucursalActual = $r['nombre'] ?? '';
        $q->close();
    }
}

// Nombre del usuario filtrado (si aplica)
$nomUsuarioFiltro = '';
if ($usuarioGet !== '') {
    $uid = (int)$usuarioGet;
    if ($q = $conn->prepare("SELECT nombre FROM usuarios WHERE id=? LIMIT 1")) {
        $q->bind_param("i", $uid);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $nomUsuarioFiltro = $r['nombre'] ?? ('ID '.$uid);
        $q->close();
    }
}

// Textos de filtros
$txtTipoVenta = ($tipoVentaGet !== '') ? $tipoVentaGet : 'Todas';
$txtUsuario   = ($usuarioGet !== '') ? ($nomUsuarioFiltro ?: ('ID '.(int)$usuarioGet)) : 'Todos';

$txtRolLinea  = $rol;
if ($rol === 'Gerente' && $nomSucursalActual !== '') {
    $txtRolLinea .= " (Sucursal: {$nomSucursalActual})";
}
$txtGenerado  = (new DateTime())->format('Y-m-d H:i:s');
$txtPeriodo   = $inicioSemanaObj->format('d/m/Y') . " - " . $finSemanaObj->format('d/m/Y');

/* ========================
   Encabezado (tabla simple)
======================== */
echo "<table border='0' cellspacing='0' cellpadding='4'>";
echo "<tr><td colspan='2' style='font-weight:bold;font-size:16px'>Historial de Ventas de SIMs</td></tr>";
echo "<tr><td><strong>Periodo:</strong></td><td>".htmlspecialchars($txtPeriodo, ENT_QUOTES, 'UTF-8')."</td></tr>";
echo "<tr><td><strong>Generado por:</strong></td><td>".htmlspecialchars($nombreSesion, ENT_QUOTES, 'UTF-8')." &mdash; ".htmlspecialchars($txtRolLinea, ENT_QUOTES, 'UTF-8')."</td></tr>";
echo "<tr><td><strong>Fecha de generación:</strong></td><td>".htmlspecialchars($txtGenerado, ENT_QUOTES, 'UTF-8')."</td></tr>";
echo "<tr><td><strong>Filtro tipo de venta:</strong></td><td>".htmlspecialchars($txtTipoVenta, ENT_QUOTES, 'UTF-8')."</td></tr>";
echo "<tr><td><strong>Filtro usuario:</strong></td><td>".htmlspecialchars($txtUsuario, ENT_QUOTES, 'UTF-8')."</td></tr>";
echo "</table>";

echo "<br/>"; // separación visual

/* ========================
   Render tabla de datos
======================== */
echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>
            <th>Teléfono</th>
            <th>ICCID / Tipo</th>
            <th>Operador</th>
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
    $tipoVenta  = (string)($row['tipo_venta'] ?? '');
    $modalidad  = ($tipoVenta === 'Pospago') ? ($row['modalidad'] ?? '') : '';
    $cliente    = $row['nombre_cliente'] ?? '';
    $telefono   = ($tipoVenta === 'Pospago') ? (string)($row['numero_cliente'] ?? '') : '';
    $coment     = $row['comentarios'] ?? '';

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
    echo td((int)$row['id_venta']);
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

echo "</tbody></table>";
exit;
