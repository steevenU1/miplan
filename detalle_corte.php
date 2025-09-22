<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$rol        = $_SESSION['rol'] ?? '';
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

/*
  Permisos:
  - Gerente / Admin => siempre
  - Ejecutivo       => solo si su sucursal NO tiene gerente activo
*/
$hayGerente = true; // por seguridad, asumir que sí hay
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
        $hayGerente = ((int)$cnt > 0);
    }
}

$allow = in_array($rol, ['Gerente','Admin'], true) || ($rol === 'Ejecutivo' && !$hayGerente);
if (!$allow) {
    header("Location: 403.php");
    exit();
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$idCorte = isset($_GET['id_corte']) ? (int)$_GET['id_corte'] : 0;
if ($idCorte <= 0) {
    header("Location: cortes_caja.php");
    exit();
}

$sqlCorte = "
  SELECT cc.*, u.nombre AS usuario, s.nombre AS sucursal
  FROM cortes_caja cc
  INNER JOIN usuarios u   ON u.id = cc.id_usuario
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE cc.id = ?
";
$stmt = $conn->prepare($sqlCorte);
$stmt->bind_param("i", $idCorte);
$stmt->execute();
$corte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$corte) {
    header("Location: cortes_caja.php");
    exit();
}

if ($rol === 'Gerente' && (int)$corte['id_sucursal'] !== $idSucursal) {
    header("Location: cortes_caja.php");
    exit();
}

$sqlCobros = "
  SELECT c.*, u.nombre AS usuario
  FROM cobros c
  INNER JOIN usuarios u ON u.id = c.id_usuario
  WHERE c.id_corte = ?
  ORDER BY c.fecha_cobro ASC
";
$stmt = $conn->prepare($sqlCobros);
$stmt->bind_param("i", $idCorte);
$stmt->execute();
$cobros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sumEfec = 0.0; $sumTjta = 0.0; $sumComEsp = 0.0; $sumTotal = 0.0;
$cntEfec = 0; $cntTjta = 0; $cntMixto = 0;
foreach ($cobros as $c) {
    $ef = (float)$c['monto_efectivo'];
    $tj = (float)$c['monto_tarjeta'];
    $sumEfec  += $ef;
    $sumTjta  += $tj;
    $sumComEsp+= (float)$c['comision_especial'];
    $sumTotal += (float)$c['monto_total'];

    if ($ef > 0 && $tj > 0)      $cntMixto++;
    elseif ($ef > 0)             $cntEfec++;
    elseif ($tj > 0)             $cntTjta++;
}

$difGeneral   = (float)$corte['total_general'] - (float)$corte['monto_depositado'];
$depositado   = (float)$corte['monto_depositado'];
$totalGeneral = (float)$corte['total_general'];
$pctDepos     = $totalGeneral > 0 ? max(0, min(100, round(($depositado / $totalGeneral) * 100))) : 0;
$difClass     = $difGeneral == 0 ? 'text-success' : ($difGeneral < 0 ? 'text-primary' : 'text-danger');

require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Detalle del Corte #<?= (int)$idCorte ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color: var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#eef2ff; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }
    .chip-pending{ background:#eef2ff; color:#3f51b5; border-color:#dfe3ff; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .progress{ height:10px; border-radius:999px; }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="page-header">
    <div>
      <h1 class="page-title">🧾 Detalle del Corte <span class="text-muted">#<?= (int)$idCorte ?></span></h1>
      <div class="small-muted">Sucursal: <strong><?= h($corte['sucursal']) ?></strong> · Usuario: <strong><?= h($corte['usuario']) ?></strong></div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if (($corte['estado'] ?? '') === 'Pendiente'): ?>
        <span class="chip chip-warn"><i class="bi bi-hourglass-split"></i> Pendiente</span>
      <?php else: ?>
        <span class="chip chip-success"><i class="bi bi-check2-circle"></i> Validado</span>
      <?php endif; ?>
      <a class="btn btn-soft" href="cortes_caja.php"><i class="bi bi-arrow-left"></i> Volver</a>
      <button class="btn btn-soft" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat mb-2"><div class="icon"><i class="bi bi-info-circle"></i></div><div><div class="small-muted">Información</div></div></div>
        <div class="row">
          <div class="col-6 small-muted">Fecha operación</div><div class="col-6 text-end"><strong><?= h($corte['fecha_operacion']) ?></strong></div>
          <div class="col-6 small-muted">Fecha corte</div><div class="col-6 text-end"><strong><?= h($corte['fecha_corte']) ?></strong></div>
          <div class="col-6 small-muted">Estado</div>
          <div class="col-6 text-end">
            <?php if (($corte['estado'] ?? '') === 'Pendiente'): ?>
              <span class="chip chip-warn">Pendiente</span>
            <?php else: ?>
              <span class="chip chip-success">Validado</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat mb-2"><div class="icon"><i class="bi bi-calculator"></i></div><div><div class="small-muted">Totales del corte</div></div></div>
        <div class="row">
          <div class="col-6 small-muted">Efectivo</div><div class="col-6 text-end h6 mb-0">$<?= number_format((float)$corte['total_efectivo'],2) ?></div>
          <div class="col-6 small-muted">Tarjeta</div><div class="col-6 text-end h6 mb-0">$<?= number_format((float)$corte['total_tarjeta'],2) ?></div>
          <div class="col-6 small-muted">Comisión esp.</div><div class="col-6 text-end">$<?= number_format((float)$corte['total_comision_especial'],2) ?></div>
          <div class="col-6 small-muted">Total general</div><div class="col-6 text-end h5 mb-0">$<?= number_format((float)$corte['total_general'],2) ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat mb-2"><div class="icon"><i class="bi bi-piggy-bank"></i></div><div><div class="small-muted">Depósito</div></div></div>
        <?php
          $pct = (int)$pctDepos;
        ?>
        <div class="mb-2 small-muted">Progreso del depósito</div>
        <div class="progress mb-2">
          <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between">
          <div class="small-muted">Depositado</div>
          <div><strong>$<?= number_format($depositado,2) ?></strong> de $<?= number_format($totalGeneral,2) ?> (<?= $pct ?>%)</div>
        </div>
        <hr>
        <div class="d-flex justify-content-between">
          <div class="small-muted">¿Depositado?</div><div><strong><?= $corte['depositado'] ? 'Sí' : 'No' ?></strong></div>
        </div>
        <div class="d-flex justify-content-between">
          <div class="small-muted">Diferencia</div><div class="<?= $difClass ?>"><strong>$<?= number_format($difGeneral,2) ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($corte['observaciones'])): ?>
    <div class="card card-surface mt-3">
      <div class="p-3">
        <h6 class="mb-2"><i class="bi bi-chat-left-text me-2"></i>Observaciones</h6>
        <p class="mb-0"><?= nl2br(h($corte['observaciones'])) ?></p>
      </div>
    </div>
  <?php endif; ?>

  <div class="card card-surface mt-4 mb-5">
    <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <h5 class="m-0"><i class="bi bi-receipt me-2"></i>Cobros incluidos en el corte</h5>
        <span class="chip chip-pending"><i class="bi bi-cash-coin"></i> Efectivo: <?= (int)$cntEfec ?></span>
        <span class="chip chip-pending"><i class="bi bi-credit-card"></i> Tarjeta: <?= (int)$cntTjta ?></span>
        <span class="chip chip-pending"><i class="bi bi-intersect"></i> Mixto: <?= (int)$cntMixto ?></span>
      </div>
      <div class="filters d-flex align-items-center gap-2">
        <input id="searchInput" class="form-control" type="search" placeholder="Buscar por usuario, motivo o referencia…">
        <select id="tipoPagoFilter" class="form-select">
          <option value="">Tipo: Todos</option>
          <option value="Efectivo">Efectivo</option>
          <option value="Tarjeta">Tarjeta</option>
          <option value="Mixto">Mixto</option>
        </select>
      </div>
    </div>

    <div class="p-3 pt-2">
      <?php if (empty($cobros)): ?>
        <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay cobros asociados a este corte.</div>
      <?php else: ?>
        <div class="tbl-wrap">
          <table id="tablaCobros" class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="min-width:140px;">Fecha</th>
                <th style="min-width:140px;">Usuario</th>
                <th>Motivo</th>
                <th>Tipo Pago</th>
                <th>Total</th>
                <th>Efectivo</th>
                <th>Tarjeta</th>
                <th>Comisión Esp.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cobros as $p):
                $ef = (float)$p['monto_efectivo'];
                $tj = (float)$p['monto_tarjeta'];
                $tipo = ($ef > 0 && $tj > 0) ? 'Mixto' : (($ef > 0) ? 'Efectivo' : 'Tarjeta');
              ?>
                <tr data-tipo="<?= h($tipo) ?>">
                  <td><?= h($p['fecha_cobro']) ?></td>
                  <td><?= h($p['usuario']) ?></td>
                  <td><?= h($p['motivo']) ?></td>
                  <td>
                    <?php if ($tipo === 'Mixto'): ?>
                      <span class="chip chip-pending"><i class="bi bi-intersect"></i> Mixto</span>
                    <?php elseif ($tipo === 'Efectivo'): ?>
                      <span class="chip chip-pending"><i class="bi bi-cash-coin"></i> Efectivo</span>
                    <?php else: ?>
                      <span class="chip chip-pending"><i class="bi bi-credit-card"></i> Tarjeta</span>
                    <?php endif; ?>
                  </td>
                  <td class="fw-semibold">$<?= number_format((float)$p['monto_total'],2) ?></td>
                  <td>$<?= number_format($ef,2) ?></td>
                  <td>$<?= number_format($tj,2) ?></td>
                  <td>$<?= number_format((float)$p['comision_especial'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="table-secondary">
                <th colspan="4" class="text-end">Totales</th>
                <th>$<?= number_format($sumTotal,2) ?></th>
                <th>$<?= number_format($sumEfec,2) ?></th>
                <th>$<?= number_format($sumTjta,2) ?></th>
                <th>$<?= number_format($sumComEsp,2) ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(() => {
  const searchInput = document.getElementById('searchInput');
  const tipoFilter  = document.getElementById('tipoPagoFilter');
  const rows        = Array.from(document.querySelectorAll('#tablaCobros tbody tr'));

  function normaliza(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,''); }

  function filtrar(){
    const q = normaliza(searchInput.value);
    const t = (tipoFilter.value || '');
    rows.forEach(tr => {
      const text = normaliza(tr.innerText);
      const tipo = tr.getAttribute('data-tipo') || '';
      const matchQ = !q || text.includes(q);
      const matchT = !t || tipo === t;
      tr.style.display = (matchQ && matchT) ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', filtrar);
  tipoFilter?.addEventListener('change', filtrar);
})();
</script>
</body>
</html>
