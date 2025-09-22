<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = "";
$editMode = false;
$editData = null;

if (isset($_GET['edit_id'])) {
    $editMode = true;
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $conn->prepare("SELECT * FROM cuotas_mensuales WHERE id=?");
    $stmtEdit->bind_param("i", $editId);
    $stmtEdit->execute();
    $editData = $stmtEdit->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_sucursal'])) {
    $id_sucursal = (int)$_POST['id_sucursal'];
    $anio = (int)$_POST['anio'];
    $mes = (int)$_POST['mes'];
    $cuota_unidades = (int)$_POST['cuota_unidades'];
    $cuota_monto = (float)$_POST['cuota_monto'];

    if (!empty($_POST['edit_id'])) {
        $editId = (int)$_POST['edit_id'];
        $stmt = $conn->prepare("UPDATE cuotas_mensuales 
                                SET id_sucursal=?, anio=?, mes=?, cuota_unidades=?, cuota_monto=? 
                                WHERE id=?");
        $stmt->bind_param("iiiidi", $id_sucursal, $anio, $mes, $cuota_unidades, $cuota_monto, $editId);
        if ($stmt->execute()) {
            $msg = "✅ Cuota actualizada correctamente.";
            $editMode = false;
            $editData = null;
        } else {
            $msg = "❌ Error al actualizar cuota: " . $conn->error;
        }
    } else {
        $check = $conn->prepare("SELECT id FROM cuotas_mensuales WHERE id_sucursal=? AND anio=? AND mes=?");
        $check->bind_param("iii", $id_sucursal, $anio, $mes);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $msg = "⚠️ Ya existe una cuota para esa sucursal en ese mes/año.";
        } else {
            $stmt = $conn->prepare("INSERT INTO cuotas_mensuales (id_sucursal, anio, mes, cuota_unidades, cuota_monto) VALUES (?,?,?,?,?)");
            $stmt->bind_param("iiiid", $id_sucursal, $anio, $mes, $cuota_unidades, $cuota_monto);
            if ($stmt->execute()) {
                $msg = "✅ Cuota registrada correctamente.";
            } else {
                $msg = "❌ Error al registrar cuota: " . $conn->error;
            }
        }
    }
}

$anioSel = $_GET['anio'] ?? date('Y');
$mesSel = $_GET['mes'] ?? date('n');

$sql = "
    SELECT cm.*, s.nombre AS sucursal
    FROM cuotas_mensuales cm
    INNER JOIN sucursales s ON s.id = cm.id_sucursal
    WHERE cm.anio=? AND cm.mes=?
    ORDER BY s.nombre
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $anioSel, $mesSel);
$stmt->execute();
$result = $stmt->get_result();

$sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuotas Mensuales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="text-primary fw-bold">📅 Gestión de Cuotas Mensuales</h3>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <?= $editMode ? "✏️ Editar Cuota" : "➕ Agregar Nueva Cuota" ?>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="edit_id" value="<?= $editMode ? $editData['id'] : '' ?>">

                <div class="col-md-4">
                    <label class="form-label">Sucursal</label>
                    <select name="id_sucursal" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php 
                        $sucursales->data_seek(0);
                        while($s = $sucursales->fetch_assoc()): 
                        ?>
                            <option value="<?= $s['id'] ?>" 
                                <?= $editMode && $editData['id_sucursal']==$s['id']?'selected':'' ?>>
                                <?= $s['nombre'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Año</label>
                    <input type="number" name="anio" value="<?= $editMode?$editData['anio']:date('Y') ?>" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mes</label>
                    <input type="number" name="mes" min="1" max="12" value="<?= $editMode?$editData['mes']:date('n') ?>" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cuota Unidades</label>
                    <input type="number" name="cuota_unidades" min="0" value="<?= $editMode?$editData['cuota_unidades']:'' ?>" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cuota Monto ($)</label>
                    <input type="number" step="0.01" name="cuota_monto" min="0" value="<?= $editMode?$editData['cuota_monto']:'' ?>" class="form-control" required>
                </div>

                <div class="col-12 text-end mt-2">
                    <button class="btn btn-success"><?= $editMode ? "💾 Actualizar" : "💾 Guardar" ?></button>
                    <?php if($editMode): ?>
                        <a href="cuotas_mensuales.php" class="btn btn-secondary ms-2">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <form method="GET" class="row row-cols-auto g-2 align-items-center">
                <div class="col">
                    <label class="form-label mb-0">Mes:</label>
                    <select name="mes" class="form-select">
                        <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?= $m ?>" <?= $m==$mesSel?'selected':'' ?>><?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col">
                    <label class="form-label mb-0">Año:</label>
                    <input type="number" name="anio" value="<?= $anioSel ?>" class="form-control">
                </div>
                <div class="col mt-4">
                    <button class="btn btn-outline-primary">🔍 Filtrar</button>
                </div>
            </form>
        </div>
        <div class="card-body">
            <h5 class="mb-3 text-dark">📋 Cuotas Registradas <?= $mesSel ?>/<?= $anioSel ?></h5>
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Sucursal</th>
                        <th>Unidades</th>
                        <th>Monto ($)</th>
                        <th>Fecha Registro</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= $row['sucursal'] ?></td>
                                <td><?= $row['cuota_unidades'] ?></td>
                                <td>$<?= number_format($row['cuota_monto'],2) ?></td>
                                <td><?= $row['fecha_registro'] ?></td>
                                <td class="text-center">
                                    <a href="cuotas_mensuales.php?edit_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning">✏️ Editar</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No hay cuotas registradas para este mes/año.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
