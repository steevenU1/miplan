<?php
/**
 * exportar_ventas_csv.php
 * Exporta todas las ventas con nombre de sucursal y ejecutivo
 */

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

// Forzar descarga CSV
$filename = 'ventas_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM para Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Encabezados del CSV
fputcsv($output, [
    'ID Venta',
    'TAG',
    'Tipo de Venta',
    'Precio Venta',
    'Fecha Venta',

    'Cliente',
    'Teléfono',

    'Sucursal',
    'Ejecutivo',

    'Enganche',
    'Forma Pago Enganche',
    'Enganche Efectivo',
    'Enganche Tarjeta',
    'Plazo (Semanas)',
    'Financiera',

    'Comisión Ejecutivo',
    'Comisión Especial',
    'Comisión Gerente',

    'Comentarios'
]);

// Query "vista temporal"
$sql = "
SELECT
    v.id,
    v.tag,
    v.tipo_venta,
    v.precio_venta,
    v.fecha_venta,

    v.nombre_cliente,
    v.telefono_cliente,

    s.nombre AS nombre_sucursal,
    u.nombre AS nombre_ejecutivo,

    v.enganche,
    v.forma_pago_enganche,
    v.enganche_efectivo,
    v.enganche_tarjeta,
    v.plazo_semanas,
    v.financiera,

    v.comision,
    v.comision_especial,
    v.comision_gerente,

    v.comentarios

FROM ventas v
LEFT JOIN sucursales s ON s.id = v.id_sucursal
LEFT JOIN usuarios u   ON u.id = v.id_usuario
ORDER BY v.fecha_venta DESC
";

$result = $conn->query($sql);

// Volcar datos al CSV
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['tag'],
        $row['tipo_venta'],
        $row['precio_venta'],
        $row['fecha_venta'],

        $row['nombre_cliente'],
        $row['telefono_cliente'],

        $row['nombre_sucursal'],
        $row['nombre_ejecutivo'],

        $row['enganche'],
        $row['forma_pago_enganche'],
        $row['enganche_efectivo'],
        $row['enganche_tarjeta'],
        $row['plazo_semanas'],
        $row['financiera'],

        $row['comision'],
        $row['comision_especial'],
        $row['comision_gerente'],

        $row['comentarios']
    ]);
}

fclose($output);
exit;
