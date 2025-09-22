<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// ============================
// Función para obtener meses en español
// ============================
function nombreMes($mes) {
    $meses = [
        1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
        7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
    ];
    return $meses[intval($mes)] ?? '';
}

// ============================
// Filtro de mes y año
// ============================
$mesSeleccionado = isset($_GET['mes']) ? (int)$_GET['mes'] : date('n');
$anioSeleccionado = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');

// Rango de fechas para el mes
$inicioMes = "$anioSeleccionado-" . str_pad($mesSeleccionado,2,"0",STR_PAD_LEFT) . "-01";
$finMes = date("Y-m-t", strtotime($inicioMes));

// ============================
// Obtener cuota mensual de ejecutivos
// ============================
$sqlCuota = "
    SELECT cuota_unidades, cuota_monto 
    FROM cuotas_mensuales_ejecutivos
    WHERE mes=? AND anio=? 
    ORDER BY id DESC LIMIT 1
";
$stmtC = $conn->prepare($sqlCuota);
$stmtC->bind_param("ii", $mesSeleccionado, $anioSeleccionado);
$stmtC->execute();
$cuota = $stmtC->get_result()->fetch_assoc();
$cuota_unidades = (int)($cuota['cuota_unidades'] ?? 0);
$cuota_monto = (float)($cuota['cuota_monto'] ?? 0);

// ============================
// Consultar ejecutivos y ventas
// ============================
$sql = "
    SELECT u.id, u.nombre, u.rol, s.nombre AS sucursal,
           IFNULL(SUM(
               CASE 
                   WHEN dv.id IS NULL THEN 0
                   WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                   WHEN v.tipo_venta='Financiamiento+Combo' 
                        AND dv.id = (
                            SELECT MIN(dv2.id) 
                            FROM detalle_venta dv2 
                            WHERE dv2.id_venta=v.id
                        ) THEN 2
                   ELSE 1
               END
           ),0) AS unidades,
           IFNULL(SUM(dv.precio_unitario),0) AS total_ventas
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ventas v 
        ON v.id_usuario = u.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda' AND u.rol='Ejecutivo'
    GROUP BY u.id
    ORDER BY unidades DESC, total_ventas DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$ejecutivos = [];
$totalUnidades = 0;
$totalVentas = 0;

while ($row = $res->fetch_assoc()) {
    $row['unidades'] = (int)$row['unidades'];
    $row['total_ventas'] = (float)$row['total_ventas'];
    $row['cumplimiento'] = $cuota_unidades>0 ? ($row['unidades']/$cuota_unidades*100) : 0;

    $totalUnidades += $row['unidades'];
    $totalVentas += $row['total_ventas'];

    $ejecutivos[] = $row;
}

// ============================
// Cálculos globales
// ============================
$numEjecutivos = count($ejecutivos);
$totalEsperadoUnidades = $cuota_unidades * $numEjecutivos;
$totalEsperadoMonto = $cuota_monto * $numEjecutivos;

$cumplimientoGlobalUnidades = $totalEsperadoUnidades>0 ? ($totalUnidades/$totalEsperadoUnidades*100) : 0;
$cumplimientoGlobalMonto = $totalEsperadoMonto>0 ? ($totalVentas/$totalEsperadoMonto*100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Mensual Ejecutivos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h2>📆 Dashboard Mensual de Ejecutivos</h2>
    <form method="GET" class="mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <select name="mes" class="form-select" onchange="this.form.submit()">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $m==$mesSeleccionado?'selected':'' ?>>
                            <?= nombreMes($m) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="anio" class="form-select" onchange="this.form.submit()">
                    <?php for($a=date('Y');$a>=2023;$a--): ?>
                        <option value="<?= $a ?>" <?= $a==$anioSeleccionado?'selected':'' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </form>

    <!-- Tarjetas resumen -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center shadow">
                <div class="card-header bg-primary text-white">Unidades Vendidas</div>
                <div class="card-body">
                    <h3><?= $totalUnidades ?></h3>
                    <p>De <?= $totalEsperadoUnidades ?> esperadas</p>
                    <b><?= number_format($cumplimientoGlobalUnidades,1) ?>% Cumplimiento</b>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center shadow">
                <div class="card-header bg-success text-white">Ventas Totales ($)</div>
                <div class="card-body">
                    <h3>$<?= number_format($totalVentas,2) ?></h3>
                    <p>De $<?= number_format($totalEsperadoMonto,2) ?> esperadas</p>
                    <b><?= number_format($cumplimientoGlobalMonto,1) ?>% Cumplimiento</b>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center shadow">
                <div class="card-header bg-dark text-white">Ejecutivos Activos</div>
                <div class="card-body">
                    <h3><?= $numEjecutivos ?></h3>
                    <p>Con cuota mensual de <?= $cuota_unidades ?> unidades</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla Ejecutivos -->
    <div class="card shadow">
        <div class="card-header bg-dark text-white">Detalle por Ejecutivo</div>
        <div class="card-body table-responsive">
            <table class="table table-striped table-bordered text-center">
                <thead class="table-dark">
                    <tr>
                        <th>Ejecutivo</th>
                        <th>Sucursal</th>
                        <th>Unidades</th>
                        <th>Ventas ($)</th>
                        <th>% Cumplimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ejecutivos as $r): ?>
                        <?php 
                            $cumpl = round($r['cumplimiento'],1);
                            $fila = $cumpl>=100?"table-success":($cumpl>=60?"table-warning":"table-danger");
                        ?>
                        <tr class="<?= $fila ?>">
                            <td><?= $r['nombre'] ?></td>
                            <td><?= $r['sucursal'] ?></td>
                            <td><?= $r['unidades'] ?></td>
                            <td>$<?= number_format($r['total_ventas'],2) ?></td>
                            <td><?= $cumpl ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
