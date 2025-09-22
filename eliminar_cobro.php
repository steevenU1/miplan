<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$id_cobro = $_GET['id'] ?? 0;

// 🔹 Verificar que el cobro exista y no tenga corte
$stmt = $conn->prepare("SELECT * FROM cobros WHERE id=? AND id_corte IS NULL");
$stmt->bind_param("i", $id_cobro);
$stmt->execute();
$cobro = $stmt->get_result()->fetch_assoc();

if (!$cobro) {
    echo "<div class='alert alert-danger'>Cobro no encontrado o ya pertenece a un corte.</div>";
    exit();
}

// 🔹 Si se confirma, eliminar
if (isset($_POST['confirmar'])) {
    $stmtDel = $conn->prepare("DELETE FROM cobros WHERE id=? AND id_corte IS NULL");
    $stmtDel->bind_param("i", $id_cobro);
    $stmtDel->execute();
    header("Location: cobros.php?msg=eliminado");
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Eliminar Cobro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">

<div class="container">
    <h3>🗑 Eliminar Cobro #<?= $cobro['id'] ?></h3>
    <p><strong>Motivo:</strong> <?= $cobro['motivo'] ?></p>
    <p><strong>Total:</strong> $<?= number_format($cobro['monto_efectivo'] + $cobro['monto_tarjeta'], 2) ?></p>

    <form method="POST">
        <button class="btn btn-danger" name="confirmar" type="submit">Sí, eliminar</button>
        <a href="cobros.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

</body>
</html>
