<?php
include 'db.php';

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;

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

$sql = "
    SELECT s.nombre AS Sucursal, s.zona AS Zona,
           COUNT(dv.id) AS Unidades,
           IFNULL(SUM(v.precio_venta),0) AS Total_Ventas,
           s.cuota_semanal AS Cuota_Semanal
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.precio_venta, v.fecha_venta
        FROM ventas v
        WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ) v ON v.id_sucursal = s.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    GROUP BY s.id
    ORDER BY Total_Ventas DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$result = $stmt->get_result();

// Encabezados para Excel
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Sucursales_Semana_'.$inicioSemana.'_al_'.$finSemana.'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Sucursal', 'Zona', 'Unidades', 'Total Ventas', 'Cuota Semanal']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
exit;
