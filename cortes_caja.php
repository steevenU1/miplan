<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once 'db.php';

$rol        = $_SESSION['rol'] ?? '';
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

/* ==============================
   Permisos:
   - Admin / Super / Gerente / GerenteSucursal => SIEMPRE
   - Ejecutivo => SOLO si su sucursal NO tiene gerente activo
================================= */
$hayGerente = true; // por seguridad, asumir que sí hay gerente
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

$allow =
    in_array($rol, ['Admin','Super','Gerente','GerenteSucursal'], true) ||
    ($rol === 'Ejecutivo' && !$hayGerente);

if (!$allow) {
    header("Location: 403.php");
    exit();
}

// -------------------------------------------------------------

include 'navbar.php';

// Puedes seguir usando $idSucursal que ya está seteado arriba
$hoy = date('Y-m-d');

// 🔹 1️⃣ Consultar cortes de la sucursal (igual que tu versión)
$sqlCortes = "
    SELECT cc.*, u.nombre AS usuario
    FROM cortes_caja cc
    INNER JOIN usuarios u ON u.id = cc.id_usuario
    WHERE cc.id_sucursal = ?
    ORDER BY cc.fecha_operacion DESC
";
$stmt = $conn->prepare($sqlCortes);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$cortes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 🔹 2️⃣ Detectar si hay cortes pendientes (igual que tu versión)
$hayPendiente = false;
foreach ($cortes as $c) {
    if (($c['estado'] ?? '') === 'Pendiente') { $hayPendiente = true; break; }
}

// 🔹 3️⃣ Datos de resumen para tarjetas (presentación; no cambia tu lógica)
$totalCortes = count($cortes);
$pendientesCount = 0;
$validadosCount  = 0;
$sumPendiente    = 0.0; // sumatoria de total_general de pendientes (referencial/visual)
$sumValidado     = 0.0;

foreach ($cortes as $c) {
    $esPend  = ($c['estado'] ?? '') === 'Pendiente';
    $totalG  = (float)($c['total_general'] ?? 0);
    if ($esPend) { $pendientesCount++; $sumPendiente += $totalG; }
    else         { $validadosCount++;  $sumValidado  += $totalG; }
}

$ultimoCorteFecha = $totalCortes ? ($cortes[0]['fecha_corte'] ?: '—') : '—';
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Historial de Cortes de Caja</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --surface: #ffffff;
      --muted: #6b7280;
    }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color: var(--muted); font-size:.92rem; }
    .card-surface{
      background: var(--surface);
      border: 1px solid rgba(0,0,0,.05);
      box-shadow: 0 6px 16px rgba(16,24,40,.06);
      border-radius: 18px;
    }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{
      width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#eef2ff;
    }
    .chip{
      display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem;
      border:1px solid transparent;
    }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }
    .chip-pending{ background:#eef2ff; color:#3f51b5; border-color:#dfe3ff; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
  </style>
</head>
<body>
<div class="container py-3">

  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">💰 Historial de Cortes de Caja</h1>
      <div class="small-muted">Usuario: <strong><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></strong></div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="chip chip-pending"><i class="bi bi-clipboard-check"></i> Cortes: <?= (int)$totalCortes ?></span>
      <span class="chip chip-warn"><i class="bi bi-hourglass-split"></i> Pendientes: <?= (int)$pendientesCount ?></span>
      <span class="chip chip-success"><i class="bi bi-check2-circle"></i> Validados: <?= (int)$validadosCount ?></span>
    </div>
  </div>

  <!-- Aviso pendiente -->
  <?php if ($hayPendiente): ?>
    <div class="alert alert-warning card-surface mt-3 mb-0">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><strong>Atención:</strong> Existen cortes pendientes de validación o depósito.</div>
        </div>
        <div class="d-flex gap-2">
          <a href="generar_corte.php" class="btn btn-warning btn-sm">
            <i class="bi bi-journal-plus me-1"></i> Generar corte pendiente
          </a>
          <a href="depositos_sucursal.php" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-cash-coin me-1"></i> Ir a depósitos
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Tarjetas resumen -->
  <div class="row g-3 mt-3">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-collection"></i></div>
          <div>
            <div class="small-muted">Total de cortes</div>
            <div class="h4 m-0"><?= (int)$totalCortes ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-hourglass-split"></i></div>
          <div>
            <div class="small-muted">Pendientes</div>
            <div class="h4 m-0"><?= (int)$pendientesCount ?></div>
            <div class="small-muted">Total (ref): $<?= number_format($sumPendiente, 2) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-check2-circle"></i></div>
          <div>
            <div class="small-muted">Validados</div>
            <div class="h4 m-0"><?= (int)$validadosCount ?></div>
            <div class="small-muted">Total (ref): $<?= number_format($sumValidado, 2) ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card card-surface p-3">
        <div class="stat">
          <div class="icon"><i class="bi bi-calendar-check"></i></div>
          <div>
            <div class="small-muted">Último corte</div>
            <div class="h5 m-0"><?= htmlspecialchars($ultimoCorteFecha) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Controles -->
  <div class="card card-surface p-3 mt-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h4 class="m-0"><i class="bi bi-table me-2"></i>Listado de cortes</h4>
      <div class="filters d-flex align-items-center gap-2">
        <input id="searchInput" class="form-control" type="search" placeholder="Buscar por usuario, fechas o ID…">
        <select id="estadoFilter" class="form-select">
          <option value="">Estado: Todos</option>
          <option value="Pendiente">Pendiente</option>
          <option value="Validado">Validado</option>
        </select>
        <a href="generar_corte.php" class="btn btn-soft">
          <i class="bi bi-journal-plus me-1"></i> Generar corte
        </a>
      </div>
    </div>

    <?php if (empty($cortes)): ?>
      <div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Aún no hay cortes generados para esta sucursal.</div>
    <?php else: ?>
      <div class="tbl-wrap mt-3">
        <table id="tablaCortes" class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="min-width:120px;">ID Corte</th>
              <th>Fecha Operación</th>
              <th>Fecha Generado</th>
              <th>Usuario</th>
              <th>Total Efectivo</th>
              <th>Total Tarjeta</th>
              <th>Total General</th>
              <th>Estado</th>
              <th style="min-width:150px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cortes as $c): ?>
              <tr data-estado="<?= htmlspecialchars($c['estado']) ?>">
                <td><span class="badge text-bg-secondary">#<?= (int)$c['id'] ?></span></td>
                <td><?= htmlspecialchars($c['fecha_operacion']) ?></td>
                <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                <td><?= htmlspecialchars($c['usuario']) ?></td>
                <td>$<?= number_format((float)$c['total_efectivo'], 2) ?></td>
                <td>$<?= number_format((float)$c['total_tarjeta'], 2) ?></td>
                <td class="fw-semibold">$<?= number_format((float)$c['total_general'], 2) ?></td>
                <td>
                  <?php if (($c['estado'] ?? '') === 'Pendiente'): ?>
                    <span class="chip chip-warn"><i class="bi bi-hourglass-split"></i> Pendiente</span>
                  <?php else: ?>
                    <span class="chip chip-success"><i class="bi bi-check2-circle"></i> Validado</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="detalle_corte.php?id_corte=<?= (int)$c['id'] ?>" class="btn btn-soft btn-sm">
                    <i class="bi bi-search"></i> Ver detalle
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(() => {
  const searchInput  = document.getElementById('searchInput');
  const estadoFilter = document.getElementById('estadoFilter');
  const rows         = Array.from(document.querySelectorAll('#tablaCortes tbody tr'));

  function normaliza(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,''); }

  function filtrar(){
    const q = normaliza(searchInput.value);
    const est = (estadoFilter.value || '');

    rows.forEach(tr => {
      const text = normaliza(tr.innerText);
      const estado = tr.getAttribute('data-estado') || '';
      const matchQ = !q || text.includes(q);
      const matchE = !est || estado === est;
      tr.style.display = (matchQ && matchE) ? '' : 'none';
    });
  }

  searchInput.addEventListener('input', filtrar);
  estadoFilter.addEventListener('change', filtrar);
})();
</script>
</body>
</html>
