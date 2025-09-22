<?php
/* reporte_nomina_gerentes_zona.php */
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}
require_once __DIR__ . '/db.php';

/* ========================
   Helpers & Semana (mar-lun)
======================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = (int)$hoy->format('N'); // 1=lun..7=dom
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);
    if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');

    $fin = clone $inicio;
    $fin->modify('+6 days')->setTime(23,59,59);

    return [$inicio, $fin];
}

/* ========================
   Semana seleccionada
======================== */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$fechaInicio = $inicioSemanaObj->format('Y-m-d');
$fechaFin    = $finSemanaObj->format('Y-m-d');

/* ========================
   Gerentes de Zona
======================== */
$sqlGerentes = "
    SELECT u.id, u.nombre, s.zona
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.rol='GerenteZona'
";
$gerentes = $conn->query($sqlGerentes);

/* ========================
   Histórico (si existe)
======================== */
$stmtHist = $conn->prepare("
    SELECT cgz.*, u.nombre AS gerente
    FROM comisiones_gerentes_zona cgz
    INNER JOIN usuarios u ON cgz.id_gerente = u.id
    WHERE cgz.fecha_inicio = ?
    ORDER BY cgz.zona
");
$stmtHist->bind_param("s", $fechaInicio);
$stmtHist->execute();
$resultHist = $stmtHist->get_result();

$datos = [];

if ($resultHist->num_rows > 0) {
    while ($row = $resultHist->fetch_assoc()) {
        $datos[] = [
            'gerente'      => $row['gerente'],
            'zona'         => $row['zona'],
            'cumplimiento' => (float)$row['porcentaje_cumplimiento'], // se recalcula más abajo
            'com_equipos'  => (float)$row['comision_equipos'],
            'com_sims'     => (float)$row['comision_sims'],
            'com_pospago'  => (float)$row['comision_pospago'],
            'com_total'    => (float)$row['comision_total'],
            // columnas de unidades (se llenan más abajo)
            'u_eq'         => 0,
            'u_sims'       => 0,
            'u_pos'        => 0,
        ];
    }
} else {
    /* ========================
       Sin histórico → calcular comisiones (tu misma lógica base)
    ======================== */
    while ($g = $gerentes->fetch_assoc()) {
        $idGerente     = (int)$g['id'];
        $zona          = $g['zona'];
        $nombreGerente = $g['nombre'];

        // 1) Equipos (para comisión)
        $stmtEq = $conn->prepare("
            SELECT COUNT(dv.id) AS total_equipos, IFNULL(SUM(v.precio_venta),0) AS monto
            FROM detalle_venta dv
            INNER JOIN ventas v ON dv.id_venta = v.id
            INNER JOIN sucursales s ON s.id = v.id_sucursal
            WHERE s.zona = ? AND DATE(v.fecha_venta) BETWEEN ? AND ?
        ");
        $stmtEq->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtEq->execute();
        $rowEq = $stmtEq->get_result()->fetch_assoc();
        $stmtEq->close();

        $totalEquipos = (int)$rowEq['total_equipos'];

        // 2) SIMs (para comisión)
        $stmtSims = $conn->prepare("
            SELECT COUNT(dvs.id) AS total_sims
            FROM detalle_venta_sims dvs
            INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
            INNER JOIN sucursales s ON s.id = vs.id_sucursal
            WHERE s.zona = ? AND DATE(vs.fecha_venta) BETWEEN ? AND ?
        ");
        $stmtSims->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtSims->execute();
        $rowSims = $stmtSims->get_result()->fetch_assoc();
        $stmtSims->close();

        $totalSims  = (int)$rowSims['total_sims'];

        // 3) % (lo establecemos más abajo con el método correcto de zona)
        $cumplimiento = 0.0;

        // 4) Comisiones base (como tenías)
        if ($cumplimiento < 80) {
            $comEquipos = $totalEquipos * 10;
            $comSims    = 0;
        } elseif ($cumplimiento < 100) {
            $comEquipos = $totalEquipos * 10;
            $comSims    = $totalSims * 5;
        } else {
            $comEquipos = $totalEquipos * 20;
            $comSims    = $totalSims * 10;
        }

        // 5) Pospago (para comisión)
        $comPospago = 0.0;
        $stmtPos = $conn->prepare("
            SELECT vs.precio_total, vs.modalidad
            FROM ventas_sims vs
            INNER JOIN sucursales s ON s.id = vs.id_sucursal
            WHERE s.zona = ?
              AND DATE(vs.fecha_venta) BETWEEN ? AND ?
              AND vs.tipo_venta = 'Pospago'
        ");
        $stmtPos->bind_param("sss", $zona, $fechaInicio, $fechaFin);
        $stmtPos->execute();
        $resPos = $stmtPos->get_result();
        while ($row = $resPos->fetch_assoc()) {
            $plan      = (int)$row['precio_total'];
            $conEquipo = ($row['modalidad'] === 'Con equipo');
            if     ($plan >= 339) $comPospago += $conEquipo ? 30 : 25;
            elseif ($plan >= 289) $comPospago += $conEquipo ? 25 : 20;
            elseif ($plan >= 249) $comPospago += $conEquipo ? 20 : 15;
            elseif ($plan >= 199) $comPospago += $conEquipo ? 15 : 10;
        }
        $stmtPos->close();

        $comTotal  = $comEquipos + $comSims + $comPospago;

        $datos[] = [
            'gerente'      => $nombreGerente,
            'zona'         => $zona,
            'cumplimiento' => $cumplimiento,  // se fija más abajo
            'com_equipos'  => $comEquipos,
            'com_sims'     => $comSims,
            'com_pospago'  => $comPospago,
            'com_total'    => $comTotal,
            'u_eq'         => 0,
            'u_sims'       => 0,
            'u_pos'        => 0,
        ];
    }
}

/* =========================================================
   ALCANCE EXACTO (como Dashboard) y UNIDADES por zona
========================================================= */

/* Sub-agregado de ventas de equipos (excluye modem/mifi) con monto y UNIDADES */
$subAggEq = "
  SELECT
      v.id,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END)                           AS uds,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END)          AS monto
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p     ON p.id = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* Ventas $ por ZONA (equipos sin modem/mifi) */
$sqlVentasZona = "
  SELECT s.zona, IFNULL(SUM(va.monto),0) AS ventas_zona
  FROM sucursales s
  LEFT JOIN ( $subAggEq ) va ON va.id_sucursal = s.id
  GROUP BY s.zona
";
$stmtV = $conn->prepare($sqlVentasZona);
$stmtV->bind_param("ss", $fechaInicio, $fechaFin);
$stmtV->execute();
$resV = $stmtV->get_result();
$ventasZonaMap = [];
while ($r = $resV->fetch_assoc()) {
  $z = trim((string)$r['zona']);
  if ($z === '') $z = 'Sin zona';
  $ventasZonaMap[$z] = (float)$r['ventas_zona'];
}
$stmtV->close();

/* UNIDADES de equipos por ZONA (excluye modem/mifi) */
$sqlUdsEqZona = "
  SELECT s.zona, IFNULL(SUM(va.uds),0) AS uds_eq
  FROM sucursales s
  LEFT JOIN ( $subAggEq ) va ON va.id_sucursal = s.id
  GROUP BY s.zona
";
$stmtUE = $conn->prepare($sqlUdsEqZona);
$stmtUE->bind_param("ss", $fechaInicio, $fechaFin);
$stmtUE->execute();
$resUE = $stmtUE->get_result();
$udsEqZona = [];
while ($r = $resUE->fetch_assoc()) {
  $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
  $udsEqZona[$z] = (int)$r['uds_eq'];
}
$stmtUE->close();

/* Cuota vigente por SUCURSAL y suma por ZONA */
$sqlCuotasSucVig = "
  SELECT s.id AS id_sucursal, s.zona AS zona, cv.cuota_monto
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
";
$stmtC = $conn->prepare($sqlCuotasSucVig);
$stmtC->bind_param("s", $fechaInicio);
$stmtC->execute();
$resC = $stmtC->get_result();
$cuotaZonaMap = [];
while ($r = $resC->fetch_assoc()) {
  $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
  $cu = (float)($r['cuota_monto'] ?? 0);
  if (!isset($cuotaZonaMap[$z])) $cuotaZonaMap[$z] = 0.0;
  $cuotaZonaMap[$z] += $cu;
}
$stmtC->close();

/* UNIDADES SIMs por ZONA (todas las SIMs) */
$sqlUdsSimsZona = "
  SELECT s.zona, COUNT(dvs.id) AS uds_sims
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?
  GROUP BY s.zona
";
$stmtUS = $conn->prepare($sqlUdsSimsZona);
$stmtUS->bind_param("ss", $fechaInicio, $fechaFin);
$stmtUS->execute();
$resUS = $stmtUS->get_result();
$udsSimsZona = [];
while ($r = $resUS->fetch_assoc()) {
  $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
  $udsSimsZona[$z] = (int)$r['uds_sims'];
}
$stmtUS->close();

/* UNIDADES Pospago por ZONA (conteo de DETALLE de SIMs en ventas_sims POSPAGO) ✅ */
$sqlUdsPosZona = "
  SELECT s.zona, COUNT(dvs.id) AS uds_pos
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(vs.fecha_venta) BETWEEN ? AND ? AND vs.tipo_venta='Pospago'
  GROUP BY s.zona
";
$stmtUP = $conn->prepare($sqlUdsPosZona);
$stmtUP->bind_param("ss", $fechaInicio, $fechaFin);
$stmtUP->execute();
$resUP = $stmtUP->get_result();
$udsPosZona = [];
while ($r = $resUP->fetch_assoc()) {
  $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
  $udsPosZona[$z] = (int)$r['uds_pos'];
}
$stmtUP->close();

/* Cumplimiento por ZONA y asignación + unidades por zona a cada gerente */
$cumpZonaMap = [];
foreach ($cuotaZonaMap as $z => $cuotaZ) {
  $ventasZ = (float)($ventasZonaMap[$z] ?? 0.0);
  $cumpZonaMap[$z] = $cuotaZ > 0 ? ($ventasZ / $cuotaZ) * 100.0 : 0.0;
}
foreach ($datos as &$d) {
  $z = trim((string)$d['zona']); if ($z === '') $z = 'Sin zona';
  $d['cumplimiento'] = (float)($cumpZonaMap[$z] ?? 0.0);
  $d['u_eq']   = (int)($udsEqZona[$z]  ?? 0);
  $d['u_sims'] = (int)($udsSimsZona[$z]?? 0);
  $d['u_pos']  = (int)($udsPosZona[$z] ?? 0);
}
unset($d);

/* ========================
   Totales / promedio
======================== */
$total_com_equipos   = array_sum(array_column($datos, 'com_equipos'));
$total_com_sims      = array_sum(array_column($datos, 'com_sims'));
$total_com_pospago   = array_sum(array_column($datos, 'com_pospago'));
$total_comisiones    = array_sum(array_column($datos, 'com_total'));
$total_global        = $total_comisiones;
$prom_cumplimiento   = count($datos) ? array_sum(array_map(fn($d)=> (float)$d['cumplimiento'], $datos)) / count($datos) : 0.0;

$total_u_eq   = array_sum(array_column($datos,'u_eq'));
$total_u_sims = array_sum(array_column($datos,'u_sims'));
$total_u_pos  = array_sum(array_column($datos,'u_pos'));

/* ========================
   Zonas para filtro rápido
======================== */
$zonas = [];
foreach ($datos as $d) { $zonas[$d['zona']] = true; }
ksort($zonas, SORT_NATURAL | SORT_FLAG_CASE);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Nómina — Gerentes de Zona</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --card-bg:#fff; --chip:#f1f5f9; }
    body{ background:#f7f7fb; }
    .page-header{ display:flex; gap:1rem; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .page-title{ display:flex; gap:.75rem; align-items:center; }
    .page-title .emoji{ font-size:1.6rem; }
    .card-soft{ background:var(--card-bg); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .summary-cards .card-soft{ min-width:220px; }
    .chip{ display:inline-flex; gap:.5rem; align-items:center; background:var(--chip); border-radius:999px; padding:.4rem .7rem; font-size:.9rem; }
    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #e5e7eb; }
    .th-sort{ cursor:pointer; white-space:nowrap; }

    /* Pills de % cumplimiento (forzamos color para que se lean SIEMPRE) */
    .pct-pill{ color:#111 !important; font-weight:700; }
    .badge-cump-low{   background:#fee2e2; border:1px solid #fecaca; }
    .badge-cump-mid{   background:#fff7ed; border:1px solid #fed7aa; }
    .badge-cump-high{  background:#ecfdf5; border:1px solid #a7f3d0; }

    @media print{
      .no-print{ display:none !important; }
      body{ background:#fff; }
      .card-soft{ border:0; box-shadow:none; }
      .table thead th{ position:static; }
    }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-4">
  <!-- Header -->
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">🧭</span>
      <div>
        <h3 class="mb-0">Nómina — Gerentes de Zona</h3>
        <div class="text-muted small">Semana del <strong><?= $inicioSemanaObj->format('d/m/Y') ?></strong> al <strong><?= $finSemanaObj->format('d/m/Y') ?></strong></div>
      </div>
    </div>

    <div class="no-print">
      <form method="GET" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-muted">Semana</label>
        <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <?php for($i=0;$i<8;$i++):
            list($ini,$fin) = obtenerSemanaPorIndice($i);
            $txt = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
          ?>
          <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $txt ?></option>
          <?php endfor; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
          <i class="bi bi-printer me-1"></i> Imprimir
        </button>
      </form>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="summary-cards d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Gerentes</div>
      <div class="h4 mb-0"><?= count($datos) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Promedio Cumplimiento</div>
      <div class="h5 mb-0"><?= number_format($prom_cumplimiento,1) ?>%</div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total Comisiones</div>
      <div class="h5 mb-0">$<?= number_format($total_comisiones,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total a Pagar</div>
      <div class="h5 mb-0">$<?= number_format($total_global,2) ?></div>
    </div>

    <!-- Card de filtros/botones (regresada) -->
    <div class="card-soft p-3 no-print" style="flex:1">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small text-muted">Filtrar por zona</label>
          <select id="fZona" class="form-select form-select-sm">
            <option value="">Todas</option>
            <?php foreach(array_keys($zonas) as $z): ?>
              <option value="<?= h($z) ?>"><?= h($z) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label mb-1 small text-muted">Buscar</label>
          <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Gerente, zona…">
        </div>
        <div class="col-12">
          <div class="d-flex flex-wrap gap-2">
            <button id="btnExport" class="btn btn-outline-primary btn-sm">
              <i class="bi bi-filetype-csv me-1"></i> Exportar CSV
            </button>
            <form action="recalcular_comisiones_gerentes_zona.php" method="POST" class="d-inline">
              <input type="hidden" name="fecha_inicio" value="<?= $fechaInicio ?>">
              <input type="hidden" name="semana" value="<?= $semanaSeleccionada ?>">
              <button type="submit" class="btn btn-warning btn-sm"
                onclick="return confirm('¿Seguro que deseas recalcular las comisiones de esta semana?');">
                <i class="bi bi-arrow-repeat me-1"></i> Recalcular semana
              </button>
            </form>
            <a href="exportar_nomina_gerentes_excel.php?semana=<?= $fechaInicio ?>" class="btn btn-success btn-sm">
              <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card-soft p-0">
    <div class="table-responsive">
      <table id="tablaZona" class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th class="th-sort" data-key="gerente">Gerente <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="zona">Zona <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="cump">% Cumpl. <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="ueq">Uds. Equipos <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="usims">Uds. SIMs <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="upos">Uds. Pospago <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="eq">Com. Equipos <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="sims">Com. SIMs <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="pos">Com. Pospago <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="com">Total Comisión <i class="bi bi-arrow-down-up ms-1"></i></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($datos as $d):
          $cump = (float)$d['cumplimiento'];
          $cls  = $cump >= 100 ? 'badge-cump-high' : ($cump >= 80 ? 'badge-cump-mid' : 'badge-cump-low');
        ?>
          <tr
            data-gerente="<?= h($d['gerente']) ?>"
            data-zona="<?= h($d['zona']) ?>"
            data-cump="<?= $cump ?>"
            data-ueq="<?= (int)$d['u_eq'] ?>"
            data-usims="<?= (int)$d['u_sims'] ?>"
            data-upos="<?= (int)$d['u_pos'] ?>"
            data-eq="<?= (float)$d['com_equipos'] ?>"
            data-sims="<?= (float)$d['com_sims'] ?>"
            data-pos="<?= (float)$d['com_pospago'] ?>"
            data-com="<?= (float)$d['com_total'] ?>"
          >
            <td class="fw-semibold"><?= h($d['gerente']) ?></td>
            <td><span class="chip"><?= h($d['zona']) ?></span></td>
            <td class="text-center">
              <span class="badge rounded-pill pct-pill <?= $cls ?>"><?= number_format($cump,1) ?>%</span>
            </td>
            <td class="text-end"><?= (int)$d['u_eq'] ?></td>
            <td class="text-end"><?= (int)$d['u_sims'] ?></td>
            <td class="text-end"><?= (int)$d['u_pos'] ?></td>
            <td class="text-end">$<?= number_format($d['com_equipos'],2) ?></td>
            <td class="text-end">$<?= number_format($d['com_sims'],2) ?></td>
            <td class="text-end">$<?= number_format($d['com_pospago'],2) ?></td>
            <td class="text-end fw-semibold">$<?= number_format($d['com_total'],2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <th colspan="3">Totales</th>
            <th class="text-end"><?= (int)$total_u_eq ?></th>
            <th class="text-end"><?= (int)$total_u_sims ?></th>
            <th class="text-end"><?= (int)$total_u_pos ?></th>
            <th class="text-end">$<?= number_format($total_com_equipos,2) ?></th>
            <th class="text-end">$<?= number_format($total_com_sims,2) ?></th>
            <th class="text-end">$<?= number_format($total_com_pospago,2) ?></th>
            <th class="text-end">$<?= number_format($total_comisiones,2) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="mt-2 text-muted small">
    * % Cumplimiento: ventas de <b>equipos (sin modem/MiFi)</b> vs suma de <b>cuotas vigentes</b> por sucursal en la zona (misma lógica del dashboard).<br>
    * “Uds. Pospago” cuenta líneas en <code>detalle_venta_sims</code> de ventas_sims con <code>tipo_venta='Pospago'</code>.
  </div>

  <?php if (!empty($_GET['debug'])): ?>
    <div class="card-soft p-3 my-3">
      <h5 class="mb-2">Diagnóstico de Cumplimiento</h5>
      <p class="text-muted small">Semana del <b><?= h($fechaInicio) ?></b> al <b><?= h($fechaFin) ?></b> (ventas con CONVERT_TZ a -06:00)</p>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>Zona</th>
              <th>Ventas Zona (equipos)</th>
              <th>Cuota Zona</th>
              <th>% Cumpl.</th>
              <th>Uds. Equipos</th>
              <th>Uds. SIMs</th>
              <th>Uds. Pospago</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cumpZonaMap as $z => $pc): ?>
              <tr>
                <td><?= h($z) ?></td>
                <td>$<?= number_format((float)($ventasZonaMap[$z] ?? 0),2) ?></td>
                <td>$<?= number_format((float)($cuotaZonaMap[$z]  ?? 0),2) ?></td>
                <td><?= number_format($pc,1) ?>%</td>
                <td><?= (int)($udsEqZona[$z]   ?? 0) ?></td>
                <td><?= (int)($udsSimsZona[$z] ?? 0) ?></td>
                <td><?= (int)($udsPosZona[$z]  ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- JS filtros/orden/export -->
<script>
  const fZona   = document.getElementById('fZona');
  const fSearch = document.getElementById('fSearch');
  const tbody   = document.querySelector('#tablaZona tbody');

  function applyFilters(){
    const z = (fZona?.value || '').toLowerCase();
    const q = (fSearch?.value || '').toLowerCase();
    [...tbody.rows].forEach(tr=>{
      const tz  = (tr.dataset.zona||'').toLowerCase();
      const txt = (tr.textContent||'').toLowerCase();
      let ok = true;
      if (z && tz !== z) ok = false;
      if (q && !txt.includes(q)) ok = false;
      tr.style.display = ok ? '' : 'none';
    });
  }
  fZona && fZona.addEventListener('change', applyFilters);
  fSearch && fSearch.addEventListener('input', applyFilters);

  let sortState = { key:null, dir:1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key===key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
    });
  });
  function sortRows(key, dir){
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      const va = a.dataset[key] ?? '';
      const vb = b.dataset[key] ?? '';
      const na = Number(va), nb = Number(vb);
      if(!Number.isNaN(na) && !Number.isNaN(nb)) return (na-nb)*dir;
      return String(va).localeCompare(String(vb), 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }

  document.getElementById('btnExport')?.addEventListener('click', ()=>{
    const headers = [...document.querySelectorAll('#tablaZona thead th')].map(th=>th.innerText.trim());
    const rows = [];
    [...tbody.rows].forEach(tr=>{
      if (tr.style.display === 'none') return;
      rows.push([...tr.cells].map(td=>td.innerText.replace(/\s+/g,' ').trim()));
    });
    const csv = [headers, ...rows].map(r=>r.map(v=>`"${v.replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'nomina_gerentes_zona.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });
</script>
</body>
</html>
