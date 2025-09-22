<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$id_cobro = $_GET['id'] ?? 0;

// 🔹 Obtener el cobro
$stmt = $conn->prepare("
    SELECT * 
    FROM cobros 
    WHERE id=? AND id_corte IS NULL
");
$stmt->bind_param("i", $id_cobro);
$stmt->execute();
$cobro = $stmt->get_result()->fetch_assoc();

if (!$cobro) {
    echo "<div class='alert alert-danger'>Cobro no encontrado o ya pertenece a un corte.</div>";
    exit();
}

// 🔹 Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motivo = $_POST['motivo'];
    $monto_efectivo = floatval($_POST['monto_efectivo'] ?? 0);
    $monto_tarjeta = floatval($_POST['monto_tarjeta'] ?? 0);

    // Validación de total
    $total = $monto_efectivo + $monto_tarjeta;
    if ($total <= 0) {
        $error = "El monto total debe ser mayor a 0.";
    } else {
        $stmtUpd = $conn->prepare("
            UPDATE cobros 
            SET motivo=?, monto_efectivo=?, monto_tarjeta=? 
            WHERE id=? AND id_corte IS NULL
        ");
        $stmtUpd->bind_param("sddi", $motivo, $monto_efectivo, $monto_tarjeta, $id_cobro);
        $stmtUpd->execute();
        header("Location: cobros.php?msg=editado");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Cobro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">

<div class="container">
    <h3>✏ Editar Cobro #<?= $cobro['id'] ?></h3>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Motivo</label>
            <select name="motivo" class="form-select" required>
                <option <?= $cobro['motivo']=='Enganche'?'selected':'' ?>>Enganche</option>
                <option <?= $cobro['motivo']=='Equipo de contado'?'selected':'' ?>>Equipo de contado</option>
                <option <?= $cobro['motivo']=='Venta SIM'?'selected':'' ?>>Venta SIM</option>
                <option <?= $cobro['motivo']=='Recarga Tiempo aire'?'selected':'' ?>>Recarga Tiempo aire</option>
                <option <?= $cobro['motivo']=='Abono Payjoy'?'selected':'' ?>>Abono Payjoy</option>
                <option <?= $cobro['motivo']=='Abono Krediya'?'selected':'' ?>>Abono Krediya</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Monto en Efectivo</label>
            <input type="number" step="0.01" name="monto_efectivo" class="form-control"
                   value="<?= $cobro['monto_efectivo'] ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Monto en Tarjeta</label>
            <input type="number" step="0.01" name="monto_tarjeta" class="form-control"
                   value="<?= $cobro['monto_tarjeta'] ?>">
        </div>

        <button class="btn btn-primary" type="submit">💾 Guardar Cambios</button>
        <a href="cobros.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

</body>
</html>
