<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Subdistribuidor'])) {
    header("Location: 403.php");
    exit();
}

include 'db.php';

// 🔹 ID de la sucursal de Eulalia (almacén)
$idSucursalAlmacen = 40; // Ajusta si el ID real es diferente

// 🔹 Consulta de inventario
$sql = "
    SELECT 
        i.id AS id_inventario,
        p.marca,
        p.modelo,
        p.color,
        p.capacidad,
        p.imei1,
        p.imei2,
        p.costo,
        (p.costo * 1.05) AS costo_con_extra,
        p.precio_lista,
        i.estatus,
        i.fecha_ingreso
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ? 
      AND i.estatus = 'Disponible'
    ORDER BY p.marca, p.modelo
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursalAlmacen);
$stmt->execute();
$result = $stmt->get_result();
$inventario = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" /> <!-- ✅ hace responsivo el navbar en móvil -->
    <title>Inventario Subdistribuidor</title>
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">

    <!-- Bootstrap / Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

    <style>
      :root{
        --brand:#0d6efd;
        --brand-100:rgba(13,110,253,.08);
      }
      body.bg-light{
        background:
          radial-gradient(1200px 420px at 110% -80%, var(--brand-100), transparent),
          radial-gradient(1200px 420px at -10% 120%, rgba(25,135,84,.06), transparent),
          #f8fafc;
      }

      /* 🔧 Overrides RESPONSIVE del NAVBAR (no tocamos navbar.php, sólo estilos aquí) */
      #topbar, .navbar-luga{ font-size:16px; }
      @media (max-width:576px){
        #topbar, .navbar-luga{
          font-size:16px;
          --brand-font:1.00em;
          --nav-font:.95em;
          --drop-font:.95em;
          --icon-em:1.05em;
          --pad-y:.44em; --pad-x:.62em;
        }
        #topbar .navbar-brand img, .navbar-luga .navbar-brand img{ width:1.8em; height:1.8em; }
        #topbar .btn-asistencia, .navbar-luga .btn-asistencia{ font-size:.95em; padding:.5em .9em !important; border-radius:12px; }
        #topbar .navbar-toggler, .navbar-luga .navbar-toggler{ padding:.45em .7em; }
        #topbar .nav-avatar, #topbar .nav-initials,
        .navbar-luga .nav-avatar, .navbar-luga .nav-initials{ width:2.1em; height:2.1em; }
      }
      @media (max-width:360px){
        #topbar, .navbar-luga{ font-size:15px; }
      }

      /* Encabezado moderno */
      .page-head{
        border:0; border-radius:1rem;
        background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 60%, #8b5cf6 100%);
        color:#fff;
        box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
      }
      .page-head .icon{
        width:48px;height:48px; display:grid;place-items:center;
        background:rgba(255,255,255,.15); border-radius:14px;
      }
      .chip{
        background:rgba(255,255,255,.16);
        border:1px solid rgba(255,255,255,.25);
        color:#fff; padding:.35rem .6rem; border-radius:999px; font-weight:600;
      }

      .card-elev{
        border:0; border-radius:1rem;
        box-shadow:0 10px 28px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05);
      }
      .filters .form-control, .filters .form-select{
        border-radius:.75rem;
      }
      .btn-clear{
        border-radius:.75rem;
      }

      /* DataTable look & feel */
      table.dataTable>thead>tr>th{
        vertical-align:middle;
      }
      .table thead th{
        letter-spacing:.4px; text-transform:uppercase; font-size:.78rem;
      }
      .badge-soft{
        background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;
      }
      .status-badge{
        font-weight:600; font-size:.75rem;
      }
      .status-ok{ background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
      .status-move{ background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Encabezado -->
  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="icon"><i class="bi bi-box-seam fs-4"></i></div>
      <div class="flex-grow-1">
        <h2 class="mb-1 fw-bold">Inventario — Subdistribuidor / Admin</h2>
        <div class="opacity-75">Mostrando inventario de <strong>Eulalia (Almacén)</strong></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-collection me-1"></i> <?= count($inventario) ?> equipos</span>
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card card-elev mb-4">
    <div class="card-body filters">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0"><i class="bi bi-funnel me-2 text-primary"></i>Filtros</h5>
        <button class="btn btn-outline-secondary btn-sm btn-clear" id="btnLimpiar">
          <i class="bi bi-eraser me-1"></i> Limpiar
        </button>
      </div>
      <div class="row g-3">
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-tags"></i></span>
            <input type="text" id="filtroMarca" class="form-control" placeholder="Marca">
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-phone"></i></span>
            <input type="text" id="filtroModelo" class="form-control" placeholder="Modelo">
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
            <input type="text" id="filtroIMEI" class="form-control" placeholder="Buscar IMEI">
          </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-flag"></i></span>
            <select id="filtroEstatus" class="form-select">
              <option value="">Todos los estatus</option>
              <option value="Disponible">Disponible</option>
              <option value="En tránsito">En tránsito</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="card card-elev">
    <div class="card-body p-2 p-sm-3">
      <div class="table-responsive">
        <table id="tablaInventario" class="table table-striped table-hover align-middle nowrap" style="width:100%">
          <thead class="table-dark">
            <tr>
              <th>ID</th>
              <th>Marca</th>
              <th>Modelo</th>
              <th>Color</th>
              <th>Capacidad</th>
              <th>IMEI1</th>
              <th>IMEI2</th>
              <th>Costo ($)</th>
              <th>Precio Lista ($)</th>
              <th>Estatus</th>
              <th>Ingreso</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventario as $row): ?>
              <tr>
                <td><?= (int)$row['id_inventario'] ?></td>
                <td><?= htmlspecialchars($row['marca'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['modelo'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['color'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['capacidad'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei1'] ?? '-', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['imei2'] ?? '-', ENT_QUOTES) ?></td>
                <td>$<?= number_format((float)$row['costo_con_extra'],2) ?></td>
                <td>$<?= number_format((float)$row['precio_lista'],2) ?></td>
                <td>
                  <?php
                    $st = trim($row['estatus']);
                    $cls = $st==='Disponible' ? 'status-ok' : 'status-move';
                  ?>
                  <span class="badge status-badge <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
                </td>
                <td><?= htmlspecialchars($row['fecha_ingreso']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /container -->

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
$(function(){
  // DataTable
  const tabla = $('#tablaInventario').DataTable({
    responsive: true,
    pageLength: 25,
    order: [[1,'asc'],[2,'asc']],
    language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json" },
    dom: 'lrtip' // ocultamos el buscador nativo (tenemos filtros propios)
  });

  // Filtros personalizados
  $('#filtroMarca').on('keyup', function(){ tabla.column(1).search(this.value).draw(); });
  $('#filtroModelo').on('keyup', function(){ tabla.column(2).search(this.value).draw(); });
  $('#filtroIMEI').on('keyup', function(){ tabla.columns([5,6]).search(this.value).draw(); });
  $('#filtroEstatus').on('change', function(){ tabla.column(9).search(this.value).draw(); });

  // Limpiar filtros
  $('#btnLimpiar').on('click', function(){
    $('#filtroMarca').val('');
    $('#filtroModelo').val('');
    $('#filtroIMEI').val('');
    $('#filtroEstatus').val('');
    tabla.search('').columns().search('').draw();
  });
});
</script>

</body>
</html>
