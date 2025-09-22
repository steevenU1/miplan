<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=inventario_sucursal.xls");
header("Pragma: no-cache");
header("Expires: 0");

$rol = $_SESSION['rol'];
$id_sucursal = $_SESSION['id_sucursal'] ?? 0;

$where = "WHERE i.estatus IN ('Disponible','En tránsito')";
$params = [];
$types = "";

if ($rol != 'Admin') {
    $where .= " AND i.id_sucursal = ?";
    $params[] = $id_sucursal;
    $types .= "i";
}

// Consulta del inventario
$sql = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, 
           p.imei1, p.imei2, i.estatus, i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    $where
    ORDER BY i.fecha_ingreso DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Encabezados
echo "ID\tMarca\tModelo\tColor\tCapacidad\tIMEI1\tIMEI2\tEstatus\tFecha Ingreso\n";

// Filas
while ($row = $result->fetch_assoc()) {
    echo $row['id']."\t".$row['marca']."\t".$row['modelo']."\t".$row['color']."\t".
         $row['capacidad']."\t".$row['imei1']."\t".$row['imei2']."\t".
         $row['estatus']."\t".$row['fecha_ingreso']."\n";
}

$stmt->close();
$conn->close();
?>
