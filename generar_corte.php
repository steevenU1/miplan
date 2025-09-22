<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once 'db.php';

$rol        = $_SESSION['rol'] ?? '';
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// ¿La sucursal del usuario NO tiene gerente activo?
$sucursalSinGerente = false;
if ($idSucursal > 0) {
  if ($st = $conn->prepare("
        SELECT COUNT(*)
        FROM usuarios
        WHERE id_sucursal = ?
          AND rol IN ('Gerente','GerenteSucursal')
          AND activo = 1
      ")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($cnt);
    $st->fetch();
    $st->close();
    $sucursalSinGerente = ((int)$cnt === 0);
  }
}

// ¿Tiene permiso para ver la vista?
$allow =
  in_array($rol, ['Admin','Super','Gerente','GerenteSucursal'], true) ||
  ($rol === 'Ejecutivo' && $sucursalSinGerente);

if (!$allow) {
  header("Location: 403.php");
  exit();
}

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$fechaHoy    = date('Y-m-d');
$msg = "";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== 1) Días con cobros pendientes ===== */
$sqlDiasPendientes = "
  SELECT DATE(fecha_cobro) AS fecha, COUNT(*) AS total
  FROM cobros
  WHERE id_sucursal = ? AND id_corte IS NULL AND corte_generado = 0
  GROUP BY DATE(fecha_cobro)
  ORDER BY fecha ASC
";
$stmt = $conn->prepare($sqlDiasPendientes);
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
$diasPendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pendientes = [];
foreach ($diasPendientes as $d) $pendientes[$d['fecha']] = (int)$d['total'];
$fechaDefault = !empty($pendientes) ? array_key_first($pendientes) : $fechaHoy;

/* ===== 2) Fecha seleccionada (GET) ===== */
$fechaSeleccionada = $_GET['fecha_operacion'] ?? $fechaDefault;

/* ===== 3) GENERAR CORTE (POST) — ANTES de imprimir navbar o HTML ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fecha_operacion'])) {
    $fecha_operacion = $_POST['fecha_operacion'] ?: $fechaSeleccionada;

    $sqlCobros = "
      SELECT *
      FROM cobros
      WHERE id_sucursal = ?
        AND DATE(fecha_cobro) = ?
        AND id_corte IS NULL
        AND corte_generado = 0
      FOR UPDATE
    ";

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($sqlCobros);
        $stmt->bind_param("is", $id_sucursal, $fecha_operacion);
        $stmt->execute();
        $cobros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($cobros) === 0) {
            $conn->rollback();
            $_SESSION['flash_msg'] = "<div class='alert alert-info'>
              ⚠ No hay cobros pendientes para generar corte en <b>".h($fecha_operacion)."</b>.
            </div>";
            header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
            exit();
        }

        $total_efectivo = 0.0;
        $total_tarjeta  = 0.0;
        $total_comision_especial = 0.0;
        foreach ($cobros as $c) {
            $total_efectivo          += (float)$c['monto_efectivo'];
            $total_tarjeta           += (float)$c['monto_tarjeta'];
            $total_comision_especial += (float)$c['comision_especial'];
        }
        $total_general = $total_efectivo + $total_tarjeta;

        $stmtCorte = $conn->prepare("
          INSERT INTO cortes_caja
          (id_sucursal, id_usuario, fecha_operacion, fecha_corte, estado,
           total_efectivo, total_tarjeta, total_comision_especial, total_general,
           depositado, monto_depositado, observaciones)
          VALUES (?, ?, ?, NOW(), 'Pendiente', ?, ?, ?, ?, 0, 0, '')
        ");
        $stmtCorte->bind_param("iisdddd",
            $id_sucursal, $id_usuario, $fecha_operacion,
            $total_efectivo, $total_tarjeta, $total_comision_especial, $total_general
        );
        if (!$stmtCorte->execute()) throw new Exception("Error al insertar corte: ".$stmtCorte->error);
        $id_corte = $stmtCorte->insert_id;
        $stmtCorte->close();

        $stmtUpd = $conn->prepare("
          UPDATE cobros
          SET id_corte = ?, corte_generado = 1
          WHERE id_sucursal = ? AND DATE(fecha_cobro) = ? AND id_corte IS NULL AND corte_generado = 0
        ");
        $stmtUpd->bind_param("iis", $id_corte, $id_sucursal, $fecha_operacion);
        if (!$stmtUpd->execute()) throw new Exception("Error al actualizar cobros: ".$stmtUpd->error);
        $stmtUpd->close();

        $conn->commit();

        $_SESSION['flash_msg'] = "<div class='alert alert-success'>
          ✅ Corte generado (ID: {$id_corte}) para <b>".h($fecha_operacion)."</b>.
        </div>";
        header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>";
        header("Location: generar_corte.php?fecha_operacion=".urlencode($fecha_operacion));
        exit();
    }
}

/* ===== 4) A partir de aquí ya podemos imprimir HTML / incluir navbar ===== */
require_once __DIR__ . '/navbar.php';

/* Cobros pendientes de la fecha seleccionada */
$sqlCobrosPend = "
  SELECT c.*, u.nombre AS usuario
  FROM cobros c
  INNER JOIN usuarios u ON u.id = c.id_usuario
  WHERE c.id_sucursal = ?
    AND DATE(c.fecha_cobro) = ?
    AND c.id_corte IS NULL
    AND c.corte_generado = 0
  ORDER BY c.fecha_cobro ASC
";
$stmt = $conn->prepare($sqlCobrosPend);
$stmt->bind_param("is", $id_sucursal, $fechaSeleccionada);
$stmt->execute();
$cobrosFecha = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hayCobros   = count($cobrosFecha) > 0;
$btnDisabled = $hayCobros ? '' : 'disabled';

/* Totales del día (para tarjetas y modal) */
$totalEf = 0.0; $totalTar = 0.0; $totalTot = 0.0; $totalComEsp = 0.0;
foreach ($cobrosFecha as $r) {
  $totalEf     += (float)$r['monto_efectivo'];
  $totalTar    += (float)$r['monto_tarjeta'];
  $totalTot    += (float)$r['monto_total'];
  $totalComEsp += (float)$r['comision_especial'];
}
/* A depositar (LUGA: depósito = efectivo) */
$aDepositar = $totalEf;

/* Resumen por rubro (con separación efectivo/tarjeta) */
$resumenRubros = [];
$sumEfCnt=0; $sumEfTot=0.0; $sumTarCnt=0; $sumTarTot=0.0;
foreach ($cobrosFecha as $r) {
  $motivo = trim($r['motivo'] ?? '') ?: 'Sin motivo';
  if (!isset($resumenRubros[$motivo])) {
    $resumenRubros[$motivo] = ['cnt'=>0,'tot'=>0.0,'ef_cnt'=>0,'ef_tot'=>0.0,'tar_cnt'=>0,'tar_tot'=>0.0];
  }
  $resumenRubros[$motivo]['cnt']++;
  $resumenRubros[$motivo]['tot'] += (float)$r['monto_total'];

  if ((float)$r['monto_efectivo'] > 0) {
    $resumenRubros[$motivo]['ef_cnt']++;
    $resumenRubros[$motivo]['ef_tot'] += (float)$r['monto_efectivo'];
    $sumEfCnt++; $sumEfTot += (float)$r['monto_efectivo'];
  }
  if ((float)$r['monto_tarjeta'] > 0) {
    $resumenRubros[$motivo]['tar_cnt']++;
    $resumenRubros[$motivo]['tar_tot'] += (float)$r['monto_tarjeta'];
    $sumTarCnt++; $sumTarTot += (float)$r['monto_tarjeta'];
  }
}
uasort($resumenRubros, function($a,$b){ return $b['tot'] <=> $a['tot']; });

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$sqlHistCortes = "
  SELECT cc.*, u.nombre AS usuario
  FROM cortes_caja cc
  INNER JOIN usuarios u ON u.id = cc.id_usuario
  WHERE cc.id_sucursal = ?
    AND DATE(cc.fecha_corte) BETWEEN ? AND ?
  ORDER BY cc.fecha_corte DESC
";
$stmtHistCortes = $conn->prepare($sqlHistCortes);
$stmtHistCortes->bind_param("iss", $id_sucursal, $desde, $hasta);
$stmtHistCortes->execute();
$histCortes = $stmtHistCortes->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHistCortes->close();

/* Mensaje flash */
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Corte de Caja</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .page-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .controls-compact { display:flex; align-items:end; gap:.5rem; }
    .controls-compact .form-control { min-width: 210px; }
    .pill-days .btn { margin:.25rem .25rem; }
    .card-metric { border:0; box-shadow:0 8px 24px rgba(0,0,0,.06); border-radius:1rem; }
    .card-metric .card-body { padding:1rem 1.1rem; }
    .metric-value { font-size: clamp(1.25rem,1.5rem,1.8rem); font-weight:800; }
    .metric-label { color:#6b7280; font-size:.9rem; letter-spacing:.2px; }
    .muted-sm { font-size:.85rem; color:#6b7280; }
    .shadow-soft { box-shadow:0 8px 24px rgba(0,0,0,.06); }
    .badge-soft { background:#f1f5f9; color:#0f172a; }
    .table thead th { white-space:nowrap; }
    .table td { vertical-align:middle; }
    #confirmModal .table th, #confirmModal .table td { padding:.35rem .5rem; font-size:.9rem; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">

  <!-- Encabezado + controles compactos a la derecha -->
  <div class="page-head mb-3">
    <h2 class="m-0">🧾 Generar Corte de Caja</h2>

    <form id="formCorte" class="controls-compact" method="POST" action="generar_corte.php">
      <div class="w-auto">
        <label class="form-label mb-1 small">Fecha de operación</label>
        <input id="inpFecha" type="date" name="fecha_operacion"
               class="form-control form-control-sm"
               value="<?= h($fechaSeleccionada) ?>" max="<?= h($fechaHoy) ?>" required>
      </div>
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              formmethod="get" title="Ver cobros">
        <i class="bi bi-eye"></i> Ver
      </button>
      <button type="button" class="btn btn-primary btn-sm"
              <?= $btnDisabled ?>
              title="<?= $hayCobros ? 'Generar corte' : 'No hay cobros en esta fecha' ?>"
              data-bs-toggle="modal" data-bs-target="#confirmModal">
        <i class="bi bi-clipboard-check"></i> Generar
      </button>
    </form>
  </div>

  <?= $msg ?>

  <!-- Días pendientes -->
  <div class="mb-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <h6 class="m-0">Días pendientes de corte</h6>
      <?php if (!empty($pendientes)): ?>
        <span class="badge rounded-pill text-bg-warning"><?= count($pendientes) ?> día(s)</span>
      <?php endif; ?>
    </div>
    <?php if (empty($pendientes)): ?>
      <div class="alert alert-info shadow-soft m-0">No hay días pendientes.</div>
    <?php else: ?>
      <div class="pill-days">
        <?php foreach ($pendientes as $f => $total): ?>
          <a class="btn btn-sm <?= $f===$fechaSeleccionada ? 'btn-primary' : 'btn-outline-primary' ?>"
             href="?fecha_operacion=<?= h($f) ?>">
            <?= h($f) ?> <span class="badge text-bg-light ms-1"><?= (int)$total ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tarjetas de totales -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
      <div class="card card-metric">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <div class="metric-label">Efectivo cobrado</div>
            <div class="metric-value">$<?= number_format($totalEf, 2) ?></div>
            <div class="muted-sm">Fecha: <?= h($fechaSeleccionada) ?></div>
          </div>
          <i class="bi bi-cash-coin fs-2 text-success"></i>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card card-metric">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <div class="metric-label">Tarjeta cobrada</div>
            <div class="metric-value">$<?= number_format($totalTar, 2) ?></div>
            <div class="muted-sm">TPV / banco</div>
          </div>
          <i class="bi bi-credit-card-2-front fs-2 text-primary"></i>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card card-metric">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <div class="metric-label">A depositar (efectivo)</div>
            <div class="metric-value">$<?= number_format($aDepositar, 2) ?></div>
            <div class="muted-sm">Día siguiente</div>
          </div>
          <i class="bi bi-bank fs-2"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla Cobros -->
  <div class="card shadow-soft mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="m-0">Cobros pendientes para: <?= h($fechaSeleccionada) ?></h5>
      <?php if ($hayCobros): ?>
        <span class="badge badge-soft rounded-pill"><?= count($cobrosFecha) ?> registro(s)</span>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <?php if (!$hayCobros): ?>
        <div class="p-3">
          <div class="alert alert-info m-0">No hay cobros pendientes para la fecha <?= h($fechaSeleccionada) ?>.</div>
        </div>
      <?php else: ?>
        <table class="table table-hover table-sm align-middle">
          <thead class="table-dark">
            <tr>
              <th>Fecha</th><th>Usuario</th><th>Motivo</th><th>Tipo Pago</th>
              <th class="text-end">Total</th><th class="text-end">Efectivo</th>
              <th class="text-end">Tarjeta</th><th class="text-end">Comisión Esp.</th><th class="text-center">Eliminar</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cobrosFecha as $p): ?>
              <tr>
                <td><?= h($p['fecha_cobro']) ?></td>
                <td><?= h($p['usuario']) ?></td>
                <td><?= h($p['motivo']) ?></td>
                <td><span class="badge text-bg-secondary"><?= h($p['tipo_pago']) ?></span></td>
                <td class="text-end">$<?= number_format((float)$p['monto_total'], 2) ?></td>
                <td class="text-end">$<?= number_format((float)$p['monto_efectivo'], 2) ?></td>
                <td class="text-end">$<?= number_format((float)$p['monto_tarjeta'], 2) ?></td>
                <td class="text-end">$<?= number_format((float)$p['comision_especial'], 2) ?></td>
                <td class="text-center">
                  <a href="eliminar_cobro.php?id=<?= (int)$p['id'] ?>"
                     class="btn btn-outline-danger btn-sm"
                     onclick="return confirm('¿Seguro de eliminar este cobro?');">🗑</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light fw-semibold">
              <td colspan="4" class="text-end">Totales del día:</td>
              <td class="text-end">$<?= number_format($totalTot, 2) ?></td>
              <td class="text-end">$<?= number_format($totalEf, 2) ?></td>
              <td class="text-end">$<?= number_format($totalTar, 2) ?></td>
              <td class="text-end">$<?= number_format($totalComEsp, 2) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Historial de Cortes -->
  <div class="card shadow-soft">
    <div class="card-header">
      <h3 class="m-0">📜 Historial de Cortes</h3>
    </div>
    <div class="card-body">
      <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <input type="hidden" name="fecha_operacion" value="<?= h($fechaSeleccionada) ?>">
          <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filtrar</button>
        </div>
      </form>

      <?php if (empty($histCortes)): ?>
        <div class="alert alert-info">No hay cortes en el rango seleccionado.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-sm">
            <thead class="table-dark">
              <tr>
                <th>ID Corte</th><th>Fecha Corte</th><th>Usuario</th>
                <th class="text-end">Efectivo</th><th class="text-end">Tarjeta</th>
                <th class="text-end">Total</th><th>Estado</th><th class="text-end">Monto Depositado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($histCortes as $c): ?>
                <tr class="<?= $c['estado']==='Cerrado' ? 'table-success' : 'table-warning' ?>">
                  <td><?= (int)$c['id'] ?></td>
                  <td><?= h($c['fecha_corte']) ?></td>
                  <td><?= h($c['usuario']) ?></td>
                  <td class="text-end">$<?= number_format((float)$c['total_efectivo'], 2) ?></td>
                  <td class="text-end">$<?= number_format((float)$c['total_tarjeta'], 2) ?></td>
                  <td class="text-end">$<?= number_format((float)$c['total_general'], 2) ?></td>
                  <td><?= h($c['estado']) ?></td>
                  <td class="text-end">$<?= number_format((float)$c['monto_depositado'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Modal de confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title"><i class="bi bi-shield-check me-1"></i> Confirmar generación de corte</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border mb-3">
          <div class="d-flex justify-content-between"><span>Fecha de operación:</span><strong id="m-fecha"><?= h($fechaSeleccionada) ?></strong></div>
          <div class="d-flex justify-content-between"><span>Registros de cobro:</span><strong id="m-count"><?= (int)count($cobrosFecha) ?></strong></div>
          <hr class="my-2">
          <div class="d-flex justify-content-between"><span>Efectivo:</span><strong>$<?= number_format($totalEf, 2) ?></strong></div>
          <div class="d-flex justify-content-between"><span>Tarjeta:</span><strong>$<?= number_format($totalTar, 2) ?></strong></div>
          <div class="d-flex justify-content-between"><span class="fw-semibold">A depositar (efectivo):</span><strong class="fw-semibold">$<?= number_format($aDepositar, 2) ?></strong></div>
        </div>

        <?php if (!empty($resumenRubros)): ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>Rubro</th>
                  <th class="text-end">Efectivo ($)</th>
                  <th class="text-end"># Efec.</th>
                  <th class="text-end">Tarjeta ($)</th>
                  <th class="text-end"># Tarj.</th>
                  <th class="text-end">Total ($)</th>
                  <th class="text-end"># Cobros</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($resumenRubros as $rubro => $dat): ?>
                  <tr>
                    <td><?= h($rubro) ?></td>
                    <td class="text-end">$<?= number_format($dat['ef_tot'], 2) ?></td>
                    <td class="text-end"><?= (int)$dat['ef_cnt'] ?></td>
                    <td class="text-end">$<?= number_format($dat['tar_tot'], 2) ?></td>
                    <td class="text-end"><?= (int)$dat['tar_cnt'] ?></td>
                    <td class="text-end">$<?= number_format($dat['tot'], 2) ?></td>
                    <td class="text-end"><?= (int)$dat['cnt'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="table-light fw-semibold">
                  <td class="text-end">Totales:</td>
                  <td class="text-end">$<?= number_format($sumEfTot, 2) ?></td>
                  <td class="text-end"><?= (int)$sumEfCnt ?></td>
                  <td class="text-end">$<?= number_format($sumTarTot, 2) ?></td>
                  <td class="text-end"><?= (int)$sumTarCnt ?></td>
                  <td class="text-end">$<?= number_format($totalTot, 2) ?></td>
                  <td class="text-end"><?= (int)count($cobrosFecha) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>

        <!-- Indicador informativo: Comisiones especiales del día -->
        <div class="mt-2 d-flex justify-content-between align-items-center">
          <span class="text-muted">Comisiones especiales del día</span>
          <strong>$<?= number_format($totalComEsp, 2) ?></strong>
        </div>

        <p class="text-muted mb-0 mt-2"><i class="bi bi-info-circle"></i> El resumen corresponde a los cobros listados en pantalla para la fecha cargada.</p>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnConfirmarEnvio">
          <i class="bi bi-rocket-takeoff"></i> Confirmar y generar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Actualiza la fecha mostrada en el modal si cambian el input
  document.getElementById('inpFecha')?.addEventListener('change', function(){
    const x = document.getElementById('m-fecha');
    if (x) x.textContent = this.value || x.textContent;
  });

  // Enviar el formulario tras confirmar en el modal
  document.getElementById('btnConfirmarEnvio')?.addEventListener('click', function(){
    const f = document.getElementById('formCorte');
    if (!f) return;
    f.removeAttribute('formmethod'); // aseguramos POST
    f.method = 'POST';
    f.submit();
  });
</script>
</body>
</html>
