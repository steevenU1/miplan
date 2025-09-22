<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   FUNCIONES AUXILIARES
======================== */
function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);

    if ($offset > 0) $inicio->modify("-" . (7*$offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana = $finSemanaObj->format('Y-m-d 23:59:59');

echo "<h3>🔄 Debug Comisión de Gerentes - Semana seleccionada</h3>";
echo "<p>Del {$inicioSemanaObj->format('d/m/Y')} al {$finSemanaObj->format('d/m/Y')}</p>";

/* ========================
   CONSULTA DE GERENTES
======================== */
$sqlGerentes = "
    SELECT u.id, u.nombre, s.id AS id_sucursal, s.nombre AS sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE u.rol = 'Gerente'
    ORDER BY s.nombre
";
$resGerentes = $conn->query($sqlGerentes);

$totalProcesados = 0;

while ($g = $resGerentes->fetch_assoc()) {
    $id_gerente = $g['id'];
    $id_sucursal = $g['id_sucursal'];

    echo "<div style='border:1px solid #ccc; margin:15px; padding:10px; background:#f9f9f9'>";
    echo "<h4>🏬 Sucursal: {$g['sucursal']} - Gerente: {$g['nombre']} (ID {$id_gerente})</h4>";

    /* ========================
       OBTENER CUOTA EN MONTO
    ========================= */
    $sqlCuota = "
        SELECT cuota_monto 
        FROM cuotas_sucursales
        WHERE id_sucursal=? AND fecha_inicio <= ? 
        ORDER BY fecha_inicio DESC
        LIMIT 1
    ";
    $stmtC = $conn->prepare($sqlCuota);
    $stmtC->bind_param("is", $id_sucursal, $inicioSemana);
    $stmtC->execute();
    $cuota_monto = (float)($stmtC->get_result()->fetch_assoc()['cuota_monto'] ?? 0);

    echo "<p>💰 Cuota semanal en monto: <b>$".number_format($cuota_monto,2)."</b></p>";

    /* ========================
       DETALLE DE VENTAS DE SUCURSAL
    ========================= */
    $sqlVentas = "
        SELECT dv.id, dv.precio_unitario, dv.comision_especial,
               p.marca, p.modelo, p.tipo_producto, v.id_usuario, v.fecha_venta
        FROM detalle_venta dv
        INNER JOIN ventas v ON dv.id_venta=v.id
        INNER JOIN productos p ON dv.id_producto=p.id
        WHERE v.id_sucursal=? 
          AND v.fecha_venta BETWEEN ? AND ?
        ORDER BY v.fecha_venta ASC
    ";
    $stmtV = $conn->prepare($sqlVentas);
    $stmtV->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
    $stmtV->execute();
    $ventas = $stmtV->get_result();

    $unidades = 0;
    $modems = 0;
    $sims = 0;
    $monto_total = 0;

    echo "<table border='1' cellpadding='4' cellspacing='0' style='margin:10px 0; border-collapse:collapse;'>
            <tr style='background:#eee'>
                <th>Fecha</th><th>Producto</th><th>Tipo</th><th>Precio</th>
            </tr>";

    while ($vta = $ventas->fetch_assoc()) {
        $tipo = strtolower($vta['tipo_producto']);
        $precio = (float)$vta['precio_unitario'];
        $monto_total += $precio;

        if ($tipo == 'mifi' || $tipo == 'modem') {
            $modems++;
        } elseif ($tipo == 'sim' || $tipo == 'chip' || $tipo == 'pospago') {
            $sims++;
        } else {
            $unidades++;
        }

        echo "<tr>
                <td>{$vta['fecha_venta']}</td>
                <td>{$vta['marca']} {$vta['modelo']}</td>
                <td>{$vta['tipo_producto']}</td>
                <td>$".number_format($precio,2)."</td>
              </tr>";
    }

    echo "</table>";

    echo "<p>📦 Resumen: <b>{$unidades}</b> unidades, 
          <b>{$modems}</b> modems, 
          <b>{$sims}</b> sims. 
          Monto total: <b>$".number_format($monto_total,2)."</b></p>";

    $cumple_cuota = $monto_total >= $cuota_monto;
    echo "<p>🏁 Cumple cuota de sucursal: " . ($cumple_cuota ? "✅ Sí" : "❌ No") . "</p>";

    $totalProcesados++;
    echo "</div>";
}

echo "<hr><h4>✅ Debug completado. Gerentes procesados: {$totalProcesados}</h4>";
echo '<a href="reporte_nomina.php?semana='.$semanaSeleccionada.'" class="btn btn-primary mt-3">← Volver al Reporte</a>';
?>
