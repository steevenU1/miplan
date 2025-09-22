<?php
// inventario_historico.php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') === '') {
  header("Location: index.php");
  exit();
}

require 'db.php';
require 'navbar.php';

/* ===== Configuración de retención ===== */
$RETENCION_DIAS = 15;
$maxFecha = date('Y-m-d');                                 // hoy
$minFecha = date('Y-m-d', strtotime("-{$RETENCION_DIAS} days")); // hace 15 días

/* ===== Parámetros (con valores por defecto) ===== */
$fFecha       = $_GET['fecha']         ?? date('Y-m-d', strtotime('-1 day')); // por defecto: ayer
$fSucursal    = $_GET['sucursal']      ?? '';
$fImei        = $_GET['imei']          ?? '';
$fTipo        = $_GET['tipo_producto'] ?? '';
$fEstatus     = $_GET['estatus']       ?? '';
$fAntiguedad  = $_GET['antiguedad']    ?? '';
$fPrecioMin   = $_GET['precio_min']    ?? '';
$fPrecioMax   = $_GET['precio_max']    ?? '';

/* Clamp de fecha al rango de retención */
if ($fFecha < $minFecha) $fFecha = $minFecha;
if ($fFecha > $maxFecha) $fFecha = $maxFecha;

/* Sucursales para el filtro */
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

/* ===== Consulta al snapshot ===== */
$sql = "
SELECT
  id_inventario, id_sucursal, sucursal_nombre, marca, modelo, color, capacidad,
  imei1, imei2, tipo_producto, proveedor,
  costo_con_iva, precio_lista, profit,
  estatus, fecha_ingreso, antiguedad_dias, codigo_producto
FROM inventario_snapshot
WHERE snapshot_date = ?
";
$params = [$fFecha];
$types  = "s";

if ($fSucursal !== '') {
  $sql .= " AND id_sucursal = ?";
  $params[] = (int)$fSucursal; $types .= "i";
}
if ($fImei !== '') {
  $sql .= " AND (imei1 LIKE ? OR imei2 LIKE ?)";
  $like = "%$fImei%";
  $params[] = $like; $params[] = $like; $types .= "ss";
}
if ($fTipo !== '') {
  $sql .= " AND tipo_producto = ?";
  $params[] = $fTipo; $types .= "s";
}
if ($fEstatus !== '') {
  $sql .= " AND estatus = ?";
  $params[] = $fEstatus; $types .= "s";
}
if ($fAntiguedad === '<30') {
  $sql .= " AND antiguedad_dias < 30";
} elseif ($fAntiguedad === '30-90') {
  $sql .= " AND antiguedad_dias BETWEEN 30 AND 90";
} elseif ($fAntiguedad === '>90') {
  $sql .= " AND antiguedad_dias > 90";
}
if ($fPrecioMin !== '') {
  $sql .= " AND precio_lista >= ?";
  $params[] = (float)$fPrecioMin; $types .= "d";
}
if ($fPrecioMax !== '') {
  $sql .= " AND precio_lista <= ?";
  $params[] = (float)$fPrecioMax; $types .= "d";
}

$sql .= " ORDER BY sucursal_nombre, fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) { die("Error: ".$conn->error); }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();

/* Armar datos y resumen por antigüedad */
$inventario = [];
$rangos = ['<30'=>0, '30-90'=>0, '>90'=>0];
while ($row = $rs->fetch_assoc()) {
  $inventario[] = $row;
  $d = (int)$row['antiguedad_dias'];
  if     ($d < 30)  $rangos['<30']++;
  elseif ($d <= 90) $rangos['30-90']++;
  else              $rangos['>90']++;
}
$stmt->close();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Inventario histórico</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <h2 class="m-0">🗓️ Inventario histórico</h2>
    <small class="text-muted">Retención: <?= $RETENCION_DIAS ?> días · Rango: <?= h($minFecha) ?> a <?= h($maxFecha) ?></small>
  </div>

  <?php if (empty($inventario)): ?>
    <div class="alert alert-info">
      No hay datos para la fecha <b><?= h($fFecha) ?></b> con los filtros actuales.
      <?php if (isset($_GET['fecha'])): ?>
        <div class="mt-2">Tip: prueba otra fecha dentro del rango de retención o limpia los filtros.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <p class="text-muted">Fecha consultada: <b><?= h($fFecha) ?></b> · Resultados: <b><?= count($inventario) ?></b></p>

  <!-- Filtros -->
  <form method="GET" class="card p-3 mb-3 shadow-sm bg-white">
    <div class="row g-3">
      <div class="col-md-2">
        <input type="date" name="fecha" class="form-control"
               value="<?= h($fFecha) ?>" min="<?= h($minFecha) ?>" max="<?= h($maxFecha) ?>" required>
      </div>
      <div class="col-md-2">
        <select name="sucursal" class="form-select">
          <option value="">Todas las sucursales</option>
          <?php while ($s = $sucursales->fetch_assoc()): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $fSucursal==$s['id']?'selected':'' ?>>
              <?= h($s['nombre']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="text" name="imei" class="form-control" placeholder="Buscar IMEI..." value="<?= h($fImei) ?>">
      </div>
      <div class="col-md-2">
        <select name="tipo_producto" class="form-select">
          <option value="">Todos los tipos</option>
          <option value="Equipo"    <?= $fTipo==='Equipo'?'selected':'' ?>>Equipo</option>
          <option value="Modem"     <?= $fTipo==='Modem'?'selected':'' ?>>Módem</option>
          <option value="Accesorio" <?= $fTipo==='Accesorio'?'selected':'' ?>>Accesorio</option>
        </select>
      </div>
      <div class="col-md-2">
        <select name="estatus" class="form-select">
          <option value="">Todos Estatus</option>
          <option value="Disponible"  <?= $fEstatus==='Disponible'?'selected':'' ?>>Disponible</option>
          <option value="En tránsito" <?= $fEstatus==='En tránsito'?'selected':'' ?>>En tránsito</option>
          <option value="Vendido"     <?= $fEstatus==='Vendido'?'selected':'' ?>>Vendido</option>
          <option value="Reservado"   <?= $fEstatus==='Reservado'?'selected':'' ?>>Reservado</option>
        </select>
      </div>
      <div class="col-md-2">
        <select name="antiguedad" class="form-select">
          <option value="">Antigüedad</option>
          <option value="<30"   <?= $fAntiguedad === '<30' ? 'selected' : '' ?>>< 30 días</option>
          <option value="30-90" <?= $fAntiguedad === '30-90' ? 'selected' : '' ?>>30–90 días</option>
          <option value=">90"   <?= $fAntiguedad === '>90' ? 'selected' : '' ?>>> 90 días</option>
        </select>
      </div>
      <div class="col-md-2">
        <input type="number" step="0.01" name="precio_min" class="form-control" placeholder="Precio min" value="<?= h($fPrecioMin) ?>">
      </div>
      <div class="col-md-2">
        <input type="number" step="0.01" name="precio_max" class="form-control" placeholder="Precio max" value="<?= h($fPrecioMax) ?>">
      </div>
      <div class="col-md-12 text-end">
        <button class="btn btn-primary">Filtrar</button>
        <a class="btn btn-secondary" href="inventario_historico.php?fecha=<?= urlencode($fFecha) ?>">Limpiar</a>
        <a class="btn btn-success" href="exportar_inventario_historico.php?<?= http_build_query($_GET) ?>">📊 Exportar Excel</a>
      </div>
    </div>
  </form>

  <!-- Resumen por antigüedad -->
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card border-success">
        <div class="card-body d-flex justify-content-between">
          <span><b>< 30 días</b></span><span><?= (int)$rangos['<30'] ?></span>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-warning">
        <div class="card-body d-flex justify-content-between">
          <span><b>30–90 días</b></span><span><?= (int)$rangos['30-90'] ?></span>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-danger">
        <div class="card-body d-flex justify-content-between">
          <span><b>> 90 días</b></span><span><?= (int)$rangos['>90'] ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card shadow">
    <div class="card-header bg-dark text-white">
      Inventario al <?= h($fFecha) ?>
    </div>
    <div class="card-body">
      <table id="tablaHist" class="table table-striped table-bordered table-sm">
        <thead class="table-dark">
          <tr>
            <th>ID Inv</th>
            <th>Sucursal</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>Código</th>
            <th>Color</th>
            <th>Capacidad</th>
            <th>IMEI1</th>
            <th>IMEI2</th>
            <th>Tipo</th>
            <th>Proveedor</th>
            <th>Costo c/IVA</th>
            <th>Precio Lista</th>
            <th>Profit</th>
            <th>Estatus</th>
            <th>Fecha Ingreso</th>
            <th>Antigüedad (días)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventario as $r): ?>
          <tr>
            <td><?= (int)$r['id_inventario'] ?></td>
            <td><?= h($r['sucursal_nombre']) ?></td>
            <td><?= h($r['marca']) ?></td>
            <td><?= h($r['modelo']) ?></td>
            <td><code><?= h($r['codigo_producto'] ?? '-') ?></code></td>
            <td><?= h($r['color']) ?></td>
            <td><?= h($r['capacidad'] ?? '-') ?></td>
            <td><?= h($r['imei1'] ?? '-') ?></td>
            <td><?= h($r['imei2'] ?? '-') ?></td>
            <td><?= h($r['tipo_producto']) ?></td>
            <td><?= h($r['proveedor'] ?? '-') ?></td>
            <td class="text-end">$<?= number_format((float)$r['costo_con_iva'], 2) ?></td>
            <td class="text-end">$<?= number_format((float)$r['precio_lista'], 2) ?></td>
            <td class="text-end">$<?= number_format((float)$r['profit'], 2) ?></td>
            <td><?= h($r['estatus']) ?></td>
            <td><?= h($r['fecha_ingreso']) ?></td>
            <td><b><?= (int)$r['antiguedad_dias'] ?></b></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
  $('#tablaHist').DataTable({
    pageLength: 25,
    order: [[0, 'desc']],
    language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' }
  });
});
</script>
</body>
</html>
