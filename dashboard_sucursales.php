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

// 🔹 Semana seleccionada
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// 🔹 Traer ventas por sucursal sin duplicar SUM de ventas
$sql = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal, s.zona, s.cuota_semanal,
           IFNULL(COUNT(dv.id),0) AS unidades,
           IFNULL(SUM(v.precio_venta),0) AS total_ventas
    FROM sucursales s
    LEFT JOIN (
        SELECT v.id, v.id_sucursal, v.precio_venta, v.fecha_venta
        FROM ventas v
        WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ) v ON v.id_sucursal = s.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    GROUP BY s.id
    ORDER BY total_ventas DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$result = $stmt->get_result();

// 🔹 Procesar resultados
$sucursales = [];
$totalUnidades = 0;
$totalVentasGlobal = 0;
$totalesPorZona = [];

while ($row = $result->fetch_assoc()) {
    $row['unidades'] = (int)$row['unidades'];
    $row['total_ventas'] = (float)$row['total_ventas'];
    $row['cuota_semanal'] = (float)$row['cuota_semanal'];

    // % Cumplimiento por sucursal
    $cumplimiento = 0;
    if ($row['cuota_semanal'] > 0) {
        $cumplimiento = ($row['total_ventas'] / $row['cuota_semanal']) * 100;
    }
    $row['cumplimiento'] = $cumplimiento;

    // Acumular totales globales
    $totalUnidades += $row['unidades'];
    $totalVentasGlobal += $row['total_ventas'];

    // Totales por zona
    if (!isset($totalesPorZona[$row['zona']])) {
        $totalesPorZona[$row['zona']] = 0;
    }
    $totalesPorZona[$row['zona']] += $row['total_ventas'];

    $sucursales[] = $row;
}

// % Cumplimiento Global
$totalCuotaGlobal = array_sum(array_column($sucursales, 'cuota_semanal'));
$porcentajeGlobal = $totalCuotaGlobal>0 ? ($totalVentasGlobal/$totalCuotaGlobal)*100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Semanal por Sucursal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Dashboard Semanal por Sucursal</h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <!-- Tarjetas resumen -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h5>Total Unidades</h5>
                    <h3><?= $totalUnidades ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h5>Total Ventas ($)</h5>
                    <h3>$<?= number_format($totalVentasGlobal,2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h5>% Cumplimiento Global</h5>
                    <h3><?= round($porcentajeGlobal,1) ?>%</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow text-center">
                <div class="card-body">
                    <h5>Zonas</h5>
                    <?php foreach ($totalesPorZona as $zona => $monto): 
                        $porcZona = $totalCuotaGlobal>0 ? ($monto/$totalCuotaGlobal)*100 : 0;
                    ?>
                        <p><b>Zona <?= $zona ?>:</b> $<?= number_format($monto,2) ?> (<?= round($porcZona,1) ?>%)</p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Selector de semana -->
    <form method="GET" class="mb-3">
        <label><strong>Selecciona semana:</strong></label>
        <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
            <?php for ($i=0; $i<8; $i++): 
                list($ini, $fin) = obtenerSemanaPorIndice($i);
                $texto = "Semana del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
            ?>
                <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <div class="row">
        <!-- Unidades -->
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">Unidades vendidas por sucursal</div>
                <div class="card-body">
                    <canvas id="chartUnidades"></canvas>
                </div>
            </div>
        </div>

        <!-- Cumplimiento -->
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-success text-white">% Cumplimiento de cuota</div>
                <div class="card-body">
                    <canvas id="chartCumplimiento"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla ranking de sucursales -->
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            Ranking de Sucursales (<?= $inicioObj->format('d/m/Y') ?> - <?= $finObj->format('d/m/Y') ?>)
        </div>
        <div class="card-body">
            <table class="table table-striped table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Sucursal</th>
                        <th>Zona</th>
                        <th>Unidades</th>
                        <th>Cuota</th>
                        <th>Total Ventas ($)</th>
                        <th>% Cumplimiento</th>
                        <th>Progreso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        $top3 = array_slice(array_column($sucursales, 'sucursal'), 0, 3);
                        foreach ($sucursales as $s): 
                            $cumpl = round($s['cumplimiento'],1);
                            $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                            $claseFila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                            $iconTop = in_array($s['sucursal'], $top3) ? ' 🏆' : '';
                    ?>
                    <tr class="<?= $claseFila ?>">
                        <td><?= $s['sucursal'] . $iconTop ?></td>
                        <td>Zona <?= $s['zona'] ?></td>
                        <td><?= $s['unidades'] ?></td>
                        <td>$<?= number_format($s['cuota_semanal'],2) ?></td>
                        <td>$<?= number_format($s['total_ventas'],2) ?></td>
                        <td><?= $cumpl ?>% <?= $estado ?></td>
                        <td>
                            <div class="progress" style="height:20px">
                                <div class="progress-bar <?= $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger') ?>" 
                                     role="progressbar" style="width: <?= min(100,$cumpl) ?>%">
                                     <?= $cumpl ?>%
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
    const labels = <?= json_encode(array_column($sucursales, 'sucursal')) ?>;
    const unidades = <?= json_encode(array_column($sucursales, 'unidades')) ?>;
    const cumplimiento = <?= json_encode(array_map(fn($v)=>round($v['cumplimiento'],1), $sucursales)) ?>;

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
        options: { scales: { y: { beginAtZero: true, ticks:{ stepSize:1 } } } }
    });

    // Gráfico de % cumplimiento
    new Chart(document.getElementById('chartCumplimiento'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: '% Cumplimiento',
                data: cumplimiento,
                backgroundColor: '#198754'
            }]
        },
        options: { scales: { y: { beginAtZero: true } } }
    });
</script>

</body>
</html>

