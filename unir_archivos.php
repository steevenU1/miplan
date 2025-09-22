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
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // Martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days");
    $inicio->setTime(0,0,0);

    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days");
    $fin->setTime(23,59,59);

    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// =====================
// NUEVAS MÉTRICAS
// =====================
$ventasPospagoEjecutivos = [];
$detalleSIMsEjecutivos = [];
$detalleSIMsSucursales = [];

$sqlPospago = "
    SELECT v.id_usuario, COUNT(*) AS pospago
    FROM ventas v
    JOIN detalle_venta dv ON dv.id_venta = v.id
    JOIN productos p ON p.id = dv.id_producto
    WHERE LOWER(p.tipo_producto) = 'pospago'
      AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    GROUP BY v.id_usuario
";
$stmt = $conn->prepare($sqlPospago);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $ventasPospagoEjecutivos[$r['id_usuario']] = (int)$r['pospago'];
}

$sqlSIMs = "
    SELECT id_usuario,
        SUM(CASE WHEN tipo_venta = 'Nueva' THEN 1 ELSE 0 END) AS nuevas,
        SUM(CASE WHEN tipo_venta = 'Portabilidad' THEN 1 ELSE 0 END) AS portabilidad
    FROM ventas_sims
    WHERE DATE(CONVERT_TZ(fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    GROUP BY id_usuario
";
$stmt = $conn->prepare($sqlSIMs);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $detalleSIMsEjecutivos[$r['id_usuario']] = [
        'nuevas' => (int)$r['nuevas'],
        'portabilidad' => (int)$r['portabilidad']
    ];
}

$sqlSIMsSucursales = "
    SELECT u.id_sucursal,
        SUM(CASE WHEN vs.tipo_venta = 'Nueva' THEN 1 ELSE 0 END) AS nuevas,
        SUM(CASE WHEN vs.tipo_venta = 'Portabilidad' THEN 1 ELSE 0 END) AS portabilidad
    FROM ventas_sims vs
    JOIN usuarios u ON u.id = vs.id_usuario
    WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    GROUP BY u.id_sucursal
";
$stmt = $conn->prepare($sqlSIMsSucursales);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $detalleSIMsSucursales[$r['id_sucursal']] = [
        'nuevas' => (int)$r['nuevas'],
        'portabilidad' => (int)$r['portabilidad']
    ];
}

// Después de procesar rankingEjecutivos
foreach ($rankingEjecutivos as &$r) {
    $r['pospago'] = $ventasPospagoEjecutivos[$r['id']] ?? 0;
    $r['prepago_nuevas'] = $detalleSIMsEjecutivos[$r['id']]['nuevas'] ?? 0;
    $r['prepago_porta'] = $detalleSIMsEjecutivos[$r['id']]['portabilidad'] ?? 0;
}
unset($r);

// Después de procesar sucursales
foreach ($sucursales as &$s) {
    $s['prepago_nuevas'] = $detalleSIMsSucursales[$s['id_sucursal']]['nuevas'] ?? 0;
    $s['prepago_porta'] = $detalleSIMsSucursales[$s['id_sucursal']]['portabilidad'] ?? 0;
}
unset($s);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Semanal Luga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📊 Dashboard Semanal Luga</h2>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ejecutivos">Ejecutivos 👔</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sucursales">Sucursales 🏢</button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Ejecutivos -->
        <div class="tab-pane fade show active" id="ejecutivos">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Ejecutivos</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Ejecutivo</th>
                                <th>Sucursal</th>
                                <th>Unidades</th>
                                <th>Ventas $</th>
                                <th>% Cumplimiento</th>
                                <th>POSPAGO</th>
                                <th>Prepago Nueva</th>
                                <th>Prepago Portabilidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rankingEjecutivos as $r):
                                $cumpl = round($r['cumplimiento'], 1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                                $iconTop = in_array($r['id'], $top3Ejecutivos) ? ' 🏆' : '';
                            ?>
                            <tr>
                                <td><?= $r['nombre'].$iconTop ?></td>
                                <td><?= $r['sucursal'] ?></td>
                                <td><?= $r['unidades'] ?></td>
                                <td>$<?= number_format($r['total_ventas'], 2) ?></td>
                                <td><?= $cumpl ?>% <?= $estado ?></td>
                                <td><?= $r['pospago'] ?></td>
                                <td><?= $r['prepago_nuevas'] ?></td>
                                <td><?= $r['prepago_porta'] ?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sucursales -->
        <div class="tab-pane fade" id="sucursales">
            <div class="card mb-4 shadow">
                <div class="card-header bg-dark text-white">Ranking de Sucursales</div>
                <div class="card-body">
                    <table class="table table-striped table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Sucursal</th>
                                <th>Zona</th>
                                <th>Unidades</th>
                                <th>Ventas $</th>
                                <th>Cuota $</th>
                                <th>% Cumplimiento</th>
                                <th>Prepago Nueva</th>
                                <th>Prepago Portabilidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s):
                                $cumpl = round($s['cumplimiento'], 1);
                                $estado = $cumpl>=100?"✅":($cumpl>=60?"⚠️":"❌");
                            ?>
                            <tr>
                                <td><?= $s['sucursal'] ?></td>
                                <td><?= $s['zona'] ?></td>
                                <td><?= $s['unidades'] ?></td>
                                <td>$<?= number_format($s['total_ventas'], 2) ?></td>
                                <td>$<?= number_format($s['cuota_semanal'], 2) ?></td>
                                <td><?= $cumpl ?>% <?= $estado ?></td>
                                <td><?= $s['prepago_nuevas'] ?></td>
                                <td><?= $s['prepago_porta'] ?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
