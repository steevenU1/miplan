<?php
// inventario_estadisticas.php — Inteligencia de Inventario por CÓDIGO DE PRODUCTO
// Agrupa por productos.codigo_producto (no por IMEI ni id_producto).
// Calcula WOS = Stock / (Ventas_promedio_semanal). Ventas de últimas N semanas (default 8).

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

function esc($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function n0($v){ return number_format((float)$v, 0); }
function n2($v){ return number_format((float)$v, 2); }
function fetchAll(mysqli $c, string $sql): array { $o=[]; if($rs=$c->query($sql)){ while($r=$rs->fetch_assoc()) $o[]=$r; $rs->close(); } return $o; }
function hasCol(mysqli $c, string $t, string $col): bool {
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  if($rs=$c->query("SHOW COLUMNS FROM `$t` LIKE '$col'")){ $ok=$rs->num_rows>0; $rs->close(); return $ok; }
  return false;
}

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$esAdmin     = in_array($ROL, ['Admin','Super','GerenteZona','Gerente','Subdistribuidor'], true);

$semanas    = isset($_GET['semanas']) ? max(1, min(52, (int)$_GET['semanas'])) : 8;
$umbralDias = isset($_GET['umbral'])  ? max(15, min(365, (int)$_GET['umbral'])) : 60;
$sucParam   = isset($_GET['sucursal'])? (int)$_GET['sucursal'] : 0; // 0 = todas (solo Admin)

$hoy = new DateTime('today 23:59:59');
$ini = (new DateTime('today 00:00:00'))->modify('-'.($semanas*7-1).' days');
$iniStr = $ini->format('Y-m-d 00:00:00');
$finStr = $hoy->format('Y-m-d 23:59:59');
$numSemanas = max(1, $semanas);

// Alcance sucursal
$soloSucursal = (!$esAdmin) ? $ID_SUCURSAL : ($sucParam ?: 0);

// Sucursales para selector (Admin)
$sucursales = $esAdmin ? fetchAll($conn, "SELECT id, nombre FROM sucursales ORDER BY nombre") : [];

/* ===============================
   STOCK por código (solo 'Disponible')
   - agrupa por p.codigo_producto
   - trae marca y modelo (MIN para evitar ONLY_FULL_GROUP_BY)
================================= */
$whereInv = "i.estatus='Disponible'";
if ($soloSucursal > 0) $whereInv .= " AND i.id_sucursal=".(int)$soloSucursal;

$sqlInv = "
  SELECT
    p.codigo_producto                      AS codigo,
    MIN(p.marca)                            AS marca,
    MIN(p.modelo)                           AS modelo,
    COUNT(i.id)                              AS stock,
    MIN(i.fecha_ingreso)                    AS fecha_mas_vieja
  FROM inventario i
  JOIN productos p ON p.id=i.id_producto
  WHERE $whereInv
  GROUP BY p.codigo_producto
";
$inv = fetchAll($conn, $sqlInv);

/* ===============================
   VENTAS por código (últimas N semanas)
   - detalle_venta -> productos.codigo_producto
   - ventas.fecha_venta, ventas.estatus
================================= */
$ventasTieneEstatus  = hasCol($conn,'ventas','estatus');
$ventasTieneSucursal = hasCol($conn,'ventas','id_sucursal');

$whereVentas  = "v.fecha_venta BETWEEN '$iniStr' AND '$finStr'";
if ($ventasTieneEstatus)  $whereVentas .= " AND LOWER(COALESCE(v.estatus,'')) NOT IN ('cancelada','cancelado')";
if ($soloSucursal > 0 && $ventasTieneSucursal) $whereVentas .= " AND v.id_sucursal=".(int)$soloSucursal;

$sqlSales = "
  SELECT
    p.codigo_producto                  AS codigo,
    COUNT(dv.id)                       AS unidades,
    COALESCE(SUM(dv.precio_unitario),0) AS monto
  FROM detalle_venta dv
  JOIN ventas v    ON v.id = dv.id_venta
  JOIN productos p ON p.id = dv.id_producto
  WHERE $whereVentas
  GROUP BY p.codigo_producto
";
$sales = fetchAll($conn, $sqlSales);

/* ===============================
   MEZCLA por código
================================= */
$ventasByCode = [];
foreach ($sales as $r) $ventasByCode[(string)$r['codigo']] = (int)$r['unidades'];

$byCode = []; // codigo => stats
foreach ($inv as $r) {
  $code   = (string)$r['codigo'];
  $stock  = (int)$r['stock'];
  $ventas = (int)($ventasByCode[$code] ?? 0);
  $avgSem = $ventas / $numSemanas;
  $wos    = $avgSem > 0 ? $stock / $avgSem : ($stock > 0 ? 99.0 : 0.0);

  // edad (días) de la pieza más vieja con ese código
  $edad = null;
  if (!empty($r['fecha_mas_vieja'])) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $r['fecha_mas_vieja']) ?: DateTime::createFromFormat('Y-m-d', $r['fecha_mas_vieja']);
    if ($d) $edad = (int)$d->diff(new DateTime('today'))->format('%a');
  }

  $byCode[$code] = [
    'codigo' => $code,
    'marca'  => (string)$r['marca'],
    'modelo' => (string)$r['modelo'],
    'stock'  => $stock,
    'ventas' => $ventas,
    'avg_sem'=> round($avgSem, 2),
    'wos'    => round($wos,   2),
    'edad_dias' => $edad,
  ];
}

// VENTAS de códigos que hoy ya no tienen stock (para Top/Bottom)
$sqlCat = "
  SELECT MIN(p.marca) AS marca, MIN(p.modelo) AS modelo, p.codigo_producto AS codigo
  FROM detalle_venta dv
  JOIN ventas v    ON v.id=dv.id_venta
  JOIN productos p ON p.id=dv.id_producto
  WHERE $whereVentas
  GROUP BY p.codigo_producto
";
$catVentas = fetchAll($conn, $sqlCat);
foreach ($catVentas as $p) {
  $code = (string)$p['codigo'];
  if (!isset($byCode[$code])) {
    $ventas = (int)($ventasByCode[$code] ?? 0);
    $avgSem = $ventas / $numSemanas;
    $byCode[$code] = [
      'codigo'=>$code,
      'marca'=>(string)$p['marca'],
      'modelo'=>(string)$p['modelo'],
      'stock'=>0,
      'ventas'=>$ventas,
      'avg_sem'=>round($avgSem,2),
      'wos'=>0.0,
      'edad_dias'=>null,
    ];
  }
}

/* ===============================
   KPIs globales
================================= */
$totalUnidades=0; $skuConStock=0; $wosList=[]; $stockSinVentas=0; $envejecidos=0;
foreach ($byCode as $r) {
  $totalUnidades += (int)$r['stock'];
  if ($r['stock']>0) $skuConStock++;
  if ($r['stock']>0 && $r['avg_sem']>0) $wosList[] = $r['wos'];
  if ($r['stock']>0 && $r['ventas']==0) $stockSinVentas += (int)$r['stock'];
  if ($r['stock']>0 && $r['edad_dias']!==null && $r['edad_dias']>=$umbralDias) $envejecidos++;
}
$wosMediana = 0.0;
if (count($wosList)>0) { sort($wosList); $wosMediana = $wosList[(int)floor((count($wosList)-1)/2)]; }

/* ===============================
   Listas
================================= */
$rowsAll = array_values($byCode);

$masVendidos = array_values(array_filter($rowsAll, fn($x)=>$x['ventas']>0));
usort($masVendidos, fn($a,$b)=> $b['ventas']<=>$a['ventas']);
$masVendidos = array_slice($masVendidos, 0, 10);

$menosVendidos = array_values(array_filter($rowsAll, fn($x)=>$x['ventas']>0));
usort($menosVendidos, fn($a,$b)=> $a['ventas']<=>$b['ventas']);
$menosVendidos = array_slice($menosVendidos, 0, 10);

$sobre = array_values(array_filter($rowsAll, fn($x)=>$x['stock']>0 && $x['avg_sem']>0 && $x['wos']>=8));
usort($sobre, fn($a,$b)=> $b['wos']<=>$a['wos']); $sobre = array_slice($sobre, 0, 20);

$desabasto = array_values(array_filter($rowsAll, fn($x)=>$x['avg_sem']>0 && $x['wos']<2));
usort($desabasto, fn($a,$b)=> $a['wos']<=>$b['wos']); $desabasto = array_slice($desabasto, 0, 20);

$sinVentas = array_values(array_filter($rowsAll, fn($x)=>$x['stock']>0 && $x['ventas']==0));
usort($sinVentas, fn($a,$b)=> $b['stock']<=>$a['stock']); $sinVentas = array_slice($sinVentas, 0, 20);

$envejecido = array_values(array_filter($rowsAll, fn($x)=>$x['stock']>0 && $x['edad_dias']!==null && $x['edad_dias']>=$umbralDias));
usort($envejecido, fn($a,$b)=> $b['edad_dias']<=>$a['edad_dias']); $envejecido = array_slice($envejecido, 0, 20);

/* ===============================
   Export CSV
================================= */
if (isset($_GET['export']) && $_GET['export']==='csv'){
  $tipo = $_GET['tipo'] ?? 'master';
  $map = [
    'master'=>$rowsAll,
    'masvendidos'=>$masVendidos,
    'menosvendidos'=>$menosVendidos,
    'sobre'=>$sobre,
    'desabasto'=>$desabasto,
    'sinventas'=>$sinVentas,
    'envejecido'=>$envejecido,
  ];
  $data = $map[$tipo] ?? $rowsAll;
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="inventario_'.$tipo.'_'.date('Ymd_His').'.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,['Código','Marca','Modelo','Stock','Ventas '.(int)$semanas.'s','Prom/Sem','WOS','Edad(días)']);
  foreach($data as $r){
    fputcsv($out, [$r['codigo'],$r['marca'],$r['modelo'],$r['stock'],$r['ventas'],n2($r['avg_sem']),$r['wos'],$r['edad_dias']??'']);
  }
  fclose($out); exit;
}

/* ===============================
   Datos para gráficas
================================= */
$chartTopL = array_map(fn($r)=> trim($r['marca'].' '.$r['modelo'].' ('.$r['codigo'].')'), $masVendidos);
$chartTopV = array_map(fn($r)=> (int)$r['ventas'], $masVendidos);
$chartBotL = array_map(fn($r)=> trim($r['marca'].' '.$r['modelo'].' ('.$r['codigo'].')'), $menosVendidos);
$chartBotV = array_map(fn($r)=> (int)$r['ventas'], $menosVendidos);
?>
<style>
  .kpi-card { border:0; border-radius:1rem; box-shadow:0 0.5rem 1rem rgba(0,0,0,.08); }
  .kpi-title { font-size:.9rem; opacity:.8; }
  .table-sticky thead th { position: sticky; top: 0; background: #fff; z-index: 2; }
  .chart-box { height:320px; position:relative; }
  .chart-box canvas { max-height:320px; }
  .pill { border-radius:999px; padding:.25rem .6rem; font-weight:600; }
</style>

<div class="container-fluid mt-3">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-clipboard2-pulse text-info fs-4"></i>
    <h4 class="mb-0">Inteligencia de Inventario</h4>
    <span class="text-muted ms-2">
      Ventana: últimas <?= (int)$semanas ?> semanas<?= $soloSucursal? ' · Sucursal #'.(int)$soloSucursal : ' · Todas las sucursales' ?>
    </span>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2">
      <label class="form-label">Semanas</label>
      <input type="number" class="form-control" name="semanas" value="<?= (int)$semanas ?>" min="1" max="52">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label">Umbral envejecido (días)</label>
      <input type="number" class="form-control" name="umbral" value="<?= (int)$umbralDias ?>" min="15" max="365">
    </div>
    <?php if ($esAdmin): ?>
      <div class="col-12 col-md-3">
        <label class="form-label">Sucursal</label>
        <select name="sucursal" class="form-select">
          <option value="0" <?= $soloSucursal? '' : 'selected' ?>>Todas</option>
          <?php foreach($sucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ($soloSucursal==(int)$s['id'])?'selected':'' ?>><?= esc($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-12 col-md-3 d-grid">
      <label class="form-label">&nbsp;</label>
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Aplicar</button>
    </div>
    <div class="col-12 col-md-2 d-grid">
      <label class="form-label">&nbsp;</label>
      <a class="btn btn-outline-secondary"
         href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=master">
         <i class="bi bi-download me-1"></i> CSV general
      </a>
    </div>
  </form>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Total unidades en stock</div>
      <div class="display-6 fw-bold"><?= n0($totalUnidades) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Códigos con stock</div>
      <div class="display-6 fw-bold"><?= n0($skuConStock) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">WOS mediano</div>
      <div class="display-6 fw-bold"><?= n2($wosMediana) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Unidades sin ventas recientes</div>
      <div class="display-6 fw-bold"><?= n0($stockSinVentas) ?></div>
    </div></div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
      <div class="card kpi-card"><div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-graph-up"></i><strong>Top 10 más vendidos</strong></div>
        <div class="chart-box"><canvas id="chartTop"></canvas></div>
        <div class="mt-2">
          <a class="btn btn-sm btn-outline-secondary"
             href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=masvendidos">
             <i class="bi bi-download me-1"></i>CSV
          </a>
        </div>
      </div></div>
    </div>
    <div class="col-12 col-xl-6">
      <div class="card kpi-card"><div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-graph-down"></i><strong>Bottom 10 (con ventas)</strong></div>
        <div class="chart-box"><canvas id="chartBottom"></canvas></div>
        <div class="mt-2">
          <a class="btn btn-sm btn-outline-secondary"
             href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=menosvendidos">
             <i class="bi bi-download me-1"></i>CSV
          </a>
        </div>
      </div></div>
    </div>
  </div>

  <!-- Tablas de acción -->
  <div class="card kpi-card mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-exclamation-triangle"></i><strong>SOBREINVENTARIO (WOS ≥ 8)</strong></div>
      <div class="table-responsive table-sticky">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Código</th><th>Marca</th><th>Modelo</th>
              <th class="text-end">Stock</th><th class="text-end">Ventas <?= (int)$semanas ?>s</th>
              <th class="text-end">Prom/Sem</th><th class="text-end">WOS</th>
              <th class="text-end">Edad (días)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sobre)): ?>
              <tr><td colspan="8" class="text-center text-muted py-3">Sin sobreinventario según la ventana actual.</td></tr>
            <?php else: foreach($sobre as $r): ?>
              <tr>
                <td><?= esc($r['codigo']) ?></td>
                <td><?= esc($r['marca']) ?></td>
                <td><?= esc($r['modelo']) ?></td>
                <td class="text-end fw-semibold"><?= n0($r['stock']) ?></td>
                <td class="text-end"><?= n0($r['ventas']) ?></td>
                <td class="text-end"><?= n2($r['avg_sem']) ?></td>
                <td class="text-end"><span class="pill bg-dark text-white"><?= n2($r['wos']) ?></span></td>
                <td class="text-end"><?= $r['edad_dias']!==null? n0($r['edad_dias']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-secondary"
           href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=sobre">
           <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </div>
  </div>

  <div class="card kpi-card mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-thermometer-half"></i><strong>DESABASTO (WOS &lt; 2 con ventas)</strong></div>
      <div class="table-responsive table-sticky">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Código</th><th>Marca</th><th>Modelo</th>
              <th class="text-end">Stock</th><th class="text-end">Ventas <?= (int)$semanas ?>s</th>
              <th class="text-end">Prom/Sem</th><th class="text-end">WOS</th>
              <th class="text-end">Edad (días)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($desabasto)): ?>
              <tr><td colspan="8" class="text-center text-muted py-3">Sin desabasto crítico según la ventana actual.</td></tr>
            <?php else: foreach($desabasto as $r): ?>
              <tr>
                <td><?= esc($r['codigo']) ?></td>
                <td><?= esc($r['marca']) ?></td>
                <td><?= esc($r['modelo']) ?></td>
                <td class="text-end fw-semibold"><?= n0($r['stock']) ?></td>
                <td class="text-end"><?= n0($r['ventas']) ?></td>
                <td class="text-end"><?= n2($r['avg_sem']) ?></td>
                <td class="text-end"><span class="pill bg-dark text-white"><?= n2($r['wos']) ?></span></td>
                <td class="text-end"><?= $r['edad_dias']!==null? n0($r['edad_dias']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-secondary"
           href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=desabasto">
           <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </div>
  </div>

  <div class="card kpi-card mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-bag-x"></i><strong>Stock alto SIN ventas recientes</strong></div>
      <div class="table-responsive table-sticky">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Código</th><th>Marca</th><th>Modelo</th>
              <th class="text-end">Stock</th><th class="text-end">Ventas <?= (int)$semanas ?>s</th>
              <th class="text-end">Prom/Sem</th><th class="text-end">WOS</th>
              <th class="text-end">Edad (días)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sinVentas)): ?>
              <tr><td colspan="8" class="text-center text-muted py-3">Todo lo que tiene stock tuvo al menos una venta en la ventana.</td></tr>
            <?php else: foreach($sinVentas as $r): ?>
              <tr>
                <td><?= esc($r['codigo']) ?></td>
                <td><?= esc($r['marca']) ?></td>
                <td><?= esc($r['modelo']) ?></td>
                <td class="text-end fw-semibold"><?= n0($r['stock']) ?></td>
                <td class="text-end">0</td>
                <td class="text-end">0.00</td>
                <td class="text-end"><span class="pill bg-dark text-white"><?= n2($r['wos']) ?></span></td>
                <td class="text-end"><?= $r['edad_dias']!==null? n0($r['edad_dias']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-secondary"
           href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=sinventas">
           <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </div>
  </div>

  <div class="card kpi-card mb-4">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-hourglass-split"></i><strong>Inventario ENVEJECIDO (≥ <?= (int)$umbralDias ?> días)</strong></div>
      <div class="table-responsive table-sticky">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Código</th><th>Marca</th><th>Modelo</th>
              <th class="text-end">Stock</th><th class="text-end">Ventas <?= (int)$semanas ?>s</th>
              <th class="text-end">Prom/Sem</th><th class="text-end">WOS</th>
              <th class="text-end">Edad (días)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($envejecido)): ?>
              <tr><td colspan="8" class="text-center text-muted py-3">No se detectó inventario envejecido con los datos disponibles.</td></tr>
            <?php else: foreach($envejecido as $r): ?>
              <tr>
                <td><?= esc($r['codigo']) ?></td>
                <td><?= esc($r['marca']) ?></td>
                <td><?= esc($r['modelo']) ?></td>
                <td class="text-end fw-semibold"><?= n0($r['stock']) ?></td>
                <td class="text-end"><?= n0($r['ventas']) ?></td>
                <td class="text-end"><?= n2($r['avg_sem']) ?></td>
                <td class="text-end"><span class="pill bg-dark text-white"><?= n2($r['wos']) ?></span></td>
                <td class="text-end"><?= $r['edad_dias']!==null? n0($r['edad_dias']) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-secondary"
           href="?semanas=<?= (int)$semanas ?>&umbral=<?= (int)$umbralDias ?><?= $soloSucursal? '&sucursal='.(int)$soloSucursal:'' ?>&export=csv&tipo=envejecido">
           <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const tctx = document.getElementById('chartTop');
  const bctx = document.getElementById('chartBottom');
  if (tctx) new Chart(tctx, {
    type:'bar',
    data:{ labels: <?= json_encode($chartTopL, JSON_UNESCAPED_UNICODE) ?>, datasets:[{ label:'Unidades vendidas', data: <?= json_encode($chartTopV) ?> }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true } } }
  });
  if (bctx) new Chart(bctx, {
    type:'bar',
    data:{ labels: <?= json_encode($chartBotL, JSON_UNESCAPED_UNICODE) ?>, datasets:[{ label:'Unidades vendidas', data: <?= json_encode($chartBotV) ?> }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true } } }
  });
})();
</script>
