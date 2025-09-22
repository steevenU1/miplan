<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';

    // Campos de la tabla
    $campos = [
        'venta_directa_sin','venta_directa_con',
        'sucursal_1_10_sin','sucursal_1_10_con',
        'sucursal_11_20_sin','sucursal_11_20_con',
        'sucursal_21_mas_sin','sucursal_21_mas_con',
        'comision_modem_sin','comision_modem_con',
        'comision_sim_sin','comision_sim_con',
        'comision_pos_con_equipo','comision_pos_sin_equipo'
    ];

    $valores = [];
    foreach($campos as $c){
        $valores[$c] = $_POST[$c] ?? 0;
    }

    if ($fecha_inicio) {
        // Obtener vigente
        $sqlVigente = "SELECT * FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC LIMIT 1";
        $vigente = $conn->query($sqlVigente)->fetch_assoc();

        $duplicado = false;
        if ($vigente) {
            $duplicado = true;
            foreach($campos as $c){
                if ((float)$vigente[$c] != (float)$valores[$c]) {
                    $duplicado = false;
                    break;
                }
            }
        }

        if ($duplicado) {
            $mensaje = "⚠️ El esquema ingresado es igual al vigente. No se registró.";
        } else {
            $sql = "INSERT INTO esquemas_comisiones_gerentes 
                (fecha_inicio,".implode(",",$campos).") 
                VALUES (?,".str_repeat("?,",count($campos)-1)."?)";

            $stmt = $conn->prepare($sql);
            $types = "s".str_repeat("d",count($campos));
            $stmt->bind_param($types, $fecha_inicio, ...array_values($valores));
            
            if ($stmt->execute()) {
                header("Location: esquemas_comisiones_gerentes.php");
                exit();
            } else {
                $mensaje = "❌ Error al guardar: ".$conn->error;
            }
        }
    } else {
        $mensaje = "❌ La fecha de inicio es obligatoria.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nuevo Esquema Gerentes</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>➕ Nuevo Esquema - Gerentes</h2>
    <?php if($mensaje): ?><div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label><strong>Fecha de Inicio:</strong></label>
            <input type="date" name="fecha_inicio" class="form-control" required>
        </div>

        <h5>Venta Directa</h5>
        <div class="row g-2">
            <div class="col"><input type="number" step="0.01" name="venta_directa_sin" class="form-control" placeholder="Sin cuota" required></div>
            <div class="col"><input type="number" step="0.01" name="venta_directa_con" class="form-control" placeholder="Con cuota" required></div>
        </div>

        <h5 class="mt-3">Ventas de Sucursal</h5>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="sucursal_1_10_sin" class="form-control" placeholder="1-10 sin" required></div>
            <div class="col"><input type="number" step="0.01" name="sucursal_1_10_con" class="form-control" placeholder="1-10 con" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="sucursal_11_20_sin" class="form-control" placeholder="11-20 sin" required></div>
            <div class="col"><input type="number" step="0.01" name="sucursal_11_20_con" class="form-control" placeholder="11-20 con" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="sucursal_21_mas_sin" class="form-control" placeholder="21+ sin" required></div>
            <div class="col"><input type="number" step="0.01" name="sucursal_21_mas_con" class="form-control" placeholder="21+ con" required></div>
        </div>

        <h5 class="mt-3">Otros</h5>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_modem_sin" class="form-control" placeholder="Modem sin" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_modem_con" class="form-control" placeholder="Modem con" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_sim_sin" class="form-control" placeholder="SIM sin" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_sim_con" class="form-control" placeholder="SIM con" required></div>
        </div>

        <h5 class="mt-3">Comisiones Pospago Bait</h5>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_pos_con_equipo" class="form-control" placeholder="Con equipo" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_pos_sin_equipo" class="form-control" placeholder="Sin equipo" required></div>
        </div>

        <button type="submit" class="btn btn-success mt-3">💾 Guardar Esquema</button>
        <a href="esquemas_comisiones_gerentes.php" class="btn btn-secondary mt-3">⬅ Volver</a>
    </form>
</div>

</body>
</html>
