<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
$mensaje = '';

// Obtener esquemas para relacionar
$esquemas = $conn->query("SELECT id, fecha_inicio FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id_esquema = $_POST['id_esquema'] ?? 0;
    $tipo = $_POST['tipo'] ?? '';
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $plan_monto = $_POST['plan_monto'] ?? 0;
    $com_equipo = $_POST['com_equipo'] ?? 0;
    $com_sin_equipo = $_POST['com_sin_equipo'] ?? 0;

    if ($id_esquema && $tipo && $plan_monto>0) {
        // Validar duplicado
        $stmtCheck = $conn->prepare("
            SELECT 1 FROM esquemas_comisiones_pospago
            WHERE id_esquema=? AND tipo=? AND plan_monto=? LIMIT 1
        ");
        $stmtCheck->bind_param("isi", $id_esquema, $tipo, $plan_monto);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows>0) {
            $mensaje = "⚠️ Este plan ya existe para este esquema y tipo.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO esquemas_comisiones_pospago
                (id_esquema, tipo, fecha_inicio, plan_monto, comision_con_equipo, comision_sin_equipo)
                VALUES (?,?,?,?,?,?)
            ");
            $stmt->bind_param("issddd", $id_esquema, $tipo, $fecha_inicio, $plan_monto, $com_equipo, $com_sin_equipo);
            if ($stmt->execute()) {
                header("Location: esquemas_comisiones_pospago.php");
                exit();
            } else {
                $mensaje = "❌ Error al guardar: ".$conn->error;
            }
        }
    } else {
        $mensaje = "❌ Debes completar todos los campos obligatorios.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nuevo Plan Pospago</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>➕ Nuevo Plan Pospago</h2>
    <?php if($mensaje): ?><div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label><strong>Esquema de Comisiones:</strong></label>
            <select name="id_esquema" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php while($e=$esquemas->fetch_assoc()): ?>
                    <option value="<?= $e['id'] ?>">Inicio: <?= $e['fecha_inicio'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label><strong>Tipo:</strong></label>
            <select name="tipo" class="form-select" required>
                <option value="">Seleccione...</option>
                <option value="Ejecutivo">Ejecutivo</option>
                <option value="Gerente">Gerente</option>
            </select>
        </div>

        <div class="mb-3">
            <label><strong>Fecha de inicio de vigencia:</strong></label>
            <input type="date" name="fecha_inicio" class="form-control" required>
        </div>

        <div class="mb-3">
            <label><strong>Monto del Plan ($)</strong></label>
            <input type="number" step="0.01" name="plan_monto" class="form-control" required>
        </div>

        <div class="row g-2">
            <div class="col">
                <label>Comisión con equipo ($)</label>
                <input type="number" step="0.01" name="com_equipo" class="form-control" required>
            </div>
            <div class="col">
                <label>Comisión sin equipo ($)</label>
                <input type="number" step="0.01" name="com_sin_equipo" class="form-control" required>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-3">💾 Guardar</button>
        <a href="esquemas_comisiones_pospago.php" class="btn btn-secondary mt-3">⬅ Volver</a>
    </form>
</div>

</body>
</html>
