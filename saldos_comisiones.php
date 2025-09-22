<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','GerenteZona'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$rol = $_SESSION['rol'];
$id_usuario = $_SESSION['id_usuario'];

// 🔹 Determinar zona si es GerenteZona
$zonaGerente = '';
if ($rol == 'GerenteZona') {
    $sqlZona = "
        SELECT s.zona 
        FROM sucursales s
        INNER JOIN usuarios u ON u.id_sucursal = s.id
        WHERE u.id = ?
        LIMIT 1
    ";
    $stmtZona = $conn->prepare($sqlZona);
    $stmtZona->bind_param("i", $id_usuario);
    $stmtZona->execute();
    $zonaGerente = $stmtZona->get_result()->fetch_assoc()['zona'] ?? '';
}

// 🔹 Filtro SQL para las consultas
$filtroZona = $zonaGerente ? " WHERE s.zona = '$zonaGerente' " : "";

// ==========================
//  1️⃣ Saldos por Sucursal
// ==========================
$sqlSaldos = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        IFNULL(SUM(c.comision_especial),0) AS comisiones_acumuladas,
        IFNULL(SUM(e.monto_entregado),0) AS entregado,
        (IFNULL(SUM(c.comision_especial),0) - IFNULL(SUM(e.monto_entregado),0)) AS saldo_pendiente
    FROM sucursales s
    LEFT JOIN cobros c 
        ON c.id_sucursal = s.id
       AND c.corte_generado=1
    LEFT JOIN entregas_comisiones_especiales e 
        ON e.id_sucursal = s.id
    $filtroZona
    GROUP BY s.id
    ORDER BY s.nombre
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);

// ==========================
//  2️⃣ Historial de entregas
// ==========================
$whereHistorial = $zonaGerente ? "WHERE s.zona = '$zonaGerente'" : "";
$sqlHistorial = "
    SELECT e.id, s.nombre AS sucursal, u.nombre AS gerente, 
           e.monto_entregado, e.fecha_entrega, e.observaciones
    FROM entregas_comisiones_especiales e
    INNER JOIN sucursales s ON s.id=e.id_sucursal
    INNER JOIN usuarios u ON u.id=e.id_gerentezona
    $whereHistorial
    ORDER BY e.fecha_entrega DESC
";
$historial = $conn->query($sqlHistorial)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Saldos Comisiones Especiales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Saldos de Comisiones Especiales</h2>
    <p>Visualiza el saldo pendiente por sucursal y el historial de entregas de comisiones especiales.</p>

    <h4>📊 Saldos por Sucursal</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>Total Comisiones</th>
                <th>Total Entregado</th>
                <th>Saldo Pendiente</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($saldos as $s): ?>
            <tr>
                <td><?= $s['sucursal'] ?></td>
                <td>$<?= number_format($s['comisiones_acumuladas'],2) ?></td>
                <td>$<?= number_format($s['entregado'],2) ?></td>
                <td class="<?= $s['saldo_pendiente'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                    $<?= number_format($s['saldo_pendiente'],2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4 class="mt-4">📜 Historial de Entregas</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>Gerente Zona</th>
                <th>Monto Entregado</th>
                <th>Fecha</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td><?= $h['id'] ?></td>
                <td><?= $h['sucursal'] ?></td>
                <td><?= $h['gerente'] ?></td>
                <td>$<?= number_format($h['monto_entregado'],2) ?></td>
                <td><?= $h['fecha_entrega'] ?></td>
                <td><?= htmlspecialchars($h['observaciones'], ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
