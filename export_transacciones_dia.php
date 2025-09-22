<?php
// Exporta todas las transacciones (tabla cobros) de un día dado en CSV
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    http_response_code(403);
    echo "No autorizado";
    exit();
}

require 'db.php';

$dia = $_GET['dia'] ?? '';
if (!$dia || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
    echo "Día inválido. Usa formato YYYY-MM-DD";
    exit();
}

// Traemos cobros de ese día, con sucursal y ejecutivo
$sql = "
  SELECT 
    c.id,
    DATE(c.fecha_cobro) AS fecha,
    TIME(c.fecha_cobro) AS hora,
    s.nombre AS sucursal,
    u.nombre AS ejecutivo,
    c.motivo,
    c.tipo_pago,
    c.monto_total,
    c.monto_efectivo,
    c.monto_tarjeta,
    c.comision_especial,
    c.corte_generado,
    c.id_corte
  FROM cobros c
  LEFT JOIN sucursales s ON s.id = c.id_sucursal
  LEFT JOIN usuarios   u ON u.id = c.id_usuario
  WHERE DATE(c.fecha_cobro) = ?
  ORDER BY s.nombre, c.fecha_cobro, c.id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $dia);
$stmt->execute();
$res = $stmt->get_result();

$filename = "transacciones_$dia.csv";
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$out = fopen('php://output', 'w');

// BOM para Excel
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
fputcsv($out, [
  'ID Cobro','Fecha','Hora','Sucursal','Ejecutivo',
  'Motivo','Tipo Pago','Total','Efectivo','Tarjeta',
  'Comisión Especial','Corte Generado','ID Corte'
]);

while ($r = $res->fetch_assoc()) {
  fputcsv($out, [
    $r['id'],
    $r['fecha'],
    $r['hora'],
    $r['sucursal'],
    $r['ejecutivo'],
    $r['motivo'],
    $r['tipo_pago'],
    number_format((float)$r['monto_total'], 2, '.', ''),
    number_format((float)$r['monto_efectivo'], 2, '.', ''),
    number_format((float)$r['monto_tarjeta'], 2, '.', ''),
    number_format((float)$r['comision_especial'], 2, '.', ''),
    (int)$r['corte_generado'],
    (int)$r['id_corte']
  ]);
}
fclose($out);
exit();
