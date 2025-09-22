<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// 🔹 Filtro por IMEI (BACKEND: igual que tu versión)
$filtroImei = $_GET['imei'] ?? '';
$where  = "WHERE i.estatus IN ('Disponible','En tránsito')";
$params = [];
$types  = "";

if ($rol !== 'Admin') {
    $where   .= " AND i.id_sucursal = ?";
    $params[] = $id_sucursal;
    $types   .= "i";
}

if (!empty($filtroImei)) {
    $where   .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $filtroLike = "%$filtroImei%";
    $params[] = $filtroLike;
    $params[] = $filtroLike;
    $types   .= "ss";
}

// 🔹 Consulta inventario (BACKEND: misma estructura)
$sql = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad,
           p.imei1, p.imei2, i.estatus, i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    $where
    ORDER BY i.fecha_ingreso DESC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

// Pasamos a arreglo para calcular resúmenes sin tocar la DB
$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$totalEquipos = count($rows);

// 🔹 Resúmenes (solo front)
$porEstatus = ['Disponible'=>0,'En tránsito'=>0];
foreach ($rows as $r) {
  $st = $r['estatus'] ?? '';
  if (isset($porEstatus[$st])) $porEstatus[$st]++;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Inventario de Sucursal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#eef2ff; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .copy-btn{ border:1px dashed rgba(0,0,0,.2); }
    .kpi{ font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>

<div class="container py-3">
  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">📦 Inventario de Sucursal</h1>
      <div class="small-muted">
        Usuario: <strong><?= h($_SESSION['nombre'] ?? '') ?></strong>
        <?php if ($rol !== 'Admin'): ?> · Sucursal ID: <strong><?= (int)$id_sucursal ?></strong><?php endif; ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip chip-success"><i class="bi bi-check2-circle"></i> Disp.: <?= (int)$porEstatus['Disponible'] ?></span>
      <span class="chip chip-warn"><i class="bi bi-truck"></i> En tránsito: <?= (int)$porEstatus['En tránsito'] ?></span>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mt-1">
    <div class="col-12 col-md-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="small-muted">Total de equipos</div>
            <div class="h4 m-0 kpi"><?= (int)$totalEquipos ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-phone"></i></div>
          <div>
            <div class="small-muted">Disponibles</div>
            <div class="h4 m-0 kpi"><?= (int)$porEstatus['Disponible'] ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-truck"></i></div>
          <div>
            <div class="small-muted">En tránsito</div>
            <div class="h4 m-0 kpi"><?= (int)$porEstatus['En tránsito'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="card card-surface p-3 mt-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-funnel me-2"></i>Filtros</h5>
      <div class="small-muted">La búsqueda por IMEI se hace en el servidor. Los demás filtros son en vivo (front).</div>
    </div>

    <div class="row g-3 mt-1 filters">
      <div class="col-md-4">
        <label class="small-muted">Buscar por IMEI</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
          <input type="text" name="imei" class="form-control" placeholder="Ej. 3567..." value="<?= h($filtroImei) ?>">
        </div>
      </div>
      <div class="col-md-4">
        <label class="small-muted">Búsqueda rápida</label>
        <input id="qFront" type="text" class="form-control" placeholder="Filtra por marca, modelo, color, capacidad…">
      </div>
      <div class="col-md-4">
        <label class="small-muted">Estatus</label>
        <select id="statusFront" class="form-select">
          <option value="">Todos</option>
          <option value="Disponible">Disponible</option>
          <option value="En tránsito">En tránsito</option>
        </select>
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <button class="btn btn-primary"><i class="bi bi-search"></i> Buscar</button>
      <a href="panel.php" class="btn btn-secondary">Limpiar</a>
      <a href="exportar_inventario_excel.php" class="btn btn-success">
        <i class="bi bi-file-earmark-excel"></i> Exportar a Excel
      </a>
    </div>
  </form>

  <!-- Tabla -->
  <div class="card card-surface mt-3 mb-5">
    <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-table me-2"></i>Inventario</h5>
      <div class="small-muted">Mostrando <span id="countVisible"><?= (int)$totalEquipos ?></span> de <?= (int)$totalEquipos ?> equipos</div>
    </div>
    <div class="p-3 pt-2 tbl-wrap">
      <table id="tablaInv" class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="min-width:90px;">ID</th>
            <th style="min-width:120px;">Marca</th>
            <th style="min-width:140px;">Modelo</th>
            <th>Color</th>
            <th>Capacidad</th>
            <th style="min-width:210px;">IMEI1</th>
            <th style="min-width:210px;">IMEI2</th>
            <th style="min-width:130px;">Estatus</th>
            <th style="min-width:150px;">Fecha Ingreso</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): 
            $estatus = $row['estatus'] ?? '';
            $chip = $estatus === 'Disponible' ? 'chip-success' : 'chip-warn';
          ?>
            <tr data-status="<?= h($estatus) ?>">
              <td><span class="badge text-bg-secondary">#<?= (int)$row['id'] ?></span></td>
              <td><?= h($row['marca'] ?? '') ?></td>
              <td><?= h($row['modelo'] ?? '') ?></td>
              <td><?= h($row['color'] ?? '') ?></td>
              <td><?= h($row['capacidad'] ?? '-') ?></td>
              <td>
                <?php if (!empty($row['imei1'])): ?>
                  <div class="d-flex align-items-center gap-2">
                    <span class="font-monospace"><?= h($row['imei1']) ?></span>
                    <button type="button" class="btn btn-sm btn-soft copy-btn" data-copy="<?= h($row['imei1']) ?>" title="Copiar IMEI1">
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </div>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($row['imei2'])): ?>
                  <div class="d-flex align-items-center gap-2">
                    <span class="font-monospace"><?= h($row['imei2']) ?></span>
                    <button type="button" class="btn btn-sm btn-soft copy-btn" data-copy="<?= h($row['imei2']) ?>" title="Copiar IMEI2">
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </div>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="chip <?= $chip ?>">
                  <?php if ($estatus === 'Disponible'): ?><i class="bi bi-check2-circle"></i><?php else: ?><i class="bi bi-truck"></i><?php endif; ?>
                  <?= h($estatus) ?>
                </span>
              </td>
              <td><?= h($row['fecha_ingreso']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Copiar IMEI
document.querySelectorAll('.copy-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const val = btn.getAttribute('data-copy') || '';
    try {
      await navigator.clipboard.writeText(val);
      btn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
      setTimeout(() => btn.innerHTML = '<i class="bi bi-clipboard"></i>', 1200);
    } catch(e) { /* noop */ }
  });
});

// Filtros en vivo (front)
(() => {
  const qInput = document.getElementById('qFront');
  const stSel  = document.getElementById('statusFront');
  const rows   = Array.from(document.querySelectorAll('#tablaInv tbody tr'));
  const countEl= document.getElementById('countVisible');
  const norm = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
  function apply(){
    const q  = norm(qInput.value);
    const st = stSel.value;
    let visible = 0;
    rows.forEach(tr => {
      const text = norm(tr.innerText);
      const matchQ = !q || text.includes(q);
      const matchS = !st || (tr.getAttribute('data-status') === st);
      const show = matchQ && matchS;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    countEl.textContent = visible;
  }
  qInput.addEventListener('input', apply);
  stSel.addEventListener('change', apply);
})();
</script>
</body>
</html>
