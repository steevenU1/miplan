<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
  header("Location: index.php"); exit();
}

require_once __DIR__ . '/db.php';

/* ========================
   Helpers
======================== */
function obtenerSemanaPorIndice($offset = 0) {
  $hoy = new DateTime();
  $dia = $hoy->format('N'); // 1=Lun ... 7=Dom
  $dif = $dia - 2; if ($dif < 0) $dif += 7; // base martes
  $ini = new DateTime(); $ini->modify("-$dif days")->setTime(0,0,0);
  if ($offset > 0) $ini->modify("-".(7*$offset)." days");
  $fin = clone $ini; $fin->modify("+6 days")->setTime(23,59,59);
  return [$ini,$fin];
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ========================
   Parámetros
======================== */
$semana     = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
$id_usuario = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;

list($iniObj,$finObj) = obtenerSemanaPorIndice($semana);
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Datos del ejecutivo
======================== */
$usr = null;
$stmt = $conn->prepare("
  SELECT u.nombre, s.nombre AS sucursal
  FROM usuarios u
  LEFT JOIN sucursales s ON s.id = u.id_sucursal
  WHERE u.id = ? LIMIT 1
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$usr = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nombreEjecutivo = $usr['nombre'] ?? 'Ejecutivo';
$nombreSucursal  = $usr['sucursal'] ?? '(sin sucursal)';

/* ========================
   Heurístico robusto: PARECE MODEM
======================== */
$condPareceModem = "("
  ." p.tipo_producto = 'Modem'"
  ." OR UPPER(TRIM(p.marca))  LIKE 'HBB%'"
  ." OR UPPER(TRIM(p.modelo)) LIKE '%MIFI%'"
  ." OR UPPER(TRIM(p.modelo)) LIKE '%MODEM%'"
.")";

/* ========================
   1) EQUIPOS (excluye “parece modem”)
   Nota prod: reemplazo ANY_VALUE(...) por MIN(...) para compatibilidad.
======================== */
$equipos = [];
$sqlEquipos = "
  SELECT v.id AS venta_id,
         v.fecha_venta,
         /* etiqueta informativa: cualquier tipo no-modem en la venta */
         MIN(CASE WHEN {$condPareceModem} THEN 'Modem' ELSE p.tipo_producto END) AS tipo_detectado,
         SUM(dv.comision) AS comision_venta
  FROM detalle_venta dv
  INNER JOIN ventas v  ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_usuario = ?
    AND v.fecha_venta BETWEEN ? AND ?
    AND LOWER(p.tipo_producto) <> 'accesorio'
    AND NOT {$condPareceModem}
  GROUP BY v.id, v.fecha_venta
  ORDER BY v.fecha_venta, v.id
";
$stmt = $conn->prepare($sqlEquipos);
$stmt->bind_param("iss", $id_usuario, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
$totalEquipos = 0.0;
while ($r = $rs->fetch_assoc()) { $equipos[] = $r; $totalEquipos += (float)$r['comision_venta']; }
$stmt->close();

/* Unidades de equipo (informativo) */
$unidadesEquipos = 0;
$sqlUnidades = "
  SELECT COUNT(*) AS unidades
  FROM detalle_venta dv
  INNER JOIN ventas v  ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_usuario = ?
    AND v.fecha_venta BETWEEN ? AND ?
    AND LOWER(p.tipo_producto) <> 'accesorio'
    AND NOT {$condPareceModem}
";
$stmt = $conn->prepare($sqlUnidades);
$stmt->bind_param("iss", $id_usuario, $ini, $fin);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$unidadesEquipos = (int)($row['unidades'] ?? 0);
$stmt->close();

/* ========================
   2) MODEM (incluye “parece modem”)
======================== */
$modems = [];
$sqlModems = "
  SELECT v.id AS venta_id, v.fecha_venta, SUM(dv.comision) AS comision_venta
  FROM detalle_venta dv
  INNER JOIN ventas v  ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_usuario = ?
    AND v.fecha_venta BETWEEN ? AND ?
    AND {$condPareceModem}
  GROUP BY v.id, v.fecha_venta
  ORDER BY v.fecha_venta, v.id
";
$stmt = $conn->prepare($sqlModems);
$stmt->bind_param("iss", $id_usuario, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
$totalModem = 0.0;
while ($r = $rs->fetch_assoc()) { $modems[] = $r; $totalModem += (float)$r['comision_venta']; }
$stmt->close();

/* ========================
   3) SIMs PREPAGO
======================== */
$sims = [];
$sqlSims = "
  SELECT vs.id AS venta_id, vs.fecha_venta, vs.tipo_venta, vs.comision_ejecutivo
  FROM ventas_sims vs
  WHERE vs.id_usuario = ?
    AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta IN ('Nueva','Portabilidad','Regalo')
  ORDER BY vs.fecha_venta, vs.id
";
$stmt = $conn->prepare($sqlSims);
$stmt->bind_param("iss", $id_usuario, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
$totalSims = 0.0;
while ($r = $rs->fetch_assoc()) { $sims[] = $r; $totalSims += (float)$r['comision_ejecutivo']; }
$stmt->close();

/* ========================
   4) POSPAGO
======================== */
$pospago = [];
$sqlPos = "
  SELECT vs.id AS venta_id, vs.fecha_venta, vs.modalidad, vs.precio_total, vs.comision_ejecutivo
  FROM ventas_sims vs
  WHERE vs.id_usuario = ?
    AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta = 'Pospago'
  ORDER BY vs.fecha_venta, vs.id
";
$stmt = $conn->prepare($sqlPos);
$stmt->bind_param("iss", $id_usuario, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
$totalPospago = 0.0;
while ($r = $rs->fetch_assoc()) { $pospago[] = $r; $totalPospago += (float)$r['comision_ejecutivo']; }
$stmt->close();

/* ========================
   TOTAL
======================== */
$total = $totalEquipos + $totalModem + $totalSims + $totalPospago;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Auditoría comisiones — Ejecutivo</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --card-bg:#fff; --chip:#f1f5f9; }
    body{ background:#f7f7fb; }
    .page-header{ display:flex; gap:1rem; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .page-title{ display:flex; gap:.75rem; align-items:center; }
    .page-title .emoji{ font-size:1.6rem; }
    .card-soft{ background:var(--card-bg); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .chip{ display:inline-flex; gap:.4rem; align-items:center; background:var(--chip); border-radius:999px; padding:.25rem .6rem; font-size:.85rem; }
    .section h6{ margin:0; }
    .pill{ display:inline-block; padding:.25rem .55rem; border-radius:999px; font-size:.8rem; border:1px solid #e5e7eb; }
    .pill-eq{ background:#eef2ff; color:#3730a3; border-color:#e0e7ff; }
    .pill-mo{ background:#ecfeff; color:#155e75; border-color:#cffafe; }
    .pill-si{ background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
    .pill-po{ background:#fff7ed; color:#9a3412; border-color:#fed7aa; }
    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; }
    @media print{ .no-print{display:none!important;} body{background:#fff;} .card-soft{box-shadow:none;border:0;} .table thead th{position:static;} }
  </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container py-4">

  <!-- Header -->
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">🔍</span>
      <div>
        <h3 class="mb-0">Auditoría de comisiones — <?= h($nombreEjecutivo) ?></h3>
        <div class="text-muted small">
          <span class="chip"><i class="bi bi-shop-window me-1"></i><?= h($nombreSucursal) ?></span>
          <span class="chip"><i class="bi bi-calendar-week me-1"></i><?= $iniObj->format('d/m/Y') ?> – <?= $finObj->format('d/m/Y') ?></span>
          <span class="chip"><i class="bi bi-phone me-1"></i>Unidades equipo: <strong><?= $unidadesEquipos ?></strong></span>
        </div>
      </div>
    </div>
    <div class="no-print d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="reporte_nomina.php?semana=<?= $semana ?>"><i class="bi bi-arrow-left me-1"></i>Volver</a>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
    </div>
  </div>

  <!-- Resumen -->
  <div class="d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Equipos</div>
      <div class="h5 mb-0">$<?= number_format($totalEquipos,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">MiFi / Modem</div>
      <div class="h5 mb-0">$<?= number_format($totalModem,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">SIMs (prepago)</div>
      <div class="h5 mb-0">$<?= number_format($totalSims,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Pospago</div>
      <div class="h5 mb-0">$<?= number_format($totalPospago,2) ?></div>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total comisiones</div>
      <div class="h4 mb-0">$<?= number_format($total,2) ?></div>
    </div>
  </div>

  <!-- Secciones -->
  <div class="row g-3">

    <!-- Equipos -->
    <div class="col-12">
      <div class="card-soft p-0 section">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
          <h6><span class="pill pill-eq me-2">Equipos</span>Ventas de equipos (excluye modem)</h6>
          <div class="text-muted small">Total: <strong>$<?= number_format($totalEquipos,2) ?></strong></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Venta #</th>
                <th>Fecha</th>
                <th>Tipo detectado</th>
                <th class="text-end">Comisión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$equipos): ?>
                <tr><td colspan="4" class="text-muted text-center py-3">Sin ventas de equipos.</td></tr>
              <?php else: foreach($equipos as $v): ?>
                <tr>
                  <td><?= (int)$v['venta_id'] ?></td>
                  <td><?= h(date('d/m/Y', strtotime($v['fecha_venta']))) ?></td>
                  <td><span class="pill"><?= h($v['tipo_detectado']) ?></span></td>
                  <td class="text-end">$<?= number_format($v['comision_venta'],2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modem -->
    <div class="col-12">
      <div class="card-soft p-0 section">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
          <h6><span class="pill pill-mo me-2">MiFi/Modem</span>Ventas que parecen modem</h6>
          <div class="text-muted small">Total: <strong>$<?= number_format($totalModem,2) ?></strong></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Venta #</th>
                <th>Fecha</th>
                <th class="text-end">Comisión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$modems): ?>
                <tr><td colspan="3" class="text-muted text-center py-3">Sin ventas de modem.</td></tr>
              <?php else: foreach($modems as $v): ?>
                <tr>
                  <td><?= (int)$v['venta_id'] ?></td>
                  <td><?= h(date('d/m/Y', strtotime($v['fecha_venta']))) ?></td>
                  <td class="text-end">$<?= number_format($v['comision_venta'],2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- SIMs -->
    <div class="col-12">
      <div class="card-soft p-0 section">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
          <h6><span class="pill pill-si me-2">SIMs (prepago)</span></h6>
          <div class="text-muted small">Total: <strong>$<?= number_format($totalSims,2) ?></strong></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Venta #</th>
                <th>Fecha</th>
                <th>Tipo</th>
                <th class="text-end">Comisión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$sims): ?>
                <tr><td colspan="4" class="text-muted text-center py-3">Sin ventas de SIMs (prepago).</td></tr>
              <?php else: foreach($sims as $v): ?>
                <tr>
                  <td><?= (int)$v['venta_id'] ?></td>
                  <td><?= h(date('d/m/Y', strtotime($v['fecha_venta']))) ?></td>
                  <td><?= h($v['tipo_venta']) ?></td>
                  <td class="text-end">$<?= number_format($v['comision_ejecutivo'],2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pospago -->
    <div class="col-12">
      <div class="card-soft p-0 section">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
          <h6><span class="pill pill-po me-2">Pospago</span></h6>
          <div class="text-muted small">Total: <strong>$<?= number_format($totalPospago,2) ?></strong></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Venta #</th>
                <th>Fecha</th>
                <th>Modalidad</th>
                <th>Plan</th>
                <th class="text-end">Comisión</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pospago): ?>
                <tr><td colspan="5" class="text-muted text-center py-3">Sin ventas de pospago.</td></tr>
              <?php else: foreach($pospago as $v): ?>
                <tr>
                  <td><?= (int)$v['venta_id'] ?></td>
                  <td><?= h(date('d/m/Y', strtotime($v['fecha_venta']))) ?></td>
                  <td><?= h($v['modalidad']) ?></td>
                  <td>$<?= number_format($v['precio_total'],2) ?></td>
                  <td class="text-end">$<?= number_format($v['comision_ejecutivo'],2) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <div class="alert alert-warning mt-3">
    <strong>Total comisiones capturadas:</strong> $<?= number_format($total,2) ?>
  </div>

  <div class="no-print">
    <a href="reporte_nomina.php?semana=<?= $semana ?>" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Volver al Reporte
    </a>
  </div>
</div>
</body>
</html>
