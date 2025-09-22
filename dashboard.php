<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

// 🔹 Función para obtener inicio y fin de semana (martes-lunes)
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days");
    $inicio->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7*$offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days");
    $fin->setTime(23,59,59);

    return [$inicio, $fin];
}

// 🔹 Determinar semana seleccionada
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// 🔹 Consulta: ranking por unidades
$sql = "
    SELECT u.id, u.nombre, u.rol, s.nombre AS sucursal,
           IFNULL(COUNT(dv.id),0) AS unidades,
           IFNULL(SUM(dv.comision),0) AS comision_total
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv 
        ON dv.id_venta = v.id
    GROUP BY u.id
    ORDER BY unidades DESC, comision_total DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$result = $stmt->get_result();

$labels = [];
$unidades = [];
$comisiones = [];
$ranking = [];

while ($row = $result->fetch_assoc()) {
    $labels[] = $row['nombre'];
    $unidades[] = (int)$row['unidades'];
    $comisiones[] = (float)$row['comision_total'];
    $ranking[] = $row;
}

// 🔹 Top 3 vendedores por unidades
$topVendedores = array_slice(array_column($ranking, 'id'), 0, 3);

// 🔹 Últimas 8 semanas para el selector
$opcionesSemanas = [];
for ($i = 0; $i < 8; $i++) {
    list($ini, $fin) = obtenerSemanaPorIndice($i);
    $opcionesSemanas[$i] = [
        'inicio' => $ini->format('d/m/Y'),
        'fin' => $fin->format('d/m/Y')
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Semanal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Dashboard Semanal de Ejecutivos</h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <!-- Filtro de semanas -->
    <form method="GET" class="mb-4">
        <label><strong>Selecciona semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
            <?php foreach ($opcionesSemanas as $i => $sem): 
                $texto = "Semana del {$sem['inicio']} al {$sem['fin']}";
            ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada ? 'selected':'' ?>><?= $texto ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="row">
        <!-- Gráfico de unidades -->
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">Unidades vendidas vs cuota</div>
                <div class="card-body">
                    <canvas id="chartUnidades"></canvas>
                </div>
            </div>
        </div>

        <!-- Gráfico de comisiones -->
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-success text-white">Comisiones generadas</div>
                <div class="card-body">
                    <canvas id="chartComisiones"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de ranking -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            Ranking de Ejecutivos (<?= $opcionesSemanas[$semanaSeleccionada]['inicio'] ?> - <?= $opcionesSemanas[$semanaSeleccionada]['fin'] ?>)
        </div>
        <div class="card-body">
            <table class="table table-striped table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Ejecutivo</th>
                        <th>Sucursal</th>
                        <th>Unidades</th>
                        <th>Cuota</th>
                        <th>Comisión Total</th>
                        <th>Progreso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $r): 
                        $uni = (int)$r['unidades'];
                        $com = (float)$r['comision_total'];
                        $porcentaje = min(100, ($uni / 6) * 100);

                        // Estado visual según unidades
                        if ($uni >= 6) {
                            $estado = "✅";
                            $claseFila = "table-success";
                        } elseif ($uni >= 4) {
                            $estado = "⚠️";
                            $claseFila = "table-warning";
                        } else {
                            $estado = "❌";
                            $claseFila = "table-danger";
                        }

                        // Íconos extra
                        $iconoTop = in_array($r['id'], $topVendedores) && $uni>0 ? ' 🏆' : '';
                        $iconoGerente = ($r['rol'] == 'Gerente') ? ' 👑' : '';
                    ?>
                    <tr class="<?= $claseFila ?>">
                        <td><?= $r['nombre'] . $iconoGerente . $iconoTop ?></td>
                        <td><?= $r['sucursal'] ?></td>
                        <td><?= $uni . ' ' . $estado ?></td>
                        <td>6</td>
                        <td>$<?= number_format($com, 2) ?></td>
                        <td>
                            <div class="progress" style="height:20px">
                                <div class="progress-bar <?= $uni>=6?'bg-success':($uni>=4?'bg-warning':'bg-danger') ?>" 
                                     role="progressbar" style="width: <?= $porcentaje ?>%">
                                    <?= round($porcentaje) ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const labels = <?= json_encode($labels) ?>;
    const unidades = <?= json_encode($unidades) ?>;
    const comisiones = <?= json_encode($comisiones) ?>;

    // Gráfico de unidades
    new Chart(document.getElementById('chartUnidades'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Unidades vendidas',
                data: unidades,
                backgroundColor: '#0d6efd'
            }]
        },
        options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Gráfico de comisiones
    new Chart(document.getElementById('chartComisiones'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Comisión total ($)',
                data: comisiones,
                backgroundColor: '#198754'
            }]
        },
        options: { scales: { y: { beginAtZero: true } } }
    });
</script>

</body>
</html>
