<?php
include 'db.php';

// 🔹 Semana seleccionada
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;

// 🔹 Función para obtener inicio y fin de semana (martes-lunes)
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N');
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days");
    $inicio->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7*$offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days");
    $fin->setTime(23,59,59);

    return [$inicio, $fin];
}

list($inicioObj, $finObj) = obtenerSemanaPorIndice($semana);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// 🔹 Query para traer todas las ventas con detalle y comisiones
$sql = "
    SELECT 
        u.nombre AS Ejecutivo,
        u.rol AS Rol,
        s.nombre AS Sucursal,
        v.tag AS TAG,
        v.nombre_cliente AS Cliente,
        CONCAT(p.marca,' ',p.modelo,' ',p.color) AS Producto,
        dv.precio_unitario AS Precio,
        dv.comision AS Comision_Ejecutivo,
        v.comision_gerente AS Comision_Gerente,
        DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS Fecha_Venta
    FROM ventas v
    INNER JOIN usuarios u ON u.id = v.id_usuario
    INNER JOIN sucursales s ON s.id = v.id_sucursal
    INNER JOIN detalle_venta dv ON dv.id_venta = v.id
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ORDER BY s.nombre, u.nombre, v.fecha_venta
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$result = $stmt->get_result();

// 🔹 Encabezados para Excel
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Nomina_Semana_'.$inicioSemana.'_al_'.$finSemana.'.csv"');

$output = fopen('php://output', 'w');

// 🔹 Encabezados del CSV
fputcsv($output, [
    'Ejecutivo', 'Rol', 'Sucursal', 'TAG', 'Cliente', 
    'Producto', 'Precio', 'Comision Ejecutivo', 'Comision Gerente', 'Fecha Venta'
]);

// 🔹 Variables para resumen
$resumenUsuarios = [];
$totalGeneral = 0;

// 🔹 Exportar filas y acumular comisiones
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['Ejecutivo'],
        $row['Rol'],
        $row['Sucursal'],
        $row['TAG'],
        $row['Cliente'],
        $row['Producto'],
        number_format($row['Precio'], 2, '.', ''),
        number_format($row['Comision_Ejecutivo'], 2, '.', ''),
        number_format($row['Comision_Gerente'], 2, '.', ''),
        $row['Fecha_Venta']
    ]);

    $usuario = $row['Ejecutivo'];
    $totalComision = $row['Comision_Ejecutivo'] + $row['Comision_Gerente'];
    $totalGeneral += $totalComision;

    if (!isset($resumenUsuarios[$usuario])) {
        $resumenUsuarios[$usuario] = 0;
    }
    $resumenUsuarios[$usuario] += $totalComision;
}

// 🔹 Línea vacía
fputcsv($output, []);
fputcsv($output, ['Resumen de Comisiones por Usuario']);
fputcsv($output, ['Ejecutivo', 'Total Comisiones']);

// 🔹 Resumen por usuario
foreach ($resumenUsuarios as $usuario => $total) {
    fputcsv($output, [$usuario, number_format($total, 2, '.', '')]);
}

// 🔹 Total general
fputcsv($output, []);
fputcsv($output, ['Total General de Comisiones', number_format($totalGeneral, 2, '.', '')]);

fclose($output);
exit;
