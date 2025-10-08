<?php
// historial_ventas_accesorios.php
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__.'/db.php';

// ====== CSRF ======
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ====== Helpers ======
function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $c, string $t, string $col): bool {
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=$c->query("SHOW COLUMNS FROM `$t` LIKE '$col'"); return $r && $r->num_rows>0;
}

// Semana martes->lunes (devuelve [startInclusive, endExclusive] en Y-m-d H:i:s)
function rangoSemanaMartesALunes(string $anchorYmd=null): array {
  $d = $anchorYmd ? DateTime::createFromFormat('Y-m-d', $anchorYmd) : new DateTime();
  if (!$d) $d = new DateTime();
  $d->setTime(0,0,0);
  while ((int)$d->format('N') !== 2) { // 2 = martes
    $d->modify('-1 day');
  }
  $start = clone $d;
  $endEx = (clone $start)->modify('+7 days'); // exclusivo (siguiente martes)
  return [$start->format('Y-m-d 00:00:00'), $endEx->format('Y-m-d 00:00:00')];
}

// Mes (devuelve [startInclusive, endExclusive])
function rangoMes(string $ym=null): array {
  $ref = $ym ? DateTime::createFromFormat('Y-m', $ym) : new DateTime('first day of this month');
  if (!$ref) $ref = new DateTime('first day of this month');
  $start = (clone $ref)->modify('first day of this month')->setTime(0,0,0);
  $endEx = (clone $start)->modify('first day of next month')->setTime(0,0,0);
  return [$start->format('Y-m-d H:i:s'), $endEx->format('Y-m-d H:i:s')];
}

// WHERE por alcance del usuario
function whereScope(string $rol, int $idUsuario, int $idSucursal, ?int $sucFiltro=null): array {
  $w = []; $types=''; $vals=[];
  if ($rol === 'Admin') {
    if ($sucFiltro && $sucFiltro>0) { $w[]='va.id_sucursal=?'; $types.='i'; $vals[]=$sucFiltro; }
  } elseif ($rol === 'Gerente') {
    $w[]='va.id_sucursal=?'; $types.='i'; $vals[]=$idSucursal;
  } else { // Ejecutivo
    $w[]='va.id_usuario=?'; $types.='i'; $vals[]=$idUsuario;
  }
  $sql = $w ? (' AND '.implode(' AND ', $w)) : '';
  return [$sql, $types, $vals];
}

// ====== Contexto sesión ======
$id_usuario          = (int)$_SESSION['id_usuario'];
$rol                 = (string)($_SESSION['rol'] ?? 'Ejecutivo');
$id_sucursal_usuario = (int)($_SESSION['id_sucursal'] ?? 0);

// ====== Parámetros UI ======
$mode   = $_GET['mode'] ?? 'weekly'; // weekly | monthly
$date   = $_GET['date'] ?? date('Y-m-d'); // para weekly (ancla)
$month  = $_GET['month'] ?? date('Y-m');  // para monthly (YYYY-MM)
$sucSel = isset($_GET['sucursal']) ? (int)$_GET['sucursal'] : 0; // admin only

// Rango fecha
if ($mode === 'monthly') { [$from, $toEx] = rangoMes($month); }
else                     { [$from, $toEx] = rangoSemanaMartesALunes($date); }

// ====== Export ======
if (isset($_GET['export']) && $_GET['export']==='csv') {
  // detalle por ítem
  [$scopeSql, $scopeTypes, $scopeVals] = whereScope($rol, $id_usuario, $id_sucursal_usuario, $sucSel);

  $sql = "
    SELECT va.id AS venta_id, va.fecha, va.total, va.forma_pago, va.pago_efectivo, va.pago_tarjeta,
           s.nombre AS sucursal, COALESCE(u.nombre, CONCAT('ID ',va.id_usuario)) AS vendedor,
           p.codigo_producto, p.marca, p.modelo, p.descripcion, dva.cantidad, dva.precio_unitario, dva.subtotal
    FROM ventas_accesorios va
    JOIN detalle_ventas_accesorios dva ON dva.id_venta_accesorio = va.id
    LEFT JOIN productos p   ON p.id = dva.id_producto
    LEFT JOIN sucursales s  ON s.id = va.id_sucursal
    LEFT JOIN usuarios u    ON u.id = va.id_usuario
    WHERE va.fecha >= ? AND va.fecha < ?
    $scopeSql
    ORDER BY va.fecha DESC, va.id DESC, p.marca, p.modelo
  ";
  $types = 'ss'.$scopeTypes;
  $vals  = array_merge([$from, $toEx], $scopeVals);

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$vals);
  $stmt->execute();
  $res = $stmt->get_result();

  $filename = 'ventas_accesorios_'.($mode==='monthly' ? $month : $date).'_'.date('Ymd_His').'.csv';
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Pragma: no-cache'); header('Expires: 0');

  $out = fopen('php://output', 'w');
  // BOM para Excel en Windows
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  fputcsv($out, [
    'VentaID','Fecha','Sucursal','Vendedor','Forma pago','$ Efectivo','$ Tarjeta','$ Total',
    'Código','Marca','Modelo/Desc','Cantidad','$ Precio U.','$ Subtotal'
  ]);

  $sumTotal = 0; $sumItems = 0;
  while($r = $res->fetch_assoc()){
    $sumTotal += (float)$r['total'];
    $sumItems += (int)$r['cantidad'];
    $desc = $r['descripcion'] ?: $r['modelo'];
    fputcsv($out, [
      $r['venta_id'],
      $r['fecha'],
      $r['sucursal'],
      $r['vendedor'],
      $r['forma_pago'],
      number_format((float)$r['pago_efectivo'],2,'.',''),
      number_format((float)$r['pago_tarjeta'],2,'.',''),
      number_format((float)$r['total'],2,'.',''),
      $r['codigo_producto'],
      $r['marca'],
      $desc,
      (int)$r['cantidad'],
      number_format((float)$r['precio_unitario'],2,'.',''),
      number_format((float)$r['subtotal'],2,'.',''),
    ]);
  }
  // Línea de resumen
  fputcsv($out, []);
  fputcsv($out, ['','','','','','', 'Totales:', number_format($sumTotal,2,'.',''), '', '', '', $sumItems, '', '']);
  fclose($out);
  exit;
}

// ====== Datos para tabla (agrupado por venta, + productos) ======
[$scopeSql, $scopeTypes, $scopeVals] = whereScope($rol, $id_usuario, $id_sucursal_usuario, $sucSel);

$sql = "
  SELECT
    va.id,
    va.fecha,
    va.total,
    va.forma_pago,
    va.pago_efectivo,
    va.pago_tarjeta,
    va.nombre_cliente,
    va.telefono_cliente,
    s.nombre AS sucursal,
    COALESCE(u.nombre, CONCAT('ID ',va.id_usuario)) AS vendedor,
    SUM(dva.cantidad) AS items,
    GROUP_CONCAT(
      CONCAT(
        dva.cantidad, 'x ',
        COALESCE(NULLIF(TRIM(p.descripcion), ''), CONCAT(p.marca,' ',p.modelo), 'Producto sin descripción')
      )
      SEPARATOR ' | '
    ) AS productos
  FROM ventas_accesorios va
  JOIN detalle_ventas_accesorios dva ON dva.id_venta_accesorio = va.id
  LEFT JOIN productos p ON p.id = dva.id_producto
  LEFT JOIN sucursales s ON s.id = va.id_sucursal
  LEFT JOIN usuarios  u ON u.id = va.id_usuario
  WHERE va.fecha >= ? AND va.fecha < ?
  $scopeSql
  GROUP BY va.id
  ORDER BY va.fecha DESC, va.id DESC
";
$types = 'ss'.$scopeTypes;
$vals  = array_merge([$from, $toEx], $scopeVals);

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$vals);
$stmt->execute();
$ventas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totales resumen
$totVentas = count($ventas);
$totItems  = 0; $totMonto = 0.0;
foreach ($ventas as $v) { $totItems += (int)$v['items']; $totMonto += (float)$v['total']; }

// Sucursales para filtro admin
$sucursales = [];
if ($rol==='Admin') {
  $q = $conn->query("SELECT id,nombre FROM sucursales ORDER BY nombre");
  if ($q) $sucursales = $q->fetch_all(MYSQLI_ASSOC);
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial de ventas de accesorios</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{background:#f8fafc}
    .stat{font-weight:700}
    .table-sm td, .table-sm th{vertical-align: middle;}
    .productos-cell{max-width: 520px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
  </style>
</head>
<body>
<?php include __DIR__.'/navbar.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0"><i class="bi bi-bag"></i> Historial de ventas de accesorios</h3>
    <div>
      <a class="btn btn-outline-secondary" href="nueva_venta_accesorio.php"><i class="bi bi-plus-circle"></i> Nueva venta</a>
    </div>
  </div>

  <?php if(isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= esc($_GET['msg']) ?></div>
  <?php endif; ?>
  <?php if(isset($_GET['err'])): ?>
    <div class="alert alert-danger"><?= esc($_GET['err']) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end">
        <input type="hidden" name="mode" id="mode" value="<?= esc($mode) ?>">

        <div class="col-md-2">
          <label class="form-label">Vista</label>
          <div class="btn-group w-100" role="group">
            <a class="btn btn-sm <?= $mode==='weekly'?'btn-primary':'btn-outline-primary' ?>" href="?mode=weekly">Semanal</a>
            <a class="btn btn-sm <?= $mode==='monthly'?'btn-primary':'btn-outline-primary' ?>" href="?mode=monthly">Mensual</a>
          </div>
        </div>

        <?php if($mode==='weekly'): ?>
          <div class="col-md-3">
            <label class="form-label">Fecha ancla (cualquier día de la semana)</label>
            <input type="date" name="date" value="<?= esc($date) ?>" class="form-control">
          </div>
          <?php [$ini, $finEx] = rangoSemanaMartesALunes($date); $fin = (new DateTime($finEx))->modify('-1 day')->format('Y-m-d'); ?>
          <div class="col-md-3">
            <label class="form-label">Semana</label>
            <div class="form-control"><?= esc($ini) ?> → <?= esc($fin.' 23:59:59') ?></div>
          </div>
        <?php else: ?>
          <div class="col-md-3">
            <label class="form-label">Mes</label>
            <input type="month" name="month" value="<?= esc($month) ?>" class="form-control">
          </div>
          <?php [$ini, $finEx] = rangoMes($month); $fin = (new DateTime($finEx))->modify('-1 day')->format('Y-m-d'); ?>
          <div class="col-md-3">
            <label class="form-label">Rango</label>
            <div class="form-control"><?= esc($ini) ?> → <?= esc($fin.' 23:59:59') ?></div>
          </div>
        <?php endif; ?>

        <?php if($rol==='Admin'): ?>
          <div class="col-md-3">
            <label class="form-label">Sucursal (opcional)</label>
            <select class="form-select" name="sucursal">
              <option value="0">Todas</option>
              <?php foreach($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $sucSel===(int)$s['id']?'selected':'' ?>>
                  <?= esc($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-1">
          <button class="btn btn-primary w-100"><i class="bi bi-filter"></i> Filtrar</button>
        </div>
        <div class="col-md-2">
          <a class="btn btn-success w-100"
             href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>">
            <i class="bi bi-file-earmark-spreadsheet"></i> Exportar Excel
          </a>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Ventas</div>
          <div class="h4 stat"><?= (int)$totVentas ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Ítems vendidos</div>
          <div class="h4 stat"><?= (int)$totItems ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">Monto total</div>
          <div class="h4 stat">$ <?= number_format($totMonto,2) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>Venta</th>
              <th>Fecha</th>
              <th>Sucursal</th>
              <th>Vendedor</th>
              <th>Cliente</th>
              <th>Teléfono</th>
              <th>Ítems</th>
              <th>Producto(s)</th>
              <th>Forma pago</th>
              <th>$ Efectivo</th>
              <th>$ Tarjeta</th>
              <th>$ Total</th>
              <?php if($rol==='Admin'): ?>
                <th>Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($ventas)): ?>
              <tr>
                <td colspan="<?= $rol==='Admin' ? 13 : 12 ?>" class="text-center text-muted">
                  Sin registros en el rango seleccionado.
                </td>
              </tr>
            <?php else: foreach($ventas as $v): ?>
              <tr>
                <td>#<?= (int)$v['id'] ?></td>
                <td><?= esc($v['fecha']) ?></td>
                <td><?= esc($v['sucursal']) ?></td>
                <td><?= esc($v['vendedor']) ?></td>
                <td><?= esc($v['nombre_cliente'] ?? '') ?></td>
                <td><?= esc($v['telefono_cliente'] ?? '') ?></td>
                <td><?= (int)$v['items'] ?></td>
                <td class="productos-cell" title="<?= esc($v['productos'] ?? '') ?>">
                  <?= esc($v['productos'] ?? '') ?>
                </td>
                <td><?= esc($v['forma_pago']) ?></td>
                <td>$ <?= number_format((float)$v['pago_efectivo'],2) ?></td>
                <td>$ <?= number_format((float)$v['pago_tarjeta'],2) ?></td>
                <td class="fw-bold">$ <?= number_format((float)$v['total'],2) ?></td>

                <?php if($rol==='Admin'): ?>
                  <td>
                    <form method="POST" action="eliminar_venta_accesorio.php"
                          onsubmit="return confirm('¿Eliminar la venta #<?= (int)$v['id'] ?> y devolver stock?');">
                      <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                      <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
                      <!-- preserva filtros -->
                      <input type="hidden" name="mode" value="<?= esc($mode) ?>">
                      <?php if($mode==='weekly'): ?>
                        <input type="hidden" name="date" value="<?= esc($date) ?>">
                      <?php else: ?>
                        <input type="hidden" name="month" value="<?= esc($month) ?>">
                      <?php endif; ?>
                      <?php if($rol==='Admin'): ?>
                        <input type="hidden" name="sucursal" value="<?= (int)$sucSel ?>">
                      <?php endif; ?>

                      <button class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash"></i> Eliminar
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
