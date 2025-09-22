<?php
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

// 🔹 Funciones de cálculo de comisiones según tipo
function comisionPorRango($numeroVenta, $cumplioCuota) {
    // Ventas de equipos principales (no MiFi ni SIM)
    if ($cumplioCuota) {
        if ($numeroVenta <= 10) return 50;
        if ($numeroVenta <= 20) return 150;
        return 200;
    } else {
        if ($numeroVenta <= 10) return 25;
        if ($numeroVenta <= 20) return 75;
        return 125;
    }
}

function comisionMiFi($cumplioCuota) {
    return $cumplioCuota ? 50 : 25;
}

function comisionSIM($cumplioCuota) {
    return $cumplioCuota ? 30 : 10;
}

function comisionComboExtra() {
    return 75; // siempre fijo
}

list($inicioObj, $finObj) = obtenerSemanaPorIndice(0);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana = $finObj->format('Y-m-d');

// 🔹 1. Determinar si sucursales cumplen cuota
$sqlSucursales = "
    SELECT s.id, s.nombre, s.cuota_semanal,
           COUNT(dv.id) AS unidades
    FROM sucursales s
    LEFT JOIN usuarios u ON u.id_sucursal = s.id
    LEFT JOIN ventas v
        ON v.id_usuario = u.id
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    GROUP BY s.id
";

$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("ss", $inicioSemana, $finSemana);
$stmt->execute();
$resultSucursales = $stmt->get_result();

$sucursalesCuota = [];
while ($s = $resultSucursales->fetch_assoc()) {
    $sucursalesCuota[$s['id']] = ($s['unidades'] >= $s['cuota_semanal']);
}

// 🔹 2. Procesar ventas por sucursal, ordenadas cronológicamente
$sqlVentas = "
    SELECT v.id AS id_venta, v.id_sucursal, v.tipo_venta, v.fecha_venta
    FROM ventas v
    WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    ORDER BY v.id_sucursal, v.fecha_venta ASC
";

$stmtV = $conn->prepare($sqlVentas);
$stmtV->bind_param("ss", $inicioSemana, $finSemana);
$stmtV->execute();
$resultVentas = $stmtV->get_result();

$ventasProcesadas = 0;
$ventaNumeroPorSucursal = [];

while ($venta = $resultVentas->fetch_assoc()) {
    $idVenta = $venta['id_venta'];
    $idSucursal = $venta['id_sucursal'];
    $cumplioCuota = $sucursalesCuota[$idSucursal] ?? false;

    // 🔹 Enumerar ventas por sucursal
    if (!isset($ventaNumeroPorSucursal[$idSucursal])) {
        $ventaNumeroPorSucursal[$idSucursal] = 1;
    } else {
        $ventaNumeroPorSucursal[$idSucursal]++;
    }
    $numeroVenta = $ventaNumeroPorSucursal[$idSucursal];

    $comisionGerente = 0;

    // 🔹 Obtener detalle de productos de la venta
    $sqlDetalle = "
        SELECT dv.id_producto, p.precio_lista AS precio, LOWER(p.tipo_producto) AS tipo_producto
        FROM detalle_venta dv
        INNER JOIN productos p ON p.id = dv.id_producto
        WHERE dv.id_venta = ?
    ";
    $stmtD = $conn->prepare($sqlDetalle);
    $stmtD->bind_param("i", $idVenta);
    $stmtD->execute();
    $resultDetalle = $stmtD->get_result();

    while ($prod = $resultDetalle->fetch_assoc()) {
        $tipo = $prod['tipo_producto'];

        if ($tipo == 'sim') {
            // SIM normal
            $comisionGerente += comisionSIM($cumplioCuota);

        } elseif ($tipo == 'modem' || $tipo == 'mifi') {
            // MiFi o modem
            $comisionGerente += comisionMiFi($cumplioCuota);

        } else {
            // Equipo principal o combo
            if ($tipo == 'combo') {
                // Equipo combo siempre $75
                $comisionGerente += comisionComboExtra();
            } else {
                // Equipo principal según número de venta
                $comisionGerente += comisionPorRango($numeroVenta, $cumplioCuota);
            }
        }
    }
    $stmtD->close();

    // 🔹 Actualizar la comisión de gerente en la venta
    $sqlUpdate = "UPDATE ventas SET comision_gerente = ? WHERE id = ?";
    $stmtU = $conn->prepare($sqlUpdate);
    $stmtU->bind_param("di", $comisionGerente, $idVenta);
    $stmtU->execute();

    $ventasProcesadas++;
}

// 🔹 Resumen en pantalla
echo "<h2>✅ Comisiones de Gerentes calculadas con nuevo esquema</h2>";
echo "<p>Semana del {$inicioSemana} al {$finSemana}</p>";
echo "<p>Ventas procesadas: {$ventasProcesadas}</p>";
echo "<p>Se actualizó la columna <b>comision_gerente</b> en la tabla ventas.</p>";
?>
