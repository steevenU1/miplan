<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_sucursal = $_SESSION['id_sucursal'] ?? 0;
$msg = "";

// 🔹 Guardar depósito
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_corte = (int)$_POST['id_corte'];
    $fecha_deposito = $_POST['fecha_deposito'] ?? date('Y-m-d H:i:s');
    $banco = $_POST['banco'] ?? '';
    $referencia = $_POST['referencia'] ?? '';
    $monto_depositado = (float)$_POST['monto_depositado'];
    $observaciones = $_POST['observaciones'] ?? '';

    // 🔹 Validar monto efectivo del corte
    $stmt = $conn->prepare("
        SELECT 
            SUM(monto_efectivo) AS total_efectivo 
        FROM cobros 
        WHERE id_corte = ? 
    ");
    $stmt->bind_param("i", $id_corte);
    $stmt->execute();
    $total_efectivo = (float)($stmt->get_result()->fetch_assoc()['total_efectivo'] ?? 0);

    if ($monto_depositado > $total_efectivo) {
        $msg = "<div class='alert alert-danger'>El monto depositado no puede ser mayor al efectivo del corte ($total_efectivo).</div>";
    } else {
        // 🔹 Insertar depósito
        $stmt = $conn->prepare("
            INSERT INTO depositos_sucursal
            (id_sucursal, id_corte, fecha_deposito, monto_depositado, banco, referencia, monto_validado, estado, observaciones)
            VALUES (?, ?, ?, ?, ?, ?, 0, 'Pendiente', ?)
        ");
        $stmt->bind_param("iisddss", $id_sucursal, $id_corte, $fecha_deposito, $monto_depositado, $banco, $referencia, $observaciones);

        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>Depósito registrado correctamente.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Error al registrar el depósito.</div>";
        }
    }
}

// 🔹 Obtener cortes pendientes de depósito
$sqlCortes = "
    SELECT c.id, c.fecha_corte,
           SUM(cob.monto_efectivo) AS efectivo,
           SUM(cob.monto_tarjeta) AS tarjeta
    FROM cortes_caja c
    LEFT JOIN cobros cob ON cob.id_corte = c.id
    WHERE c.id_sucursal = ? 
    GROUP BY c.id
    ORDER BY c.fecha_corte DESC
";
$stmtC = $conn->prepare($sqlCortes);
$stmtC->bind_param("i", $id_sucursal);
$stmtC->execute();
$cortes = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Depósito Bancario</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>🏦 Registrar Depósito Bancario</h2>
    <p>Depósitos de la sucursal: <b><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></b></p>
    <?= $msg ?>

    <form method="POST" class="card p-3 mb-4 shadow">
        <div class="mb-3">
            <label class="form-label">Corte de Caja</label>
            <select name="id_corte" class="form-select" required>
                <option value="">Seleccione un corte</option>
                <?php foreach ($cortes as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        Corte #<?= $c['id'] ?> | Fecha: <?= $c['fecha_corte'] ?> | 
                        Efectivo: $<?= number_format($c['efectivo'],2) ?> | 
                        Tarjeta: $<?= number_format($c['tarjeta'],2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Fecha Depósito</label>
            <input type="datetime-local" name="fecha_deposito" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Banco</label>
            <input type="text" name="banco" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Referencia</label>
            <input type="text" name="referencia" class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Monto Depositado</label>
            <input type="number" step="0.01" name="monto_depositado" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control"></textarea>
        </div>

        <button type="submit" class="btn btn-success">💾 Registrar Depósito</button>
    </form>
</div>

</body>
</html>
