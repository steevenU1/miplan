<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin', 'RH'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* ========================
   Constantes (blindaje)
======================== */
const GERENTE_COMISION_REGULAR_FIJA = 25.0;

/* ========================
   FUNCIONES AUXILIARES
======================== */
function obtenerSemanaPorIndice($offset = 0)
{
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=Lunes ... 7=Domingo
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0, 0, 0);
    if ($offset > 0) $inicio->modify("-" . (7 * $offset) . " days");

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23, 59, 59);

    return [$inicio, $fin];
}

/* Detección de columna (tipo vs tipo_producto; es_combo en detalle) */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    if ($rs = $conn->query($sql)) { $ok = $rs->num_rows > 0; $rs->close(); return $ok; }
    return false;
}
$colTipoProd    = hasColumn($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';
$tieneEsComboDV = hasColumn($conn, 'detalle_venta', 'es_combo');

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d 00:00:00');
$finSemana    = $finSemanaObj->format('Y-m-d 23:59:59');

echo "<h3>🔄 Recalculo total de comisiones - Semana seleccionada</h3>";
echo "<p>Del {$inicioSemanaObj->format('d/m/Y')} al {$finSemanaObj->format('d/m/Y')}</p>";

/* ========================
   ESQUEMAS (vigentes)
======================== */
$esqEje = $conn->query("SELECT * FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc();
$esqGer = $conn->query("SELECT * FROM esquemas_comisiones_gerentes   ORDER BY fecha_inicio DESC LIMIT 1")->fetch_assoc();

/* ========================
   REGLAS EJECUTIVO
======================== */
function comisionEjecutivoEquipo($precio, $tipoProducto, $cumpleCuota, $e)
{
    $tipo = strtolower($tipoProducto);
    if (in_array($tipo, ['mifi', 'modem'])) {
        return $cumpleCuota ? (float)$e['comision_mifi_con'] : (float)$e['comision_mifi_sin'];
    }
    if ($precio <= 3499) return $cumpleCuota ? (float)$e['comision_c_con'] : (float)$e['comision_c_sin'];
    if ($precio <= 5499) return $cumpleCuota ? (float)$e['comision_b_con'] : (float)$e['comision_b_sin'];
    return $cumpleCuota ? (float)$e['comision_a_con'] : (float)$e['comision_a_sin'];
}

function comisionPrepagoEjecutivo($tipoSim, $tipoVenta, $cumpleCuota, $e)
{
    $op = strtolower(trim($tipoSim));   // 'bait' | 'att'
    $t  = strtolower(trim($tipoVenta)); // 'nueva' | 'portabilidad'
    $nueva = ($t === 'nueva');
    if ($op === 'bait') {
        if ($cumpleCuota) return $nueva ? (float)$e['comision_sim_bait_nueva_con'] : (float)$e['comision_sim_bait_port_con'];
        return               $nueva ? (float)$e['comision_sim_bait_nueva_sin'] : (float)$e['comision_sim_bait_port_sin'];
    } else { // ATT
        if ($cumpleCuota) return $nueva ? (float)$e['comision_sim_att_nueva_con'] : (float)$e['comision_sim_att_port_con'];
        return               $nueva ? (float)$e['comision_sim_att_nueva_sin'] : (float)$e['comision_sim_att_port_sin'];
    }
}

/* ========================
   REGLAS GERENTE
======================== */
function gerenteVentaDirectaMonto($cumpleTienda, $g)
{
    return $cumpleTienda ? (float)$g['venta_directa_con'] : (float)$g['venta_directa_sin'];
}
function gerenteModemMonto($cumpleTienda, $g)
{
    return $cumpleTienda ? (float)$g['comision_modem_con'] : (float)$g['comision_modem_sin'];
}
function gerenteSimMonto($cumpleTienda, $g)
{
    return $cumpleTienda ? (float)$g['comision_sim_con']   : (float)$g['comision_sim_sin'];
}
function gerenteEscalonMonto($idx, $cumpleTienda, $g)
{
    if ($cumpleTienda) {
        if ($idx <= 10) return (float)$g['sucursal_1_10_con'];
        if ($idx <= 20) return (float)$g['sucursal_11_20_con'];
        return (float)$g['sucursal_21_mas_con'];
    } else {
        if ($idx <= 10) return (float)$g['sucursal_1_10_sin'];
        if ($idx <= 20) return (float)$g['sucursal_11_20_sin'];
        return (float)$g['sucursal_21_mas_sin'];
    }
}

function comisionPospagoGerente(mysqli $conn, float $planMonto, string $modalidad): float
{
    $sql = "SELECT comision_con_equipo, comision_sin_equipo
            FROM esquemas_comisiones_pospago
            WHERE tipo='Gerente' AND plan_monto=?
            ORDER BY fecha_inicio DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("d", $planMonto);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 0.0;
    $con = (stripos($modalidad, 'con') !== false);
    return (float)($con ? $row['comision_con_equipo'] : $row['comision_sin_equipo']);
}

/* === POSPAGO EJECUTIVO === */
function comisionPospagoEjecutivo(mysqli $conn, float $planMonto, string $modalidad): float
{
    $sql = "SELECT comision_con_equipo, comision_sin_equipo
            FROM esquemas_comisiones_pospago
            WHERE tipo='Ejecutivo' AND plan_monto=?
            ORDER BY fecha_inicio DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("d", $planMonto);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 0.0;
    $con = (stripos($modalidad, 'con') !== false); // 'Con equipo' vs 'Sin equipo'
    return (float)($con ? $row['comision_con_equipo'] : $row['comision_sin_equipo']);
}

/* ============================================================
   SUBCONSULTA BASE (alineada con dashboards)
============================================================ */
$subVentasAggSemana = "
  SELECT
      v.id,
      v.id_usuario,
      v.id_sucursal,
      v.fecha_venta AS fecha,
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
        ELSE COALESCE(d.has_non_modem,1)
      END AS unidades,
      CASE
        WHEN COALESCE(d.has_non_modem,1)=1 THEN v.precio_venta
        ELSE 0
      END AS monto
  FROM ventas v
  LEFT JOIN (
    SELECT dv.id_venta,
           MAX(CASE
                 WHEN LOWER(COALESCE(p.$colTipoProd,'')) IN ('modem','mifi') THEN 0
                 ELSE 1
               END) AS has_non_modem
    FROM detalle_venta dv
    LEFT JOIN productos p ON p.id = dv.id_producto
    GROUP BY dv.id_venta
  ) d ON d.id_venta = v.id
  WHERE v.fecha_venta BETWEEN ? AND ?
  GROUP BY v.id
";

/* ========================
   USUARIOS (Gerentes + Ejecutivos)
======================== */
$sqlUsuarios = "
    SELECT u.id, u.nombre, u.rol, u.id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id=u.id_sucursal
    WHERE u.rol IN ('Ejecutivo','Gerente')
    ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$resUsuarios = $conn->query($sqlUsuarios);

$totalProcesados = 0;

/* ========================
   LOOP POR USUARIO
======================== */
while ($u = $resUsuarios->fetch_assoc()) {
    $id_usuario  = (int)$u['id'];
    $id_sucursal = (int)$u['id_sucursal'];
    $rol         = $u['rol'];

    /* ===== 1) Unidades del EJECUTIVO para su cuota (POR VENTA) ===== */
    $sqlUnidades = "
        SELECT IFNULL(SUM(va.unidades),0) AS unidades
        FROM ( $subVentasAggSemana ) va
        WHERE va.id_usuario = ?
    ";
    $stmtU = $conn->prepare($sqlUnidades);
    $stmtU->bind_param("ssi", $inicioSemana, $finSemana, $id_usuario);
    $stmtU->execute();
    $unidades = (int)($stmtU->get_result()->fetch_assoc()['unidades'] ?? 0);
    $stmtU->close();

    // Cuota semanal (unidades) para ejecutivo — si no hay registro, default 6
    $sqlCuota = "
        SELECT cuota_unidades
        FROM cuotas_semanales_sucursal
        WHERE id_sucursal=? 
          AND semana_inicio <= ? 
          AND semana_fin   >= ?
        LIMIT 1
    ";
    $stmtC = $conn->prepare($sqlCuota);
    $stmtC->bind_param("iss", $id_sucursal, $inicioSemana, $inicioSemana);
    $stmtC->execute();
    $cuota = (int)($stmtC->get_result()->fetch_assoc()['cuota_unidades'] ?? 6);
    $stmtC->close();

    $cumpleCuotaEjecutivo = $unidades >= $cuota;

    /* ===== 1-bis) Cumplimiento de TIENDA si el usuario es GERENTE ===== */
    $esGerente = ($rol === 'Gerente');
    $cumpleTiendaGerente = false;
    if ($esGerente) {
        // Cuota en pesos de la sucursal
        $stmtQ = $conn->prepare("
          SELECT cuota_monto
          FROM cuotas_sucursales
          WHERE id_sucursal=? AND fecha_inicio <= ?
          ORDER BY fecha_inicio DESC
          LIMIT 1
        ");
        $stmtQ->bind_param("is", $id_sucursal, $inicioSemana);
        $stmtQ->execute();
        $cuotaMonto = (float)($stmtQ->get_result()->fetch_assoc()['cuota_monto'] ?? 0);
        $stmtQ->close();

        // Monto semanal sucursal (excluye ventas solo módem/MiFi)
        $stmtMS = $conn->prepare("
          SELECT IFNULL(SUM(va.monto),0) AS monto
          FROM ( $subVentasAggSemana ) va
          WHERE va.id_sucursal=?
        ");
        $stmtMS->bind_param("ssi", $inicioSemana, $finSemana, $id_sucursal);
        $stmtMS->execute();
        $montoSuc = (float)($stmtMS->get_result()->fetch_assoc()['monto'] ?? 0);
        $stmtMS->close();

        $cumpleTiendaGerente = $cuotaMonto > 0 ? ($montoSuc >= $cuotaMonto) : false;
    }

    /* ===== 2) Recalcular EQUIPOS (rol-aware) ===== */
    $sqlVentas = "SELECT id FROM ventas WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
    $stmtV = $conn->prepare($sqlVentas);
    $stmtV->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtV->execute();
    $resVentas = $stmtV->get_result();

    while ($venta = $resVentas->fetch_assoc()) {
        $id_venta = (int)$venta['id'];
        $totalVenta = 0.0;
        $totalVentaEspecial = 0.0;

        // Traemos es_combo si existe; si no, 0 AS es_combo
        $sqlDV = "
            SELECT dv.id,
                   dv.precio_unitario,
                   dv.comision_especial,
                   p.$colTipoProd AS tipo_producto" .
                   ($tieneEsComboDV ? ", dv.es_combo" : ", 0 AS es_combo") . "
            FROM detalle_venta dv
            INNER JOIN productos p ON dv.id_producto=p.id
            WHERE dv.id_venta=?
        ";
        $stmtDV = $conn->prepare($sqlDV);
        $stmtDV->bind_param("i", $id_venta);
        $stmtDV->execute();
        $detalles = $stmtDV->get_result();

        while ($det = $detalles->fetch_assoc()) {
            $espOriginal = (float)$det['comision_especial'];
            $esCombo     = (int)($det['es_combo'] ?? 0) === 1;

            // Comisión especial:
            // - Gerente: se respeta lo capturado
            // - Ejecutivo: 0 si no cumple su cuota personal
            $comEsp = $esGerente ? $espOriginal : ($cumpleCuotaEjecutivo ? $espOriginal : 0.0);
            if ($comEsp !== $espOriginal) {
                $stmtUpdEsp = $conn->prepare("UPDATE detalle_venta SET comision_especial=? WHERE id=?");
                $stmtUpdEsp->bind_param("di", $comEsp, $det['id']);
                $stmtUpdEsp->execute();
                $stmtUpdEsp->close();
            }

            // Comisión REGULAR:
            if ($esGerente) {
                // 🔒 Blindado: gerente venta directa SIEMPRE $25 por renglón (principal y combo)
                $comReg = GERENTE_COMISION_REGULAR_FIJA;
            } else {
                // Ejecutivo
                if ($esCombo) {
                    // Combo del ejecutivo SIEMPRE 75
                    $comReg = 75.0;
                } else {
                    $comReg = comisionEjecutivoEquipo(
                        (float)$det['precio_unitario'],
                        $det['tipo_producto'],
                        $cumpleCuotaEjecutivo,
                        $esqEje
                    );
                }
            }

            $comTot = $comReg + $comEsp;

            $stmtUpdDV = $conn->prepare("UPDATE detalle_venta SET comision_regular=?, comision=? WHERE id=?");
            $stmtUpdDV->bind_param("ddi", $comReg, $comTot, $det['id']);
            $stmtUpdDV->execute();
            $stmtUpdDV->close();

            $totalVenta += $comTot;
            $totalVentaEspecial += $comEsp;
        }
        $stmtDV->close();

        $stmtUpdV = $conn->prepare("UPDATE ventas SET comision=?, comision_especial=? WHERE id=?");
        $stmtUpdV->bind_param("ddi", $totalVenta, $totalVentaEspecial, $id_venta);
        $stmtUpdV->execute();
        $stmtUpdV->close();
    }
    $stmtV->close();

    /* ===== 3) Recalcular EJECUTIVO: PREPAGO ===== */
    $sqlPrepago = "
        SELECT vs.id, vs.tipo_venta, vs.tipo_sim
        FROM ventas_sims vs
        WHERE vs.id_usuario=?
          AND vs.fecha_venta BETWEEN ? AND ?
          AND vs.tipo_venta IN ('Nueva','Portabilidad')
    ";
    $stmtPrep = $conn->prepare($sqlPrepago);
    $stmtPrep->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtPrep->execute();
    $resPrep = $stmtPrep->get_result();

    while ($row = $resPrep->fetch_assoc()) {
        $id_vs = (int)$row['id'];
        $com   = comisionPrepagoEjecutivo($row['tipo_sim'], $row['tipo_venta'], $cumpleCuotaEjecutivo, $esqEje);
        $stmtUP = $conn->prepare("UPDATE ventas_sims SET comision_ejecutivo=? WHERE id=?");
        $stmtUP->bind_param("di", $com, $id_vs);
        $stmtUP->execute();
        $stmtUP->close();
    }
    $stmtPrep->close();

    /* ===== 3-bis) Recalcular EJECUTIVO: POSPAGO ===== */
    $sqlPosE = "
        SELECT id, precio_total, modalidad
        FROM ventas_sims
        WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?
          AND tipo_venta='Pospago'
    ";
    $stmtPE = $conn->prepare($sqlPosE);
    $stmtPE->bind_param("iss", $id_usuario, $inicioSemana, $finSemana);
    $stmtPE->execute();
    $resPE = $stmtPE->get_result();
    while ($r = $resPE->fetch_assoc()) {
        $comE = comisionPospagoEjecutivo($conn, (float)$r['precio_total'], $r['modalidad']);
        $stmt = $conn->prepare("UPDATE ventas_sims SET comision_ejecutivo=? WHERE id=?");
        $stmt->bind_param("di", $comE, $r['id']);
        $stmt->execute();
        $stmt->close();
    }
    $stmtPE->close();

    /* ===== 4) Recalcular GERENTE: por SUCURSAL ===== */
    if ($esGerente) {
        // 4.2 Reset semana
        $stmt = $conn->prepare("UPDATE ventas SET comision_gerente=0 WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?");
        $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE ventas_sims SET comision_gerente=0 WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?");
        $stmt->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmt->execute();
        $stmt->close();

        // 4.3 Venta DIRECTA gerente (por venta)
        $sqlVD = "
            SELECT va.id AS id_venta, va.unidades
            FROM ( $subVentasAggSemana ) va
            WHERE va.id_usuario = ?
        ";
        $stmtVD = $conn->prepare($sqlVD);
        $stmtVD->bind_param("ssi", $inicioSemana, $finSemana, $id_usuario);
        $stmtVD->execute();
        $resVD = $stmtVD->get_result();

        $pagoVD = gerenteVentaDirectaMonto($cumpleTiendaGerente, $esqGer);
        while ($r = $resVD->fetch_assoc()) {
            $monto = $pagoVD * (int)$r['unidades'];
            $stmt = $conn->prepare("UPDATE ventas SET comision_gerente = comision_gerente + ? WHERE id=?");
            $stmt->bind_param("di", $monto, $r['id_venta']);
            $stmt->execute();
            $stmt->close();
        }
        $stmtVD->close();

        // 4.4 Escalonados sucursal
        $sqlEq = "
            SELECT va.id AS id_venta, va.unidades, va.fecha
            FROM ( $subVentasAggSemana ) va
            WHERE va.id_sucursal = ?
            ORDER BY va.fecha, va.id
        ";
        $stmtEq = $conn->prepare($sqlEq);
        $stmtEq->bind_param("ssi", $inicioSemana, $finSemana, $id_sucursal);
        $stmtEq->execute();
        $resEq = $stmtEq->get_result();

        $porVenta = [];
        $idx = 0;
        while ($row = $resEq->fetch_assoc()) {
            $n = (int)$row['unidades'];
            if ($n <= 0) continue;
            $pago = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $idx++;
                $pago += gerenteEscalonMonto($idx, $cumpleTiendaGerente, $esqGer);
            }
            $porVenta[(int)$row['id_venta']] = ($porVenta[(int)$row['id_venta']] ?? 0) + $pago;
        }
        $stmtEq->close();

        foreach ($porVenta as $idVenta => $monto) {
            $stmt = $conn->prepare("UPDATE ventas SET comision_gerente = comision_gerente + ? WHERE id=?");
            $stmt->bind_param("di", $monto, $idVenta);
            $stmt->execute();
            $stmt->close();
        }

        // 4.5 Modems/MiFi — por línea
        $sqlMod = "
            SELECT v.id AS id_venta, COUNT(dv.id) AS unidades
            FROM detalle_venta dv
            INNER JOIN ventas v ON dv.id_venta=v.id
            INNER JOIN productos p ON dv.id_producto=p.id
            WHERE v.id_sucursal=? AND v.fecha_venta BETWEEN ? AND ?
              AND LOWER(p.$colTipoProd) IN ('mifi','modem')
            GROUP BY v.id
        ";
        $stmtMod = $conn->prepare($sqlMod);
        $stmtMod->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtMod->execute();
        $resMod = $stmtMod->get_result();
        $pagoMod = gerenteModemMonto($cumpleTiendaGerente, $esqGer);
        while ($r = $resMod->fetch_assoc()) {
            $monto = $pagoMod * (int)$r['unidades'];
            $stmt = $conn->prepare("UPDATE ventas SET comision_gerente = comision_gerente + ? WHERE id=?");
            $stmt->bind_param("di", $monto, $r['id_venta']);
            $stmt->execute();
            $stmt->close();
        }
        $stmtMod->close();

        // 4.6 SIMS PREPAGO
        $sqlSimsG = "
            SELECT id
            FROM ventas_sims
            WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?
              AND tipo_venta IN ('Nueva','Portabilidad')
        ";
        $stmtSG = $conn->prepare($sqlSimsG);
        $stmtSG->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtSG->execute();
        $resSG = $stmtSG->get_result();
        $pagoSim = gerenteSimMonto($cumpleTiendaGerente, $esqGer);
        while ($r = $resSG->fetch_assoc()) {
            $stmt = $conn->prepare("UPDATE ventas_sims SET comision_gerente=? WHERE id=?");
            $stmt->bind_param("di", $pagoSim, $r['id']);
            $stmt->execute();
            $stmt->close();
        }
        $stmtSG->close();

        // 4.7 POSPAGO
        $sqlPosG = "
            SELECT id, precio_total, modalidad
            FROM ventas_sims
            WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?
              AND tipo_venta='Pospago'
        ";
        $stmtPG = $conn->prepare($sqlPosG);
        $stmtPG->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
        $stmtPG->execute();
        $resPG = $stmtPG->get_result();
        while ($r = $resPG->fetch_assoc()) {
            $comG = comisionPospagoGerente($conn, (float)$r['precio_total'], $r['modalidad']);
            $stmt = $conn->prepare("UPDATE ventas_sims SET comision_gerente=? WHERE id=?");
            $stmt->bind_param("di", $comG, $r['id']);
            $stmt->execute();
            $stmt->close();
        }
        $stmtPG->close();
    }

    echo "<p>" . ($rol == "Gerente" ? "🟩" : "🟦") . " {$rol} {$u['nombre']} – Unidades(Eje): {$unidades}/{$cuota} → " . ($cumpleCuotaEjecutivo ? '✅' : '❌') . "</p>";
    $totalProcesados++;
}

echo "<hr><h4>✅ Recalculo completado. Usuarios procesados: {$totalProcesados}</h4>";
echo '<a href="reporte_nomina.php?semana=' . $semanaSeleccionada . '" class="btn btn-primary mt-3">← Volver al Reporte</a>';
