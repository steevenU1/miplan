<?php
// compras_resumen.php — Central 2.0 (UI Pro)
// Resumen de facturas de compra: filtros + KPIs + aging + alertas + acciones

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

// ====== Helpers ======
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function cap($s,$n){ return substr(trim($s ?? ''),0,$n); }
function n2($v){ return number_format((float)$v, 2); }

$hoy = date('Y-m-d');

// ====== Filtros ======
$estado   = cap($_GET['estado'] ?? 'todos', 20);           // todos|Pendiente|Parcial|Pagada|Cancelada
$prov_id  = (int)($_GET['proveedor'] ?? 0);
$suc_id   = (int)($_GET['sucursal'] ?? 0);
$desde    = cap($_GET['desde'] ?? '', 10);                 // YYYY-MM-DD
$hasta    = cap($_GET['hasta'] ?? '', 10);                 // YYYY-MM-DD
$q        = cap($_GET['q'] ?? '', 60);                     // búsqueda por # factura
$pxdias   = (int)($_GET['px'] ?? 7);                       // Próximos X días (default 7)
if ($pxdias < 0) $pxdias = 0;

$where = [];
$params = [];
$types = '';

if ($estado !== 'todos') { $where[] = "c.estatus = ?"; $params[] = $estado; $types.='s'; }
if ($prov_id > 0)        { $where[] = "c.id_proveedor = ?"; $params[] = $prov_id; $types.='i'; }
if ($suc_id > 0)         { $where[] = "c.id_sucursal = ?";  $params[] = $suc_id;  $types.='i'; }
if ($desde !== '')       { $where[] = "c.fecha_factura >= ?"; $params[] = $desde; $types.='s'; }
if ($hasta !== '')       { $where[] = "c.fecha_factura <= ?"; $params[] = $hasta; $types.='s'; }
if ($q !== '')           { $where[] = "c.num_factura LIKE ?"; $params[] = "%$q%"; $types.='s'; }

$sqlWhere = count($where) ? ('WHERE '.implode(' AND ', $where)) : '';

// Catálogos para filtros
$proveedores = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$sucursales  = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// ====== Consulta principal ======
$sql = "
  SELECT
    c.id,
    c.num_factura,
    c.fecha_factura,
    c.fecha_vencimiento,
    c.subtotal,
    c.iva,
    c.total,
    c.estatus,
    c.id_proveedor,
    p.nombre AS proveedor,
    s.nombre AS sucursal,
    IFNULL(pg.pagado, 0) AS pagado,
    (c.total - IFNULL(pg.pagado, 0)) AS saldo,
    IFNULL(ing.pendientes_ingreso, 0) AS pendientes_ingreso,
    ing.primer_detalle_pendiente
  FROM compras c
  INNER JOIN proveedores p ON p.id = c.id_proveedor
  INNER JOIN sucursales  s ON s.id = c.id_sucursal
  LEFT JOIN (
    SELECT id_compra, SUM(monto) AS pagado
    FROM compras_pagos
    GROUP BY id_compra
  ) pg ON pg.id_compra = c.id
  LEFT JOIN (
    SELECT
      d.id_compra,
      SUM( GREATEST(d.cantidad - IFNULL(x.ing,0), 0) ) AS pendientes_ingreso,
      MIN( CASE WHEN GREATEST(d.cantidad - IFNULL(x.ing,0), 0) > 0 THEN d.id END ) AS primer_detalle_pendiente
    FROM compras_detalle d
    LEFT JOIN (
      SELECT id_detalle, COUNT(*) AS ing
      FROM compras_detalle_ingresos
      GROUP BY id_detalle
    ) x ON x.id_detalle = d.id
    GROUP BY d.id_compra
  ) ing ON ing.id_compra = c.id
  $sqlWhere
  ORDER BY c.fecha_factura DESC, c.id DESC
";
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error en prepare: ".$conn->error); }
if (strlen($types) > 0) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

// Cargar filas en memoria
$rows = [];
while($row = $res->fetch_assoc()){
  $row['total']  = (float)$row['total'];
  $row['pagado'] = (float)$row['pagado'];
  $row['saldo']  = (float)$row['saldo'];
  $rows[] = $row;
}

// ====== KPIs y métricas ======
$totalCompras = 0.0;
$totalPagado  = 0.0;
$totalSaldo   = 0.0;
$saldoVencido = 0.0;
$saldoPorVencer = 0.0;

// Aging buckets
$aging = [
  'current' => 0.0,
  'd1_30'   => 0.0,
  'd31_60'  => 0.0,
  'd61_90'  => 0.0,
  'd90p'    => 0.0,
];

$vencidas = [];
$porVencer = [];

foreach ($rows as $r) {
  $totalCompras += $r['total'];
  $totalPagado  += $r['pagado'];
  $totalSaldo   += max(0, $r['saldo']);

  $vence = $r['fecha_vencimiento'] ?: null;
  $saldo = max(0, $r['saldo']);
  $pagada = ($r['estatus'] === 'Pagada');

  if ($saldo <= 0 || $pagada) continue;

  if ($vence) {
    $diffDays = (int)floor((strtotime($vence) - strtotime($hoy)) / 86400);
    if ($diffDays < 0) {
      $saldoVencido += $saldo;
      $daysOver = abs($diffDays);
      if     ($daysOver <= 30) $aging['d1_30']  += $saldo;
      elseif ($daysOver <= 60) $aging['d31_60'] += $saldo;
      elseif ($daysOver <= 90) $aging['d61_90'] += $saldo;
      else                     $aging['d90p']   += $saldo;

      $vencidas[] = $r + ['dias' => -$diffDays];
    } else {
      if ($diffDays <= $pxdias) {
        $saldoPorVencer += $saldo;
        $porVencer[] = $r + ['dias' => $diffDays];
      }
      $aging['current'] += $saldo;
    }
  } else {
    $aging['current'] += $saldo;
  }
}

usort($vencidas, fn($a,$b)=> $b['dias'] <=> $a['dias']);
usort($porVencer, fn($a,$b)=> $a['dias'] <=> $b['dias']);

$saldoPorProveedor = [];
foreach ($rows as $r) {
  $saldo = max(0, (float)$r['saldo']);
  if ($r['estatus'] === 'Pagada' || $saldo <= 0) continue;
  $pid = (int)$r['id_proveedor'];
  $saldoPorProveedor[$pid]['proveedor'] = $r['proveedor'];
  $saldoPorProveedor[$pid]['saldo'] = ($saldoPorProveedor[$pid]['saldo'] ?? 0) + $saldo;
}
usort($saldoPorProveedor, function($a,$b){ return ($b['saldo'] ?? 0) <=> ($a['saldo'] ?? 0); });
$topProv = array_slice($saldoPorProveedor, 0, 5, true);

// Para Chart.js
$agingData = [
  (float)$aging['current'],
  (float)$aging['d1_30'],
  (float)$aging['d31_60'],
  (float)$aging['d61_90'],
  (float)$aging['d90p'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Resumen · Compras — Central 2.0</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <style>
    body{ background:#f6f7fb; }
    .page-head{ display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .role-chip{ font-size:.8rem; padding:.2rem .55rem; border-radius:999px; background:#eef2ff; color:#3743a5; border:1px solid #d9e0ff; }
    .toolbar{ display:flex; gap:8px; align-items:center; }
    .card-soft{ border:1px solid #e9ecf1; border-radius:16px; box-shadow:0 2px 12px rgba(16,24,40,.06); }
    .kpi{ border:1px solid #e9ecf1; border-radius:16px; background:#fff; box-shadow:0 2px 8px rgba(16,24,40,.06); padding:16px; }
    .kpi h6{ margin:0; font-size:.9rem; color:#6b7280; } .kpi .metric{ font-weight:800; font-size:1.4rem; margin-top:4px; }
    .chip{ display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:.8rem; border:1px solid #e2e8f0; }
    .status-dot{ width:8px; height:8px; border-radius:50%; display:inline-block; }
    .dot-green{ background:#16a34a; } .dot-amber{ background:#f59e0b; } .dot-red{ background:#dc2626; } .dot-gray{ background:#94a3b8; }
    .table-wrap{ background:#fff; border:1px solid #e9ecf1; border-radius:16px; padding:8px 8px 16px; box-shadow:0 2px 10px rgba(16,24,40,.06); }
    .nowrap{ white-space:nowrap; }
  </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4">

  <!-- Encabezado -->
  <div class="page-head">
    <div>
      <h2 class="page-title">📊 Resumen de compras</h2>
      <div class="mt-1"><span class="role-chip"><?= esc($ROL) ?></span></div>
    </div>
    <div class="toolbar">
      <a href="compras_nueva.php" class="btn btn-primary btn-sm rounded-pill"><i class="bi bi-plus-circle me-1"></i>Nueva compra</a>
      <a href="compras_resumen.php" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar filtros</a>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card card-soft mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><i class="bi bi-sliders me-1"></i>Filtros</span>
      <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosBody">Mostrar/Ocultar</button>
    </div>
    <div id="filtrosBody" class="card-body collapse show">
      <form class="row g-2">
        <div class="col-md-2">
          <label class="form-label">Estatus</label>
          <select name="estado" class="form-select" onchange="this.form.submit()">
            <?php $estados = ['todos'=>'Todos','Pendiente'=>'Pendiente','Parcial'=>'Parcial','Pagada'=>'Pagada','Cancelada'=>'Cancelada'];
              foreach ($estados as $val=>$txt): ?>
              <option value="<?= $val ?>" <?= $estado===$val?'selected':'' ?>><?= $txt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor</label>
          <select name="proveedor" class="form-select">
            <option value="0">Todos</option>
            <?php if($proveedores) while($p=$proveedores->fetch_assoc()): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $prov_id===(int)$p['id']?'selected':'' ?>><?= esc($p['nombre']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">Todas</option>
            <?php if($sucursales) while($s=$sucursales->fetch_assoc()): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $suc_id===(int)$s['id']?'selected':'' ?>><?= esc($s['nombre']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" value="<?= esc($desde) ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" value="<?= esc($hasta) ?>" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label"># Factura</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Buscar por número">
        </div>
        <div class="col-md-2">
          <label class="form-label">Próximos (días)</label>
          <input type="number" name="px" min="0" step="1" value="<?= (int)$pxdias ?>" class="form-control">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-primary w-100">Aplicar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="kpi"><h6>Total compras</h6><div class="metric">$<?= n2($totalCompras) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><h6>Pagado</h6><div class="metric text-success">$<?= n2($totalPagado) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><h6>Saldo</h6><div class="metric text-primary">$<?= n2($totalSaldo) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><h6>Vencido</h6><div class="metric text-danger">$<?= n2($saldoVencido) ?></div></div></div>
  </div>

  <!-- Aging + Por vencer + Top proveedores -->
  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card card-soft h-100">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Aging de saldos</span>
          <span class="chip"><span class="status-dot dot-gray"></span>Solo con saldo &gt; 0</span>
        </div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-6">
              <canvas id="chartAging" height="190"></canvas>
            </div>
            <div class="col-6">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                  <thead><tr><th>Rango</th><th class="text-end">Saldo</th></tr></thead>
                  <tbody>
                    <tr><td>Current</td><td class="text-end">$<?= n2($aging['current']) ?></td></tr>
                    <tr><td>1–30</td><td class="text-end">$<?= n2($aging['d1_30']) ?></td></tr>
                    <tr><td>31–60</td><td class="text-end">$<?= n2($aging['d31_60']) ?></td></tr>
                    <tr><td>61–90</td><td class="text-end">$<?= n2($aging['d61_90']) ?></td></tr>
                    <tr class="table-danger"><td>&gt;90</td><td class="text-end">$<?= n2($aging['d90p']) ?></td></tr>
                  </tbody>
                  <tfoot><tr class="table-light"><th>Total</th><th class="text-end">$<?= n2(array_sum($aging)) ?></th></tr></tfoot>
                </table>
              </div>
            </div>
          </div>
          <div class="small text-muted">Excluye facturas con estatus “Pagada”.</div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card card-soft h-100">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Próximas a vencer (≤ <?= (int)$pxdias ?> días)</span>
          <span class="fw-semibold text-primary">$<?= n2($saldoPorVencer) ?></span>
        </div>
        <div class="card-body">
          <?php if (count($porVencer)): ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle">
                <thead><tr><th>Proveedor</th><th>Factura</th><th>Vence</th><th class="text-end">Saldo</th><th class="text-center">Días</th><th></th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($porVencer, 0, 8) as $r): ?>
                    <tr>
                      <td><?= esc($r['proveedor']) ?></td>
                      <td><?= esc($r['num_factura']) ?></td>
                      <td><?= esc($r['fecha_vencimiento']) ?></td>
                      <td class="text-end">$<?= n2($r['saldo']) ?></td>
                      <td class="text-center"><?= (int)$r['dias'] ?></td>
                      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="compras_ver.php?id=<?= (int)$r['id'] ?>">Ver</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted">No hay facturas por vencer en este rango.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card card-soft h-100">
        <div class="card-header bg-white fw-semibold">Top proveedores por saldo</div>
        <div class="card-body">
          <?php if (count($topProv)): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($topProv as $tp): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><?= esc($tp['proveedor']) ?></span>
                  <span class="fw-semibold">$<?= n2($tp['saldo']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">Sin saldos pendientes.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla principal -->
  <div class="table-wrap">
    <div class="d-flex justify-content-between align-items-center p-2">
      <h6 class="m-0">Facturas</h6>
      <div class="d-flex gap-2">
        <button id="btnExportExcel" class="btn btn-success btn-sm rounded-pill"><i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel</button>
        <button id="btnExportCSV" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
        <button id="btnColVis" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-view-list me-1"></i>Columnas</button>
      </div>
    </div>
    <div class="px-2 pb-2">
      <div class="table-responsive">
        <table id="tablaCompras" class="table table-hover align-middle nowrap" style="width:100%;">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Proveedor</th>
              <th>Factura</th>
              <th>Sucursal</th>
              <th>Fecha</th>
              <th>Vence</th>
              <th class="text-end">Total</th>
              <th class="text-end">Pagado</th>
              <th class="text-end">Saldo</th>
              <th class="text-center">Pend. ingreso</th>
              <th class="text-center">Estatus</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i=1;
          foreach ($rows as $r):
            $saldo = (float)$r['saldo'];
            $vence = $r['fecha_vencimiento'];
            $rowClass = '';
            if ($r['estatus'] !== 'Pagada' && $saldo > 0 && $vence) {
              if ($vence < $hoy) { $rowClass = 'table-danger'; }
              else if ($vence <= date('Y-m-d', strtotime("+$pxdias days"))) { $rowClass = 'table-warning'; }
            }
          ?>
            <tr class="<?= $rowClass ?>">
              <td><?= $i++ ?></td>
              <td><?= esc($r['proveedor']) ?></td>
              <td><?= esc($r['num_factura']) ?></td>
              <td><?= esc($r['sucursal']) ?></td>
              <td><?= esc($r['fecha_factura']) ?></td>
              <td><?= esc($vence ?: '-') ?></td>
              <td class="text-end">$<?= n2($r['total']) ?></td>
              <td class="text-end">$<?= n2($r['pagado']) ?></td>
              <td class="text-end fw-semibold">$<?= n2($saldo) ?></td>
              <td class="text-center">
                <?php if ((int)$r['pendientes_ingreso'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?= (int)$r['pendientes_ingreso'] ?></span>
                <?php else: ?>
                  <span class="badge bg-success">0</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php
                  $badge = 'secondary';
                  if ($r['estatus']==='Pagada') $badge='success';
                  elseif ($r['estatus']==='Parcial') $badge='warning text-dark';
                  elseif ($r['estatus']==='Pendiente') $badge='danger';
                ?>
                <span class="badge bg-<?= $badge ?>"><?= esc($r['estatus']) ?></span>
              </td>
              <td class="text-end">
                <div class="btn-group">
                  <a class="btn btn-sm btn-outline-secondary" href="compras_ver.php?id=<?= (int)$r['id'] ?>">Ver</a>
                  <a class="btn btn-sm btn-success" href="compras_pagos.php?id=<?= (int)$r['id'] ?>">Abonar</a>
                  <?php if ((int)$r['pendientes_ingreso'] > 0 && (int)$r['primer_detalle_pendiente'] > 0): ?>
                    <a class="btn btn-sm btn-primary"
                       href="compras_ingreso.php?detalle=<?= (int)$r['primer_detalle_pendiente'] ?>&compra=<?= (int)$r['id'] ?>">
                       Ingresar
                    </a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled>Ingresar</button>
                  <?php endif; ?>
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

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- DataTables core + addons -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
  // Forzar título de pestaña
  try { document.title = 'Resumen · Compras — Central 2.0'; } catch(e){}

  // DataTable
  let dt = null;
  $(function(){
    dt = $('#tablaCompras').DataTable({
      pageLength: 25,
      order: [[ 4, 'desc' ]], // por fecha factura
      fixedHeader: true,
      responsive: true,
      language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
      dom: "<'row align-items-center mb-2'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" +
           "tr" +
           "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: [
        { extend: 'csvHtml5',   className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-filetype-csv me-1"></i>CSV' },
        { extend: 'excelHtml5', className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-file-earmark-excel me-1"></i>Excel' },
        { extend: 'colvis',     className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-view-list me-1"></i>Columnas' }
      ],
      columnDefs: [
        { targets: [0,4,5,6,7,8,11], className: 'nowrap' },
        { targets: [6,7,8], render: $.fn.dataTable.render.number('.', ',', 2, '$') }
      ]
    });

    // Botones externos (toolbar)
    $('#btnExportExcel').on('click', ()=> dt.button('.buttons-excel').trigger());
    $('#btnExportCSV').on('click',   ()=> dt.button('.buttons-csv').trigger());
    $('#btnColVis').on('click',      ()=> dt.button('.buttons-colvis').trigger());
  });

  // Chart.js - Aging
  const agingData = <?= json_encode(array_values($agingData)) ?>;
  const ctx = document.getElementById('chartAging');
  if (ctx && agingData.reduce((a,b)=>a+b,0) > 0) {
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Current','1–30','31–60','61–90','>90'],
        datasets: [{ data: agingData }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: (ctx)=> {
            const v = ctx.parsed || 0;
            return `${ctx.label}: $${v.toLocaleString('es-MX',{minimumFractionDigits:2})}`;
          } } }
        },
        cutout: '55%'
      }
    });
  }
</script>
</body>
</html>
