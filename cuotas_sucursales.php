<?php
// cuotas_sucursales.php — LUGA
// Vista optimizada y bonita para cuotas por sucursal (solo Tiendas)
// - Tarjetas por sucursal con cuota vigente destacada
// - Histórico por sucursal en acordeón colapsable
// - Buscador, paginación por sucursal y toggle "Solo vigentes"
// Requisitos: PHP 7.4+, MySQL 5.7/8.0, Bootstrap 5

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Admin','Gerente General'])) {
    header("Location: index.php"); exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$'.number_format((float)$n, 2); }
function fmtDate($ymd){ return $ymd ? date('d/m/Y', strtotime($ymd)) : ''; }

// ===== Filtros UI =====
$q         = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$pageSize  = max(6, min(48, (int)($_GET['per_page'] ?? 12))); // por tarjetas
$onlyVig   = isset($_GET['vig']) ? (($_GET['vig'] === '1') ? 1 : 0) : 1; // default: solo vigentes visible

// ===== 1) Contar sucursales (para paginación) =====
$sqlCount = "SELECT COUNT(*) AS total
             FROM sucursales
             WHERE tipo_sucursal='Tienda' ".
            ($q !== '' ? "AND nombre LIKE ?" : "");

$stmt = $conn->prepare($sqlCount);
if ($q !== '') {
    $like = "%$q%";
    $stmt->bind_param('s', $like);
}
$stmt->execute();
$totalSuc = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$pages = max(1, (int)ceil($totalSuc / $pageSize));
$page  = min($page, $pages);
$offset = ($page - 1) * $pageSize;

// ===== 2) Traer sucursales de la página =====
$sqlSuc = "SELECT id, nombre
           FROM sucursales
           WHERE tipo_sucursal='Tienda' ".
          ($q !== '' ? "AND nombre LIKE ? " : "") .
          "ORDER BY nombre
           LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sqlSuc);
if ($q !== '') {
    $stmt->bind_param('sii', $like, $pageSize, $offset);
} else {
    $stmt->bind_param('ii', $pageSize, $offset);
}
$stmt->execute();
$resSuc = $stmt->get_result();
$sucursales = [];
$sucIds = [];
while ($r = $resSuc->fetch_assoc()) {
    $sucursales[] = $r;
    $sucIds[] = (int)$r['id'];
}
$stmt->close();

// Early exit si no hay sucursales que mostrar
if (empty($sucIds)) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Gestión de Cuotas de Sucursales</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
    <div class="container mt-4">
        <h2 class="mb-3">📋 Gestión de Cuotas de Sucursales (solo Tiendas)</h2>
        <div class="d-flex gap-2 mb-3">
            <a href="nueva_cuota_sucursal.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Nueva Cuota
            </a>
            <a href="cuotas_sucursales.php" class="btn btn-outline-secondary">Limpiar filtros</a>
        </div>
        <div class="alert alert-info">
            No se encontraron sucursales que coincidan con tu búsqueda.
        </div>
    </div>
    </body>
    </html>
    <?php
    exit();
}

// ===== 3) Traer todas las cuotas de esas sucursales (histórico completo) =====
$inPlaceholders = implode(',', array_fill(0, count($sucIds), '?'));
$types = str_repeat('i', count($sucIds));

$sqlCuotas = "SELECT cs.id, cs.id_sucursal, cs.cuota_monto, cs.fecha_inicio
              FROM cuotas_sucursales cs
              WHERE cs.id_sucursal IN ($inPlaceholders)
              ORDER BY cs.id_sucursal, cs.fecha_inicio DESC";

$stmt = $conn->prepare($sqlCuotas);
$stmt->bind_param($types, ...$sucIds);
$stmt->execute();
$resCuotas = $stmt->get_result();

// Agrupar por sucursal y detectar vigente
$map = []; // id_sucursal => ['nombre'=>, 'vigente'=>row, 'hist'=>[rows...]]
foreach ($sucursales as $s) {
    $map[(int)$s['id']] = [
        'nombre'  => $s['nombre'],
        'vigente' => null,
        'hist'    => []
    ];
}
while ($r = $resCuotas->fetch_assoc()) {
    $sid = (int)$r['id_sucursal'];
    if (!isset($map[$sid])) continue; // solo por seguridad
    if ($map[$sid]['vigente'] === null) {
        $map[$sid]['vigente'] = $r; // primera fila por sucursal = más reciente
    } else {
        $map[$sid]['hist'][] = $r; // resto es histórico
    }
}
$stmt->close();

// Utilería para armar URL de paginación/filtros
function buildUrl($params = []){
    $base = 'cuotas_sucursales.php';
    $curr = $_GET;
    foreach ($params as $k=>$v) {
        if ($v === null) unset($curr[$k]);
        else $curr[$k] = $v;
    }
    $qstr = http_build_query($curr);
    return $base . ($qstr ? ('?' . $qstr) : '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Cuotas de Sucursales</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  .toolbar {
    position: sticky; top: 0; z-index: 100; backdrop-filter: blur(4px);
    background: rgba(248,249,250,0.85); border-bottom: 1px solid #eee;
  }
  .quota-card { border: 1px solid #eaeaea; }
  .quota-card .card-header { background: #fff; }
  .quota-pill {
    font-weight: 700; font-size: 1.35rem; line-height: 1; padding: .4rem .6rem;
    border-radius: .75rem; background: #f5f7ff; border: 1px solid #e3e8ff;
  }
  .hist-row.vigente { background: #e9f7ef; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
</style>
</head>
<body class="bg-light">

<div class="toolbar py-3">
  <div class="container d-flex flex-wrap align-items-center gap-2">
    <h2 class="m-0 me-auto">📋 Gestión de Cuotas de Sucursales</h2>
    <a href="nueva_cuota_sucursal.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i> Nueva Cuota
    </a>
    <a href="<?= h(buildUrl(['q'=>null,'page'=>1,'vig'=>1])) ?>" class="btn btn-outline-secondary">
      Limpiar filtros
    </a>
  </div>
  <div class="container mt-2">
    <form class="row g-2 align-items-end" method="get" action="cuotas_sucursales.php">
      <div class="col-sm-6 col-md-5 col-lg-4">
        <label class="form-label">Buscar sucursal</label>
        <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Ej. Metepec, Centro, Reforma...">
      </div>
      <div class="col-sm-3 col-md-2">
        <label class="form-label">Por página</label>
        <select name="per_page" class="form-select">
          <?php foreach ([6,12,18,24,36,48] as $p): ?>
            <option value="<?= $p ?>" <?= $p===$pageSize?'selected':'' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3 col-md-3 d-flex align-items-center">
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="ckOnlyVig" <?= $onlyVig ? 'checked' : '' ?>>
          <label class="form-check-label" for="ckOnlyVig">Solo vigentes</label>
        </div>
        <input type="hidden" name="vig" id="vigInput" value="<?= (int)$onlyVig ?>">
      </div>
      <div class="col-sm-12 col-md-2">
        <button class="btn btn-dark w-100">Aplicar</button>
      </div>
    </form>
    <div class="small text-muted mt-2">
      Mostrando <strong><?= count($sucursales) ?></strong> de <strong><?= $totalSuc ?></strong> sucursales (pág. <?= $page ?> de <?= $pages ?>)
    </div>
  </div>
</div>

<div class="container my-4">

  <!-- Grid de tarjetas por sucursal -->
  <div class="grid">
    <?php foreach ($map as $sid => $info): ?>
      <?php $vig = $info['vigente']; $hist = $info['hist']; ?>
      <div class="card quota-card shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="fw-semibold"><?= h($info['nombre']) ?></div>
          <span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i>Vigente</span>
        </div>
        <div class="card-body">
          <?php if ($vig): ?>
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Cuota (monto semanal/mes):</div>
                <div class="quota-pill"><?= money($vig['cuota_monto']) ?></div>
              </div>
              <div class="text-end">
                <div class="text-muted small">Desde</div>
                <div class="fw-semibold"><?= fmtDate($vig['fecha_inicio']) ?></div>
              </div>
            </div>
          <?php else: ?>
            <div class="text-muted">Sin cuota registrada.</div>
          <?php endif; ?>

          <hr>

          <?php $hid = 'hist_'.$sid; $countHist = count($hist); ?>
          <button class="btn btn-outline-primary btn-sm w-100 d-flex align-items-center justify-content-center"
                  type="button" data-bs-toggle="collapse" data-bs-target="#<?= $hid ?>" aria-expanded="false">
            <i class="bi bi-clock-history me-2"></i> Historial <?= $countHist > 0 ? '(' . $countHist . ')' : '' ?>
          </button>

          <div class="collapse mt-2 hist-container <?= $onlyVig ? '' : 'show' ?>" id="<?= $hid ?>">
            <?php if ($countHist === 0): ?>
              <div class="text-muted small">Sin registros históricos.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr class="table-light">
                      <th style="width: 45%">Cuota</th>
                      <th style="width: 35%">Inicio</th>
                      <th style="width: 20%">Estado</th>
                    </tr>
                  </thead>
                  <tbody>
                    <!-- Vigente (también lo listamos arriba de históricos para contexto visual) -->
                    <?php if ($vig): ?>
                    <tr class="hist-row vigente">
                      <td class="fw-semibold"><?= money($vig['cuota_monto']) ?></td>
                      <td><?= fmtDate($vig['fecha_inicio']) ?></td>
                      <td><span class="badge text-bg-success">Vigente</span></td>
                    </tr>
                    <?php endif; ?>
                    <!-- Históricos -->
                    <?php foreach ($hist as $row): ?>
                      <tr>
                        <td><?= money($row['cuota_monto']) ?></td>
                        <td><?= fmtDate($row['fecha_inicio']) ?></td>
                        <td><span class="badge text-bg-secondary">Histórica</span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

        </div>
        <div class="card-footer d-flex gap-2">
          <a href="nueva_cuota_sucursal.php?id_sucursal=<?= (int)$sid ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Nueva cuota para esta sucursal
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Paginación -->
  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= h(buildUrl(['page'=>max(1,$page-1)])) ?>">Anterior</a>
      </li>
      <?php
      // paginación compacta
      $win = 2;
      $start = max(1, $page - $win);
      $end   = min($pages, $page + $win);
      if ($start > 1) {
          echo '<li class="page-item"><a class="page-link" href="'.h(buildUrl(['page'=>1])).'">1</a></li>';
          if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
      }
      for ($i=$start; $i<=$end; $i++){
          $active = $i===$page ? 'active' : '';
          echo '<li class="page-item '.$active.'"><a class="page-link" href="'.h(buildUrl(['page'=>$i])).'">'.$i.'</a></li>';
      }
      if ($end < $pages) {
          if ($end < $pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          echo '<li class="page-item"><a class="page-link" href="'.h(buildUrl(['page'=>$pages])).'">'.$pages.'</a></li>';
      }
      ?>
      <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
        <a class="page-link" href="<?= h(buildUrl(['page'=>min($pages,$page+1)])) ?>">Siguiente</a>
      </li>
    </ul>
  </nav>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Toggle "Solo vigentes" sincroniza input hidden y colapsa/expande historial
const ck = document.getElementById('ckOnlyVig');
const vigInput = document.getElementById('vigInput');
if (ck && vigInput) {
  ck.addEventListener('change', () => {
    vigInput.value = ck.checked ? '1' : '0';
    // Sin recargar: colapsar/expandir todos los históricos
    document.querySelectorAll('.hist-container').forEach(el => {
      const c = bootstrap.Collapse.getOrCreateInstance(el);
      if (ck.checked) c.hide(); else c.show();
    });
  });
}
</script>
</body>
</html>
