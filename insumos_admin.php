<?php
// insumos_admin.php — Gestión de Insumos (Admin) · UI Pro

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

// Asegurar zona horaria MX para el cálculo del "mes siguiente"
if (function_exists('date_default_timezone_set')) {
  date_default_timezone_set('America/Mexico_City');
}

/* =============================
   Periodo (mes/año) por defecto
   =============================
   Si NO vienen ambos parámetros en la URL, usamos el mes siguiente al día de hoy.
*/
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : null;
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : null;

if ($anio === null || $mes === null) {
  $dt = new DateTime('now');
  $dt->modify('first day of next month');
  $anio = (int)$dt->format('Y');
  $mes  = (int)$dt->format('n');
}

$whereSuc = "
  LOWER(s.tipo_sucursal) <> 'almacen'
  AND LOWER(COALESCE(s.subtipo,'')) NOT IN ('subdistribuidor','master admin')
";

/* ---------- util: headers excel con BOM ---------- */
function xls_headers($filename)
{
  // Cerrar cualquier buffer abierto para evitar que haya salida previa
  while (ob_get_level()) { ob_end_clean(); }
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header("Pragma: no-cache");
  header("Expires: 0");
  echo "\xEF\xBB\xBF"; // BOM UTF-8
}

/* =========================================================
   EXPORTS (antes de cualquier HTML o includes que impriman)
========================================================= */
$isExport = isset($_GET['export']) ? $_GET['export'] : null;

if ($isExport === 'xls_general') {
  $q = $conn->query("
    SELECT 
      COALESCE(cat.nombre,'Sin categoría') AS categoria,
      c.nombre, c.unidad,
      SUM(d.cantidad) AS total_cant
    FROM insumos_pedidos p
    INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
    INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
    LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
    INNER JOIN sucursales s ON s.id = p.id_sucursal
    WHERE p.anio = $anio AND p.mes = $mes
      AND p.estatus IN ('Enviado','Aprobado')
      AND $whereSuc
    GROUP BY categoria, c.nombre, c.unidad
    ORDER BY categoria, c.nombre
  ");

  xls_headers("insumos_concentrado_general_{$anio}_" . sprintf('%02d', $mes) . ".xls");
  echo "<table border='1'>";
  echo "<tr><th colspan='4'>Concentrado general — " . sprintf('%02d', $mes) . "/$anio</th></tr>";
  echo "<tr><th>Categoría</th><th>Insumo</th><th>Unidad</th><th>Cantidad Total</th></tr>";
  while ($r = $q->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['categoria']) . "</td>";
    echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
    echo "<td>" . htmlspecialchars($r['unidad']) . "</td>";
    echo "<td>" . number_format((float)$r['total_cant'], 2, '.', '') . "</td>";
    echo "</tr>";
  }
  echo "</table>";
  exit;
}

if ($isExport === 'xls_sucursales') {
  $q = $conn->query("
    SELECT 
      s.nombre AS sucursal,
      COALESCE(cat.nombre,'Sin categoría') AS categoria,
      c.nombre AS insumo, c.unidad,
      SUM(d.cantidad) AS total_cant
    FROM insumos_pedidos p
    INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
    INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
    LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
    INNER JOIN sucursales s ON s.id = p.id_sucursal
    WHERE p.anio = $anio AND p.mes = $mes
      AND p.estatus IN ('Enviado','Aprobado')
      AND $whereSuc
    GROUP BY s.nombre, categoria, insumo, c.unidad
    ORDER BY s.nombre, categoria, insumo
  ");

  xls_headers("insumos_concentrado_sucursales_{$anio}_" . sprintf('%02d', $mes) . ".xls");
  echo "<table border='1'>";
  echo "<tr><th colspan='5'>Concentrado por sucursal — " . sprintf('%02d', $mes) . "/$anio</th></tr>";
  echo "<tr><th>Sucursal</th><th>Categoría</th><th>Insumo</th><th>Unidad</th><th>Cantidad Total</th></tr>";
  while ($r = $q->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['sucursal']) . "</td>";
    echo "<td>" . htmlspecialchars($r['categoria']) . "</td>";
    echo "<td>" . htmlspecialchars($r['insumo']) . "</td>";
    echo "<td>" . htmlspecialchars($r['unidad']) . "</td>";
    echo "<td>" . number_format((float)$r['total_cant'], 2, '.', '') . "</td>";
    echo "</tr>";
  }
  echo "</table>";
  exit;
}

/* =================== VISTA NORMAL =================== */

// A partir de aquí ya podemos incluir navbar (imprime HTML)
require_once __DIR__ . '/navbar.php';

// Mensaje de acción
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['id_pedido'])) {
  $idp = (int)$_POST['id_pedido'];
  $map = ['aprobar' => 'Aprobado', 'rechazar' => 'Rechazado', 'surtido' => 'Surtido'];
  if (isset($map[$_POST['accion']])) {
    $nuevo = $map[$_POST['accion']];
    $st = $conn->prepare("UPDATE insumos_pedidos SET estatus=? WHERE id=?");
    $st->bind_param("si", $nuevo, $idp);
    $st->execute();
    $msg = "Pedido #$idp → $nuevo";
  }
}

/* Pedidos por sucursal (para tarjetas) */
$ped = $conn->query("
  SELECT p.*, s.nombre AS sucursal, s.tipo_sucursal, s.subtipo
  FROM insumos_pedidos p
  INNER JOIN sucursales s ON s.id = p.id_sucursal
  WHERE p.anio = $anio AND p.mes = $mes
    AND $whereSuc
  ORDER BY s.nombre, p.id DESC
");

/* Stats por estatus (KPIs) — incluye Borrador para contarlo en chips */
$statsRes = $conn->query("
  SELECT p.estatus, COUNT(*) AS c
  FROM insumos_pedidos p
  INNER JOIN sucursales s ON s.id = p.id_sucursal
  WHERE p.anio = $anio AND p.mes = $mes
    AND $whereSuc
  GROUP BY p.estatus
");
$stats = ['Borrador' => 0, 'Enviado' => 0, 'Aprobado' => 0, 'Rechazado' => 0, 'Surtido' => 0];
$totalPed = 0;
while ($r = $statsRes->fetch_assoc()) {
  $stats[$r['estatus']] = (int)$r['c'];
  $totalPed += (int)$r['c'];
}

/* Concentrado general (por insumo) — solo Enviado/Aprobado */
$conGeneral = $conn->query("
  SELECT 
    COALESCE(cat.nombre,'Sin categoría') AS categoria,
    c.nombre, c.unidad,
    SUM(d.cantidad) AS total_cant
  FROM insumos_pedidos p
  INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
  INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
  LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
  INNER JOIN sucursales s ON s.id = p.id_sucursal
  WHERE p.anio = $anio AND p.mes = $mes
    AND p.estatus IN ('Enviado','Aprobado')
    AND $whereSuc
  GROUP BY categoria, c.nombre, c.unidad
  ORDER BY categoria, c.nombre
");
$rowsGeneral = [];
$sumGeneral  = 0.0;
while ($r = $conGeneral->fetch_assoc()) {
  $rowsGeneral[] = $r;
  $sumGeneral += (float)$r['total_cant'];
}

/* Concentrado por sucursal (preview en tab) — solo Enviado/Aprobado */
$conSuc = $conn->query("
  SELECT 
    s.nombre AS sucursal,
    COALESCE(cat.nombre,'Sin categoría') AS categoria,
    c.nombre AS insumo, c.unidad,
    SUM(d.cantidad) AS total_cant
  FROM insumos_pedidos p
  INNER JOIN insumos_pedidos_detalle d ON d.id_pedido = p.id
  INNER JOIN insumos_catalogo c ON c.id = d.id_insumo
  LEFT JOIN insumos_categorias cat ON cat.id = c.id_categoria
  INNER JOIN sucursales s ON s.id = p.id_sucursal
  WHERE p.anio = $anio AND p.mes = $mes
    AND p.estatus IN ('Enviado','Aprobado')
    AND $whereSuc
  GROUP BY s.nombre, categoria, insumo, c.unidad
  ORDER BY s.nombre, categoria, insumo
");
$rowsSuc = [];
while ($r = $conSuc->fetch_assoc()) $rowsSuc[] = $r;

// Meses bonitos
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <title>Gestión de Insumos — Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.1/css/responsive.bootstrap5.min.css">
  <style>
    body { background: #f6f7fb; }
    .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title { font-weight:700; letter-spacing:.2px; margin:0; }
    .card-soft { border:1px solid #e9ecf1; border-radius:16px; box-shadow:0 2px 12px rgba(16,24,40,.06); }
    .chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:.85rem; border:1px solid #e2e8f0; }
    .pedido-card { border:1px solid #e9ecf1; border-radius:14px; box-shadow:0 2px 10px rgba(16,24,40,.06); }
    .badge-status { font-size:.78rem; }
    .filter-chip { cursor:pointer; user-select:none; }
    .filter-chip.active { background:#e8ecff; border-color:#cfd8ff; color:#1d2b7b; }
    .table thead th { white-space:nowrap; }
  </style>
</head>

<body>

  <div class="container-fluid px-3 px-lg-4">

    <!-- Encabezado -->
    <div class="page-head">
      <div>
        <h2 class="page-title">📦 Gestión de Insumos — Admin</h2>
        <div class="text-muted mt-1">Periodo: <strong><?= $meses[$mes] ?? $mes ?></strong> de <strong><?= $anio ?></strong></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-success btn-sm rounded-pill" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=xls_general">
          <i class="bi bi-file-earmark-excel me-1"></i> Excel — Concentrado general
        </a>
        <a class="btn btn-outline-secondary btn-sm rounded-pill" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=xls_sucursales">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel — Por sucursal
        </a>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card card-soft mb-3">
      <div class="card-header bg-white">
        <form class="row g-2 align-items-end">
          <div class="col-sm-3 col-md-2">
            <label class="form-label">Mes</label>
            <select name="mes" class="form-select">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>><?= $meses[$m] ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-sm-3 col-md-2">
            <label class="form-label">Año</label>
            <select name="anio" class="form-select">
              <?php for ($a = date('Y') - 1; $a <= date('Y') + 1; $a++): ?>
                <option value="<?= $a ?>" <?= $a == $anio ? 'selected' : '' ?>><?= $a ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-sm-3 col-md-2">
            <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Aplicar</button>
          </div>
          <div class="col-12 col-md text-md-end">
            <div class="d-inline-flex flex-wrap gap-2">
              <!-- Nuevo flujo: Activos por defecto (sin Borrador) -->
              <span class="chip filter-chip active" data-status="activos"><i class="bi bi-lightning-charge"></i>&nbsp;Activos (<?= max(0, $totalPed - $stats['Borrador']) ?>)</span>
              <span class="chip filter-chip" data-status="todos"><i class="bi bi-list-task"></i>&nbsp;Todos (<?= $totalPed ?>)</span>
              <span class="chip filter-chip" data-status="Enviado"><i class="bi bi-send"></i>&nbsp;Enviados (<?= $stats['Enviado'] ?>)</span>
              <span class="chip filter-chip" data-status="Aprobado"><i class="bi bi-check2-circle"></i>&nbsp;Aprobados (<?= $stats['Aprobado'] ?>)</span>
              <span class="chip filter-chip" data-status="Rechazado"><i class="bi bi-x-circle"></i>&nbsp;Rechazados (<?= $stats['Rechazado'] ?>)</span>
              <span class="chip filter-chip" data-status="Surtido"><i class="bi bi-box-seam"></i>&nbsp;Surtidos (<?= $stats['Surtido'] ?>)</span>
              <span class="chip filter-chip" data-status="Borrador"><i class="bi bi-pencil-square"></i>&nbsp;Borradores (<?= $stats['Borrador'] ?>)</span>
            </div>
          </div>
        </form>
      </div>
      <div class="card-body">
        <!-- KPIs -->
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="text-muted">Pedidos</div>
                <div class="fs-4 fw-bold"><?= $totalPed ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="text-muted">Enviados</div>
                <div class="fs-4 fw-bold text-primary"><?= $stats['Enviado'] ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="text-muted">Aprobados</div>
                <div class="fs-4 fw-bold text-success"><?= $stats['Aprobado'] ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <div class="text-muted">Líneas (conc. general)</div>
                <div class="fs-4 fw-bold"><?= number_format($sumGeneral, 2) ?></div>
              </div>
            </div>
          </div>
        </div>
        <!-- Buscador de pedidos -->
        <div class="mt-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input id="buscarPedido" class="form-control" placeholder="Buscar por sucursal, folio o comentario…">
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($msg)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t1" type="button" role="tab">
          Pedidos por sucursal
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#t2" type="button" role="tab">
          Concentrado general
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#t3" type="button" role="tab">
          Concentrado por sucursal
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- Pedidos por sucursal -->
      <div class="tab-pane fade show active" id="t1" role="tabpanel">
        <?php if ($ped->num_rows == 0): ?>
          <div class="text-muted">No hay pedidos en este periodo.</div>
        <?php else:
          $statusMap = [
            'Borrador'  => 'warning',
            'Enviado'   => 'primary',
            'Aprobado'  => 'success',
            'Rechazado' => 'secondary',
            'Surtido'   => 'info'
          ];
          while ($p = $ped->fetch_assoc()):
            $pid = (int)$p['id'];
            $est = $p['estatus'];
            $badge = $statusMap[$est] ?? 'secondary';
            $det = $conn->query("
              SELECT COALESCE(cat.nombre,'Sin categoría') AS categoria,
                     c.nombre, c.unidad, d.cantidad, d.comentario
              FROM insumos_pedidos_detalle d
              INNER JOIN insumos_catalogo c ON c.id=d.id_insumo
              LEFT JOIN insumos_categorias cat ON cat.id=c.id_categoria
              WHERE d.id_pedido={$p['id']}
              ORDER BY categoria, c.nombre
            ");
        ?>
            <div class="pedido-card mb-3"
                 data-status="<?= htmlspecialchars($est) ?>"
                 data-sucursal="<?= htmlspecialchars($p['sucursal']) ?>"
                 data-folio="<?= $pid ?>">
              <div class="p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-3">
                  <div class="fw-semibold">
                    <i class="bi bi-shop"></i> <?= htmlspecialchars($p['sucursal']) ?>
                    <span class="text-muted">· Pedido #<?= $pid ?></span>
                  </div>
                  <span class="badge bg-<?= $badge ?> badge-status"><?= htmlspecialchars($est) ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button class="btn btn-sm btn-light border toggle-detalle" type="button"
                          data-target="#det<?= $pid ?>">
                    <i class="bi bi-list-ul me-1"></i><span class="lbl">Detalle</span>
                  </button>
                  <?php if ($p['estatus'] === 'Enviado'): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="id_pedido" value="<?= $pid ?>">
                      <button class="btn btn-sm btn-outline-success" name="accion" value="aprobar">
                        <i class="bi bi-check2-circle"></i> Aprobar
                      </button>
                      <button class="btn btn-sm btn-outline-danger" name="accion" value="rechazar">
                        <i class="bi bi-x-circle"></i> Rechazar
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($p['estatus'] === 'Aprobado'): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('¿Marcar como surtido?');">
                      <input type="hidden" name="id_pedido" value="<?= $pid ?>">
                      <button class="btn btn-sm btn-primary" name="accion" value="surtido">
                        <i class="bi bi-box-seam"></i> Marcar surtido
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div class="collapse" id="det<?= $pid ?>">
                <div class="px-3 pb-3">
                  <?php if ($det->num_rows == 0): ?>
                    <div class="text-muted">Sin líneas.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-light">
                          <tr>
                            <th>Categoría</th>
                            <th>Insumo</th>
                            <th class="text-end">Cantidad</th>
                            <th>Unidad</th>
                            <th>Comentario</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php while ($r = $det->fetch_assoc()): ?>
                            <tr>
                              <td><?= htmlspecialchars($r['categoria']) ?></td>
                              <td><?= htmlspecialchars($r['nombre']) ?></td>
                              <td class="text-end"><?= number_format((float)$r['cantidad'], 2) ?></td>
                              <td><?= htmlspecialchars($r['unidad']) ?></td>
                              <td><?= htmlspecialchars($r['comentario']) ?></td>
                            </tr>
                          <?php endwhile; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
        <?php endwhile; endif; ?>
      </div>

      <!-- Concentrado general -->
      <div class="tab-pane fade" id="t2" role="tabpanel">
        <?php if (empty($rowsGeneral)): ?>
          <div class="text-muted">No hay concentrado (nada Enviado/Aprobado).</div>
        <?php else: ?>
          <div class="card card-soft">
            <div class="card-body">
              <div class="table-responsive">
                <table id="tablaGeneral" class="table table-hover align-middle nowrap" style="width:100%;">
                  <thead class="table-light">
                    <tr>
                      <th>Categoría</th>
                      <th>Insumo</th>
                      <th>Unidad</th>
                      <th class="text-end">Cantidad total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rowsGeneral as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r['categoria']) ?></td>
                        <td><?= htmlspecialchars($r['nombre']) ?></td>
                        <td><?= htmlspecialchars($r['unidad']) ?></td>
                        <td class="text-end"><?= number_format((float)$r['total_cant'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="text-end mt-2">
                <span class="chip"><i class="bi bi-calculator"></i>&nbsp;Total líneas: <strong><?= number_format($sumGeneral, 2) ?></strong></span>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Concentrado por sucursal -->
      <div class="tab-pane fade" id="t3" role="tabpanel">
        <?php if (empty($rowsSuc)): ?>
          <div class="text-muted">No hay concentrado por sucursal.</div>
        <?php else: ?>
          <div class="card card-soft">
            <div class="card-body">
              <div class="table-responsive">
                <table id="tablaSuc" class="table table-hover align-middle nowrap" style="width:100%;">
                  <thead class="table-light">
                    <tr>
                      <th>Sucursal</th>
                      <th>Categoría</th>
                      <th>Insumo</th>
                      <th>Unidad</th>
                      <th class="text-end">Cantidad total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rowsSuc as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r['sucursal']) ?></td>
                        <td><?= htmlspecialchars($r['categoria']) ?></td>
                        <td><?= htmlspecialchars($r['insumo']) ?></td>
                        <td><?= htmlspecialchars($r['unidad']) ?></td>
                        <td class="text-end"><?= number_format((float)$r['total_cant'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="text-end mt-2">
                <a class="btn btn-outline-secondary btn-sm rounded-pill" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=xls_sucursales">
                  <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar Excel
                </a>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /tab-content -->

  </div><!-- /container -->

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- Si tu navbar no incluye Bootstrap JS, descomenta la siguiente línea -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.1/js/dataTables.responsive.min.js"></script>
  <script>
    try { document.title = 'Gestión de Insumos — Admin'; } catch (e) {}

    // DataTables para concentrados
    $(function() {
      if ($('#tablaGeneral').length) {
        $('#tablaGeneral').DataTable({
          pageLength: 25,
          order: [[0, 'asc'], [1, 'asc']],
          responsive: true,
          language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
          columnDefs: [{ targets: 3, className: 'text-end' }]
        });
      }

      if ($('#tablaSuc').length) {
        $('#tablaSuc').DataTable({
          pageLength: 25,
          order: [[0, 'asc'], [1, 'asc'], [2, 'asc']],
          responsive: true,
          language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
          columnDefs: [{ targets: 4, className: 'text-end' }]
        });
      }
    });

    // Filtros por estatus (chips) + buscador
    const chips = document.querySelectorAll('.filter-chip');
    const cards = document.querySelectorAll('.pedido-card');
    const buscar = document.getElementById('buscarPedido');

    // Por defecto: "activos" (ocultar Borrador)
    let filtroStatus = 'activos';
    let filtroText = '';

    chips.forEach(ch => ch.addEventListener('click', () => {
      chips.forEach(c => c.classList.remove('active'));
      ch.classList.add('active');
      filtroStatus = ch.dataset.status || 'activos';
      aplicarFiltros();
    }));

    buscar && buscar.addEventListener('input', () => {
      filtroText = (buscar.value || '').toLowerCase();
      aplicarFiltros();
    });

    function aplicaTexto(card) {
      if (!filtroText) return true;
      const suc = (card.dataset.sucursal || '').toLowerCase();
      const fol = String(card.dataset.folio || '').toLowerCase();
      const txt = (suc + ' ' + fol);
      return txt.includes(filtroText);
    }

    function aplicaStatus(card) {
      const st = card.dataset.status;
      switch (filtroStatus) {
        case 'activos':   return st !== 'Borrador';
        case 'todos':     return true;
        case 'Borrador':  return st === 'Borrador';
        case 'Enviado':
        case 'Aprobado':
        case 'Rechazado':
        case 'Surtido':   return st === filtroStatus;
        default:          return true;
      }
    }

    function aplicarFiltros() {
      let visibles = 0;
      cards.forEach(card => {
        const ok = aplicaTexto(card) && aplicaStatus(card);
        card.style.display = ok ? '' : 'none';
        if (ok) visibles++;
      });
    }

    // Toggle robusto de detalles (si Bootstrap JS está presente)
    document.querySelectorAll('.toggle-detalle').forEach(btn => {
      const sel = btn.getAttribute('data-target');
      const el = document.querySelector(sel);
      if (!el) return;

      if (typeof bootstrap === 'undefined' || !bootstrap.Collapse) return;

      const inst = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });

      btn.addEventListener('click', () => {
        const abierto = el.classList.contains('show');
        if (abierto) { inst.hide(); } else { inst.show(); }
        btn.innerHTML = abierto
          ? '<i class="bi bi-list-ul me-1"></i><span class="lbl">Detalle</span>'
          : '<i class="bi bi-chevron-up me-1"></i><span class="lbl">Ocultar</span>';
      });
    });

    // Aplicar filtros al cargar (para esconder borradores de inicio)
    aplicarFiltros();
  </script>

</body>
</html>
