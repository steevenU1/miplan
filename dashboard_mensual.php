<?php
session_start();
if (empty($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}
require_once __DIR__ . '/db.php';

/* --------------------------
   Utilidades
---------------------------*/
function nombreMes($mes)
{
  $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
  return $meses[$mes] ?? '';
}
function normalizarZona($raw)
{
  $t = trim((string)$raw);
  if ($t === '') return null;
  $t = preg_replace('/^(?:\s*Zona\s+)+/i', 'Zona ', $t);
  if (preg_match('/(\d+)/', $t, $m)) return 'Zona ' . (int)$m[1];
  if (preg_match('/^Zona\s+\S+/i', $t)) return preg_replace('/\s+/', ' ', $t);
  return $t;
}
function badgeFila($pct)
{
  if ($pct === null) return '';
  return $pct >= 100 ? 'table-success' : ($pct >= 60 ? 'table-warning' : 'table-danger');
}
/* iconito ▲/▼ y clase bootstrap */
function arrowIcon($delta)
{
  if ($delta > 0) return ['▲', 'text-success'];
  if ($delta < 0) return ['▼', 'text-danger'];
  return ['▬', 'text-secondary'];
}
function pctDelta($curr, $prev)
{
  if ($prev == 0) return null;
  return (($curr - $prev) / $prev) * 100.0;
}
/* Quita prefijo “Luga ” */
function sucursalCorta($s)
{
  return trim(preg_replace('/^\s*Luga\s+/i', '', (string)$s));
}

/* --------------------------
   Mes/Año seleccionados
---------------------------*/
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));
$diasMes   = (int)date('t', strtotime($inicioMes));
$factorSem = 7 / max(1, $diasMes);

/* Mes anterior */
$inicioPrevMes = date('Y-m-01', strtotime("$inicioMes -1 month"));
$finPrevMes    = date('Y-m-t', strtotime($inicioPrevMes));

/* --------------------------
   Cuota mensual ejecutivos
---------------------------*/
$cuotaMesU_porEj = 0.0;
$cuotaMesM_porEj = 0.0;
$qe = $conn->prepare("
    SELECT cuota_unidades, cuota_monto
    FROM cuotas_mensuales_ejecutivos
    WHERE anio=? AND mes=?
    ORDER BY id DESC LIMIT 1
");
$qe->bind_param("ii", $anio, $mes);
$qe->execute();
if ($rowQ = $qe->get_result()->fetch_assoc()) {
  $cuotaMesU_porEj = (float)$rowQ['cuota_unidades'];
  $cuotaMesM_porEj = (float)$rowQ['cuota_monto'];
}
$qe->close();

/* --------------------------
   Subconsulta: ventas por VENTA (mes)
---------------------------*/
$subVentasAggMes = "
  SELECT
      v.id,
      v.id_usuario,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
        ELSE SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END)
      END AS unidades,
      CASE
        WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN COALESCE(MAX(v.precio_venta),0)
        WHEN SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END) > 0
          THEN COALESCE(MAX(v.precio_venta),0)
        ELSE 0
      END AS monto
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p      ON p.id       = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* --------------------------
   SIMs (ventas_sims)
---------------------------*/
$mapSimsByUser = [];
$mapSimsBySuc  = [];

$sqlSimsUser = "
  SELECT id_usuario,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 1 ELSE 0 END) AS sim_pos,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 0
               WHEN (LOWER(tipo_venta) LIKE '%regalo%'
                  OR LOWER(tipo_sim)   LIKE '%regalo%'
                  OR LOWER(IFNULL(comentarios,'')) LIKE '%regalo%')
               THEN 0
               ELSE 1 END) AS sim_pre
  FROM ventas_sims
  WHERE DATE(CONVERT_TZ(fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY id_usuario
";
$stSU = $conn->prepare($sqlSimsUser);
$stSU->bind_param("ss", $inicioMes, $finMes);
$stSU->execute();
$resSU = $stSU->get_result();
while ($r = $resSU->fetch_assoc()) {
  $mapSimsByUser[(int)$r['id_usuario']] = ['pre' => (int)$r['sim_pre'], 'pos' => (int)$r['sim_pos']];
}
$stSU->close();

$sqlSimsSuc = "
  SELECT id_sucursal,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 1 ELSE 0 END) AS sim_pos,
         SUM(CASE
               WHEN (LOWER(tipo_venta) REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(tipo_sim)   REGEXP 'pospago|postpago|\\bpos\\b'
                  OR LOWER(IFNULL(comentarios,'')) REGEXP 'pospago|postpago|\\bpos\\b')
               THEN 0
               WHEN (LOWER(tipo_venta) LIKE '%regalo%'
                  OR LOWER(tipo_sim)   LIKE '%regalo%'
                  OR LOWER(IFNULL(comentarios,'')) LIKE '%regalo%')
               THEN 0
               ELSE 1 END) AS sim_pre
  FROM ventas_sims
  WHERE DATE(CONVERT_TZ(fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY id_sucursal
";
$stSS = $conn->prepare($sqlSimsSuc);
$stSS->bind_param("ss", $inicioMes, $finMes);
$stSS->execute();
$resSS = $stSS->get_result();
while ($r = $resSS->fetch_assoc()) {
  $mapSimsBySuc[(int)$r['id_sucursal']] = ['pre' => (int)$r['sim_pre'], 'pos' => (int)$r['sim_pos']];
}
$stSS->close();

/* --------------------------
   Sucursales: unidades/ventas y cuotas del mes
---------------------------*/
$sqlSuc = "
    SELECT
      s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
      IFNULL(SUM(va.unidades),0) AS unidades,
      IFNULL(SUM(va.monto),0)    AS ventas
    FROM sucursales s
    LEFT JOIN ( $subVentasAggMes ) va ON va.id_sucursal = s.id
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY ventas DESC
";
$stmt = $conn->prepare($sqlSuc);
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$sucursales = [];
$totalGlobalUnidades = 0;
$totalGlobalVentas   = 0;
$totalGlobalCuota    = 0;
$totalSimPre         = 0;
$totalSimPos         = 0;

/* Cuotas mensuales por sucursal */
$cuotasSuc = [];
$q = $conn->prepare("SELECT id_sucursal, cuota_unidades, cuota_monto FROM cuotas_mensuales WHERE anio=? AND mes=?");
$q->bind_param("ii", $anio, $mes);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) {
  $cuotasSuc[(int)$row['id_sucursal']] = [
    'cuota_unidades' => (int)$row['cuota_unidades'],
    'cuota_monto'    => (float)$row['cuota_monto']
  ];
}
$q->close();

while ($row = $res->fetch_assoc()) {
  $id_suc = (int)$row['id_sucursal'];
  $cuotaUnidades = $cuotasSuc[$id_suc]['cuota_unidades'] ?? 0;
  $cuotaMonto    = $cuotasSuc[$id_suc]['cuota_monto']    ?? 0;

  $cumpl = $cuotaMonto > 0 ? ($row['ventas'] / $cuotaMonto * 100) : 0;

  $simS = $mapSimsBySuc[$id_suc] ?? ['pre' => 0, 'pos' => 0];

  $sucursales[] = [
    'id_sucursal'     => $id_suc,
    'sucursal'        => $row['sucursal'],
    'zona'            => $row['zona'],
    'unidades'        => (int)$row['unidades'],
    'ventas'          => (float)$row['ventas'],
    'cuota_unidades'  => (int)$cuotaUnidades,
    'cuota_monto'     => (float)$cuotaMonto,
    'cumplimiento'    => $cumpl,
    'sim_prepago'     => (int)$simS['pre'],
    'sim_pospago'     => (int)$simS['pos'],
  ];

  $totalGlobalUnidades += (int)$row['unidades'];
  $totalGlobalVentas   += (float)$row['ventas'];
  $totalGlobalCuota    += (float)$cuotaMonto;
  $totalSimPre         += (int)$simS['pre'];
  $totalSimPos         += (int)$simS['pos'];
}
$stmt->close();

/* ---- Mes anterior (para Δ de sucursales) ---- */
$sqlSucPrev = "
  SELECT s.id AS id_sucursal, IFNULL(SUM(va.monto),0) AS ventas_prev
  FROM sucursales s
  LEFT JOIN ( $subVentasAggMes ) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id
";
$sp = $conn->prepare($sqlSucPrev);
$sp->bind_param("ss", $inicioPrevMes, $finPrevMes);
$sp->execute();
$rp = $sp->get_result();
$prevSuc = [];
while ($row = $rp->fetch_assoc()) {
  $prevSuc[(int)$row['id_sucursal']] = (float)$row['ventas_prev'];
}
$sp->close();

foreach ($sucursales as &$s) {
  $prev = $prevSuc[$s['id_sucursal']] ?? 0.0;
  $s['delta_monto'] = $s['ventas'] - $prev;
  $s['pct_delta_monto'] = $prev > 0 ? (($s['ventas'] - $prev) / $prev) * 100.0 : null;
}
unset($s);

/* --------------------------
   Zonas (cards)
---------------------------*/
$zonas = [];
foreach ($sucursales as $s) {
  $z = normalizarZona($s['zona'] ?? '') ?? 'Sin zona';
  if (!isset($zonas[$z])) $zonas[$z] = ['unidades' => 0, 'ventas' => 0.0, 'cuota' => 0.0];
  $zonas[$z]['unidades'] += $s['unidades'];
  $zonas[$z]['ventas']   += $s['ventas'];
  $zonas[$z]['cuota']    += $s['cuota_monto'];
}
$porcentajeGlobal = $totalGlobalCuota > 0 ? ($totalGlobalVentas / $totalGlobalCuota * 100) : 0;

/* --------------------------
   Ejecutivos (mensual)
---------------------------*/
$sqlEj = "
    SELECT 
        u.id,
        u.nombre,
        s.nombre AS sucursal,
        IFNULL(SUM(va.unidades),0) AS unidades,
        IFNULL(SUM(va.monto),0)    AS ventas
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ( $subVentasAggMes ) va ON va.id_usuario = u.id
    WHERE u.activo = 1 AND u.rol IN ('Ejecutivo','Gerente')
    GROUP BY u.id
    ORDER BY unidades DESC, ventas DESC
";
$stEj = $conn->prepare($sqlEj);
$stEj->bind_param("ss", $inicioMes, $finMes);
$stEj->execute();
$resEj = $stEj->get_result();

$ejecutivos = [];
while ($row = $resEj->fetch_assoc()) {
  $cumpl_uni = $cuotaMesU_porEj > 0 ? ($row['unidades'] / $cuotaMesU_porEj * 100) : null;

  $simU = $mapSimsByUser[(int)$row['id']] ?? ['pre' => 0, 'pos' => 0];

  $ejecutivos[] = [
    'id'             => (int)$row['id'],
    'nombre'         => $row['nombre'],
    'sucursal'       => $row['sucursal'],
    'unidades'       => (int)$row['unidades'],
    'ventas'         => (float)$row['ventas'],
    'cuota_unidades' => $cuotaMesU_porEj,
    'cumpl_uni'      => $cumpl_uni,
    'sim_prepago'    => (int)$simU['pre'],
    'sim_pospago'    => (int)$simU['pos'],
  ];
}
$stEj->close();

/* ---- Mes anterior (Δ ejecutivos por unidades) ---- */
$sqlEjPrev = "
  SELECT u.id, IFNULL(SUM(va.unidades),0) AS unidades_prev
  FROM usuarios u
  LEFT JOIN ( $subVentasAggMes ) va ON va.id_usuario = u.id
  WHERE u.activo = 1 AND u.rol IN ('Ejecutivo','Gerente')
  GROUP BY u.id
";
$pep = $conn->prepare($sqlEjPrev);
$pep->bind_param("ss", $inicioPrevMes, $finPrevMes);
$pep->execute();
$repp = $pep->get_result();
$ejPrev = [];
while ($r = $repp->fetch_assoc()) {
  $ejPrev[(int)$r['id']] = (int)$r['unidades_prev'];
}
$pep->close();
foreach ($ejecutivos as &$e) {
  $prev = $ejPrev[$e['id']] ?? 0;
  $e['delta_unidades'] = $e['unidades'] - $prev;
  $e['pct_delta_unidades'] = pctDelta($e['unidades'], $prev);
}
unset($e);

/* --------------------------
   Serie para gráfica (sin “Luga ”)
---------------------------*/
$seriesSucursales = [];
foreach ($sucursales as $row) {
  $seriesSucursales[] = [
    'label'    => sucursalCorta($row['sucursal']),
    'unidades' => (int)$row['unidades'],
    'ventas'   => round((float)$row['ventas'], 2),
  ];
}
$TOP_BARS = 15;

/* --------------------------
   Agrupar sucursales por zona (para la TABLA)
---------------------------*/
$gruposZona = [];
foreach ($sucursales as $s) {
  $zonaNorm = normalizarZona($s['zona'] ?? '') ?? 'Sin zona';
  if (!isset($gruposZona[$zonaNorm])) {
    $gruposZona[$zonaNorm] = [
      'rows' => [],
      'tot'  => ['unidades' => 0, 'ventas' => 0.0, 'cuota' => 0.0, 'cumpl' => 0.0, 'sim_pre' => 0, 'sim_pos' => 0]
    ];
  }
  $gruposZona[$zonaNorm]['rows'][] = $s;
  $gruposZona[$zonaNorm]['tot']['unidades'] += (int)$s['unidades'];
  $gruposZona[$zonaNorm]['tot']['ventas']   += (float)$s['ventas'];
  $gruposZona[$zonaNorm]['tot']['cuota']    += (float)$s['cuota_monto'];
  $gruposZona[$zonaNorm]['tot']['sim_pre']  += (int)$s['sim_prepago'];
  $gruposZona[$zonaNorm]['tot']['sim_pos']  += (int)$s['sim_pospago'];
}
foreach ($gruposZona as &$g) {
  usort($g['rows'], fn($a, $b) => $b['ventas'] <=> $a['ventas']);
  $g['tot']['cumpl'] = $g['tot']['cuota'] > 0 ? ($g['tot']['ventas'] / $g['tot']['cuota'] * 100) : 0.0;
}
unset($g);
/* Orden de zonas: Zona 1, Zona 2, ... y “Sin zona” al final */
uksort($gruposZona, function ($za, $zb) use ($gruposZona) {
  $rank = function ($z) {
    if (preg_match('/zona\s*(\d+)/i', $z, $m)) return (int)$m[1];
    if (stripos($z, 'sin zona') !== false) return PHP_INT_MAX - 1;
    return PHP_INT_MAX;
  };
  $ra = $rank($za);
  $rb = $rank($zb);
  if ($ra !== $rb) return $ra <=> $rb;
  return $gruposZona[$zb]['tot']['ventas'] <=> $gruposZona[$za]['tot']['ventas'];
});
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Mensual</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    .clip {
      max-width: 160px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .num {
      font-variant-numeric: tabular-nums;
      letter-spacing: -.2px;
    }

    .w-120 {
      min-width: 120px;
    }

    .progress {
      height: 18px
    }

    .progress-bar {
      font-size: .75rem
    }

    .tab-pane {
      padding-top: 10px
    }

    .table .progress {
      width: 100%;
    }

    .col-fit {
      white-space: nowrap;
    }

    /* Switch mini + ocultar deltas por clase */
    .form-switch.form-switch-sm .form-check-input {
      height: 1rem;
      width: 2rem;
      transform: scale(.95);
    }

    .form-switch.form-switch-sm .form-check-label {
      font-size: .8rem;
      margin-left: .25rem;
    }

    .trend {
      font-size: .875rem;
      white-space: nowrap;
    }

    .trend .delta {
      font-weight: 600;
    }

    .hide-delta .trend {
      display: none !important;
    }

    @media (max-width:576px) {
      body {
        font-size: 14px;
      }

      .container {
        padding: 0 8px;
      }

      .card .card-header {
        padding: .5rem .65rem;
        font-size: .95rem;
      }

      .card .card-body {
        padding: .65rem;
      }

      .table {
        font-size: 12px;
      }

      .table thead th {
        font-size: 11px;
      }

      .table td,
      .table th {
        padding: .35rem .45rem;
      }

      .clip {
        max-width: 120px;
      }

      .trend {
        font-size: .72rem;
      }
    }

    @media (max-width:360px) {
      .table {
        font-size: 11px;
      }

      .table td,
      .table th {
        padding: .30rem .40rem;
      }

      .clip {
        max-width: 96px;
      }
    }
  </style>
</head>

<body class="bg-light">

  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="container mt-4">
    <h2>📊 Dashboard Mensual - <?= nombreMes($mes) . " $anio" ?></h2>

    <!-- Filtros -->
    <form method="GET" class="row g-2 mb-4">
      <div class="col-md-2">
        <select name="mes" class="form-select">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>><?= nombreMes($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="anio" class="form-select">
          <?php for ($a = date('Y') - 1; $a <= date('Y') + 1; $a++): ?>
            <option value="<?= $a ?>" <?= $a == $anio ? 'selected' : '' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary">Filtrar</button>
      </div>
    </form>

    <!-- Cards Zonas + Global -->
    <div class="row mb-4">
      <?php foreach ($zonas as $zona => $info):
        $cumpl = $info['cuota'] > 0 ? ($info['ventas'] / $info['cuota'] * 100) : 0;
        $barra = $cumpl >= 100 ? 'bg-success' : ($cumpl >= 60 ? 'bg-warning' : 'bg-danger');
      ?>
        <div class="col-md-4 mb-3">
          <div class="card shadow text-center">
            <div class="card-header bg-dark text-white"><?= htmlspecialchars($zona) ?></div>
            <div class="card-body">
              <h5><?= number_format($cumpl, 1) ?>% Cumplimiento</h5>
              <p class="mb-2">
                Unidades: <?= (int)$info['unidades'] ?><br>
                Ventas: $<?= number_format($info['ventas'], 2) ?><br>
                Cuota: $<?= number_format($info['cuota'], 2) ?>
              </p>
              <div class="progress">
                <div class="progress-bar <?= $barra ?>" style="width:<?= min(100, $cumpl) ?>%"><?= number_format(min(100, $cumpl), 1) ?>%</div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php
      $barraG = $porcentajeGlobal >= 100 ? 'bg-success' : ($porcentajeGlobal >= 60 ? 'bg-warning' : 'bg-danger');
      ?>
      <div class="col-md-4 mb-3">
        <div class="card shadow text-center">
          <div class="card-header bg-primary text-white">🌎 Global Compañía</div>
          <div class="card-body">
            <h5><?= number_format($porcentajeGlobal, 1) ?>% Cumplimiento</h5>
            <p class="mb-2">
              Unidades: <?= (int)$totalGlobalUnidades ?><br>
              Ventas: $<?= number_format($totalGlobalVentas, 2) ?><br>
              Cuota: $<?= number_format($totalGlobalCuota, 2) ?>
            </p>
            <div class="progress">
              <div class="progress-bar <?= $barraG ?>" style="width:<?= min(100, $porcentajeGlobal) ?>%"><?= number_format(min(100, $porcentajeGlobal), 1) ?>%</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Gráfica mensual: UNA BARRA POR SUCURSAL -->
    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
        <span>Resumen mensual por sucursal</span>
        <div class="btn-group btn-group-sm">
          <button id="btnUnidades" class="btn btn-primary" type="button">Unidades</button>
          <button id="btnVentas" class="btn btn-outline-light" type="button">Ventas ($)</button>
        </div>
      </div>
      <div class="card-body">
        <div style="position:relative; height:380px;">
          <canvas id="chartMensualSuc"></canvas>
        </div>
        <small class="text-muted d-block mt-2">* Se muestran Top-<?= $TOP_BARS ?> sucursales + “Otras”.</small>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-suc">Sucursales</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ej">Ejecutivos</button></li>
    </ul>

    <div class="tab-content">
      <!-- Sucursales (agrupado por zona) -->
      <div class="tab-pane fade show active" id="tab-suc" role="tabpanel">
        <div class="card shadow mt-3 hide-delta" id="card_sucursales">
          <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
            <span>Ranking de Sucursales (agrupado por zona)</span>
            <div class="form-check form-switch form-switch-sm text-nowrap" title="Mostrar comparativo (Δ)">
              <input class="form-check-input" type="checkbox" id="swDeltaSuc">
              <label class="form-check-label small" for="swDeltaSuc">Δ</label>
            </div>
          </div>
          <div class="card-body">
            <div class="table-responsive-sm">
              <table class="table table-striped table-bordered table-sm align-middle">
                <thead class="table-dark">
                  <tr>
                    <!-- Sucursal visible en ambas vistas -->
                    <th>Sucursal</th>
                    <!-- Móvil: Uds, Ventas, % Cumpl., Prep, Pos -->
                    <th class="d-table-cell d-md-none col-fit">Uds</th>
                    <th class="d-table-cell d-md-none col-fit">Ventas</th>
                    <th class="d-table-cell d-md-none col-fit">% Cumpl.</th>
                    <th class="d-table-cell d-md-none col-fit">Prep</th>
                    <th class="d-table-cell d-md-none col-fit">Pos</th>
                    <!-- Escritorio (md+): Unidades, Ventas, Cuota, % Cumpl., Prep, Pos, Progreso -->
                    <th class="d-none d-md-table-cell col-fit">Unidades</th>
                    <th class="d-none d-md-table-cell col-fit">Ventas ($)</th>
                    <th class="d-none d-md-table-cell col-fit">Cuota ($)</th>
                    <th class="d-none d-md-table-cell col-fit">% Cumpl.</th>
                    <th class="d-none d-md-table-cell col-fit">Prep</th>
                    <th class="d-none d-md-table-cell col-fit">Pos</th>
                    <th class="d-none d-md-table-cell">Progreso</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($gruposZona as $zona => $grp): ?>
                    <!-- Encabezados de grupo por zona con colspans correctos -->
                    <tr class="table-secondary d-table-row d-md-none">
                      <th colspan="6" class="text-start"><?= htmlspecialchars($zona) ?></th>
                    </tr>
                    <tr class="table-secondary d-none d-md-table-row">
                      <th colspan="8" class="text-start"><?= htmlspecialchars($zona) ?></th>
                    </tr>

                    <?php foreach ($grp['rows'] as $s):
                      $cumpl = round($s['cumplimiento'], 1);
                      $fila = $cumpl >= 100 ? "table-success" : ($cumpl >= 60 ? "table-warning" : "table-danger");
                      $dM = (float)$s['delta_monto'];
                      [$icoM, $clsM] = arrowIcon($dM);
                      $pctM = $s['pct_delta_monto'];
                    ?>
                      <tr class="<?= $fila ?>">
                        <!-- Sucursal -->
                        <td class="clip" title="<?= htmlspecialchars($s['sucursal']) ?>">
                          <span class="d-none d-md-inline"><?= htmlspecialchars($s['sucursal']) ?></span>
                          <span class="d-inline d-md-none"><?= htmlspecialchars(sucursalCorta($s['sucursal'])) ?></span>
                        </td>

                        <!-- Móvil -->
                        <td class="d-table-cell d-md-none num col-fit"><?= (int)$s['unidades'] ?></td>
                        <td class="d-table-cell d-md-none num col-fit">
                          $<?= number_format($s['ventas'], 2) ?>
                          <div class="trend">
                            <span class="<?= $clsM ?>"><?= $icoM ?></span>
                            <span class="delta <?= $clsM ?>"><?= ($dM > 0 ? '+' : '') . '$' . number_format($dM, 2) ?></span>
                            <?php if ($pctM !== null): ?>
                              <span class="text-muted">(<?= ($pctM >= 0 ? '+' : '') . number_format($pctM, 1) ?>%)</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="d-table-cell d-md-none num col-fit"><?= number_format($cumpl, 1) ?>%</td>
                        <td class="d-table-cell d-md-none num col-fit"><?= (int)$s['sim_prepago'] ?></td>
                        <td class="d-table-cell d-md-none num col-fit"><?= (int)$s['sim_pospago'] ?></td>

                        <!-- Escritorio (md+) -->
                        <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['unidades'] ?></td>
                        <td class="d-none d-md-table-cell num col-fit">
                          $<?= number_format($s['ventas'], 2) ?>
                          <div class="trend">
                            <span class="<?= $clsM ?>"><?= $icoM ?></span>
                            <span class="delta <?= $clsM ?>"><?= ($dM > 0 ? '+' : '') . '$' . number_format($dM, 2) ?></span>
                            <?php if ($pctM !== null): ?>
                              <span class="text-muted">(<?= ($pctM >= 0 ? '+' : '') . number_format($pctM, 1) ?>%)</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="d-none d-md-table-cell num col-fit">$<?= number_format($s['cuota_monto'], 2) ?></td>
                        <td class="d-none d-md-table-cell num col-fit"><?= number_format($cumpl, 1) ?>%</td>
                        <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_prepago'] ?></td>
                        <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_pospago'] ?></td>
                        <td class="d-none d-md-table-cell">
                          <div class="progress" style="height:20px">
                            <div class="progress-bar <?= $cumpl >= 100 ? 'bg-success' : ($cumpl >= 60 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, $cumpl) ?>%"><?= $cumpl ?>%</div>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                    <!-- Totales por zona -->
                    <?php
                    $tzU = (int)$grp['tot']['unidades'];
                    $tzV = (float)$grp['tot']['ventas'];
                    $tzC = (float)$grp['tot']['cuota'];
                    $tzP = (float)$grp['tot']['cumpl'];
                    $tzPre = (int)$grp['tot']['sim_pre'];
                    $tzPos = (int)$grp['tot']['sim_pos'];
                    $cls = $tzP >= 100 ? 'bg-success' : ($tzP >= 60 ? 'bg-warning' : 'bg-danger');
                    ?>
                    <!-- Móvil: Sucursal (label), Uds, Ventas, % Cumpl., Prep, Pos -->
                    <tr class="table-light fw-semibold d-table-row d-md-none">
                      <td class="text-end">Total <?= htmlspecialchars($zona) ?>:</td>
                      <td class="num col-fit"><?= $tzU ?></td>
                      <td class="num col-fit">$<?= number_format($tzV, 2) ?></td>
                      <td class="num col-fit"><?= number_format($tzP, 1) ?>%</td>
                      <td class="num col-fit"><?= $tzPre ?></td>
                      <td class="num col-fit"><?= $tzPos ?></td>
                    </tr>
                    <!-- Escritorio: Sucursal (label), Unidades, Ventas, Cuota, % Cumpl., Prep, Pos, Progreso -->
                    <tr class="table-light fw-semibold d-none d-md-table-row">
                      <td class="text-end">Total <?= htmlspecialchars($zona) ?>:</td>
                      <td class="num"><?= $tzU ?></td>
                      <td class="num col-fit">$<?= number_format($tzV, 2) ?></td>
                      <td class="num col-fit">$<?= number_format($tzC, 2) ?></td>
                      <td class="num col-fit"><?= number_format($tzP, 1) ?>%</td>
                      <td class="num col-fit"><?= $tzPre ?></td>
                      <td class="num col-fit"><?= $tzPos ?></td>
                      <td>
                        <div class="progress" style="height:20px">
                          <div class="progress-bar <?= $cls ?>" style="width:<?= min(100, $tzP) ?>%"><?= number_format(min(100, $tzP), 1) ?>%</div>
                        </div>
                      </td>
                    </tr>

                  <?php endforeach; ?>

                  <?php
                  $clsG = $porcentajeGlobal >= 100 ? 'bg-success' : ($porcentajeGlobal >= 60 ? 'bg-warning' : 'bg-danger');
                  ?>
                  <!-- Global móvil -->
                  <tr class="table-primary fw-bold d-table-row d-md-none">
                    <td class="text-end">Total global:</td>
                    <td class="num col-fit"><?= (int)$totalGlobalUnidades ?></td>
                    <td class="num col-fit">$<?= number_format($totalGlobalVentas, 2) ?></td>
                    <td class="num col-fit"><?= number_format($porcentajeGlobal, 1) ?>%</td>
                    <td class="num col-fit"><?= (int)$totalSimPre ?></td>
                    <td class="num col-fit"><?= (int)$totalSimPos ?></td>
                  </tr>
                  <!-- Global escritorio -->
                  <tr class="table-primary fw-bold d-none d-md-table-row">
                    <td class="text-end">Total global:</td>
                    <td class="num"><?= (int)$totalGlobalUnidades ?></td>
                    <td class="num col-fit">$<?= number_format($totalGlobalVentas, 2) ?></td>
                    <td class="num col-fit">$<?= number_format($totalGlobalCuota, 2) ?></td>
                    <td class="num col-fit"><?= number_format($porcentajeGlobal, 1) ?>%</td>
                    <td class="num col-fit"><?= (int)$totalSimPre ?></td>
                    <td class="num col-fit"><?= (int)$totalSimPos ?></td>
                    <td>
                      <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $clsG ?>" style="width:<?= min(100, $porcentajeGlobal) ?>%">
                          <?= number_format(min(100, $porcentajeGlobal), 1) ?>%
                        </div>
                      </div>
                    </td>
                  </tr>

                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Ejecutivos (sin cambios de orden en esta entrega) -->
      <div class="tab-pane fade" id="tab-ej" role="tabpanel">
        <div class="card shadow mt-3 hide-delta" id="card_ejecutivos">
          <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
            <span>Productividad mensual por Ejecutivo</span>
            <div class="form-check form-switch form-switch-sm text-nowrap" title="Mostrar comparativo (Δ)">
              <input class="form-check-input" type="checkbox" id="swDeltaEj">
              <label class="form-check-label small" for="swDeltaEj">Δ</label>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive-sm">
              <table class="table table-striped table-bordered table-sm mb-0 align-middle">
                <thead class="table-dark">
                  <tr>
                    <th>Ejecutivo</th>
                    <th>Sucursal</th>

                    <!-- Uds / Unidades -->
                    <th class="col-fit" title="Unidades">
                      <span class="d-inline d-sm-none">Uds</span>
                      <span class="d-none d-sm-inline">Unidades</span>
                    </th>

                    <!-- Prep / SIM Prep. -->
                    <th class="col-fit" title="SIM Prepago">
                      <span class="d-inline d-sm-none">Prep</span>
                      <span class="d-none d-sm-inline">SIM Prep.</span>
                    </th>

                    <!-- Pos / SIM Pos. -->
                    <th class="col-fit" title="SIM Pospago">
                      <span class="d-inline d-sm-none">Pos</span>
                      <span class="d-none d-sm-inline">SIM Pos.</span>
                    </th>

                    <!-- Ventas solo en sm+ -->
                    <th class="d-none d-sm-table-cell">Ventas $</th>

                    <!-- Qta / Cuota Mes (u) -->
                    <th class="col-fit" title="Cuota del Mes (unidades)">
                      <span class="d-inline d-sm-none">Qta</span>
                      <span class="d-none d-sm-inline">Cuota Mes (u)</span>
                    </th>

                    <!-- % / % Cumpl. (Unid.) -->
                    <th class="col-fit" title="Porcentaje de Cumplimiento (unidades)">
                      <span class="d-inline d-sm-none">%</span>
                      <span class="d-none d-sm-inline">% Cumpl. (Unid.)</span>
                    </th>

                    <!-- Progreso solo en sm+ -->
                    <th class="d-none d-sm-table-cell">Progreso</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($ejecutivos as $e):
                    $pct = $e['cumpl_uni'];
                    $pctRound = ($pct === null) ? null : round($pct, 1);
                    $fila = badgeFila($pct);
                    $barClass = ($pct === null) ? 'bg-secondary' : ($pct >= 100 ? 'bg-success' : ($pct >= 60 ? 'bg-warning' : 'bg-danger'));
                    $dU = (int)$e['delta_unidades'];
                    [$icoU, $clsU] = arrowIcon($dU);
                    $pctU = $e['pct_delta_unidades'];
                  ?>
                    <tr class="<?= $fila ?>">
                      <td class="clip" title="<?= htmlspecialchars($e['nombre']) ?>"><?= htmlspecialchars($e['nombre']) ?></td>
                      <td class="clip" title="<?= htmlspecialchars($e['sucursal']) ?>"><?= htmlspecialchars($e['sucursal']) ?></td>
                      <td class="num">
                        <?= (int)$e['unidades'] ?>
                        <div class="trend">
                          <span class="<?= $clsU ?>"><?= $icoU ?></span>
                          <span class="delta <?= $clsU ?>"><?= ($dU > 0 ? '+' : '') . $dU ?> u.</span>
                          <?php if ($pctU !== null): ?>
                            <span class="text-muted">(<?= ($pctU >= 0 ? '+' : '') . number_format($pctU, 1) ?>%)</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="num col-fit"><?= (int)$e['sim_prepago'] ?></td>
                      <td class="num col-fit"><?= (int)$e['sim_pospago'] ?></td>
                      <td class="d-none d-sm-table-cell num">$<?= number_format($e['ventas'], 2) ?></td>
                      <td class="num"><?= number_format($e['cuota_unidades'], 2) ?></td>
                      <td class="num"><?= $pct === null ? '–' : ($pctRound . '%') ?></td>
                      <td class="d-none d-sm-table-cell" style="min-width:160px">
                        <div class="progress">
                          <div class="progress-bar <?= $barClass ?>" style="width: <?= $pct === null ? 0 : min(100, $pctRound) ?>%"><?= $pct === null ? 'Sin cuota' : ($pctRound . '%') ?></div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    /* ===== Chart mensual por sucursal (barras) ===== */
    const ALL_SUC = <?= json_encode($seriesSucursales, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
    const TOP_BARS = <?= (int)$TOP_BARS ?>;

    function palette(i) {
      const colors = ['#2563eb', '#16a34a', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#22c55e', '#0ea5e9', '#e11d48', '#7c3aed', '#10b981', '#eab308', '#dc2626', '#06b6d4', '#a3e635'];
      return colors[i % colors.length];
    }

    function buildTop(metric) {
      const arr = [...ALL_SUC].sort((a, b) => (b[metric] || 0) - (a[metric] || 0));
      const labels = [],
        data = [];
      let otras = 0;
      arr.forEach((r, idx) => {
        if (idx < TOP_BARS) {
          labels.push(r.label);
          data.push(r[metric] || 0);
        } else {
          otras += (r[metric] || 0);
        }
      });
      if (otras > 0) {
        labels.push('Otras');
        data.push(otras);
      }
      return {
        labels,
        data
      };
    }
    let currentMetric = 'unidades';
    let chart = null;

    function renderChart() {
      const series = buildTop(currentMetric);
      const ctx = document.getElementById('chartMensualSuc').getContext('2d');
      const bg = series.labels.map((_, i) => palette(i));
      const isMoney = (currentMetric === 'ventas');
      const data = {
        labels: series.labels,
        datasets: [{
          label: isMoney ? 'Ventas ($)' : 'Unidades (mes)',
          data: series.data,
          backgroundColor: bg,
          borderWidth: 0
        }]
      };
      const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: (ctx) => isMoney ? ' $' + Number(ctx.parsed.y).toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              }) : ' ' + ctx.parsed.y.toLocaleString('es-MX') + ' u.'
            }
          }
        },
        scales: {
          x: {
            title: {
              display: true,
              text: 'Sucursales'
            },
            ticks: {
              autoSkip: false,
              maxRotation: 45,
              minRotation: 0,
              callback: (v, i) => {
                const l = series.labels[i] || '';
                return l.length > 14 ? l.slice(0, 12) + '…' : l;
              }
            },
            grid: {
              display: false
            }
          },
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: isMoney ? 'Ventas ($)' : 'Unidades'
            }
          }
        },
        elements: {
          bar: {
            borderRadius: 4,
            barThickness: 'flex',
            maxBarThickness: 42
          }
        }
      };
      if (chart) chart.destroy();
      chart = new Chart(ctx, {
        type: 'bar',
        data,
        options
      });
    }
    renderChart();
    const btnUnidades = document.getElementById('btnUnidades');
    const btnVentas = document.getElementById('btnVentas');
    btnUnidades.addEventListener('click', () => {
      currentMetric = 'unidades';
      btnUnidades.className = 'btn btn-primary';
      btnVentas.className = 'btn btn-outline-light';
      renderChart();
    });
    btnVentas.addEventListener('click', () => {
      currentMetric = 'ventas';
      btnVentas.className = 'btn btn-primary';
      btnUnidades.className = 'btn btn-outline-light';
      renderChart();
    });

    /* ===== Switch Δ (default OFF) ===== */
    (function() {
      function wire(swId, cardId) {
        const sw = document.getElementById(swId),
          card = document.getElementById(cardId);
        if (!sw || !card) return;
        sw.checked = false;
        card.classList.add('hide-delta');
        sw.addEventListener('change', () => card.classList.toggle('hide-delta', !sw.checked));
      }
      wire('swDeltaSuc', 'card_sucursales');
      wire('swDeltaEj', 'card_ejecutivos');
    })();
  </script>
</body>

</html>