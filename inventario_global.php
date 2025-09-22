<?php
// inventario_global.php — LUGA (RAM + "Almacenamiento", fecha sin hora, tabla compacta)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona', 'Logistica'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

// No se puede editar precio desde esta vista
$canEditPrice = false;

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
require_once __DIR__.'/verificar_sesion.php';

// ===== Helpers =====
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

// ===== Filtros =====
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';
$filtroModelo     = $_GET['modelo']      ?? ''; // <<< NUEVO FILTRO

$sql = "
  SELECT i.id AS id_inventario,
         s.id AS id_sucursal,
         s.nombre AS sucursal,
         p.id AS id_producto,
         p.marca, p.modelo, p.color, p.ram, p.capacidad,
         p.imei1, p.imei2,
         p.costo,
         p.costo_con_iva,
         p.precio_lista,
         p.proveedor,
         p.codigo_producto,
         p.tipo_producto,
         (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
         i.estatus, i.fecha_ingreso,
         TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE i.estatus IN ('Disponible','En tránsito')
";

$params = [];
$types  = "";

if ($filtroSucursal !== '') {
  $sql .= " AND s.id = ?";
  $params[] = (int)$filtroSucursal; $types .= "i";
}
if ($filtroImei !== '') {
  $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
  $like = "%$filtroImei%"; $params[] = $like; $params[] = $like; $types .= "ss";
}
// --- NUEVO: Filtro por modelo (LIKE, coincidencia parcial) ---
if ($filtroModelo !== '') {
  $sql .= " AND p.modelo LIKE ?";
  $params[] = "%$filtroModelo%"; $types .= "s";
}
if ($filtroEstatus !== '') {
  $sql .= " AND i.estatus = ?"; $params[] = $filtroEstatus; $types .= "s";
}
if ($filtroAntiguedad == '<30') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
  $sql .= " AND p.precio_lista >= ?"; $params[] = (float)$filtroPrecioMin; $types .= "d";
}
if ($filtroPrecioMax !== '') {
  $sql .= " AND p.precio_lista <= ?"; $params[] = (float)$filtroPrecioMax; $types .= "d";
}

$sql .= " ORDER BY s.nombre ASC, i.fecha_ingreso ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// Agregados
$rangos = ['<30' => 0, '30-90' => 0, '>90' => 0];
$inventario = [];

$total=0; $sumAntiguedad=0; $sumPrecio=0.0; $sumProfit=0.0; $cntDisp=0; $cntTrans=0;

while ($row = $result->fetch_assoc()) {
  $inventario[] = $row;
  $dias = (int)$row['antiguedad_dias'];
  if ($dias < 30) $rangos['<30']++;
  elseif ($dias <= 90) $rangos['30-90']++;
  else $rangos['>90']++;

  $total++;
  $sumAntiguedad += $dias;
  $sumPrecio  += (float)$row['precio_lista'];
  $sumProfit  += (float)$row['profit'];
  if ($row['estatus'] === 'Disponible') $cntDisp++;
  if ($row['estatus'] === 'En tránsito') $cntTrans++;
}

$promAntiguedad = $total ? round($sumAntiguedad / $total, 1) : 0;
$promPrecio     = $total ? round($sumPrecio / $total, 2) : 0.0;
$promProfit     = $total ? round($sumProfit / $total, 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Inventario Global</title>
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
    body { background: #f6f7fb; }
    .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title { font-weight:700; letter-spacing:.2px; margin:0; }
    .role-chip { font-size:.8rem; padding:.2rem .55rem; border-radius:999px; background:#eef2ff; color:#3743a5; border:1px solid #d9e0ff; }
    .toolbar { display:flex; gap:8px; align-items:center; }
    .filters-card { border:1px solid #e9ecf1; box-shadow:0 1px 6px rgba(16,24,40,.06); border-radius:16px; }
    .kpi { border:1px solid #e9ecf1; border-radius:16px; background:#fff; box-shadow:0 2px 8px rgba(16,24,40,.06); padding:16px; }
    .kpi h6{ margin:0; font-size:.9rem; color:#6b7280; } .kpi .metric{ font-weight:800; font-size:1.4rem; margin-top:4px; }
    .badge-soft{ border:1px solid transparent; } .badge-soft.success{ background:#e9f9ee; color:#0b7a3a; border-color:#b9ebc9;} .badge-soft.warning{ background:#fff6e6; color:#955f00; border-color:#ffe2ad;}
    .table thead th { white-space: nowrap; }
    .profit-pos { color:#0b7a3a; font-weight:700; } .profit-neg { color:#b42318; font-weight:700; }
    .chip{ display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:.8rem; border:1px solid #e2e8f0;}
    .status-dot{ width:8px; height:8px; border-radius:50%; display:inline-block; } .dot-green{ background:#16a34a;} .dot-amber{ background:#f59e0b;} .dot-gray{ background:#94a3b8;}
    .ant-pill { font-size:.75rem; padding:.2rem .5rem; border-radius:999px; }
    .ant-pill.lt{ background:#e9f9ee; color:#0b7a3a; border:1px solid #b9ebc9;} .ant-pill.md{ background:#fff6e6; color:#955f00; border:1px solid #ffe2ad;} .ant-pill.gt{ background:#ffecec; color:#9f1c1c; border:1px solid #ffc6c6;}
    .table-wrap { background:#fff; border:1px solid #e9ecf1; border-radius:16px; padding:8px 8px 16px; box-shadow:0 2px 10px rgba(16,24,40,.06); }

    /* Compacción tabla */
    #tablaInventario td, #tablaInventario th { padding:.35rem .5rem; font-size:.88rem; }
    /* Truncar proveedor para caber mejor (columna 9 en 1-based) */
    #tablaInventario td:nth-child(9), #tablaInventario th:nth-child(9) { max-width:180px; }
    #tablaInventario td:nth-child(9) .truncate { display:inline-block; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .dt-buttons .btn { border-radius:999px !important; }
    .copy-btn { border:0; background:transparent; cursor:pointer; }
    .copy-btn:hover { opacity:.8; }
    @media (max-width: 992px) {
      .page-head{ flex-direction:column; align-items:flex-start; }
      .toolbar{ width:100%; justify-content:flex-start; flex-wrap:wrap; }
    }
  </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4">

  <!-- Encabezado -->
  <div class="page-head">
    <div>
      <h2 class="page-title">🌎 Inventario Global</h2>
      <div class="mt-1"><span class="role-chip"><?= ($ROL==='Admin' ? 'Admin' : 'Gerente de Zona'); ?></span></div>
    </div>
    <div class="toolbar">
      <button class="btn btn-outline-secondary btn-sm rounded-pill" data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
        <i class="bi bi-sliders me-1"></i> Filtros
      </button>
      <a href="exportar_inventario_global.php?<?= http_build_query($_GET) ?>" class="btn btn-success btn-sm rounded-pill">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar Excel
      </a>
      <a href="inventario_global.php" class="btn btn-light btn-sm rounded-pill border">
        <i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar
      </a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Total equipos</h6><div class="metric"><?= number_format($total) ?></div></div></div>
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Disponible</h6><div class="metric"><span class="badge badge-soft success"><?= number_format($cntDisp) ?></span></div></div></div>
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>En tránsito</h6><div class="metric"><span class="badge badge-soft warning"><?= number_format($cntTrans) ?></span></div></div></div>
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Antigüedad prom.</h6><div class="metric"><?= $promAntiguedad ?> d</div></div></div>
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Ticket promedio</h6><div class="metric">$<?= number_format($promPrecio,2) ?></div></div></div>
    <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Profit prom.</h6><div class="metric <?= $promProfit>=0?'text-success':'text-danger' ?>">$<?= number_format($promProfit,2) ?></div></div></div>
  </div>

  <!-- Filtros -->
  <div id="filtrosCollapse" class="collapse">
    <div class="card filters-card p-3 mb-3">
      <form method="GET">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Sucursal</label>
            <select name="sucursal" class="form-select">
              <option value="">Todas</option>
              <?php while ($s = $sucursales->fetch_assoc()): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $filtroSucursal==$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">IMEI</label>
            <input type="text" name="imei" class="form-control" placeholder="Buscar IMEI..." value="<?= h($filtroImei) ?>">
          </div>

          <!-- NUEVO CAMPO: MODELO -->
          <div class="col-12 col-md-3">
            <label class="form-label">Modelo</label>
            <input type="text" name="modelo" class="form-control" placeholder="Buscar modelo..." value="<?= h($filtroModelo) ?>">
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">Estatus</label>
            <select name="estatus" class="form-select">
              <option value="">Todos</option>
              <option value="Disponible" <?= $filtroEstatus=='Disponible'?'selected':'' ?>>Disponible</option>
              <option value="En tránsito" <?= $filtroEstatus=='En tránsito'?'selected':'' ?>>En tránsito</option>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">Antigüedad</label>
            <select name="antiguedad" class="form-select">
              <option value="">Todas</option>
              <option value="<30"   <?= $filtroAntiguedad=='<30'?'selected':'' ?>>< 30 días</option>
              <option value="30-90" <?= $filtroAntiguedad=='30-90'?'selected':'' ?>>30-90 días</option>
              <option value=">90"   <?= $filtroAntiguedad=='>90'?'selected':'' ?>>> 90 días</option>
            </select>
          </div>

          <div class="col-6 col-md-1">
            <label class="form-label">Precio min</label>
            <input type="number" step="0.01" name="precio_min" class="form-control" value="<?= h($filtroPrecioMin) ?>">
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">Precio max</label>
            <input type="number" step="0.01" name="precio_max" class="form-control" value="<?= h($filtroPrecioMax) ?>">
          </div>

          <div class="col-12 col-md-12 text-end">
            <button class="btn btn-primary rounded-pill"><i class="bi bi-filter me-1"></i>Aplicar</button>
            <a href="inventario_global.php" class="btn btn-light rounded-pill border"><i class="bi bi-eraser me-1"></i>Limpiar</a>
            <a href="exportar_inventario_global.php?<?= http_build_query($_GET) ?>" class="btn btn-success rounded-pill"><i class="bi bi-file-earmark-excel me-1"></i>Exportar</a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Gráfica + Top 5 -->
  <div class="d-flex justify-content-between mb-3 flex-wrap gap-3">
    <div class="card p-3 shadow-sm" style="max-width:480px; width:100%;">
      <h6 class="mb-2">Antigüedad del inventario</h6>
      <canvas id="graficaAntiguedad"></canvas>
      <div class="mt-2 d-flex gap-2">
        <span class="ant-pill lt"><span class="status-dot dot-green me-1"></span>< 30 días: <?= (int)$rangos['<30'] ?></span>
        <span class="ant-pill md"><span class="status-dot dot-amber me-1"></span>30–90: <?= (int)$rangos['30-90'] ?></span>
        <span class="ant-pill gt"><span class="status-dot dot-gray me-1"></span>> 90: <?= (int)$rangos['>90'] ?></span>
      </div>
    </div>
    <div class="card shadow-sm p-3" style="min-width:320px; flex:1;">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="mb-0">🔥 Top 5 Equipos Vendidos</h6>
        <select id="filtro-top" class="form-select form-select-sm" style="max-width:180px;">
          <option value="semana">Esta Semana</option>
          <option value="mes">Este Mes</option>
          <option value="historico" selected>Histórico</option>
        </select>
      </div>
      <div id="tabla-top" class="mt-2">
        <div class="text-muted small">Cargando...</div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="table-wrap">
    <table id="tablaInventario" class="table table-striped table-hover align-middle" style="width:100%;">
      <thead class="table-light">
        <tr>
          <th>Sucursal</th>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Color</th>
          <th>RAM</th>
          <th>Almacenamiento</th>
          <th>IMEI1</th>
          <th>IMEI2</th>
          <th>Proveedor</th>
          <th>Costo c/IVA ($)</th>
          <th>Precio Lista ($)</th>
          <th>Profit ($)</th>
          <th>Estatus</th>
          <th>Fecha ingreso</th>
          <th>Antigüedad</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($inventario as $row):
        $dias  = (int)$row['antiguedad_dias'];
        $costoConIva = $row['costo_con_iva']; if ($costoConIva === null || $costoConIva === '') { $costoConIva = $row['costo']; }
        $profit = (float)$row['profit'];
        $antClass = $dias < 30 ? 'lt' : ($dias <= 90 ? 'md' : 'gt');
        $estatus = $row['estatus'];
        $statusChip = $estatus==='Disponible'
          ? '<span class="chip"><span class="status-dot dot-green"></span>Disponible</span>'
          : '<span class="chip"><span class="status-dot dot-amber"></span>En tránsito</span>';
        $fechaSolo = h(substr((string)$row['fecha_ingreso'], 0, 10)); // YYYY-MM-DD
      ?>
        <tr>
          <td><?= h($row['sucursal']) ?></td>
          <td><?= h($row['marca']) ?></td>
          <td><?= h($row['modelo']) ?></td>
          <td><?= h($row['color']) ?></td>
          <td><?= h($row['ram'] ?? '-') ?></td>
          <td><?= h($row['capacidad'] ?? '-') ?></td>
          <td>
            <span><?= h($row['imei1'] ?? '-') ?></span>
            <?php if(!empty($row['imei1'])): ?>
              <button class="copy-btn ms-1" title="Copiar IMEI" onclick="copyText('<?= h($row['imei1']) ?>')">
                <i class="bi bi-clipboard"></i>
              </button>
            <?php endif; ?>
          </td>
          <td>
            <span><?= h($row['imei2'] ?? '-') ?></span>
            <?php if(!empty($row['imei2'])): ?>
              <button class="copy-btn ms-1" title="Copiar IMEI" onclick="copyText('<?= h($row['imei2']) ?>')">
                <i class="bi bi-clipboard"></i>
              </button>
            <?php endif; ?>
          </td>
          <td title="<?= h($row['proveedor'] ?? '-') ?>"><span class="truncate"><?= h($row['proveedor'] ?? '-') ?></span></td>
          <td class="text-end">$<?= number_format((float)$costoConIva,2) ?></td>
          <td class="text-end">$<?= number_format((float)$row['precio_lista'],2) ?></td>
          <td class="text-end">
            <span class="<?= $profit>=0 ? 'profit-pos' : 'profit-neg' ?>">$<?= number_format($profit,2) ?></span>
          </td>
          <td><?= $statusChip ?></td>
          <td><?= $fechaSolo ?></td>
          <td><span class="ant-pill <?= $antClass ?>"><?= $dias ?> d</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- DataTables core + plugins -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
  // Copiar IMEI
  function copyText(t) {
    navigator.clipboard.writeText(t).then(() => {
      const toast = document.createElement('div');
      toast.className = 'position-fixed top-0 start-50 translate-middle-x p-2';
      toast.style.zIndex = 1080;
      toast.innerHTML = '<span class="badge text-bg-success rounded-pill">IMEI copiado</span>';
      document.body.appendChild(toast);
      setTimeout(()=> toast.remove(), 1200);
    });
  }

  // DataTable (orden por fecha ingreso desc; índices actualizados por nueva columna RAM)
  $(function() {
    $('#tablaInventario').DataTable({
      pageLength: 25,
      order: [[ 13, "desc" ]], // índice de "Fecha ingreso" tras agregar RAM
      responsive: true,
      autoWidth: false,
      fixedHeader: true,
      language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json" },
      dom: "<'row align-items-center mb-2'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" +
           "tr" +
           "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
      buttons: [
        { extend: 'csvHtml5',   className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-filetype-csv me-1"></i>CSV' },
        { extend: 'excelHtml5', className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-file-earmark-excel me-1"></i>Excel' },
        { extend: 'colvis',     className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-view-list me-1"></i>Columnas' }
      ],
      columnDefs: [
        { targets: [9,10,11], className: 'text-end' },                 // precios/profit
        { targets: [13,14], className: 'text-nowrap' },                // fecha y antigüedad
        { responsivePriority: 1, targets: 0 },                         // sucursal
        { responsivePriority: 2, targets: 1 },                         // marca
        { responsivePriority: 3, targets: 2 },                         // modelo
        { responsivePriority: 100, targets: [7,8] }                    // IMEI2 / proveedor: ceden primero en móvil
      ]
    });
  });

  // Gráfica antigüedad
  (function(){
    const ctx = document.getElementById('graficaAntiguedad').getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['<30 días', '30-90 días', '>90 días'],
        datasets: [{ label: 'Cantidad de equipos', data: [<?= (int)$rangos['<30'] ?>, <?= (int)$rangos['30-90'] ?>, <?= (int)$rangos['>90'] ?>] }]
      },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
  })();

  // Top vendidos
  function cargarTopVendidos(rango = 'historico') {
    fetch('top_productos.php?rango=' + encodeURIComponent(rango))
      .then(res => res.text())
      .then(html => { document.getElementById('tabla-top').innerHTML = html; })
      .catch(() => { document.getElementById('tabla-top').innerHTML = '<div class="text-danger small">No se pudo cargar el Top.</div>'; });
  }
  document.getElementById('filtro-top').addEventListener('change', function(){ cargarTopVendidos(this.value); });
  cargarTopVendidos();
</script>
</body>
</html>
