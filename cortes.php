<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Ejecutivo') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_usuario = $_SESSION['id_usuario'];
$id_sucursal = $_SESSION['id_sucursal'];

// 🔹 Verificar si hay corte pendiente
$sqlPendiente = "
    SELECT COUNT(*) AS pendientes 
    FROM cortes_caja 
    WHERE id_sucursal = ? 
      AND estado = 'Pendiente'
";
$stmtPendiente = $conn->prepare($sqlPendiente);
$stmtPendiente->bind_param("i", $id_sucursal);
$stmtPendiente->execute();
$pendiente = $stmtPendiente->get_result()->fetch_assoc()['pendientes'] ?? 0;

// 🔹 Obtener cobros pendientes de corte
$sqlCobros = "
    SELECT *
    FROM cobros
    WHERE id_usuario = ?
      AND id_sucursal = ?
      AND id_corte IS NULL
    ORDER BY fecha_cobro ASC
";
$stmtCobros = $conn->prepare($sqlCobros);
$stmtCobros->bind_param("ii", $id_usuario, $id_sucursal);
$stmtCobros->execute();
$cobros = $stmtCobros->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔹 Calcular totales
$total_efectivo = 0;
$total_tarjeta = 0;

foreach ($cobros as $c) {
    $total_efectivo += $c['monto_efectivo'];
    $total_tarjeta += $c['monto_tarjeta'];
}

$total_dia = $total_efectivo + $total_tarjeta;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de Caja</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Corte de Caja</h2>
    <p>Sucursal: <b><?= $_SESSION['id_sucursal'] ?></b> | Usuario: <b><?= $_SESSION['nombre'] ?></b></p>

    <?php if ($pendiente > 0): ?>
        <div class="alert alert-warning">
            ⚠ Ya existe un corte pendiente en esta sucursal.  
            No podrás capturar nuevos cobros hasta que se cierre el corte.
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5>Total efectivo: $<?= number_format($total_efectivo, 2) ?></h5>
            <h5>Total tarjeta: $<?= number_format($total_tarjeta, 2) ?></h5>
            <h4 class="text-primary">Total del día: $<?= number_format($total_dia, 2) ?></h4>
        </div>
    </div>

    <?php if (count($cobros) > 0): ?>
        <form action="generar_corte.php" method="POST">
            <table class="table table-bordered table-sm">
    <thead class="table-dark">
        <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Motivo</th>
            <th>Monto Efectivo</th>
            <th>Monto Tarjeta</th>
            <th>Total</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cobros as $c): ?>
            <tr>
                <td><?= $c['id'] ?></td>
                <td><?= $c['fecha_cobro'] ?></td>
                <td><?= htmlspecialchars($c['motivo'], ENT_QUOTES) ?></td>
                <td>$<?= number_format($c['monto_efectivo'], 2) ?></td>
                <td>$<?= number_format($c['monto_tarjeta'], 2) ?></td>
                <td>$<?= number_format($c['monto_efectivo'] + $c['monto_tarjeta'], 2) ?></td>
                <td>
                    
                    <a href="eliminar_cobro.php?id=<?= $c['id'] ?>" class="btn btn-danger btn-sm"
                       onclick="return confirm('¿Seguro que deseas eliminar este cobro?')">🗑 Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

            <button type="submit" class="btn btn-success"
                onclick="return confirm('¿Confirmas generar el corte de caja del día?')">
                ✅ Generar Corte de Caja
            </button>
        </form>
    <?php else: ?>
        <div class="alert alert-info">No tienes cobros pendientes para corte.</div>
    <?php endif; ?>
</div>

</body>
</html>
