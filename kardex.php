<?php
/* kardex.php — Historial (Kardex) unificado por IMEI / id_producto
   Autor: tu compa
   Requiere:
     - db.php (mysqli $conn)
     - navbar.php
     - Vista SQL: kardex_movimientos (fecha, tipo_movimiento, fuente, movimiento_id, referencia_id, id_producto, imei, cantidad, sucursal_origen, sucursal_destino, precio_unitario, usuario_id, notas)
     - Tablas: productos(id,codigo_producto,marca,modelo,color,ram,capacidad,imei1,imei2,...)
               sucursales(id,nombre)
*/

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

$KARDEX_VIEW = 'kardex_movimientos';

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ymd($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d ? $d->format('Y-m-d') : null;
}
function intv($v){ return (int)($v ?? 0); }
function cleanEnum($v, $allowed){
  $v = trim((string)$v);
  return in_array($v, $allowed, true) ? $v : null;
}

/* ---------- Parámetros ---------- */
$q               = trim($_GET['q'] ?? '');                  // IMEI o id_producto
$fecha_desde     = ymd($_GET['desde'] ?? '');
$fecha_hasta     = ymd($_GET['hasta'] ?? '');
$tipo            = trim($_GET['tipo'] ?? '');               // enum de tipos
$suc_origen      = isset($_GET['suc_o']) ? (int)$_GET['suc_o'] : null;
$suc_destino     = isset($_GET['suc_d']) ? (int)$_GET['suc_d'] : null;
$por_pagina      = max(10, min(200, (int)($_GET['per'] ?? 50)));
$pagina          = max(1, (int)($_GET['p'] ?? 1));
$export_csv      = isset($_GET['export']) && $_GET['export'] === 'csv';

$TIPOS_VALIDOS = [
  'COMPRA_INGRESO','INGRESO_INVENTARIO','TRASPASO_SALIDA','TRASPASO_ENTRADA','VENTA','RETIRO'
];
if ($tipo !== '' && !in_array($tipo, $TIPOS_VALIDOS, true)) $tipo = '';

/* ---------- Sucursales para selects ---------- */
$sucursales = [];
if ($res = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC")) {
  while ($r = $res->fetch_assoc()) { $sucursales[(int)$r['id']] = $r['nombre']; }
  $res->free();
}

/* ---------- Construcción dinámica del WHERE ---------- */
$esc = fn($s) => $conn->real_escape_string($s);

$conds = [];
if ($q !== '') {
  if (ctype_digit($q)) {
    // Si son solo dígitos, buscamos por IMEI o id_producto exacto
    $conds[] = "(km.imei = '".$esc($q)."' OR km.id_producto = ".(int)$q.")";
  } else {
    // Texto → busca IMEI exacto (limpio)
    $conds[] = "km.imei = '".$esc($q)."'";
  }
}
if ($fecha_desde) { $conds[] = "km.fecha >= '".$esc($fecha_desde)." 00:00:00'"; }
if ($fecha_hasta) { $conds[] = "km.fecha <= '".$esc($fecha_hasta)." 23:59:59'"; }
if ($tipo !== '') { $conds[] = "km.tipo_movimiento = '".$esc($tipo)."'"; }
if ($suc_origen !== null && $suc_origen > 0) { $conds[] = "km.sucursal_origen = ".(int)$suc_origen; }
if ($suc_destino !== null && $suc_destino > 0) { $conds[] = "km.sucursal_destino = ".(int)$suc_destino; }

$where = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';

/* ---------- Totales/Conteos (para paginación y saldo) ---------- */
$sql_count = "SELECT COUNT(*) AS n, 
                     SUM(CASE WHEN km.cantidad>0 THEN km.cantidad ELSE 0 END) AS entradas,
                     SUM(CASE WHEN km.cantidad<0 THEN -km.cantidad ELSE 0 END) AS salidas
              FROM {$KARDEX_VIEW} km
              {$where}";
$meta = ['n'=>0,'entradas'=>0,'salidas'=>0];
if ($rc = $conn->query($sql_count)) {
  $meta = $rc->fetch_assoc();
  $rc->free();
}
$total_rows = (int)($meta['n'] ?? 0);
$entradas   = (int)($meta['entradas'] ?? 0);
$salidas    = (int)($meta['salidas'] ?? 0);
$saldo      = $entradas - $salidas;

$offset = ($pagina - 1) * $por_pagina;

/* ---------- Consulta principal ---------- */
$sql = "
SELECT
  km.fecha, km.tipo_movimiento, km.fuente, km.movimiento_id, km.referencia_id,
  km.id_producto, km.imei, km.cantidad, km.sucursal_origen, km.sucursal_destino,
  km.precio_unitario, km.usuario_id, km.notas,
  p.codigo_producto, p.marca, p.modelo, p.color, p.ram, p.capacidad,
  so.nombre AS suc_origen_nombre,
  sd.nombre AS suc_destino_nombre
FROM {$KARDEX_VIEW} km
LEFT JOIN productos p    ON p.id = km.id_producto
LEFT JOIN sucursales so  ON so.id = km.sucursal_origen
LEFT JOIN sucursales sd  ON sd.id = km.sucursal_destino
{$where}
ORDER BY km.fecha DESC, km.movimiento_id DESC
LIMIT {$offset}, {$por_pagina}
";

$res = $conn->query($sql);
$rows = [];
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } $res->free(); }

/* ---------- Export CSV ---------- */
if ($export_csv) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="kardex_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, [
    'fecha','tipo','fuente','mov_id','ref_id',
    'id_producto','codigo','marca','modelo','color','ram','capacidad',
    'imei','cantidad','suc_origen','suc_destino','precio_unitario','usuario_id','notas'
  ]);
  // Exportar sin paginación: re-ejecutamos sin LIMIT
  $sqlExp = "
  SELECT
    km.fecha, km.tipo_movimiento, km.fuente, km.movimiento_id, km.referencia_id,
    km.id_producto, p.codigo_producto, p.marca, p.modelo, p.color, p.ram, p.capacidad,
    km.imei, km.cantidad, so.nombre AS suc_origen, sd.nombre AS suc_destino,
    km.precio_unitario, km.usuario_id, km.notas
  FROM {$KARDEX_VIEW} km
  LEFT JOIN productos p   ON p.id = km.id_producto
  LEFT JOIN sucursales so ON so.id = km.sucursal_origen
  LEFT JOIN sucursales sd ON sd.id = km.sucursal_destino
  {$where}
  ORDER BY km.fecha DESC, km.movimiento_id DESC";
  if ($re = $conn->query($sqlExp)) {
    while ($r = $re->fetch_row()) { fputcsv($out, $r); }
    $re->free();
  }
  fclose($out);
  exit;
}

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Kardex de Productos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <style>
    .badge-in { background:#e6ffed; color:#067d33; border:1px solid #b7f0c2; padding:4px 8px; border-radius:10px; }
    .badge-out{ background:#ffecec; color:#a10f0f; border:1px solid #f5b5b5; padding:4px 8px; border-radius:10px; }
    .pill     { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; background:#f2f4f7; }
    .table-sm td, .table-sm th { padding: .4rem .5rem; }
    
  </style>
</head>
<body>
<div class="container-fluid mt-3">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <h3 class="mb-0">Kardex de Productos</h3>
    <div>
      <a class="btn btn-outline-secondary btn-sm" href="kardex.php">Reiniciar</a>
      <a class="btn btn-success btn-sm"
         href="?<?php
           $qs = $_GET; $qs['export']='csv'; echo h(http_build_query($qs));
         ?>">Exportar CSV</a>
    </div>
  </div>

  <!-- Filtros -->
  <form class="card mb-3 sticky-top p-3" method="get" style="top:70px;">
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Buscar (IMEI o ID producto)</label>
        <input type="text" name="q" class="form-control" value="<?=h($q)?>" placeholder="3538... o 123">
      </div>
      <div class="col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="desde" class="form-control" value="<?=h($fecha_desde ?? '')?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="hasta" class="form-control" value="<?=h($fecha_hasta ?? '')?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($TIPOS_VALIDOS as $t): ?>
            <option value="<?=h($t)?>" <?= $tipo===$t?'selected':'';?>><?=h($t)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label">Per pág.</label>
        <input type="number" name="per" min="10" max="200" class="form-control" value="<?=h($por_pagina)?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Aplicar</button>
      </div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-3">
        <label class="form-label">Sucursal origen</label>
        <select name="suc_o" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($sucursales as $id=>$nom): ?>
            <option value="<?=$id?>" <?=($suc_origen===$id)?'selected':''?>><?=h($nom)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Sucursal destino</label>
        <select name="suc_d" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($sucursales as $id=>$nom): ?>
            <option value="<?=$id?>" <?=($suc_destino===$id)?'selected':''?>><?=h($nom)?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </form>

  <!-- KPIs -->
  <div class="row g-3 mb-2">
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Registros</div>
          <div class="fs-4 fw-bold"><?=number_format($total_rows)?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Entradas</div>
          <div class="fs-4 fw-bold text-success"><?=number_format($entradas)?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Salidas</div>
          <div class="fs-4 fw-bold text-danger"><?=number_format($salidas)?></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Saldo (Entradas - Salidas)</div>
          <div class="fs-4 fw-bold"><?=number_format($saldo)?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="table-responsive">
    <table class="table table-striped table-hover table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>IMEI</th>
          <th>ID Prod</th>
          <th>Código</th>
          <th>Producto</th>
          <th>Cant</th>
          <th>Origen</th>
          <th>Destino</th>
          <th>Precio</th>
          <th>Usuario</th>
          <th>Fuente</th>
          <th>Ref</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="13" class="text-center text-muted">Sin resultados</td></tr>
      <?php else: foreach ($rows as $r):
        $prod = trim(($r['marca']??'').' '.($r['modelo']??'').' '.($r['color']??'').' '.($r['ram']??'').' '.($r['capacidad']??''));
        $badge = ($r['cantidad'] ?? 0) >= 0 ? 'badge-in' : 'badge-out';
      ?>
        <tr>
          <td><?=h($r['fecha'])?></td>
          <td><span class="pill"><?=h($r['tipo_movimiento'])?></span></td>
          <td><code><?=h($r['imei'])?></code></td>
          <td><?=h($r['id_producto'])?></td>
          <td><?=h($r['codigo_producto'])?></td>
          <td><?=h($prod)?></td>
          <td><span class="<?=$badge?>"><?= (int)$r['cantidad'] ?></span></td>
          <td><?=h($r['suc_origen_nombre'] ?? '')?></td>
          <td><?=h($r['suc_destino_nombre'] ?? '')?></td>
          <td>$<?=number_format((float)($r['precio_unitario'] ?? 0),2)?></td>
          <td><?=h($r['usuario_id'] ?? '')?></td>
          <td><?=h($r['fuente'])?></td>
          <td><?=h($r['referencia_id'])?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php
    $total_paginas = (int)ceil($total_rows / $por_pagina);
    if ($total_paginas < 1) $total_paginas = 1;
    $baseQS = $_GET; unset($baseQS['p']);
  ?>
  <nav>
    <ul class="pagination">
      <?php
        $mk = function($p, $label=null, $disabled=false, $active=false) use ($baseQS){
          $qs = $baseQS; $qs['p']=$p; $url='?'.h(http_build_query($qs));
          echo '<li class="page-item '.($disabled?'disabled':'').' '.($active?'active':'').'"><a class="page-link" href="'.($disabled?'#':$url).'">'.($label??$p).'</a></li>';
        };
        $mk(max(1,$pagina-1), '«', $pagina<=1);
        for ($i=max(1,$pagina-3); $i<=min($total_paginas,$pagina+3); $i++) $mk($i, (string)$i, false, $i===$pagina);
        $mk(min($total_paginas,$pagina+1), '»', $pagina>=$total_paginas);
      ?>
    </ul>
  </nav>

  <!-- <div class="text-muted small mb-5">
    Fuente: vista <code><?=h($KARDEX_VIEW)?></code>. Si necesitas auditar, usa “Fuente” + “Ref” para abrir el documento original.
  </div> -->

</div>
</body>
</html>
