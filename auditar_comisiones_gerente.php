<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin', 'RH'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

/* ========================
   Helpers
======================== */
function obtenerSemanaPorIndice($offset = 0)
{
  $hoy = new DateTime();
  $dia = $hoy->format('N'); // 1=Lun ... 7=Dom
  $dif = $dia - 2;
  if ($dif < 0) $dif += 7; // base martes
  $ini = new DateTime();
  $ini->modify("-$dif days")->setTime(0, 0, 0);
  if ($offset > 0) $ini->modify("-" . (7 * $offset) . " days");
  $fin = clone $ini;
  $fin->modify("+6 days")->setTime(23, 59, 59);
  return [$ini, $fin];
}
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function columnExists(mysqli $conn, string $table, string $col): bool
{
  $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
        LIMIT 1
    ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  $res = $stmt->get_result();
  $exists = ($res && $res->num_rows > 0);
  $stmt->close();
  return $exists;
}
/* ========================
   Params
======================== */
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
$id_sucursal = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;

list($iniObj, $finObj) = obtenerSemanaPorIndice($semana);
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Datos sucursal + gerente
======================== */
$sucursalNombre = 'Sucursal';
$stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $sucursalNombre = $r['nombre'];
$stmt->close();

$idGerente = 0;
$nombreGerente = '(sin gerente)';
$stmt = $conn->prepare("SELECT id, nombre FROM usuarios WHERE rol='Gerente' AND id_sucursal=? LIMIT 1");
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) {
  $idGerente = (int)$r['id'];
  $nombreGerente = $r['nombre'];
}
$stmt->close();

/* ========================
   Esquema gerente (vigente)
======================== */
$esqGer = [];
$res = $conn->query("SELECT * FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC LIMIT 1");
if ($res) $esqGer = $res->fetch_assoc() ?: [];

/* ========================
   Cuota tienda y monto semana (robusto a esquema)
======================== */
$cuotaMonto = 0.0;
$stmt = $conn->prepare("
  SELECT cuota_monto
  FROM cuotas_sucursales
  WHERE id_sucursal=? AND fecha_inicio <= ?
  ORDER BY fecha_inicio DESC
  LIMIT 1
");
$stmt->bind_param("is", $id_sucursal, $ini);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $cuotaMonto = (float)$r['cuota_monto'];
$stmt->close();

/* Si existe detalle_venta.precio_unitario lo usamos; si no, caemos a ventas.precio_venta */
$montoSemana = 0.0;
if (columnExists($conn, 'detalle_venta', 'precio_unitario')) {
  $sqlMonto = "
      SELECT IFNULL(SUM(dv.precio_unitario),0) AS m
      FROM detalle_venta dv
      INNER JOIN ventas v ON v.id = dv.id_venta
      INNER JOIN productos p ON p.id = dv.id_producto
      WHERE v.id_sucursal=? AND v.fecha_venta BETWEEN ? AND ?
        AND LOWER(p.tipo_producto) IN ('equipo','modem')
    ";
} else {
  $sqlMonto = "
      SELECT IFNULL(SUM(v.precio_venta),0) AS m
      FROM ventas v
      WHERE v.id_sucursal=? AND v.fecha_venta BETWEEN ? AND ?
    ";
}
$stmt = $conn->prepare($sqlMonto);
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $montoSemana = (float)$r['m'];
$stmt->close();

$cumpleTienda = $montoSemana >= $cuotaMonto;

/* ========================
   Heurístico “parece modem” (case-insensitive)
======================== */
$condPareceModem = "("
  . " LOWER(p.tipo_producto) = 'modem'"
  . " OR UPPER(TRIM(p.marca))  LIKE 'HBB%'"
  . " OR UPPER(TRIM(p.modelo)) LIKE '%MIFI%'"
  . " OR UPPER(TRIM(p.modelo)) LIKE '%MODEM%'"
  . ")";
$caseTipoDetectado = "
  CASE WHEN {$condPareceModem} THEN 'Modem' ELSE p.tipo_producto END
";

/* ========================
   1) Venta directa (equipos del gerente) — solo equipos
======================== */
$ventaDirecta = [];
if ($idGerente) {
  $sql = "
    SELECT v.id AS venta_id, v.fecha_venta
    FROM detalle_venta dv
    INNER JOIN ventas v ON v.id = dv.id_venta
    INNER JOIN productos p ON p.id = dv.id_producto
    WHERE v.id_usuario=?
      AND v.fecha_venta BETWEEN ? AND ?
      AND LOWER(p.tipo_producto) <> 'accesorio'
      AND NOT {$condPareceModem}
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iss", $idGerente, $ini, $fin);
  $stmt->execute();
  $rs = $stmt->get_result();
  while ($row = $rs->fetch_assoc()) $ventaDirecta[] = $row;
  $stmt->close();
}
$comVentaDirectaUnit = $cumpleTienda ? (float)($esqGer['venta_directa_con'] ?? 0) : (float)($esqGer['venta_directa_sin'] ?? 0);
$totalVentaDirecta = count($ventaDirecta) * $comVentaDirectaUnit;

/* ========================
   2) Escalón sucursal (EQUIPOS sin modem)
======================== */
$escalonFilas = [];
$sql = "
  SELECT v.id AS venta_id, v.fecha_venta, {$caseTipoDetectado} AS tipo_detectado
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal=?
    AND v.fecha_venta BETWEEN ? AND ?
    AND LOWER(p.tipo_producto) <> 'accesorio'
    AND NOT {$condPareceModem}
  ORDER BY v.fecha_venta, v.id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) $escalonFilas[] = $row;
$stmt->close();

function tarifaGerenteTramo($n, $cumple, $esq)
{
  if ($n <= 10)  return $cumple ? (float)($esq['sucursal_1_10_con'] ?? 0)  : (float)($esq['sucursal_1_10_sin'] ?? 0);
  if ($n <= 20)  return $cumple ? (float)($esq['sucursal_11_20_con'] ?? 0) : (float)($esq['sucursal_11_20_sin'] ?? 0);
  return              $cumple ? (float)($esq['sucursal_21_mas_con'] ?? 0)   : (float)($esq['sucursal_21_mas_sin'] ?? 0);
}
$escalonDetalle = [];
$totalEscalon = 0.0;
$i = 0;
foreach ($escalonFilas as $row) {
  $i++;
  $t = tarifaGerenteTramo($i, $cumpleTienda, $esqGer);
  $totalEscalon += $t;
  if ($i <= 10) { // mostramos primeras 10 (UI)
    $escalonDetalle[] = [
      'venta_id' => $row['venta_id'],
      'tipo'     => $row['tipo_detectado'],
      'tarifa'   => $t
    ];
  }
}

/* ========================
   3) MiFi / Modem (INCLUYE lo que “parece modem”)
======================== */
$modems = [];
$sql = "
  SELECT v.id AS venta_id, v.fecha_venta
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id = dv.id_venta
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal=?
    AND v.fecha_venta BETWEEN ? AND ?
    AND {$condPareceModem}
  ORDER BY v.fecha_venta, v.id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) $modems[] = $row;
$stmt->close();

$comModemUnit = $cumpleTienda ? (float)($esqGer['comision_modem_con'] ?? 0) : (float)($esqGer['comision_modem_sin'] ?? 0);
$totalModems = count($modems) * $comModemUnit;

/* ========================
   4) SIMs (prepago) — ya grabadas
======================== */
$simCount = 0;
$simTotal = 0.0;
$stmt = $conn->prepare("
  SELECT vs.comision_gerente
  FROM ventas_sims vs
  WHERE vs.id_sucursal=?
    AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta IN ('Nueva','Portabilidad')
");
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
  $simCount++;
  $simTotal += (float)$row['comision_gerente'];
}
$stmt->close();

/* ========================
   5) Pospago — ya grabadas
======================== */
$posRows = [];
$posTotal = 0.0;
$stmt = $conn->prepare("
  SELECT vs.id, vs.comision_gerente, vs.precio_total, vs.modalidad
  FROM ventas_sims vs
  WHERE vs.id_sucursal=?
    AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta='Pospago'
  ORDER BY vs.id
");
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
  $posTotal += (float)$row['comision_gerente'];
  $posRows[] = $row;
}
$stmt->close();

/* ========================
   Comparativo (DB)
======================== */
$calculado = $totalEscalon + $totalModems + $simTotal + $posTotal;

$dbVentas = 0.0;
$stmt = $conn->prepare("
  SELECT IFNULL(SUM(comision_gerente),0) AS t
  FROM ventas 
  WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?
");
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $dbVentas = (float)$r['t'];
$stmt->close();

$dbSims = 0.0;
$stmt = $conn->prepare("
  SELECT IFNULL(SUM(comision_gerente),0) AS t
  FROM ventas_sims 
  WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?
");
$stmt->bind_param("iss", $id_sucursal, $ini, $fin);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $dbSims = (float)$r['t'];
$stmt->close();

$dbTotal = $dbVentas + $dbSims;
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <title>Auditoría comisión Gerente</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --card-bg: #fff;
      --chip: #f1f5f9;
    }

    body {
      background: #f7f7fb;
    }

    .page-header {
      display: flex;
      gap: 1rem;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
    }

    .page-title {
      display: flex;
      gap: .75rem;
      align-items: center;
    }

    .page-title .emoji {
      font-size: 1.6rem;
    }

    .card-soft {
      background: var(--card-bg);
      border: 1px solid #eef2f7;
      border-radius: 1rem;
      box-shadow: 0 6px 18px rgba(16, 24, 40, .06);
    }

    .chip {
      display: inline-flex;
      gap: .5rem;
      align-items: center;
      background: var(--chip);
      border-radius: 999px;
      padding: .35rem .65rem;
      font-size: .85rem;
    }

    .pill {
      display: inline-block;
      padding: .25rem .55rem;
      border-radius: 999px;
      font-size: .8rem;
      border: 1px solid #e5e7eb;
    }

    .pill-eq {
      background: #eef2ff;
      color: #3730a3;
      border-color: #e0e7ff;
    }

    .pill-mo {
      background: #ecfeff;
      color: #155e75;
      border-color: #cffafe;
    }

    .pill-si {
      background: #ecfdf5;
      color: #065f46;
      border-color: #a7f3d0;
    }

    .pill-po {
      background: #fff7ed;
      color: #9a3412;
      border-color: #fed7aa;
    }

    .table thead th {
      position: sticky;
      top: 0;
      z-index: 5;
      background: #fff;
    }

    @media print {
      .no-print {
        display: none !important;
      }

      body {
        background: #fff;
      }

      .card-soft {
        box-shadow: none;
        border: 0;
      }

      .table thead th {
        position: static;
      }
    }
  </style>
</head>

<body>

  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="container py-4">
    <!-- Header -->
    <div class="page-header mb-3">
      <div class="page-title">
        <span class="emoji">🧭</span>
        <div>
          <h3 class="mb-0">Auditoría comisión — <?= h($sucursalNombre) ?></h3>
          <div class="text-muted small">
            <span class="chip"><i class="bi bi-person-gear me-1"></i><?= h($nombreGerente) ?></span>
            <span class="chip"><i class="bi bi-calendar-week me-1"></i><?= $iniObj->format('d/m/Y') ?> – <?= $finObj->format('d/m/Y') ?></span>
            <span class="chip"><i class="bi bi-flag me-1"></i>Cuota: <strong>$<?= number_format($cuotaMonto, 2) ?></strong></span>
            <span class="chip"><i class="bi bi-cash-coin me-1"></i>Monto semana: <strong>$<?= number_format($montoSemana, 2) ?></strong></span>
            <span class="chip"><?= $cumpleTienda ? '✅ Cumple' : '❌ No cumple' ?></span>
          </div>
        </div>
      </div>
      <div class="no-print d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="reporte_nomina.php?semana=<?= $semana ?>"><i class="bi bi-arrow-left me-1"></i>Volver</a>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="d-flex flex-wrap gap-3 mb-3">
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">Venta directa</div>
        <div class="h5 mb-0">$<?= number_format($totalVentaDirecta, 2) ?></div>
      </div>
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">Escalón equipos</div>
        <div class="h5 mb-0">$<?= number_format($totalEscalon, 2) ?></div>
      </div>
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">Modem</div>
        <div class="h5 mb-0">$<?= number_format($totalModems, 2) ?></div>
      </div>
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">SIMs (prepago)</div>
        <div class="h5 mb-0">$<?= number_format($simTotal, 2) ?></div>
      </div>
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">Pospago</div>
        <div class="h5 mb-0">$<?= number_format($posTotal, 2) ?></div>
      </div>
      <div class="card-soft p-3">
        <div class="text-muted small mb-1">Total calculado</div>
        <div class="h4 mb-0">$<?= number_format($calculado, 2) ?></div>
      </div>
    </div>

    <!-- Venta directa -->
    <div class="card-soft p-0 mb-3">
      <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
        <h6 class="mb-0"><span class="pill pill-eq me-2">Venta directa</span>Equipos del gerente</h6>
        <div class="text-muted small">Tarifa por unidad: <strong>$<?= number_format($comVentaDirectaUnit, 2) ?></strong></div>
      </div>
      <div class="p-3">
        <?php if (!$ventaDirecta): ?>
          <div class="text-muted">No hay ventas directas del gerente esta semana.</div>
        <?php else: ?>
          <ul class="mb-2">
            <?php foreach ($ventaDirecta as $v): ?>
              <li>Venta #<?= (int)$v['venta_id'] ?> → $<?= number_format($comVentaDirectaUnit, 2) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div><strong>Total: $<?= number_format($totalVentaDirecta, 2) ?></strong></div>
        <div class="text-muted small">* Informativo. No se suma al comparativo de comisiones de gerente.</div>
      </div>
    </div>

    <!-- Escalón sucursal -->
    <div class="card-soft p-0 mb-3">
      <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
        <h6 class="mb-0"><span class="pill pill-eq me-2">Escalón sucursal</span>Equipos (sin modem)</h6>
        <div class="text-muted small">Total: <strong>$<?= number_format($totalEscalon, 2) ?></strong></div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Venta #</th>
              <th>Fecha</th>
              <th>Tipo</th>
              <th class="text-end">Tarifa</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$escalonDetalle): ?>
              <tr>
                <td colspan="4" class="text-muted text-center py-3">No hay unidades de equipo esta semana.</td>
              </tr>
              <?php else: foreach ($escalonDetalle as $e): ?>
                <tr>
                  <td><?= (int)$e['venta_id'] ?></td>
                  <td><?= h(date('d/m/Y', strtotime($ini))) ?> – <?= h(date('d/m/Y', strtotime($fin))) ?></td>
                  <td><span class="pill"><?= h($e['tipo']) ?></span></td>
                  <td class="text-end">$<?= number_format($e['tarifa'], 2) ?></td>
                </tr>
            <?php endforeach;
            endif; ?>
            <?php if (count($escalonFilas) > 10): ?>
              <tr>
                <td colspan="4" class="text-muted small text-center">… y <?= count($escalonFilas) - 10 ?> unidades más.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MiFi / Modem -->
    <div class="card-soft p-0 mb-3">
      <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
        <h6 class="mb-0"><span class="pill pill-mo me-2">MiFi / Modem</span></h6>
        <div class="text-muted small">Tarifa por unidad: <strong>$<?= number_format($comModemUnit, 2) ?></strong></div>
      </div>
      <div class="p-3">
        <?php if (!$modems): ?>
          <div class="text-muted">No hay ventas de Modem esta semana.</div>
        <?php else: ?>
          <ul class="mb-2">
            <?php foreach ($modems as $m): ?>
              <li>Venta #<?= (int)$m['venta_id'] ?> → $<?= number_format($comModemUnit, 2) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div><strong>Total: $<?= number_format($totalModems, 2) ?></strong></div>
      </div>
    </div>

    <!-- SIMs -->
    <div class="card-soft p-0 mb-3">
      <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
        <h6 class="mb-0"><span class="pill pill-si me-2">SIMs (prepago)</span></h6>
        <div class="text-muted small">Ventas: <strong><?= (int)$simCount ?></strong></div>
      </div>
      <div class="p-3">
        <div><strong>Total: $<?= number_format($simTotal, 2) ?></strong></div>
      </div>
    </div>

    <!-- Pospago -->
    <div class="card-soft p-0 mb-3">
      <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
        <h6 class="mb-0"><span class="pill pill-po me-2">Pospago</span></h6>
        <div class="text-muted small">Total: <strong>$<?= number_format($posTotal, 2) ?></strong></div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Venta #</th>
              <th>Plan</th>
              <th>Modalidad</th>
              <th class="text-end">Comisión</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$posRows): ?>
              <tr>
                <td colspan="4" class="text-muted text-center py-3">Sin ventas de pospago esta semana.</td>
              </tr>
              <?php else: foreach ($posRows as $p): ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td>$<?= number_format($p['precio_total'], 2) ?></td>
                  <td><?= h($p['modalidad']) ?></td>
                  <td class="text-end">$<?= number_format($p['comision_gerente'], 2) ?></td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Comparativo -->
    <div class="alert alert-warning">
      <strong>Comparativo:</strong>
      Calculado = <strong>$<?= number_format($calculado, 2) ?></strong> |
      Grabado en DB = <strong>$<?= number_format($dbTotal, 2) ?></strong><br>
      <span class="text-muted small">DB = SUM(ventas.comision_gerente) + SUM(ventas_sims.comision_gerente) en la semana.</span>
    </div>

    <div class="no-print">
      <a href="reporte_nomina.php?semana=<?= $semana ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Volver al Reporte
      </a>
    </div>
  </div>
</body>

</html>