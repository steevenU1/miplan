<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'GerenteZona') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_gerente = $_SESSION['id_usuario'];

// 🔹 Obtener sucursales de la zona del gerente
$sqlSuc = "
    SELECT s.id, s.nombre 
    FROM sucursales s
    INNER JOIN usuarios u ON s.zona = (
        SELECT zona FROM sucursales sz WHERE sz.id = u.id_sucursal
    )
    WHERE u.id = ?
    ORDER BY s.nombre
";
$stmtSuc = $conn->prepare($sqlSuc);
$stmtSuc->bind_param("i", $id_gerente);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔹 Procesar entrega
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_sucursal = $_POST['id_sucursal'];
    $monto = $_POST['monto'];
    $obs = $_POST['observaciones'] ?? '';

    $sqlInsert = "
        INSERT INTO entregas_comisiones_especiales (id_sucursal, id_gerentezona, monto_entregado, observaciones)
        VALUES (?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sqlInsert);
    $stmt->bind_param("iids", $id_sucursal, $id_gerente, $monto, $obs);
    $stmt->execute();

    $mensaje = "✅ Entrega registrada correctamente.";
}

// 🔹 Obtener acumulado de comisiones especiales por sucursal
$sqlPendientes = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal,
           IFNULL(SUM(c.comision_especial),0) AS comisiones_acumuladas
    FROM sucursales s
    LEFT JOIN cobros c 
        ON c.id_sucursal = s.id 
       AND c.corte_generado=1 
       AND c.id_corte IS NOT NULL
    WHERE s.tipo_sucursal <> 'Almacen'
    GROUP BY s.id
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrega Comisiones Especiales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💵 Entrega de Comisiones Especiales</h2>
    <?php if($mensaje): ?>
        <div class="alert alert-success"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-3 mb-4">
        <div class="mb-3">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($sucursales as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['nombre'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Monto entregado</label>
            <input type="number" step="0.01" name="monto" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Registrar Entrega</button>
    </form>

    <h4>📊 Acumulado de Comisiones por Sucursal</h4>
    <table class="table table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>Sucursal</th>
                <th>Comisiones Acumuladas ($)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendientes as $p): ?>
            <tr>
                <td><?= $p['sucursal'] ?></td>
                <td>$<?= number_format($p['comisiones_acumuladas'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
