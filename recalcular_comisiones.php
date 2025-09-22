<?php
session_start();
include 'db.php';

echo "<h2>Recalculando comisiones semanales (Ejecutivos)...</h2>";

// 🔹 Función semana martes-lunes
function obtenerSemanaActual() {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $offset = $diaSemana - 2; // martes = 2
    if ($offset < 0) $offset += 7;

    $inicio = new DateTime();
    $inicio->modify("-$offset days")->setTime(0,0,0);
    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
}

// 🔹 Funciones de comisiones
function comisionEquipo($precio, $cumpleCuota, $esComboSecundario, $esMiFi) {
    if ($esComboSecundario) return 75; // Combo siempre 75
    if ($esMiFi) return $cumpleCuota ? 100 : 75;

    if ($cumpleCuota) {
        if ($precio <= 3500) return 100;
        if ($precio <= 5500) return 200;
        return 250;
    } else {
        if ($precio <= 3500) return 75;
        if ($precio <= 5500) return 100;
        return 200;
    }
}

function comisionSIM($tipoSIM) {
    return ($tipoSIM == 'Portabilidad') ? 70 : 50;
}

list($inicioSemana, $finSemana) = obtenerSemanaActual();
echo "<p>Semana: $inicioSemana a $finSemana</p>";

// 🔹 Ejecutivos
$sqlUsuarios = "SELECT id, nombre FROM usuarios WHERE rol='Ejecutivo'";
$usuarios = $conn->query($sqlUsuarios);

while ($usuario = $usuarios->fetch_assoc()) {
    $id_usuario = $usuario['id'];

    // Ventas de la semana
    $sqlVentas = "SELECT id FROM ventas 
                  WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?
                  ORDER BY fecha_venta ASC";
    $stmtVentas = $conn->prepare($sqlVentas);
    $stmtVentas->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtVentas->execute();
    $resultVentas = $stmtVentas->get_result();

    $ventasSemana = [];
    while ($v = $resultVentas->fetch_assoc()) $ventasSemana[] = $v['id'];

    // 🔹 Contar unidades (combo = 2)
    $unidades = 0;
    foreach ($ventasSemana as $id_venta) {
        $sqlUnidades = "SELECT COUNT(*) AS unidades FROM detalle_venta WHERE id_venta=?";
        $stmtUni = $conn->prepare($sqlUnidades);
        $stmtUni->bind_param("i", $id_venta);
        $stmtUni->execute();
        $res = $stmtUni->get_result()->fetch_assoc();
        $unidades += intval($res['unidades'] ?? 0);
    }

    echo "<p><strong>{$usuario['nombre']}</strong> - Unidades: $unidades</p>";

    // 🔹 ¿Cumple cuota ejecutivos?
    $cumpleCuota = ($unidades >= 6);
    $cobraSIMs = ($unidades >= 4); // meta SIMs

    foreach ($ventasSemana as $id_venta) {
        // Detalle de productos
        $sqlDetalle = "SELECT dv.id, p.precio_lista, LOWER(p.tipo_producto) AS tipo, dv.comision 
                       FROM detalle_venta dv
                       INNER JOIN productos p ON dv.id_producto = p.id
                       WHERE dv.id_venta=?";
        $stmtDet = $conn->prepare($sqlDetalle);
        $stmtDet->bind_param("i", $id_venta);
        $stmtDet->execute();
        $detalles = $stmtDet->get_result();

        $comisionTotalVenta = 0;
        $index = 0;

        while ($d = $detalles->fetch_assoc()) {
            $index++;
            $tipo = $d['tipo'];
            $esComboSec = ($index == 2); // segundo equipo = combo
            $esMiFi = ($tipo == 'modem' || $tipo == 'mifi');
            
            $comisionNueva = comisionEquipo(
                $d['precio_lista'], 
                $cumpleCuota, 
                $esComboSec, 
                $esMiFi
            );

            // Actualizar detalle
            $sqlUpdateDet = "UPDATE detalle_venta SET comision=? WHERE id=?";
            $stmtUpdateDet = $conn->prepare($sqlUpdateDet);
            $stmtUpdateDet->bind_param("di", $comisionNueva, $d['id']);
            $stmtUpdateDet->execute();

            $comisionTotalVenta += $comisionNueva;
        }

        // 🔹 Actualizar comisión total en ventas
        $sqlUpdateVenta = "UPDATE ventas SET comision=? WHERE id=?";
        $stmtUpdateVenta = $conn->prepare($sqlUpdateVenta);
        $stmtUpdateVenta->bind_param("di", $comisionTotalVenta, $id_venta);
        $stmtUpdateVenta->execute();
    }

    // 🔹 SIMs
    if ($cobraSIMs) {
        $sqlSIMs = "SELECT id, tipo_venta FROM ventas_sims 
                    WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
        $stmtSIMs = $conn->prepare($sqlSIMs);
        $stmtSIMs->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
        $stmtSIMs->execute();
        $resultSIMs = $stmtSIMs->get_result();

        while ($sim = $resultSIMs->fetch_assoc()) {
            $comisionSim = comisionSIM($sim['tipo_venta']);
            $sqlUpdateSIM = "UPDATE ventas_sims SET comision_ejecutivo=? WHERE id=?";
            $stmtUSim = $conn->prepare($sqlUpdateSIM);
            $stmtUSim->bind_param("di", $comisionSim, $sim['id']);
            $stmtUSim->execute();
        }
    } else {
        echo "<p style='color:orange'>ℹ {$usuario['nombre']} no alcanza 4 equipos, no cobra SIMs</p>";
    }

    echo $cumpleCuota 
        ? "<p style='color:green'>✅ Comisiones recalculadas para {$usuario['nombre']}</p>"
        : "<p style='color:red'>❌ No cumple cuota completa, comisiones bajas aplicadas</p>";
}

echo "<h3>Recalculo completado ✅</h3>";
?>
