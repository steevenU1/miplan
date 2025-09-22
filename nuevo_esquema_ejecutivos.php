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

    // Capturamos todas las comisiones
    $campos = [
        'comision_c_sin','comision_b_sin','comision_a_sin','comision_mifi_sin',
        'comision_c_con','comision_b_con','comision_a_con','comision_mifi_con',
        'comision_combo',
        'comision_sim_bait_nueva_sin','comision_sim_bait_port_sin',
        'comision_sim_att_nueva_sin','comision_sim_att_port_sin',
        'comision_sim_bait_nueva_con','comision_sim_bait_port_con',
        'comision_sim_att_nueva_con','comision_sim_att_port_con',
        'comision_pos_con_equipo','comision_pos_sin_equipo'
    ];

    $valores = [];
    foreach($campos as $c){
        $valores[$c] = $_POST[$c] ?? 0;
    }

    if ($fecha_inicio) {
        // Obtener vigente
        $sqlVigente = "SELECT * FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC LIMIT 1";
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
            $sql = "INSERT INTO esquemas_comisiones_ejecutivos 
                (fecha_inicio,".implode(",",$campos).") 
                VALUES (?,".str_repeat("?,",count($campos)-1)."?)";

            $stmt = $conn->prepare($sql);
            $types = "s".str_repeat("d",count($campos));
            $stmt->bind_param($types, $fecha_inicio, ...array_values($valores));
            
            if ($stmt->execute()) {
                header("Location: esquemas_comisiones_ejecutivos.php");
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
<title>Nuevo Esquema Ejecutivos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>➕ Nuevo Esquema - Ejecutivos</h2>
    <?php if($mensaje): ?><div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label><strong>Fecha de Inicio:</strong></label>
            <input type="date" name="fecha_inicio" class="form-control" required>
        </div>

        <h5>Comisiones por equipos</h5>
        <div class="row g-2">
            <div class="col"><input type="number" step="0.01" name="comision_c_sin" class="form-control" placeholder="C sin cuota" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_c_con" class="form-control" placeholder="C con cuota" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_b_sin" class="form-control" placeholder="B sin cuota" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_b_con" class="form-control" placeholder="B con cuota" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_a_sin" class="form-control" placeholder="A sin cuota" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_a_con" class="form-control" placeholder="A con cuota" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_mifi_sin" class="form-control" placeholder="MiFi sin cuota" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_mifi_con" class="form-control" placeholder="MiFi con cuota" required></div>
        </div>

        <div class="mb-3 mt-3">
            <label><strong>Comisión Combo:</strong></label>
            <input type="number" step="0.01" name="comision_combo" class="form-control" required>
        </div>

        <h5>Comisiones SIMs</h5>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_sim_bait_nueva_sin" class="form-control" placeholder="Bait Nueva sin" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_sim_bait_port_sin" class="form-control" placeholder="Bait Port sin" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_sim_att_nueva_sin" class="form-control" placeholder="ATT Nueva sin" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_sim_att_port_sin" class="form-control" placeholder="ATT Port sin" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_sim_bait_nueva_con" class="form-control" placeholder="Bait Nueva con" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_sim_bait_port_con" class="form-control" placeholder="Bait Port con" required></div>
        </div>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_sim_att_nueva_con" class="form-control" placeholder="ATT Nueva con" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_sim_att_port_con" class="form-control" placeholder="ATT Port con" required></div>
        </div>

        <h5 class="mt-3">Comisiones Pospago Bait</h5>
        <div class="row g-2 mt-2">
            <div class="col"><input type="number" step="0.01" name="comision_pos_con_equipo" class="form-control" placeholder="Con equipo" required></div>
            <div class="col"><input type="number" step="0.01" name="comision_pos_sin_equipo" class="form-control" placeholder="Sin equipo" required></div>
        </div>

        <button type="submit" class="btn btn-success mt-3">💾 Guardar Esquema</button>
        <a href="esquemas_comisiones_ejecutivos.php" class="btn btn-secondary mt-3">⬅ Volver</a>
    </form>
</div>

</body>
</html>
