<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = "";

// =====================
// INSERTAR O ACTUALIZAR
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $anio = (int)$_POST['anio'];
    $mes = (int)$_POST['mes'];
    $cuota_unidades = (int)$_POST['cuota_unidades'];
    $cuota_monto = (float)$_POST['cuota_monto'];

    // Si ya existe registro para ese mes/año, actualizamos
    $sqlCheck = "SELECT id FROM cuotas_mensuales_ejecutivos WHERE anio=? AND mes=? LIMIT 1";
    $stmt = $conn->prepare($sqlCheck);
    $stmt->bind_param("ii", $anio, $mes);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $id = $row['id'];
        $sqlUpdate = "UPDATE cuotas_mensuales_ejecutivos 
                      SET cuota_unidades=?, cuota_monto=?, fecha_registro=NOW() 
                      WHERE id=?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("idi", $cuota_unidades, $cuota_monto, $id);
        $stmtUpdate->execute();
        $msg = "✅ Cuota mensual actualizada correctamente.";
    } else {
        $sqlInsert = "INSERT INTO cuotas_mensuales_ejecutivos (anio, mes, cuota_unidades, cuota_monto)
                      VALUES (?,?,?,?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        $stmtInsert->bind_param("iiid", $anio, $mes, $cuota_unidades, $cuota_monto);
        $stmtInsert->execute();
        $msg = "✅ Nueva cuota mensual registrada correctamente.";
    }
}

// =====================
// LISTADO DE CUOTAS
// =====================
$sql = "SELECT * FROM cuotas_mensuales_ejecutivos ORDER BY anio DESC, mes DESC";
$cuotas = $conn->query($sql);

// =====================
// FUNCIÓN NOMBRE MES
// =====================
function nombreMes($mes) {
    $meses = [
        1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril",
        5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto",
        9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"
    ];
    return $meses[$mes] ?? "Mes $mes";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuotas Mensuales Ejecutivos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📆 Cuotas Mensuales para Ejecutivos</h2>
    <?php if($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <!-- Formulario para agregar/editar -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">Registrar / Editar Cuota</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-2">
                    <label>Año</label>
                    <input type="number" name="anio" class="form-control" value="<?= date('Y') ?>" required>
                </div>
                <div class="col-md-3">
                    <label>Mes</label>
                    <select name="mes" class="form-select" required>
                        <?php for($i=1;$i<=12;$i++): ?>
                            <option value="<?= $i ?>"><?= nombreMes($i) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Cuota en Unidades</label>
                    <input type="number" name="cuota_unidades" class="form-control" required min="0">
                </div>
                <div class="col-md-3">
                    <label>Cuota en Monto ($)</label>
                    <input type="number" step="0.01" name="cuota_monto" class="form-control" min="0">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-success w-100">💾 Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Listado de cuotas -->
    <div class="card shadow">
        <div class="card-header bg-primary text-white">Historial de Cuotas</div>
        <div class="card-body p-0">
            <table class="table table-striped table-bordered table-sm mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Año</th>
                        <th>Mes</th>
                        <th>Cuota Unidades</th>
                        <th>Cuota Monto ($)</th>
                        <th>Fecha Registro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $cuotas->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['anio'] ?></td>
                        <td><?= nombreMes($row['mes']) ?></td>
                        <td><?= $row['cuota_unidades'] ?></td>
                        <td>$<?= number_format($row['cuota_monto'],2) ?></td>
                        <td><?= $row['fecha_registro'] ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>
