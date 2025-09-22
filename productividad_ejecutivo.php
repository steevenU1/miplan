<?php
// productividad_ejecutivo.php — Central 2.0 (LUGA)
// Vista mensual enfocada a ROTACIÓN: identifica bajo desempeño y prioriza acciones
// Incluye: detección flexible de tabla de cuotas y columnas, fallback a última cuota, export CSV

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

/* ================================
   Helpers
================================ */
function esc($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function n0($v){ return number_format((float)$v, 0); }
function n2($v){ return number_format((float)$v, 2); }
function nombreMes($m){ $meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre']; return $meses[(int)$m] ?? ''; }
function hasColumn(mysqli $c,$t,$col){ $t=$c->real_escape_string($t); $col=$c->real_escape_string($col); $q="SHOW COLUMNS FROM `$t` LIKE '$col'"; if($r=$c->query($q)){ $ok=$r->num_rows>0; $r->close(); return $ok; } return false; }
function hasTable(mysqli $c,$t){ $t=$c->real_escape_string($t); $q="SHOW TABLES LIKE '$t'"; if($r=$c->query($q)){ $ok=$r->num_rows>0; $r->close(); return $ok; } return false; }

/* ================================
   Parámetros (con clamps)
================================ */
$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$permAdmin  = in_array($ROL, ['Admin','RH','Gerente','GerenteZona']);

$anio = isset($_GET['anio']) ? max(2000,min(2100,(int)$_GET['anio'])) : (int)date('Y');
$mes  = isset($_GET['mes'])  ? min(12, max(1, (int)$_GET['mes']))     : (int)date('n');
$soloRiesgo = isset($_GET['riesgo']) && $_GET['riesgo']==='1';
$exportCsv  = isset($_GET['export']) && $_GET['export']==='csv';

$ini = (new DateTime("$anio-$mes-01 00:00:00"))->format('Y-m-d H:i:s');
$fin = (new DateTime("$anio-$mes-01 00:00:00"))->modify('last day of this month')->setTime(23,59,59)->format('Y-m-d H:i:s');

/* ================================
   Detección columna de tipo de producto
================================ */
$colTipoProd = hasColumn($conn,'productos','tipo') ? 'tipo' : 'tipo_producto';

/* ================================
   Datos base del mes (TODOS los ejecutivos, incluso 0 ventas)
   Ahora usando agregado por VENTA para cuadrar unidades y monto:
   - F+Combo = 2 unidades
   - Venta con al menos 1 producto NO módem/MiFi = 1 unidad (monto = v.precio_venta)
   - Venta solo con módem/MiFi = 0 unidades (monto = 0)
================================ */
$condU = ["u.rol='Ejecutivo'"];
if (!$permAdmin) { $condU[] = 'u.id = '.$ID_USUARIO; }

$condVentas = ["v.fecha_venta BETWEEN '$ini' AND '$fin'"];
if (hasColumn($conn,'ventas','estatus'))       $condVentas[] = "LOWER(COALESCE(v.estatus,'')) NOT IN ('cancelada','cancelado')";
elseif (hasColumn($conn,'ventas','cancelada')) $condVentas[] = "COALESCE(v.cancelada,0)=0";
$condV = implode(' AND ', $condVentas);

// Subconsulta por venta (agrega unidades/monto a nivel VENTA)
$subVentasAgg = "
  SELECT
    v.id,
    v.id_usuario,
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
  WHERE $condV
";

// Agregado por ejecutivo usando la subconsulta
$sql = "
  SELECT
    u.id   AS id_usuario,
    u.nombre AS ejecutivo,
    s.nombre AS sucursal,
    IFNULL(SUM(va.unidades),0) AS unidades,
    IFNULL(SUM(va.monto),0)    AS monto
  FROM usuarios u
  LEFT JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN ( $subVentasAgg ) va ON va.id_usuario = u.id
  WHERE u.activo=1 AND ".implode(' AND ',$condU)."
  GROUP BY u.id, u.nombre, s.nombre
";

$rows=[]; $tot_unid=0; $tot_monto=0;
if ($rs=$conn->query($sql)){
  while($r=$rs->fetch_assoc()){
    $r['id_usuario']=(int)$r['id_usuario'];
    $r['unidades']  =(int)($r['unidades'] ?? 0);
    $r['monto']     =(float)($r['monto'] ?? 0);
    $r['ticket']    =$r['unidades']>0?($r['monto']/$r['unidades']):0.0;
    $rows[]=$r; $tot_unid+=$r['unidades']; $tot_monto+=$r['monto'];
  }
  $rs->close();
}

/* ================================
   Cuotas mensuales por ejecutivo (detección flexible)
================================ */
$cuotas = [];
$cuotaDefaultU = null; $cuotaDefaultM = null; // cuotas globales
$tablasCand = ['cuotas_mensuales_ejecutivos','cuota_mensual_ejecutivos','cuota_menusal_ejecutivos'];
$tablaCuotas = null;
foreach ($tablasCand as $t) { if (hasTable($conn,$t)) { $tablaCuotas = $t; break; } }

if ($tablaCuotas) {
  $qMes = "SELECT * FROM `$tablaCuotas` WHERE anio=$anio AND mes=$mes";
  $rowsC = [];
  if ($rc=$conn->query($qMes)) { while($c=$rc->fetch_assoc()){ $rowsC[]=$c; } $rc->close(); }
  if (empty($rowsC)) {
    $qLast = hasColumn($conn,$tablaCuotas,'fecha_registro')
      ? "SELECT * FROM `$tablaCuotas` ORDER BY fecha_registro DESC LIMIT 100"
      : "SELECT * FROM `$tablaCuotas` ORDER BY anio DESC, mes DESC LIMIT 100";
    if ($rl=$conn->query($qLast)) { while($c=$rl->fetch_assoc()){ $rowsC[]=$c; } $rl->close(); }
  }

  $tieneIdUsuario = hasColumn($conn,$tablaCuotas,'id_usuario') || hasColumn($conn,$tablaCuotas,'usuario_id') || hasColumn($conn,$tablaCuotas,'idUser');

  foreach ($rowsC as $c) {
    $cuotaU = null; $cuotaM = null;
    foreach (['cuota_unidades','unidades','cuota_u','meta_unidades'] as $k) { if (isset($c[$k])) { $cuotaU=(float)$c[$k]; break; } }
    foreach (['cuota_monto','monto','cuota_m','meta_monto']         as $k) { if (isset($c[$k])) { $cuotaM=(float)$c[$k]; break; } }

    if ($tieneIdUsuario) {
      $uid = $c['id_usuario'] ?? $c['usuario_id'] ?? $c['idUser'] ?? null; if ($uid===null) continue;
      $cuotas[(int)$uid] = ['u'=>$cuotaU, 'm'=>$cuotaM];
    } else {
      if ($cuotaU!==null) $cuotaDefaultU = $cuotaU;
      if ($cuotaM!==null) $cuotaDefaultM = $cuotaM;
    }
  }
}

/* ================================
   Medianas (referencia si no hay cuota)
================================ */
$unids = array_column($rows,'unidades'); sort($unids);
$med_unid   = count($unids)? $unids[(int)floor((count($unids)-1)/2)] : 0;
$ticketsArr = array_column($rows,'ticket'); sort($ticketsArr);
$med_ticket = count($ticketsArr)? $ticketsArr[(int)floor((count($ticketsArr)-1)/2)] : 0.0;

/* ================================
   Score de riesgo por ejecutivo (0 a 100)
================================ */
function percentileRank($sortedVals,$val){ $n=count($sortedVals); if($n==0) return 0.5; $cnt=0; foreach($sortedVals as $v){ if($v<=$val) $cnt++; else break; } return $cnt/$n; }
$sortedUnids=$unids; // ya ordenado

foreach($rows as &$r){
  $uid = (int)$r['id_usuario'];
  $cuotaU = $cuotas[$uid]['u'] ?? $cuotaDefaultU ?? null; $cuotaM = $cuotas[$uid]['m'] ?? $cuotaDefaultM ?? null;
  $r['cuota_unid']=$cuotaU; $r['cuota_monto']=$cuotaM;
  $r['pct_cuota_u'] = ($cuotaU && $cuotaU>0)? ($r['unidades']/$cuotaU*100.0) : null;
  $r['pct_cuota_m'] = ($cuotaM && $cuotaM>0)? ($r['monto']/$cuotaM*100.0) : null;

  $p = percentileRank($sortedUnids,$r['unidades']);           // 0..1 (alto es mejor)
  $risk_pos = 1 - $p;                                         // 0..1 (alto es peor)
  $target = ($cuotaU && $cuotaU>0)? $cuotaU : ($med_unid>0?$med_unid:1);
  $deficit = max(0.0, 1.0 - ($r['unidades']/$target));        // 0..1
  $ticketFactor = ($med_ticket>0)? max(0.0, ($med_ticket - $r['ticket'])/$med_ticket) : 0.0; // 0..1

  $score = 100*(0.60*$risk_pos + 0.30*$deficit + 0.10*$ticketFactor);
  $r['riesgo_score'] = round($score,1);

  if ($score>=60)      { $r['riesgo_label']='En riesgo';  $r['riesgo_class']='table-danger'; }
  elseif ($score>=40)  { $r['riesgo_label']='Observación';$r['riesgo_class']='table-warning'; }
  else                 { $r['riesgo_label']='OK';         $r['riesgo_class']='table-success'; }

  // Recomendación textual
  $faltan = ($cuotaU && $cuotaU>0)? max(0,(int)ceil($cuotaU - $r['unidades'])) : null;
  $tips=[];
  if ($faltan!==null) $tips[] = ($faltan>0? "Le faltan $faltan unidades para 100%" : "Cumplió cuota por unidades");
  if ($r['ticket'] < $med_ticket) $tips[] = 'Ticket bajo vs mediana';
  if ($p<=0.25) $tips[] = 'En último cuartil de unidades';
  $r['reco'] = $tips? implode(' · ',$tips) : 'Rendimiento dentro de lo esperado';
}
unset($r);

// Filtrar sólo en riesgo si aplica
if ($soloRiesgo){ $rows = array_values(array_filter($rows,function($r){ return $r['riesgo_label']==='En riesgo'; })); }

// Orden: peor a mejor por score
usort($rows,function($a,$b){ return $b['riesgo_score'] <=> $a['riesgo_score']; });

// Export CSV
if ($exportCsv) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="rotacion_riesgo_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['Ejecutivo','Sucursal','Unidades','Monto','Ticket','%Cuota U','%Cuota $','Score','Estatus','Recomendación']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['ejecutivo'], $r['sucursal'], $r['unidades'], n2($r['monto']), n2($r['ticket']),
      ($r['pct_cuota_u']!==null? n2($r['pct_cuota_u']).'%':'—'),
      ($r['pct_cuota_m']!==null? n2($r['pct_cuota_m']).'%':'—'),
      n2($r['riesgo_score']), $r['riesgo_label'], $r['reco']
    ]);
  }
  fclose($out); exit;
}

// Datos para gráfica: Bottom 10 por score
$labels=array_map(function($r){ return $r['ejecutivo']; }, array_slice($rows,0,10));
$vals  =array_map(function($r){ return (float)$r['riesgo_score']; }, array_slice($rows,0,10));
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
    <i class="bi bi-clipboard-data text-danger fs-4"></i>
    <h4 class="mb-0">Rotación · Riesgo por Ejecutivo — <?= esc(nombreMes($mes)) ?> <?= esc($anio) ?></h4>
  </div>

  <!-- Controles -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2">
      <label class="form-label">Mes</label>
      <select name="mes" class="form-select">
        <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= esc(nombreMes($m)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label">Año</label>
      <input type="number" class="form-control" name="anio" value="<?= esc($anio) ?>" min="2000" max="2100">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">Filtros</label>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" value="1" id="riesgo" name="riesgo" <?= $soloRiesgo?'checked':'' ?>>
        <label class="form-check-label" for="riesgo">Mostrar solo "En riesgo"</label>
      </div>
    </div>
    <div class="col-6 col-md-2 d-grid">
      <label class="form-label">&nbsp;</label>
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Aplicar</button>
    </div>
    <div class="col-6 col-md-2 d-grid">
      <label class="form-label">&nbsp;</label>
      <a class="btn btn-outline-secondary" href="?mes=<?= (int)$mes ?>&anio=<?= (int)$anio ?><?= $soloRiesgo?'&riesgo=1':'' ?>&export=csv">
        <i class="bi bi-download me-1"></i> Exportar CSV
      </a>
    </div>
  </form>

  <!-- KPIs rápidos -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Total Unidades</div>
      <div class="display-6 fw-bold"><?= n0($tot_unid) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Monto Total</div>
      <div class="display-6 fw-bold">$<?= n2($tot_monto) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Mediana Unidades (ref.)</div>
      <div class="display-6 fw-bold"><?= n0($med_unid) ?></div>
    </div></div></div>
    <div class="col-12 col-md-3"><div class="card kpi-card"><div class="card-body">
      <div class="kpi-title">Mediana Ticket (ref.)</div>
      <div class="display-6 fw-bold">$<?= n2($med_ticket) ?></div>
    </div></div></div>
  </div>

  <!-- Bottom 10 por Score (peor desempeño) -->
  <div class="card kpi-card mb-4">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-graph-down"></i><strong>Bottom 10 · Score de Riesgo</strong></div>
      <div class="chart-box"><canvas id="chartBottom"></canvas></div>
    </div>
  </div>

  <!-- Tabla priorizada para rotación -->
  <div class="card kpi-card">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-person-x"></i><strong>Lista priorizada (peor → mejor)</strong></div>
      <div class="table-responsive table-sticky">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Ejecutivo</th>
              <th>Sucursal</th>
              <th class="text-end">Unid.</th>
              <th class="text-end">Monto</th>
              <th class="text-end">Ticket</th>
              <th class="text-end">% Cuota U</th>
              <th class="text-end">% Cuota $</th>
              <th class="text-end">Score</th>
              <th>Estatus</th>
              <th>Recomendación</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="11" class="text-center text-muted py-4">Sin datos en el periodo.</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr class="<?= esc($r['riesgo_class']) ?>">
                <td><?= esc($r['ejecutivo']) ?></td>
                <td class="text-muted"><?= esc($r['sucursal']) ?></td>
                <td class="text-end fw-semibold"><?= n0($r['unidades']) ?></td>
                <td class="text-end fw-semibold">$<?= n2($r['monto']) ?></td>
                <td class="text-end">$<?= n2($r['ticket']) ?></td>
                <td class="text-end"><?php if($r['pct_cuota_u']!==null): ?><span class="pill bg-light border"><?= n2($r['pct_cuota_u']) ?>%</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="text-end"><?php if($r['pct_cuota_m']!==null): ?><span class="pill bg-light border"><?= n2($r['pct_cuota_m']) ?>%</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="text-end"><span class="pill bg-dark text-white"><?= n2($r['riesgo_score']) ?></span></td>
                <td><span class="pill <?= $r['riesgo_label']==='En riesgo'?'bg-danger text-white':($r['riesgo_label']==='Observación'?'bg-warning text-dark':'bg-success text-white') ?>"><?= esc($r['riesgo_label']) ?></span></td>
                <td><?= esc($r['reco']) ?></td>
                <td>
                  <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary" href="historial_ventas.php?ejecutivo=<?= urlencode($r['ejecutivo']) ?>&mes=<?= (int)$mes ?>&anio=<?= (int)$anio ?>" title="Ver detalle"><i class="bi bi-search"></i></a>
                    <a class="btn btn-outline-danger" href="gestionar_usuarios.php?q=<?= urlencode($r['ejecutivo']) ?>" title="Gestionar usuario"><i class="bi bi-person-gear"></i></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const ctx = document.getElementById('chartBottom'); if(!ctx) return;
  const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const vals   = <?= json_encode($vals) ?>;
  new Chart(ctx, {
    type:'bar',
    data:{ labels:labels, datasets:[{ label:'Score de riesgo (0–100)', data:vals }] },
    options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{beginAtZero:true, suggestedMax:100} }, plugins:{ legend:{display:false} } }
  });
})();
</script>
