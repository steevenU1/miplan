<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// 🔹 Confirmar o marcar depósito desde GET
$msg = '';
if (isset($_GET['accion']) && isset($_GET['id'])) {
    $idDeposito = intval($_GET['id']);
    $accion = $_GET['accion'];

    if ($accion == 'confirmar') {
        $stmt = $conn->prepare("UPDATE depositos_sucursal SET estado='Confirmado', fecha_confirmacion=NOW() WHERE id=?");
        $stmt->bind_param("i", $idDeposito);
        $stmt->execute();
        $msg = "<div class='alert alert-success'>✅ Depósito confirmado.</div>";
    } elseif ($accion == 'rechazar') {
        $stmt = $conn->prepare("UPDATE depositos_sucursal SET estado='Incompleto', fecha_confirmacion=NOW() WHERE id=?");
        $stmt->bind_param("i", $idDeposito);
        $stmt->execute();
        $msg = "<div class='alert alert-warning'>⚠ Depósito marcado como incompleto.</div>";
    }
}

// 🔹 Obtener resumen por sucursal
$sqlResumen = "
    SELECT 
        s.id AS id_sucursal,
        s.nombre AS sucursal,
        IFNULL(SUM(cc.total_efectivo),0) AS cobrado_efectivo,
        IFNULL(SUM(ds.monto_depositado),0) AS depositado,
        (IFNULL(SUM(cc.total_efectivo),0) - IFNULL(SUM(ds.monto_depositado),0)) AS pendiente
    FROM sucursales s
    LEFT JOIN cortes_caja cc ON cc.id_sucursal=s.id
    LEFT JOIN depositos_sucursal ds ON ds.id_corte=cc.id
    GROUP BY s.id
    ORDER BY s.nombre
";
$resumen = $conn->query($sqlResumen)->fetch_all(MYSQLI_ASSOC);

// 🔹 Obtener histórico de depósitos
$sqlHistorial = "
    SELECT ds.*, s.nombre AS sucursal, cc.fecha_corte
    FROM depositos_sucursal ds
    INNER JOIN sucursales s ON s.id=ds.id_sucursal
    INNER JOIN cortes_caja cc ON cc.id=ds.id_corte
    ORDER BY ds.fecha_deposito DESC
";
$historial = $conn->query($sqlHistorial)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Depósitos - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🏦 Control de Depósitos - Admin</h2>
    <?= $msg ?>

    <h4>📊 Resumen por Sucursal</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>Cobrado en Efectivo</th>
                <th>Depositado</th>
                <th>Pendiente</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resumen as $r): ?>
            <tr>
                <td><?= $r['sucursal'] ?></td>
                <td>$<?= number_format($r['cobrado_efectivo'],2) ?></td>
                <td>$<?= number_format($r['depositado'],2) ?></td>
                <td class="<?= $r['pendiente'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                    $<?= number_format($r['pendiente'],2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4 class="mt-4">📜 Histórico de Depósitos</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Sucursal</th>
                <th>ID Corte</th>
                <th>Fecha Corte</th>
                <th>Fecha Depósito</th>
                <th>Monto</th>
                <th>Banco</th>
                <th>Referencia</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td><?= $h['id'] ?></td>
                <td><?= $h['sucursal'] ?></td>
                <td><?= $h['id_corte'] ?></td>
                <td><?= $h['fecha_corte'] ?></td>
                <td><?= $h['fecha_deposito'] ?></td>
                <td>$<?= number_format($h['monto_depositado'],2) ?></td>
                <td><?= htmlspecialchars($h['banco'],ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($h['referencia'],ENT_QUOTES) ?></td>
                <td class="<?= $h['estado']=='Pendiente'?'text-warning':($h['estado']=='Confirmado'?'text-success':'text-danger') ?>">
                    <?= $h['estado'] ?>
                </td>
                <td>
                    <?php if ($h['estado'] == 'Pendiente'): ?>
                        <a href="?accion=confirmar&id=<?= $h['id'] ?>" class="btn btn-success btn-sm"
                           onclick="return confirm('¿Confirmar depósito?')">✔ Confirmar</a>
                        <a href="?accion=rechazar&id=<?= $h['id'] ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Marcar como incompleto?')">❌ Incompleto</a>
                    <?php else: ?>
                        <span class="text-muted">Sin acciones</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
