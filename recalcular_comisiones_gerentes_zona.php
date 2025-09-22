<?php
/* Recalcula e inserta comisiones para Gerentes de Zona por semana (mar–lun) */
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- Entrada ----
$fechaInicio = $_POST['fecha_inicio'] ?? ''; // Y-m-d (martes)
$semana      = (int)($_POST['semana'] ?? 0);
if (!$fechaInicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=Fecha+no+válida");
    exit();
}

// Rango mar–lun
$ini = new DateTime($fechaInicio.' 00:00:00');
$fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
$iniStr = $ini->format('Y-m-d H:i:s');
$finStr = $fin->format('Y-m-d H:i:s');

// ---- Cargar Gerentes de Zona ----
$gerentes = [];
$resG = $conn->query("
  SELECT u.id AS id_gerente, u.nombre AS gerente, s.zona
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  WHERE u.rol = 'GerenteZona'
");
while ($r = $resG->fetch_assoc()) {
    $z = trim((string)$r['zona']);
    if ($z === '') $z = 'Sin zona';
    $r['zona'] = $z;
    $gerentes[] = $r;
}
if (!$gerentes) {
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=No+hay+Gerentes+de+Zona");
    exit();
}

// ---- Sub-agregado de ventas de equipos (sin modem/mifi), agrupado por venta ----
$subAggEq = "
  SELECT
      v.id,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END)                          AS uds,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END)         AS monto
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p     ON p.id = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

// ---- Ventas y unidades de equipos por ZONA ----
$sqlEqZona = "
  SELECT s.zona, IFNULL(SUM(va.monto),0) AS ventas_eq, IFNULL(SUM(va.uds),0) AS uds_eq
  FROM sucursales s
  LEFT JOIN ( $subAggEq ) va ON va.id_sucursal = s.id
  GROUP BY s.zona
";
$stEq = $conn->prepare($sqlEqZona);
$stEq->bind_param("ss", $iniStr, $finStr);
$stEq->execute();
$resEq = $stEq->get_result();
$ventasZona = [];  // zona => monto equipos
$udsEqZona  = [];  // zona => uds equipos
while ($r = $resEq->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $ventasZona[$z] = (float)$r['ventas_eq'];
    $udsEqZona[$z]  = (int)$r['uds_eq'];
}
$stEq->close();

// ---- Unidades SIMs por ZONA (todas) ----
$stUS = $conn->prepare("
  SELECT s.zona, COUNT(dvs.id) AS uds_sims
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY s.zona
");
$stUS->bind_param("ss", $iniStr, $finStr);
$stUS->execute();
$resUS = $stUS->get_result();
$udsSimsZona = [];
while ($r = $resUS->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsSimsZona[$z] = (int)$r['uds_sims'];
}
$stUS->close();

// ---- Unidades Pospago por ZONA ----
$stUP = $conn->prepare("
  SELECT s.zona, COUNT(dvs.id) AS uds_pos
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ? AND vs.tipo_venta='Pospago'
  GROUP BY s.zona
");
$stUP->bind_param("ss", $iniStr, $finStr);
$stUP->execute();
$resUP = $stUP->get_result();
$udsPosZona = [];
while ($r = $resUP->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsPosZona[$z] = (int)$r['uds_pos'];
}
$stUP->close();

// ---- Comisión pospago por ZONA (por plan) ----
$stPos = $conn->prepare("
  SELECT s.zona, vs.precio_total, vs.modalidad
  FROM ventas_sims vs
  INNER JOIN sucursales s ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ? AND vs.tipo_venta='Pospago'
");
$stPos->bind_param("ss", $iniStr, $finStr);
$stPos->execute();
$resPos = $stPos->get_result();
$comPosZona = []; // zona => $
while ($r = $resPos->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $plan = (int)$r['precio_total'];
    $conEquipo = (isset($r['modalidad']) && $r['modalidad'] === 'Con equipo');
    $c = 0;
    if     ($plan >= 339) $c = $conEquipo ? 30 : 25;
    elseif ($plan >= 289) $c = $conEquipo ? 25 : 20;
    elseif ($plan >= 249) $c = $conEquipo ? 20 : 15;
    elseif ($plan >= 199) $c = $conEquipo ? 15 : 10;
    if (!isset($comPosZona[$z])) $comPosZona[$z] = 0.0;
    $comPosZona[$z] += $c;
}
$stPos->close();

// ---- Cuotas vigentes por SUCURSAL y suma por ZONA ----
$stC = $conn->prepare("
  SELECT s.zona, cv.cuota_monto
  FROM sucursales s
  LEFT JOIN (
    SELECT c1.id_sucursal, c1.cuota_monto
    FROM cuotas_sucursales c1
    JOIN (
      SELECT id_sucursal, MAX(fecha_inicio) AS max_f
      FROM cuotas_sucursales
      WHERE fecha_inicio <= ?
      GROUP BY id_sucursal
    ) x ON x.id_sucursal = c1.id_sucursal AND x.max_f = c1.fecha_inicio
  ) cv ON cv.id_sucursal = s.id
");
$stC->bind_param("s", $iniStr);
$stC->execute();
$resC = $stC->get_result();
$cuotaZona = []; // zona => $
while ($r = $resC->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $cu = (float)($r['cuota_monto'] ?? 0);
    if (!isset($cuotaZona[$z])) $cuotaZona[$z] = 0.0;
    $cuotaZona[$z] += $cu;
}
$stC->close();

// ---- Empezamos recalculo: limpiar semana e insertar resultados ----
$conn->begin_transaction();
try {
    // Borra semana
    $stDel = $conn->prepare("DELETE FROM comisiones_gerentes_zona WHERE fecha_inicio = ?");
    $stDel->bind_param("s", $ini->format('Y-m-d'));
    $stDel->execute();
    $stDel->close();

    // Insert preparado
    $stIns = $conn->prepare("
      INSERT INTO comisiones_gerentes_zona
      (id_gerente, fecha_inicio, zona, cuota_zona, ventas_zona, porcentaje_cumplimiento,
       comision_equipos, comision_modems, comision_sims, comision_pospago, comision_total)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($gerentes as $g) {
        $idGer   = (int)$g['id_gerente'];
        $zona    = $g['zona'];

        $ventas  = (float)($ventasZona[$zona] ?? 0.0);
        $cuota   = (float)($cuotaZona[$zona]  ?? 0.0);
        $udsEq   = (int)  ($udsEqZona[$zona]  ?? 0);
        $udsSIM  = (int)  ($udsSimsZona[$zona]?? 0);
        $comPos  = (float)($comPosZona[$zona] ?? 0.0);

        $cump = $cuota > 0 ? ($ventas / $cuota) * 100.0 : 0.0;

        // Regla de comisiones (basada en % cumplimiento de la zona)
        if ($cump < 80) {
            $comEq  = $udsEq * 10;
            $comSIM = 0;
        } elseif ($cump < 100) {
            $comEq  = $udsEq * 10;
            $comSIM = $udsSIM * 5;
        } else {
            $comEq  = $udsEq * 20;
            $comSIM = $udsSIM * 10;
        }
        $comMod = 0.0; // por ahora sin esquema de módems
        $comTot = $comEq + $comMod + $comSIM + $comPos;

        $fi = $ini->format('Y-m-d');
        $stIns->bind_param(
            "issdddddddd",
            $idGer, $fi, $zona, $cuota, $ventas, $cump,
            $comEq, $comMod, $comSIM, $comPos, $comTot
        );
        $stIns->execute();
    }

    $stIns->close();
    $conn->commit();

    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=".rawurlencode("✅ Semana recalculada correctamente"));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    $msg = "Error al recalcular: ".$e->getMessage();
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=".rawurlencode($msg));
    exit();
}
