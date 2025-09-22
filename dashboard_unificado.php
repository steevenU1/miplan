<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}
include 'db.php';

/* ==============================
   Semana actual (mar–lun)
================================= */
function obtenerSemanaPorIndice($offset = 0)
{
  $hoy = new DateTime();
  $diaSemana = (int)$hoy->format('N'); // 1=Lun..7=Dom
  $dif = $diaSemana - 2;               // Martes=2
  if ($dif < 0) $dif += 7;
  $inicio = new DateTime();
  $inicio->modify("-$dif days")->setTime(0, 0, 0);
  if ($offset > 0) $inicio->modify('-' . (7 * $offset) . ' days');
  $fin = clone $inicio;
  $fin->modify('+6 days')->setTime(23, 59, 59);
  return [$inicio, $fin];
}

$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioObj, $finObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioObj->format('Y-m-d');
$finSemana    = $finObj->format('Y-m-d');

// Semana anterior
list($inicioPrevObj, $finPrevObj) = obtenerSemanaPorIndice($semanaSeleccionada + 1);
$inicioPrev = $inicioPrevObj->format('Y-m-d');
$finPrev    = $finPrevObj->format('Y-m-d');

/* ==============================
   Helpers
================================= */
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
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Normaliza cualquier valor de zona a "Zona N" */
function normalizarZona($raw)
{
  $t = trim((string)$raw);
  if ($t === '') return null;
  $t = preg_replace('/^(?:\s*Zona\s+)+/i', 'Zona ', $t);
  if (preg_match('/(\d+)/', $t, $m)) return 'Zona ' . (int)$m[1];
  if (preg_match('/^Zona\s+\S+/i', $t)) return preg_replace('/\s+/', ' ', $t);
  return null;
}

/** Quita prefijo "Luga" en nombres de sucursal */
function sucursalCorta($nombre)
{
  $n = preg_replace('/^\s*Luga\s+/i', '', (string)$nombre);
  return trim($n);
}

/* ==========================================================
   Detectar columna de tipo en productos (tipo vs tipo_producto)
========================================================== */
$colTipoProd = 'tipo_producto';
try {
  $rs = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo'");
  if ($rs && $rs->num_rows > 0) {
    $colTipoProd = 'tipo';
  } else {
    $rs2 = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'");
    if ($rs2 && $rs2->num_rows > 0) $colTipoProd = 'tipo_producto';
  }
} catch (Exception $e) { /* fallback a tipo_producto */
}

/* ==========================================================
   Agregado por venta (unidades por VENTA, no por detalle)
========================================================== */
$subVentasAgg = "
  SELECT
    v.id,
    v.id_usuario,
    v.id_sucursal,
    DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
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
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

/* ==========================================================
   SIMs (tabla separada ventas_sims)
========================================================== */
$mapSimsByUser = [];
$mapSimsBySuc  = [];

// Por usuario
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
$stSU->bind_param("ss", $inicioSemana, $finSemana);
$stSU->execute();
$resSU = $stSU->get_result();
while ($r = $resSU->fetch_assoc()) {
  $mapSimsByUser[(int)$r['id_usuario']] = ['pre' => (int)$r['sim_pre'], 'pos' => (int)$r['sim_pos']];
}
$stSU->close();

// Por sucursal
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
$stSS->bind_param("ss", $inicioSemana, $finSemana);
$stSS->execute();
$resSS = $stSS->get_result();
while ($r = $resSS->fetch_assoc()) {
  $mapSimsBySuc[(int)$r['id_sucursal']] = ['pre' => (int)$r['sim_pre'], 'pos' => (int)$r['sim_pos']];
}
$stSS->close();

/* ==============================
   Ejecutivos
================================= */
$sqlEjecutivos = "
  SELECT 
    u.id, u.nombre, u.rol, s.nombre AS sucursal,
    COALESCE((
      SELECT css.cuota_unidades
      FROM cuotas_semanales_sucursal css
      WHERE css.id_sucursal = u.id_sucursal
        AND css.semana_inicio <= ?
        AND css.semana_fin    >= ?
      ORDER BY css.semana_inicio DESC
      LIMIT 1
    ), 6) AS cuota_ejecutivo,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0)    AS total_ventas
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN ( $subVentasAgg ) va ON va.id_usuario = u.id
  WHERE s.tipo_sucursal='Tienda'
  AND u.activo = 1
  AND u.rol IN ('Ejecutivo','Gerente')
  GROUP BY u.id
  ORDER BY unidades DESC, total_ventas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
$stmt->bind_param("ssss", $inicioSemana, $finSemana, $inicioSemana, $finSemana);
$stmt->execute();
$resEjecutivos = $stmt->get_result();

$rankingEjecutivos = [];
while ($row = $resEjecutivos->fetch_assoc()) {
  $row['unidades']        = (int)$row['unidades'];
  $row['total_ventas']    = (float)$row['total_ventas'];
  $row['cuota_ejecutivo'] = (int)($row['cuota_ejecutivo'] ?? 0);
  $row['cumplimiento']    = $row['cuota_ejecutivo'] > 0 ? ($row['unidades'] / $row['cuota_ejecutivo'] * 100) : 0;

  $simU = $mapSimsByUser[(int)$row['id']] ?? ['pre' => 0, 'pos' => 0];
  $row['sim_prepago']     = (int)$simU['pre'];
  $row['sim_pospago']     = (int)$simU['pos'];

  $rankingEjecutivos[]    = $row;
}
$top3Ejecutivos = array_slice(array_column($rankingEjecutivos, 'id'), 0, 3);

/* Semana anterior (para delta) */
$sqlEjecutivosPrev = "
  SELECT 
    u.id,
    COALESCE((
      SELECT css.cuota_unidades
      FROM cuotas_semanales_sucursal css
      WHERE css.id_sucursal = u.id_sucursal
        AND css.semana_inicio <= ?
        AND css.semana_fin    >= ?
      ORDER BY css.semana_inicio DESC
      LIMIT 1
    ), 6) AS cuota_prev,
    IFNULL(SUM(va.unidades),0) AS unidades_prev
  FROM usuarios u
  LEFT JOIN ( $subVentasAgg ) va ON va.id_usuario = u.id
  WHERE u.activo = 1
  AND u.rol IN ('Ejecutivo','Gerente')
  GROUP BY u.id
";
$stmtP = $conn->prepare($sqlEjecutivosPrev);
$stmtP->bind_param("ssss", $inicioPrev, $finPrev, $inicioPrev, $finPrev);
$stmtP->execute();
$resEjPrev = $stmtP->get_result();
$prevEjMap = [];
while ($r = $resEjPrev->fetch_assoc()) {
  $prevEjMap[(int)$r['id']] = [
    'cuota_prev'     => (int)($r['cuota_prev'] ?? 0),
    'unidades_prev'  => (int)$r['unidades_prev']
  ];
}
foreach ($rankingEjecutivos as &$r) {
  $p = $prevEjMap[(int)$r['id']] ?? ['cuota_prev' => 0, 'unidades_prev' => 0];
  $r['delta_unidades'] = $r['unidades'] - (int)$p['unidades_prev'];
}
unset($r);

/* ==============================
   Sucursales (con SIMs)
================================= */
$sqlSucursales = "
  SELECT
    s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
    (
      SELECT cs.cuota_monto
      FROM cuotas_sucursales cs
      WHERE cs.id_sucursal = s.id AND cs.fecha_inicio <= ?
      ORDER BY cs.fecha_inicio DESC LIMIT 1
    ) AS cuota_semanal,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0)    AS total_ventas
  FROM sucursales s
  LEFT JOIN ( $subVentasAgg ) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id
  ORDER BY total_ventas DESC
";
$stmt2 = $conn->prepare($sqlSucursales);
$stmt2->bind_param("sss", $inicioSemana, $inicioSemana, $finSemana);
$stmt2->execute();
$resSucursales = $stmt2->get_result();

$sucursales = [];
$totalUnidades = 0;
$totalVentasGlobal = 0;
$totalCuotaGlobal = 0;
$totalSimPre = 0;
$totalSimPos = 0;

while ($row = $resSucursales->fetch_assoc()) {
  $row['unidades']      = (int)$row['unidades'];
  $row['total_ventas']  = (float)$row['total_ventas'];
  $row['cuota_semanal'] = (float)($row['cuota_semanal'] ?? 0);
  $row['cumplimiento']  = $row['cuota_semanal'] > 0 ? ($row['total_ventas'] / $row['cuota_semanal'] * 100) : 0;

  $simS = $mapSimsBySuc[(int)$row['id_sucursal']] ?? ['pre' => 0, 'pos' => 0];
  $row['sim_prepago']   = (int)$simS['pre'];
  $row['sim_pospago']   = (int)$simS['pos'];

  $sucursales[] = $row;

  $totalUnidades     += $row['unidades'];
  $totalVentasGlobal += $row['total_ventas'];
  $totalCuotaGlobal  += $row['cuota_semanal'];
  $totalSimPre       += $row['sim_prepago'];
  $totalSimPos       += $row['sim_pospago'];
}
$porcentajeGlobal = $totalCuotaGlobal > 0 ? ($totalVentasGlobal / $totalCuotaGlobal) * 100 : 0;

/* ==============================
   Agrupación por Zonas — cards
================================= */
$zonasAgg = [];
foreach ($sucursales as $s) {
  $zNorm = normalizarZona($s['zona'] ?? '');
  if (!$zNorm) continue;
  if (!isset($zonasAgg[$zNorm])) $zonasAgg[$zNorm] = ['unidades' => 0, 'ventas' => 0.0, 'cuota' => 0.0];
  $zonasAgg[$zNorm]['unidades'] += (int)$s['unidades'];
  $zonasAgg[$zNorm]['ventas']   += (float)$s['total_ventas'];
  $zonasAgg[$zNorm]['cuota']    += (float)$s['cuota_semanal'];
}
foreach ($zonasAgg as $k => &$info) {
  $info['cumplimiento'] = $info['cuota'] > 0 ? ($info['ventas'] / $info['cuota'] * 100.0) : 0.0;
}
unset($info);

$z1 = $zonasAgg['Zona 1'] ?? ['unidades' => 0, 'ventas' => 0.0, 'cuota' => 0.0, 'cumplimiento' => 0.0];
$z2 = $zonasAgg['Zona 2'] ?? ['unidades' => 0, 'ventas' => 0.0, 'cuota' => 0.0, 'cumplimiento' => 0.0];

/* ==============================
   Semana anterior (sucursales)
================================= */
$sqlSucursalesPrev = "
  SELECT
    s.id AS id_sucursal,
    (
      SELECT cs.cuota_monto
      FROM cuotas_sucursales cs
      WHERE cs.id_sucursal = s.id AND cs.fecha_inicio <= ?
      ORDER BY cs.fecha_inicio DESC LIMIT 1
    ) AS cuota_prev,
    IFNULL(SUM(va.monto),0) AS ventas_prev
  FROM sucursales s
  LEFT JOIN ( $subVentasAgg ) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id
";
$stmt2p = $conn->prepare($sqlSucursalesPrev);
$stmt2p->bind_param("sss", $inicioPrev, $inicioPrev, $finPrev);
$stmt2p->execute();
$resSucPrev = $stmt2p->get_result();
$prevSucMap = [];
while ($r = $resSucPrev->fetch_assoc()) {
  $prevSucMap[(int)$r['id_sucursal']] = [
    'cuota_prev'    => (float)($r['cuota_prev'] ?? 0),
    'ventas_prev'   => (float)$r['ventas_prev'],
  ];
}
foreach ($sucursales as &$s) {
  $p = $prevSucMap[(int)$s['id_sucursal']] ?? ['cuota_prev' => 0, 'ventas_prev' => 0.0];
  $s['delta_monto'] = $s['total_ventas'] - (float)$p['ventas_prev'];
  $s['pct_delta_monto'] = ($p['ventas_prev'] > 0)
    ? (($s['total_ventas'] - (float)$p['ventas_prev']) / (float)$p['ventas_prev']) * 100
    : null;
}
unset($s);

/* ==============================
   Gráfica
================================= */
$seriesSucursales = [];
foreach ($sucursales as $row) {
  $seriesSucursales[] = [
    'label'    => sucursalCorta($row['sucursal']),
    'unidades' => (int)$row['unidades'],
    'ventas'   => round((float)$row['total_ventas'], 2),
  ];
}
$TOP_BARS = 15;

/* ==============================
   Agrupar Sucursales por Zona (tabla)
================================= */
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
  $gruposZona[$zonaNorm]['tot']['ventas']   += (float)$s['total_ventas'];
  $gruposZona[$zonaNorm]['tot']['cuota']    += (float)$s['cuota_semanal'];
  $gruposZona[$zonaNorm]['tot']['sim_pre']  += (int)$s['sim_prepago'];
  $gruposZona[$zonaNorm]['tot']['sim_pos']  += (int)$s['sim_pospago'];
}
foreach ($gruposZona as &$g) {
  usort($g['rows'], function ($a, $b) {
    return $b['total_ventas'] <=> $a['total_ventas'];
  });
  $g['tot']['cumpl'] = $g['tot']['cuota'] > 0 ? ($g['tot']['ventas'] / $g['tot']['cuota'] * 100) : 0.0;
}
unset($g);
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

/* ==============================
   % objetivo hoy
================================= */
$hoyMX = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$hoyStr = $hoyMX->format('Y-m-d');
if ($hoyStr < $inicioSemana) {
  $pctObjetivoSem = 0;
} elseif ($hoyStr > $finSemana) {
  $pctObjetivoSem = 100;
} else {
  $diasTrans = (new DateTime($inicioSemana))->diff(new DateTime($hoyStr))->days + 1;
  $pctObjetivoSem = min(100, ($diasTrans / 7) * 100);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Semanal Luga</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .clip {
      max-width: 160px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap
    }
    .clip-name { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
    .clip-branch { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
    .num { font-variant-numeric: tabular-nums; letter-spacing: -.2px }
    .col-fit { width: 1%; white-space: nowrap }
    .trend { font-size: .875rem; white-space: nowrap }
    .trend .delta { font-weight: 600 }
    #topbar { font-size: 16px }
    .form-switch.form-switch-sm .form-check-input { height: 1rem; width: 2rem; transform: scale(.95) }
    .form-switch.form-switch-sm .form-check-label { font-size: .8rem; margin-left: .25rem }
    .hide-delta .trend { display: none !important }
    @media (max-width:576px) {
      body { font-size: 14px }
      .container { padding-left: 8px; padding-right: 8px }
      .card .card-header { padding: .5rem .65rem; font-size: .95rem }
      .card .card-body { padding: .65rem }
      .table { font-size: 12px; table-layout: auto }
      .table thead th { font-size: 11px }
      .table td, .table th { padding: .35rem .45rem }
      .trend { font-size: .72rem }
      .clip { max-width: 130px }
      .clip-name { max-width: 240px }
      .clip-branch { max-width: 160px }
    }
    @media (max-width:360px) {
      .table { font-size: 11px }
      .table td, .table th { padding: .30rem .40rem }
      .clip { max-width: 110px }
      .clip-name { max-width: 200px }
      .clip-branch { max-width: 140px }
      .table .progress { width: 100% }
    }
    .card-header .btn.btn-sm { padding: .15rem .4rem; line-height: 1 }
    .progress { position: relative }
    .progress-target { position: absolute; left: var(--target, 0%); transform: translateX(-50%); top: -2px; height: 18px; width: 0; pointer-events: none }
    .progress-target .tick { position: absolute; bottom: 0; left: -1px; width: 2px; height: 16px; background: #16a34a; opacity: .85; border-radius: 1px; box-shadow: 0 0 0 1px rgba(0, 0, 0, .06) }
    .progress-target .dot { position: absolute; top: -6px; left: -4px; width: 8px; height: 8px; border-radius: 50%; background: #16a34a; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, .12); opacity: .95 }
  </style>
</head>

<body class="bg-light">
  <?php include 'navbar.php'; ?>

  <div class="container mt-4">
    <h2>📊 Dashboard Semanal</h2>

    <!-- Selector de semana -->
    <form method="GET" class="mb-3">
      <label><strong>Selecciona semana:</strong></label>
      <select name="semana" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
        <?php for ($i = 0; $i < 8; $i++):
          list($ini, $fin) = obtenerSemanaPorIndice($i);
          $texto = "Semana del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
        ?>
          <option value="<?= $i ?>" <?= $i == $semanaSeleccionada ? 'selected' : '' ?>><?= $texto ?></option>
        <?php endfor; ?>
      </select>
      <span class="ms-2 text-muted small">Comparando con: <?= $inicioPrevObj->format('d/m/Y') ?> → <?= $finPrevObj->format('d/m/Y') ?></span>
    </form>

    <!-- Tarjetas por zonas + global -->
    <div class="row mb-4">
      <?php
      $cards = [
        ['label' => 'Zona 1', 'data' => $z1],
        ['label' => 'Zona 2', 'data' => $z2],
      ];
      foreach ($cards as $c):
        $d = $c['data'];
        $cumpl = (float)$d['cumplimiento'];
        $barra = $cumpl >= 100 ? 'bg-success' : ($cumpl >= 60 ? 'bg-warning' : 'bg-danger');
      ?>
        <div class="col-md-4 mb-3">
          <div class="card shadow text-center">
            <div class="card-header bg-dark text-white"><?= h($c['label']) ?></div>
            <div class="card-body">
              <h5><?= number_format($cumpl, 1) ?>% Cumplimiento</h5>
              <p>
                Unidades: <?= (int)$d['unidades'] ?><br>
                Ventas: $<?= number_format($d['ventas'], 2) ?><br>
                Cuota: $<?= number_format($d['cuota'], 2) ?>
              </p>
              <div class="progress" style="height:20px">
                <div class="progress-bar <?= $barra ?>" style="width:<?= min(100, $cumpl) ?>%"><?= number_format(min(100, $cumpl), 1) ?>%</div>
                <div class="progress-target" style="--target: <?= number_format($pctObjetivoSem, 2) ?>%" title="Meta hoy: <?= number_format($pctObjetivoSem, 1) ?>%">
                  <span class="tick"></span><span class="dot"></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php
      $cumplG = (float)$porcentajeGlobal;
      $barraG = $cumplG >= 100 ? 'bg-success' : ($cumplG >= 60 ? 'bg-warning' : 'bg-danger');
      ?>
      <div class="col-md-4 mb-3">
        <div class="card shadow text-center">
          <div class="card-header bg-primary text-white">Global Compañía</div>
          <div class="card-body">
            <h5><?= number_format($cumplG, 1) ?>% Cumplimiento</h5>
            <p>
              Unidades: <?= $totalUnidades ?><br>
              Ventas: $<?= number_format($totalVentasGlobal, 2) ?><br>
              Cuota: $<?= number_format($totalCuotaGlobal, 2) ?>
            </p>
            <div class="progress" style="height:20px">
              <div class="progress-bar <?= $barraG ?>" style="width:<?= min(100, $cumplG) ?>%"><?= number_format(min(100, $cumplG), 1) ?>%</div>
              <div class="progress-target" style="--target: <?= number_format($pctObjetivoSem, 2) ?>%" title="Meta hoy: <?= number_format($pctObjetivoSem, 1) ?>%">
                <span class="tick"></span><span class="dot"></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Gráfica -->
    <div class="card shadow mb-4">
      <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
        <span>Resumen semanal por sucursal</span>
        <div class="btn-group btn-group-sm">
          <button id="btnUnidades" class="btn btn-primary" type="button">Unidades</button>
          <button id="btnVentas" class="btn btn-outline-light" type="button">Ventas ($)</button>
        </div>
      </div>
      <div class="card-body">
        <div id="chartWrap" style="position:relative; height:380px;">
          <canvas id="chartSemanal"></canvas>
        </div>
        <small class="text-muted d-block mt-2">
          * Se muestran Top-<?= $TOP_BARS ?> sucursales de la métrica seleccionada + “Otras”.
        </small>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ejecutivos">Ejecutivos 👔</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sucursales">Sucursales 🏢</button></li>
    </ul>

    <div class="tab-content">
      <!-- Ejecutivos -->
      <div class="tab-pane fade show active" id="ejecutivos">
        <div class="card mb-4 shadow hide-delta" id="card_ejecutivos">
          <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
            <span>Ranking de Ejecutivos</span>
            <div class="d-flex align-items-center gap-2">
              <div class="form-check form-switch form-switch-sm text-nowrap me-1" title="Mostrar comparativo (Δ)">
                <input class="form-check-input" type="checkbox" id="swDeltaEj">
                <label class="form-check-label small" for="swDeltaEj">Δ</label>
              </div>
              <button type="button" id="btnSnapEj" class="btn btn-outline-light btn-sm" title="Descargar imagen">
                <i class="bi bi-download"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="table-responsive-sm">
              <table class="table table-striped table-bordered align-middle table-sm">
                <thead class="table-dark">
                  <tr>
                    <th>Ejecutivo</th>
                    <th class="d-none d-sm-table-cell">Sucursal</th>
                    <th class="col-fit">Unidades</th>
                    <th class="d-none d-sm-table-cell col-fit">Total Ventas ($)</th>
                    <th class="col-fit">% Cumpl.</th>

                    <!-- SIMs visibles en móvil -->
                    <th class="d-table-cell d-md-none col-fit">SIM Prep.</th>
                    <th class="d-table-cell d-md-none col-fit">SIM Pos.</th>

                    <!-- SIMs escritorio -->
                    <th class="d-none d-md-table-cell col-fit">SIM Prep.</th>
                    <th class="d-none d-md-table-cell col-fit">SIM Pos.</th>

                    <th class="d-none d-sm-table-cell">Progreso</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rankingEjecutivos as $r):
                    $cumpl = round($r['cumplimiento'], 1);
                    $estado = $cumpl >= 100 ? "✅" : ($cumpl >= 60 ? "⚠️" : "❌");
                    $fila = $cumpl >= 100 ? "table-success" : ($cumpl >= 60 ? "table-warning" : "table-danger");
                    $iconTop = in_array($r['id'], $top3Ejecutivos) ? ' 🏆' : '';
                    $dU = (int)$r['delta_unidades'];
                    [$icoU, $clsU] = arrowIcon($dU);
                    $pctU = pctDelta($r['unidades'], $r['unidades'] - $dU);
                    $metaU = (int)$r['cuota_ejecutivo'];
                    $progPct = $metaU > 0 ? min(100, ($r['unidades'] / $metaU) * 100) : 0;
                  ?>
                    <tr class="<?= $fila ?>">
                      <td class="clip-name" title="<?= h($r['nombre']) ?>"><?= h($r['nombre']) . $iconTop ?></td>
                      <td class="clip-branch d-none d-sm-table-cell" title="<?= h($r['sucursal']) ?>"><?= h($r['sucursal']) ?></td>
                      <td class="num col-fit">
                        <?= (int)$r['unidades'] ?>
                        <div class="trend">
                          <span class="<?= $clsU ?>"><?= $icoU ?></span>
                          <span class="delta <?= $clsU ?>"><?= ($dU > 0 ? '+' : '') . $dU ?> u.</span>
                          <?php if ($pctU !== null): ?><span class="text-muted">(<?= ($pctU >= 0 ? '+' : '') . number_format($pctU, 1) ?>%)</span><?php endif; ?>
                        </div>
                      </td>
                      <td class="d-none d-sm-table-cell num col-fit">
                        <span class="money-abbr" data-raw="<?= (float)$r['total_ventas'] ?>">$<?= number_format($r['total_ventas'], 2) ?></span>
                      </td>
                      <td class="num col-fit">
                        <?= number_format($cumpl, 1) ?>%
                        <!-- icono solo en escritorio -->
                        <span class="d-none d-md-inline"><?= $estado ?></span>
                      </td>

                      <!-- móvil -->
                      <td class="d-table-cell d-md-none num col-fit"><?= (int)$r['sim_prepago'] ?></td>
                      <td class="d-table-cell d-md-none num col-fit"><?= (int)$r['sim_pospago'] ?></td>

                      <!-- escritorio -->
                      <td class="d-none d-md-table-cell num col-fit"><?= (int)$r['sim_prepago'] ?></td>
                      <td class="d-none d-md-table-cell num col-fit"><?= (int)$r['sim_pospago'] ?></td>

                      <td class="d-none d-sm-table-cell">
                        <div class="progress" style="height:20px">
                          <div class="progress-bar <?= $progPct >= 100 ? 'bg-success' : ($progPct >= 60 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $progPct ?>%"><?= number_format($progPct, 1) ?>%</div>
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

      <!-- Sucursales (AGRUPADAS POR ZONA) -->
      <div class="tab-pane fade" id="sucursales">
        <div class="card mb-4 shadow hide-delta" id="card_sucursales">
          <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
            <span>Ranking de Sucursales (agrupado por zona)</span>
            <div class="d-flex align-items-center gap-2">
              <div class="form-check form-switch form-switch-sm text-nowrap me-1" title="Mostrar comparativo (Δ)">
                <input class="form-check-input" type="checkbox" id="swDeltaSuc">
                <label class="form-check-label small" for="swDeltaSuc">Δ</label>
              </div>
              <button type="button" id="btnSnapSuc" class="btn btn-outline-light btn-sm" title="Descargar imagen">
                <i class="bi bi-download"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="table-responsive-sm">
              <table class="table table-striped table-bordered align-middle table-sm">
                <thead class="table-dark">
                  <tr>
                    <th>Sucursal</th>

                    <!-- móvil (orden: Uds, $, %, Pre, Pos) -->
                    <th class="d-table-cell d-md-none col-fit">Uds</th>
                    <th class="d-table-cell d-md-none col-fit">$</th>
                    <th class="d-table-cell d-md-none col-fit">% Cumpl.</th>
                    <th class="d-table-cell d-md-none col-fit">Pre</th>
                    <th class="d-table-cell d-md-none col-fit">Pos</th>

                    <!-- md+ -->
                    <th class="d-none d-md-table-cell">Zona</th>
                    <th class="d-none d-md-table-cell">Unidades</th>
                    <th class="d-none d-md-table-cell col-fit">Total Ventas ($)</th>
                    <th class="d-none d-md-table-cell col-fit">% Cumpl.</th>
                    <th class="d-none d-md-table-cell col-fit">SIM Prep.</th>
                    <th class="d-none d-md-table-cell col-fit">SIM Pos.</th>
                    <th class="d-none d-lg-table-cell col-fit">Cuota ($)</th>
                    <th class="d-none d-lg-table-cell">Progreso</th>
                  </tr>
                </thead>

                <tbody>
                  <?php foreach ($gruposZona as $zona => $grp): ?>
                    <!-- Encabezado de grupo (ZONA) -->
                    <tr class="table-secondary d-table-row d-md-none">
                      <th colspan="7" class="text-start"><?= h($zona) ?></th>
                    </tr>
                    <tr class="table-secondary d-none d-md-table-row d-lg-none">
                      <th colspan="8" class="text-start"><?= h($zona) ?></th>
                    </tr>
                    <tr class="table-secondary d-none d-lg-table-row">
                      <th colspan="10" class="text-start"><?= h($zona) ?></th>
                    </tr>

                    <!-- Filas de sucursales -->
                    <?php foreach ($grp['rows'] as $s):
                      $cumpl = round($s['cumplimiento'], 1);
                      $estado = $cumpl >= 100 ? "✅" : ($cumpl >= 60 ? "⚠️" : "❌");
                      $fila = $cumpl >= 100 ? "table-success" : ($cumpl >= 60 ? "table-warning" : "table-danger");

                      $dM   = (float)$s['delta_monto'];
                      [$icoM, $clsM] = arrowIcon($dM);
                      $pctM = $s['pct_delta_monto'];
                    ?>
                      <tr class="<?= $fila ?>">
                        <td class="clip-branch" title="<?= h($s['sucursal']) ?>">
                          <span class="d-none d-md-inline"><?= h($s['sucursal']) ?></span>
                          <span class="d-inline d-md-none"><?= h(sucursalCorta($s['sucursal'])) ?></span>
                        </td>

                        <!-- móvil: Uds, $, %, Pre, Pos -->
                        <td class="d-table-cell d-md-none num"><?= (int)$s['unidades'] ?></td>
                        <td class="d-table-cell d-md-none num">
                          <span class="money-abbr" data-raw="<?= (float)$s['total_ventas'] ?>">$<?= number_format($s['total_ventas'], 2) ?></span>
                        </td>
                        <!-- 👇 ÍCONO REMOVIDO EN MÓVIL -->
                        <td class="d-table-cell d-md-none num"><?= number_format($cumpl, 1) ?>%</td>
                        <td class="d-table-cell d-md-none num"><?= (int)$s['sim_prepago'] ?></td>
                        <td class="d-table-cell d-md-none num"><?= (int)$s['sim_pospago'] ?></td>

                        <!-- md+: Zona, Uds, Total, %Cumpl (con icono), SIMs, (Cuota), Progreso -->
                        <td class="d-none d-md-table-cell"><?= h(normalizarZona($s['zona'] ?? '') ?? '—') ?></td>
                        <td class="d-none d-md-table-cell num"><?= (int)$s['unidades'] ?></td>

                        <td class="d-none d-md-table-cell num col-fit">
                          <span class="money-abbr" data-raw="<?= (float)$s['total_ventas'] ?>">$<?= number_format($s['total_ventas'], 2) ?></span>
                          <div class="trend">
                            <span class="<?= $clsM ?>"><?= $icoM ?></span>
                            <span class="delta <?= $clsM ?>">
                              <?= ($dM > 0 ? '+' : ($dM < 0 ? '' : '')) . '$' . number_format($dM, 2) ?>
                            </span>
                            <?php if ($pctM !== null): ?>
                              <span class="text-muted">
                                (<?= ($pctM >= 0 ? '+' : '') . number_format($pctM, 1) ?>%)
                              </span>
                            <?php endif; ?>
                          </div>
                        </td>

                        <td class="d-none d-md-table-cell num col-fit"><?= number_format($cumpl, 1) ?>% <?= $estado ?></td>
                        <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_prepago'] ?></td>
                        <td class="d-none d-md-table-cell num col-fit"><?= (int)$s['sim_pospago'] ?></td>

                        <td class="d-none d-lg-table-cell num col-fit">
                          <span class="money-abbr" data-raw="<?= (float)$s['cuota_semanal'] ?>">$<?= number_format($s['cuota_semanal'], 2) ?></span>
                        </td>

                        <td class="d-none d-lg-table-cell">
                          <div class="progress" style="height:20px">
                            <div class="progress-bar <?= $cumpl >= 100 ? 'bg-success' : ($cumpl >= 60 ? 'bg-warning' : 'bg-danger') ?>"
                              style="width:<?= min(100, $cumpl) ?>%"><?= $cumpl ?>%</div>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                    <!-- Totales por ZONA -->
                    <?php
                    $tzU = (int)$grp['tot']['unidades'];
                    $tzV = (float)$grp['tot']['ventas'];
                    $tzC = (float)$grp['tot']['cuota'];
                    $tzP = (float)$grp['tot']['cumpl'];
                    $tzPre = (int)$grp['tot']['sim_pre'];
                    $tzPos = (int)$grp['tot']['sim_pos'];
                    $cls = $tzP >= 100 ? 'bg-success' : ($tzP >= 60 ? 'bg-warning' : 'bg-danger');
                    ?>
                    <!-- móvil total zona -->
                    <tr class="table-light fw-semibold d-table-row d-md-none">
                      <td class="text-end">Total <?= h($zona) ?>:</td>
                      <td class="num"><?= $tzU ?></td>
                      <td class="num"><span class="money-abbr" data-raw="<?= $tzV ?>">$<?= number_format($tzV, 2) ?></span></td>
                      <td class="num"><?= number_format($tzP, 1) ?>%</td>
                      <td class="num"><?= $tzPre ?></td>
                      <td class="num"><?= $tzPos ?></td>
                    </tr>

                    <!-- md+ total zona -->
                    <tr class="table-light fw-semibold d-none d-md-table-row">
                      <td colspan="2" class="text-end">Total <?= h($zona) ?>:</td>
                      <td class="num"><?= $tzU ?></td>
                      <td class="num col-fit"><span class="money-abbr" data-raw="<?= $tzV ?>">$<?= number_format($tzV, 2) ?></span></td>
                      <td class="num col-fit"><?= number_format($tzP, 1) ?>%</td>
                      <td class="num col-fit"><?= $tzPre ?></td>
                      <td class="num col-fit"><?= $tzPos ?></td>
                      <td class="d-none d-lg-table-cell num col-fit"><span class="money-abbr" data-raw="<?= $tzC ?>">$<?= number_format($tzC, 2) ?></span></td>
                      <td class="d-none d-lg-table-cell">
                        <div class="progress" style="height:20px">
                          <div class="progress-bar <?= $cls ?>" style="width:<?= min(100, $tzP) ?>%"><?= number_format(min(100, $tzP), 1) ?>%</div>
                        </div>
                      </td>
                    </tr>

                  <?php endforeach; ?>

                  <!-- ====== TOTAL GLOBAL ====== -->
                  <?php $clsG = $porcentajeGlobal >= 100 ? 'bg-success' : ($porcentajeGlobal >= 60 ? 'bg-warning' : 'bg-danger'); ?>
                  <!-- móvil -->
                  <tr class="table-primary fw-bold d-table-row d-md-none">
                    <td class="text-end">Total global:</td>
                    <td class="num"><?= (int)$totalUnidades ?></td>
                    <td class="num"><span class="money-abbr" data-raw="<?= (float)$totalVentasGlobal ?>">$<?= number_format($totalVentasGlobal, 2) ?></span></td>
                    <td class="num"><?= number_format($porcentajeGlobal, 1) ?>%</td>
                    <td class="num"><?= (int)$totalSimPre ?></td>
                    <td class="num"><?= (int)$totalSimPos ?></td>
                  </tr>
                  <!-- md+ -->
                  <tr class="table-primary fw-bold d-none d-md-table-row">
                    <td colspan="2" class="text-end">Total global:</td>
                    <td class="num"><?= (int)$totalUnidades ?></td>
                    <td class="num col-fit"><span class="money-abbr" data-raw="<?= (float)$totalVentasGlobal ?>">$<?= number_format($totalVentasGlobal, 2) ?></span></td>
                    <td class="num col-fit"><?= number_format($porcentajeGlobal, 1) ?>%</td>
                    <td class="num col-fit"><?= (int)$totalSimPre ?></td>
                    <td class="num col-fit"><?= (int)$totalSimPos ?></td>
                    <td class="d-none d-lg-table-cell num col-fit"><span class="money-abbr" data-raw="<?= (float)$totalCuotaGlobal ?>">$<?= number_format($totalCuotaGlobal, 2) ?></span></td>
                    <td class="d-none d-lg-table-cell">
                      <div class="progress" style="height:20px">
                        <div class="progress-bar <?= $clsG ?>" style="width:<?= min(100, $porcentajeGlobal) ?>%"><?= number_format(min(100, $porcentajeGlobal), 1) ?>%</div>
                      </div>
                    </td>
                  </tr>
                  <!-- ====== /TOTAL GLOBAL ====== -->

                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- libs -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

  <script>
    /* ===========================
       Datos completos de sucursales (chart)
    =========================== */
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
      const ctx = document.getElementById('chartSemanal').getContext('2d');
      const bg = series.labels.map((_, i) => palette(i));
      const isMoney = (currentMetric === 'ventas');
      const data = {
        labels: series.labels,
        datasets: [{
          label: isMoney ? 'Ventas ($)' : 'Unidades (semana)',
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
                }) :
                ' ' + ctx.parsed.y.toLocaleString('es-MX') + ' u.'
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
    const btnU = document.getElementById('btnUnidades'),
      btnV = document.getElementById('btnVentas');
    btnU.addEventListener('click', () => {
      currentMetric = 'unidades';
      btnU.className = 'btn btn-primary';
      btnV.className = 'btn btn-outline-light';
      renderChart();
    });
    btnV.addEventListener('click', () => {
      currentMetric = 'ventas';
      btnV.className = 'btn btn-primary';
      btnU.className = 'btn btn-outline-light';
      renderChart();
    });

    /* ===========================
       Abreviar montos (tablas)
    =========================== */
    (function() {
      const nf = new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });

      function abbr(n) {
        const abs = Math.abs(n);
        if (abs >= 1_000_000) return (n / 1_000_000).toFixed(2) + 'M';
        if (abs >= 1_000) return (n / 1_000).toFixed(2) + 'K';
        return nf.format(n);
      }

      function render() {
        const isMobile = window.matchMedia('(max-width: 576px)').matches;
        document.querySelectorAll('.money-abbr').forEach(el => {
          const raw = Number(el.getAttribute('data-raw') || 0);
          el.textContent = isMobile ? ('$' + abbr(raw)) : ('$' + nf.format(raw));
        });
      }
      render();
      window.addEventListener('resize', render);
    })();

    /* ===========================
       Descargar imagen (html2canvas)
    =========================== */
    (function() {
      const rango = "<?= $inicioObj->format('Ymd') ?>-<?= $finObj->format('Ymd') ?>";

      function prepOverflow(el, setVisible) {
        const affected = [];
        el.querySelectorAll('.table-responsive, .table-responsive-sm, .table-responsive-md, .table-responsive-lg').forEach(div => {
          affected.push({
            node: div,
            prev: div.style.overflow
          });
          if (setVisible) div.style.overflow = 'visible';
        });
        return affected;
      }
      async function snapCard(cardId, filename) {
        const el = document.getElementById(cardId);
        if (!el) return;
        const prev = {
          overflow: el.style.overflow,
          scrollTop: el.scrollTop,
          scrollLeft: el.scrollLeft,
          boxShadow: el.style.boxShadow
        };
        el.style.overflow = 'visible';
        const overflowFixes = prepOverflow(el, true);
        const scale = Math.min(3, (window.devicePixelRatio || 1) * 1.5);
        const canvas = await html2canvas(el, {
          backgroundColor: '#ffffff',
          scale,
          useCORS: true,
          logging: false,
          windowWidth: document.documentElement.scrollWidth,
          windowHeight: document.documentElement.scrollHeight
        });
        el.style.overflow = prev.overflow;
        el.scrollTop = prev.scrollTop;
        el.scrollLeft = prev.scrollLeft;
        el.style.boxShadow = prev.boxShadow;
        overflowFixes.forEach(({
          node,
          prev
        }) => node.style.overflow = prev);
        const link = document.createElement('a');
        link.download = filename;
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      }
      const btnEj = document.getElementById('btnSnapEj');
      if (btnEj) btnEj.addEventListener('click', () => snapCard('card_ejecutivos', `ranking_ejecutivos_${rango}.png`));
      const btnSu = document.getElementById('btnSnapSuc');
      if (btnSu) btnSu.addEventListener('click', () => snapCard('card_sucursales', `ranking_sucursales_${rango}.png`));
    })();

    /* ===========================
       Switch de DELTAS
    =========================== */
    (function() {
      const swEj = document.getElementById('swDeltaEj');
      const cardEj = document.getElementById('card_ejecutivos');
      if (swEj && cardEj) {
        swEj.checked = false;
        cardEj.classList.add('hide-delta');
        swEj.addEventListener('change', () => {
          cardEj.classList.toggle('hide-delta', !swEj.checked);
        });
      }
      const swSu = document.getElementById('swDeltaSuc');
      const cardSu = document.getElementById('card_sucursales');
      if (swSu && cardSu) {
        swSu.checked = false;
        cardSu.classList.add('hide-delta');
        swSu.addEventListener('change', () => {
          cardSu.classList.toggle('hide-delta', !swSu.checked);
        });
      }
    })();
  </script>
</body>

</html>
