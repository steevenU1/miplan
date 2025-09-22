<?php
include 'db.php';

// Semana seleccionada
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;

// Función de semana martes-lunes
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N');
    $dif = $diaSemana - 2;
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days");
    $inicio->setTime(0,0,0);

    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days");
    $fin->setTime(23,59,59);

    return [$inicio, $fin];
}

list($inicioObj, $finObj) = obtenerSemanaPorIndice($semana);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// Query ejecutivos
$sql = "
    SELECT u.nombre AS Ejecutivo, s.nombre AS Sucursal,
           COUNT(dv.id) AS Unidades,
           IFNULL(SUM(dv.comision),0) AS Comision_Total
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v ON v.id_usuario = u.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    GROUP BY u.id
    ORDER BY Unidades DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$result = $stmt->get_result();

// Encabezados para Excel
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Ejecutivos_Semana_'.$inicioSemana.'_al_'.$finSemana.'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Ejecutivo', 'Sucursal', 'Unidades', 'Comision Total']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
exit;
