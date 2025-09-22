<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2;          // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

/* ========================
   SEMANA SELECCIONADA
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemana, $finSemana) = obtenerSemanaPorIndice($semanaSeleccionada);
$fechaInicio = $inicioSemana->format('Y-m-d');
$fechaFin = $finSemana->format('Y-m-d');

/* ========================
   CONSULTA NOMINA HISTÓRICA
======================== */
$sql = "
    SELECT cgz.*, u.nombre AS gerente, u.sueldo
    FROM comisiones_gerentes_zona cgz
    INNER JOIN usuarios u ON cgz.id_gerente = u.id
    WHERE cgz.fecha_inicio = ?
    ORDER BY cgz.zona
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $fechaInicio);
$stmt->execute();
$result = $stmt->get_result();

$datos = [];
$total_sueldos = 0;
$total_comisiones = 0;
$total_global = 0;

/* ========================
   REGLAS DE COMISIONES
======================== */
// Según % cumplimiento, aplicamos tu esquema
while ($row = $result->fetch_assoc()) {
    $cumplimiento = (float)$row['porcentaje_cumplimiento'];
    $com_base = (float)$row['comision_equipos'] + (float)$row['comision_sims'] + (float)$row['comision_pospago'];

    // Ajuste dinámico según % cumplimiento
    if ($cumplimiento < 80) {
        // Mantiene la comisión base <80%
        $com_total = $row['comision_equipos'] * 1.0 + $row['comision_sims']*0 + $row['comision_pospago']; 
    } elseif ($cumplimiento < 100) {
        // 80% a 99% -> aplica segundo nivel
        $com_total = $row['comision_equipos']*1.0 + $row['comision_sims']*1.0 + $row['comision_pospago'];
    } else {
        // >=100% -> aplica tercer nivel (doble en equipos y sims)
        $com_total = $row['comision_equipos']*2.0 + $row['comision_sims']*2.0 + $row['comision_pospago'];
    }

    $total_pago = $row['sueldo'] + $com_total;

    $datos[] = [
        'gerente' => $row['gerente'],
        'zona' => $row['zona'],
        'sueldo' => $row['sueldo'],
        'com_total' => $com_total,
        'total_pago' => $total_pago
    ];

    $total_sueldos += $row['sueldo'];
    $total_comisiones += $com_total;
    $total_global += $total_pago;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nómina Gerentes de Zona</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>Reporte Nómina - Gerentes de Zona</h2>
    <p class="text-muted">Semana: <?= $fechaInicio ?> al <?= $fechaFin ?></p>

    <!-- 🔹 Selector de semana -->
    <form method="GET" class="mb-4 card card-body shadow-sm">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="semana">Seleccionar Semana</label>
                <select name="semana" id="semana" class="form-select" onchange="this.form.submit()">
                    <?php for($i=0;$i<8;$i++):
                        list($ini,$fin) = obtenerSemanaPorIndice($i);
                        $txt = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
                    ?>
                    <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>>
                        <?= $txt ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </form>

    <table class="table table-striped table-bordered text-center align-middle">
        <thead class="table-dark">
            <tr>
                <th>Gerente</th>
                <th>Zona</th>
                <th>Sueldo Base</th>
                <th>Comisión Total (Recalculada)</th>
                <th>Total a Pagar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($datos as $d): ?>
            <tr>
                <td><?= $d['gerente'] ?></td>
                <td><?= $d['zona'] ?></td>
                <td>$<?= number_format($d['sueldo'],2) ?></td>
                <td>$<?= number_format($d['com_total'],2) ?></td>
                <td><strong>$<?= number_format($d['total_pago'],2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-secondary">
            <tr>
                <th colspan="2">Totales</th>
                <th>$<?= number_format($total_sueldos,2) ?></th>
                <th>$<?= number_format($total_comisiones,2) ?></th>
                <th>$<?= number_format($total_global,2) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="text-end">
        <a href="exportar_nomina_gerentes_excel.php?semana=<?= $fechaInicio ?>" class="btn btn-success">
            📄 Exportar a Excel
        </a>
    </div>
</div>

</body>
</html>
