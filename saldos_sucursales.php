<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// 🔹 Consulta saldos por sucursal
$sql = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        -- Total de efectivo cobrado y aún no depositado
        IFNULL(SUM(CASE 
            WHEN c.tipo_pago IN ('Efectivo','Mixto') AND c.corte_generado = 1 
            THEN c.monto_efectivo ELSE 0 END),0) 
            - 
        IFNULL((SELECT SUM(d.monto_validado) 
                FROM depositos_sucursal d 
                WHERE d.id_sucursal = s.id 
                  AND d.estado IN ('Confirmado','Parcial')),0)
        AS saldo_pendiente,

        -- Total depositado
        IFNULL((SELECT SUM(d.monto_validado) 
                FROM depositos_sucursal d 
                WHERE d.id_sucursal = s.id 
                  AND d.estado IN ('Confirmado','Parcial')),0) AS total_depositado,

        -- Total comisiones especiales pendientes
        IFNULL(SUM(c.comision_especial),0)
        -
        IFNULL((SELECT SUM(e.monto_entregado) 
                FROM entregas_comisiones_especiales e
                WHERE e.id_sucursal = s.id),0) AS comisiones_pendientes

    FROM sucursales s
    LEFT JOIN cobros c ON c.id_sucursal = s.id
    GROUP BY s.id
    ORDER BY s.nombre
";
$result = $conn->query($sql);
$saldos = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>🏦 Saldos de Sucursales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🏦 Saldos de Sucursales</h2>
    <p>Resumen de efectivo pendiente por depositar y comisiones especiales por entregar.</p>

    <table class="table table-bordered table-striped table-sm align-middle">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>💵 Saldo Pendiente Efectivo</th>
                <th>🏧 Total Depositado</th>
                <th>💰 Comisiones Pendientes</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($saldos as $s): 
                $estado = $s['saldo_pendiente'] > 0 ? '⚠ Pendiente' : '✅ Al día';
                $color = $s['saldo_pendiente'] > 0 ? 'text-danger fw-bold' : 'text-success fw-bold';
            ?>
            <tr>
                <td><?= htmlspecialchars($s['sucursal']) ?></td>
                <td class="<?= $color ?>">$<?= number_format($s['saldo_pendiente'],2) ?></td>
                <td>$<?= number_format($s['total_depositado'],2) ?></td>
                <td>$<?= number_format($s['comisiones_pendientes'],2) ?></td>
                <td><?= $estado ?></td>
                <td>
                    <a href="validar_depositos.php?id_sucursal=<?= $s['id_sucursal'] ?>" class="btn btn-sm btn-primary">
                        ✅ Validar Depósitos
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
