<?php
// proveedores.php
// Vista de proveedores con:
// - Línea de crédito y días de crédito
// - Form en modal (crear/editar)
// - Modal "Ver proveedor" con métricas, últimas compras y pagos

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);

function texto($s, $len) { return substr(trim($s ?? ''), 0, $len); }
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money_in($s){
  $s = trim((string)$s);
  if ($s === '') return 0.0;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
  else { $s = str_replace(',', '', $s); }
  return is_numeric($s) ? (float)$s : 0.0;
}

/* =====================================================
   🔁 AJAX PRIMERO (antes de cualquier salida/HTML)
===================================================== */
if (isset($_GET['ajax']) && $_GET['ajax']==='provinfo') {
  header('Content-Type: application/json; charset=utf-8');
  $pid = (int)($_GET['id'] ?? 0);
  if ($pid<=0) { echo json_encode(['ok'=>false,'error'=>'id inválido']); exit; }

  // Datos del proveedor
  $st = $conn->prepare("SELECT id, nombre, rfc, contacto, telefono, email, direccion, credito_limite, dias_credito, notas, activo, DATE_FORMAT(creado_en,'%Y-%m-%d') AS creado FROM proveedores WHERE id=?");
  $st->bind_param("i",$pid); $st->execute();
  $prov = $st->get_result()->fetch_assoc(); $st->close();
  if (!$prov) { echo json_encode(['ok'=>false,'error'=>'no encontrado']); exit; }

  // Totales por proveedor (excluye Cancelada)
  $tot = $conn->query("
    SELECT
      COALESCE(SUM(c.total),0) AS total_compra,
      COALESCE(SUM(pg.pagado),0) AS total_pagado,
      COALESCE(SUM(c.total - COALESCE(pg.pagado,0)),0) AS saldo
    FROM compras c
    LEFT JOIN (SELECT id_compra, SUM(monto) AS pagado FROM compras_pagos GROUP BY id_compra) pg
      ON pg.id_compra = c.id
    WHERE c.id_proveedor = $pid AND c.estatus <> 'Cancelada'
  ")->fetch_assoc();
  $total_compra = (float)$tot['total_compra'];
  $total_pagado = (float)$tot['total_pagado'];
  $saldo        = (float)$tot['saldo'];
  $disp         = (float)$prov['credito_limite'] - $saldo;

  // Últimas 5 compras
  $ultCompras = [];
  $qC = $conn->query("
    SELECT c.id, c.num_factura, c.fecha_factura, c.fecha_vencimiento, c.total, c.estatus,
           COALESCE(pg.pagado,0) AS pagado,
           (c.total - COALESCE(pg.pagado,0)) AS saldo
    FROM compras c
    LEFT JOIN (SELECT id_compra, SUM(monto) AS pagado FROM compras_pagos GROUP BY id_compra) pg
      ON pg.id_compra=c.id
    WHERE c.id_proveedor=$pid AND c.estatus <> 'Cancelada'
    ORDER BY (c.total-COALESCE(pg.pagado,0)) > 0 DESC, c.fecha_factura DESC
    LIMIT 5
  ");
  while($r=$qC->fetch_assoc()){ $r['total']=(float)$r['total']; $r['pagado']=(float)$r['pagado']; $r['saldo']=(float)$r['saldo']; $ultCompras[]=$r; }

  // Últimos 5 pagos
  $ultPagos = [];
  $qP = $conn->query("
    SELECT cp.fecha_pago, cp.monto, cp.metodo_pago, cp.referencia
    FROM compras_pagos cp
    INNER JOIN compras c ON c.id = cp.id_compra
    WHERE c.id_proveedor = $pid
    ORDER BY cp.fecha_pago DESC, cp.id DESC
    LIMIT 5
  ");
  while($r=$qP->fetch_assoc()){ $r['monto']=(float)$r['monto']; $ultPagos[]=$r; }

  echo json_encode([
    'ok'=>true,
    'proveedor'=>$prov,
    'totales'=>[
      'total_compra'=>$total_compra,
      'total_pagado'=>$total_pagado,
      'saldo'=>$saldo,
      'credito_disponible'=>$disp
    ],
    'compras'=>$ultCompras,
    'pagos'=>$ultPagos
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =====================================================
   POST: crear / editar
===================================================== */
$mensaje = "";

if ($permEscritura && $_SERVER['REQUEST_METHOD']==='POST') {
  $id          = (int)($_POST['id'] ?? 0);
  $nombre      = texto($_POST['nombre'] ?? '', 120);
  $rfc         = texto($_POST['rfc'] ?? '', 20);
  $contacto    = texto($_POST['contacto'] ?? '', 120);
  $telefono    = texto($_POST['telefono'] ?? '', 30);
  $email       = texto($_POST['email'] ?? '', 120);
  $direccion   = texto($_POST['direccion'] ?? '', 1000);
  $credito_lim = money_in($_POST['credito_limite'] ?? '0');
  // ✅ Forzar a 0 cuando viene vacío
  $dias_cred   = ($_POST['dias_credito'] !== '' ? (int)$_POST['dias_credito'] : 0);
  $notas       = texto($_POST['notas'] ?? '', 2000);

  if ($nombre === '') {
    $mensaje = "<div class='alert alert-danger'>El nombre es obligatorio.</div>";
  } else {
    if ($id > 0) {
      $stmt = $conn->prepare("UPDATE proveedores
        SET nombre=?, rfc=?, contacto=?, telefono=?, email=?, direccion=?, credito_limite=?, dias_credito=?, notas=?
        WHERE id=?");
      // ✅ Tipos corregidos: último es id (i)
      $stmt->bind_param("ssssssdisi", $nombre, $rfc, $contacto, $telefono, $email, $direccion, $credito_lim, $dias_cred, $notas, $id);
      $ok = $stmt->execute(); $stmt->close();
      $mensaje = $ok ? "<div class='alert alert-success'>Proveedor actualizado.</div>" : "<div class='alert alert-danger'>Error al actualizar.</div>";
    } else {
      $du = $conn->prepare("SELECT COUNT(*) c FROM proveedores WHERE nombre=?");
      $du->bind_param("s", $nombre); $du->execute(); $du->bind_result($cdup); $du->fetch(); $du->close();
      if ($cdup > 0) {
        $mensaje = "<div class='alert alert-warning'>Ya existe un proveedor con ese nombre.</div>";
      } else {
        $stmt = $conn->prepare("INSERT INTO proveedores
          (nombre, rfc, contacto, telefono, email, direccion, credito_limite, dias_credito, notas, activo)
          VALUES (?,?,?,?,?,?,?,?,?,1)");
        // ✅ 9 placeholders → 9 tipos
        $stmt->bind_param("ssssssdis", $nombre, $rfc, $contacto, $telefono, $email, $direccion, $credito_lim, $dias_cred, $notas);
        $ok = $stmt->execute(); $stmt->close();
        $mensaje = $ok ? "<div class='alert alert-success'>Proveedor creado.</div>" : "<div class='alert alert-danger'>Error al crear.</div>";
      }
    }
  }
}

// Toggle activo
if ($permEscritura && isset($_GET['accion'], $_GET['id']) && $_GET['accion']==='toggle') {
  $id = (int)$_GET['id'];
  if ($id>0) { $conn->query("UPDATE proveedores SET activo=IF(activo=1,0,1) WHERE id=$id"); }
  header("Location: proveedores.php"); exit();
}

/* =====================================================
   Filtros + Listado
===================================================== */
$filtroEstado = $_GET['estado'] ?? 'activos';
$busqueda = texto($_GET['q'] ?? '', 80);

$where = [];
if ($filtroEstado === 'activos')   $where[] = "pr.activo = 1";
if ($filtroEstado === 'inactivos') $where[] = "pr.activo = 0";
if ($busqueda !== '') {
  $x = $conn->real_escape_string($busqueda);
  $where[] = "(pr.nombre LIKE '%$x%' OR pr.rfc LIKE '%$x%' OR pr.contacto LIKE '%$x%' OR pr.telefono LIKE '%$x%' OR pr.email LIKE '%$x%')";
}
$sqlWhere = count($where) ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
  SELECT
    pr.id, pr.nombre, pr.rfc, pr.contacto, pr.telefono, pr.email, pr.direccion,
    pr.credito_limite, pr.dias_credito, pr.notas, pr.activo,
    DATE_FORMAT(pr.creado_en,'%Y-%m-%d') AS creado,
    IFNULL(de.saldo,0) AS saldo_deuda,
    (pr.credito_limite - IFNULL(de.saldo,0)) AS credito_disponible
  FROM proveedores pr
  LEFT JOIN (
    SELECT c.id_proveedor, SUM(c.total - IFNULL(pg.pagado,0)) AS saldo
    FROM compras c
    LEFT JOIN (SELECT id_compra, SUM(monto) AS pagado FROM compras_pagos GROUP BY id_compra) pg
      ON pg.id_compra = c.id
    WHERE c.estatus <> 'Cancelada'
    GROUP BY c.id_proveedor
  ) de ON de.id_proveedor = pr.id
  $sqlWhere
  ORDER BY pr.nombre ASC
";
$proveedores = $conn->query($sql);
$countProv = $proveedores ? $proveedores->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- ✅ Navbar responsive en móvil -->
  <title>Catálogo · Proveedores</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">

  <!-- Bootstrap / Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

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

    /* 🔧 Ajuste visual del NAVBAR para móvil (sin tocar navbar.php) */
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

    /* ✅ Pills con texto negro y fondo claro para mejor contraste */
    .chip{
      color:#111 !important;
      background:rgba(255,255,255,.92) !important;
      border:1px solid rgba(0,0,0,.12) !important;
      padding:.35rem .6rem; border-radius:999px; font-weight:600;
    }
    .chip .bi{ color:inherit !important; }

    .card-elev{
      border:0; border-radius:1rem;
      box-shadow:0 10px 28px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05);
    }
    .filters .form-control, .filters .form-select{
      border-radius:.75rem;
    }
    .btn-clear{ border-radius:.75rem; }

    .table thead th{
      letter-spacing:.4px; text-transform:uppercase; font-size:.78rem;
    }

    /* ✅ Badges de estado: usar variables Bootstrap para asegurar texto oscuro */
    .badge.status-badge{
      --bs-badge-padding-x: .6rem;
      --bs-badge-padding-y: .35rem;
      --bs-badge-font-weight: 600;
      --bs-badge-border-radius: 999px;
      border: 1px solid transparent;
    }
    .badge.status-on{
      --bs-badge-color: #111;     /* texto */
      --bs-badge-bg: #dcfce7;     /* fondo */
      color: var(--bs-badge-color) !important;
      background-color: var(--bs-badge-bg) !important;
      border-color: #bbf7d0 !important;
    }
    .badge.status-off{
      --bs-badge-color: #111;     /* texto */
      --bs-badge-bg: #e5e7eb;     /* fondo */
      color: var(--bs-badge-color) !important;
      background-color: var(--bs-badge-bg) !important;
      border-color: #d1d5db !important;
    }

    .modal-header{ border-bottom:0; }
    .modal-footer{ border-top:0; }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Encabezado -->
  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="icon"><i class="bi bi-building-gear fs-4"></i></div>
      <div class="flex-grow-1">
        <h2 class="mb-1 fw-bold">Catálogo de Proveedores</h2>
        <div class="opacity-75">Gestión de proveedores y deuda.</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-people me-1"></i> <?= (int)$countProv ?> registros</span>
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <!-- Barra superior: filtros + nuevo -->
  <div class="card card-elev mb-4">
    <div class="card-body filters">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h5 class="mb-0"><i class="bi bi-funnel me-2 text-primary"></i>Filtros</h5>
        <div class="d-flex gap-2">
          <?php if ($permEscritura): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProv" id="btnNuevoProv">
              <i class="bi bi-plus-lg me-1"></i> Nuevo
            </button>
          <?php endif; ?>
        </div>
      </div>
      <form class="row g-3 align-items-end" method="get">
        <div class="col-12 col-sm-6 col-md-3">
          <label class="form-label mb-1">Estado</label>
          <select name="estado" class="form-select" onchange="this.form.submit()">
            <option value="activos"   <?= $filtroEstado==='activos'?'selected':'' ?>>Activos</option>
            <option value="inactivos" <?= $filtroEstado==='inactivos'?'selected':'' ?>>Inactivos</option>
            <option value="todos"     <?= $filtroEstado==='todos'?'selected':'' ?>>Todos</option>
          </select>
        </div>
        <div class="col-12 col-sm-6 col-md-6">
          <label class="form-label mb-1">Buscar</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input class="form-control" name="q" value="<?= esc($busqueda) ?>" placeholder="Nombre, RFC, contacto, teléfono o email">
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
          <button class="btn btn-outline-primary w-100"><i class="bi bi-filter-circle me-1"></i> Aplicar</button>
        </div>
      </form>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- Tabla -->
  <div class="card card-elev">
    <div class="card-body p-2 p-sm-3">
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead class="table-dark">
            <tr>
              <th>Nombre</th>
              <th>RFC</th>
              <th>Contacto</th>
              <th>Teléfono</th>
              <th>Email</th>
              <th>Alta</th>
              <th class="text-center">Días crédito</th> <!-- ✅ Nueva columna -->
              <th class="text-end">Crédito</th>
              <th class="text-end">Deuda</th>
              <th class="text-end">Disp.</th>
              <th class="text-center">Estatus</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if($proveedores && $proveedores->num_rows): while($p=$proveedores->fetch_assoc()):
              $credito  = (float)$p['credito_limite'];
              $deuda    = max(0,(float)$p['saldo_deuda']);
              $disp     = $credito - $deuda;
              $dispCls  = ($disp < 0) ? 'text-danger fw-bold' : 'text-success';
              $isOn     = ((int)$p['activo']===1);
              $diasCred = (int)$p['dias_credito'];
            ?>
            <tr>
              <td><?= esc($p['nombre']) ?></td>
              <td><?= esc($p['rfc']) ?></td>
              <td><?= esc($p['contacto']) ?></td>
              <td><?= esc($p['telefono']) ?></td>
              <td><?= esc($p['email']) ?></td>
              <td><?= esc($p['creado']) ?></td>
              <td class="text-center"><?= $diasCred ?></td> <!-- ✅ Mostrar días -->
              <td class="text-end">$<?= number_format($credito,2) ?></td>
              <td class="text-end">$<?= number_format($deuda,2) ?></td>
              <td class="text-end <?= $dispCls ?>">$<?= number_format($disp,2) ?></td>
              <td class="text-center">
                <span class="badge status-badge <?= $isOn?'status-on':'status-off' ?>">
                  <?= $isOn ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td class="text-end">
                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-secondary btnVer"
                          data-id="<?= (int)$p['id'] ?>"
                          data-nombre="<?= esc($p['nombre']) ?>">
                    <i class="bi bi-eye"></i> Ver
                  </button>
                  <?php if ($permEscritura): ?>
                    <button class="btn btn-sm btn-outline-primary btnEdit"
                      data-id="<?= (int)$p['id'] ?>"
                      data-nombre="<?= esc($p['nombre']) ?>"
                      data-rfc="<?= esc($p['rfc']) ?>"
                      data-contacto="<?= esc($p['contacto']) ?>"
                      data-telefono="<?= esc($p['telefono']) ?>"
                      data-email="<?= esc($p['email']) ?>"
                      data-direccion="<?= esc($p['direccion']) ?>"
                      data-credito="<?= number_format((float)$p['credito_limite'],2,'.','') ?>"
                      data-dias="<?= esc($p['dias_credito']) ?>"
                      data-notas="<?= esc($p['notas']) ?>"
                      data-bs-toggle="modal" data-bs-target="#modalProv">
                      <i class="bi bi-pencil-square"></i> Editar
                    </button>
                    <a class="btn btn-sm btn-outline-<?= $isOn?'danger':'success' ?>"
                       href="proveedores.php?accion=toggle&id=<?= (int)$p['id'] ?>"
                       onclick="return confirm('¿Seguro que deseas <?= $isOn?'inactivar':'activar' ?> este proveedor?');">
                       <?= $isOn?'Inactivar':'Activar' ?>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="12" class="text-center text-muted py-4">Sin proveedores</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Crear/Editar proveedor -->
<div class="modal fade" id="modalProv" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="id" id="prov_id">
      <div class="modal-header">
        <h5 class="modal-title" id="modalProvTitle"><i class="bi bi-building-add me-2 text-primary"></i>Nuevo proveedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Nombre *</label>
            <input name="nombre" id="prov_nombre" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">RFC</label>
            <input name="rfc" id="prov_rfc" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Contacto</label>
            <input name="contacto" id="prov_contacto" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Teléfono</label>
            <input name="telefono" id="prov_telefono" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="prov_email" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Línea de crédito</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" step="0.01" min="0" name="credito_limite" id="prov_credito" class="form-control" value="0.00">
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Días de crédito</label>
            <input type="number" step="1" min="0" name="dias_credito" id="prov_dias" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Dirección</label>
            <textarea name="direccion" id="prov_direccion" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Notas</label>
            <textarea name="notas" id="prov_notas" class="form-control" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i> Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Ver proveedor -->
<div class="modal fade" id="modalVer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building-check me-2 text-primary"></i><span id="ver_nombre"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="text-muted">Crédito</div>
                <div class="fs-5 fw-bold" id="ver_credito">$0.00</div>
                <div class="text-muted mt-2">Deuda</div>
                <div class="fs-5 fw-bold text-danger" id="ver_deuda">$0.00</div>
                <div class="text-muted mt-2">Disponible</div>
                <div class="fs-5 fw-bold" id="ver_disp">$0.00</div>
                <hr>
                <div class="small">
                  <div><strong>RFC:</strong> <span id="ver_rfc"></span></div>
                  <div><strong>Contacto:</strong> <span id="ver_contacto"></span></div>
                  <div><strong>Teléfono:</strong> <span id="ver_tel"></span></div>
                  <div><strong>Email:</strong> <span id="ver_email"></span></div>
                  <div><strong>Días crédito:</strong> <span id="ver_dias"></span></div>
                  <div class="mt-2"><strong>Dirección:</strong><br><span id="ver_dir"></span></div>
                  <div class="mt-2"><strong>Notas:</strong><br><span id="ver_notas"></span></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-9">
            <div class="row g-3">
              <div class="col-12">
                <div class="card shadow-sm">
                  <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt-cutoff me-2"></i>Últimas facturas</div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-sm mb-0">
                        <thead><tr>
                          <th>Factura</th><th>Fecha</th><th>Vence</th>
                          <th class="text-end">Total</th><th class="text-end">Pagado</th><th class="text-end">Saldo</th><th>Estatus</th>
                        </tr></thead>
                        <tbody id="ver_tbl_compras"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-12">
                <div class="card shadow-sm">
                  <div class="card-header bg-white fw-semibold"><i class="bi bi-cash-coin me-2"></i>Últimos pagos</div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-sm mb-0">
                        <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-end">Monto</th></tr></thead>
                        <tbody id="ver_tbl_pagos"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div> <!-- row -->
          </div>
        </div> <!-- row -->
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
const btnNuevo  = document.getElementById('btnNuevoProv');
function setForm(data){
  document.getElementById('modalProvTitle').innerHTML = (data.id ? '<i class="bi bi-pencil-square me-2 text-primary"></i>Editar proveedor'
                                                                : '<i class="bi bi-building-add me-2 text-primary"></i>Nuevo proveedor');
  document.getElementById('prov_id').value        = data.id || '';
  document.getElementById('prov_nombre').value    = data.nombre || '';
  document.getElementById('prov_rfc').value       = data.rfc || '';
  document.getElementById('prov_contacto').value  = data.contacto || '';
  document.getElementById('prov_telefono').value  = data.telefono || '';
  document.getElementById('prov_email').value     = data.email || '';
  document.getElementById('prov_credito').value   = data.credito || '0.00';
  document.getElementById('prov_dias').value      = (data.dias ?? '');
  document.getElementById('prov_direccion').value = data.direccion || '';
  document.getElementById('prov_notas').value     = data.notas || '';
}
if (btnNuevo) btnNuevo.addEventListener('click', () => setForm({}));

document.querySelectorAll('.btnEdit').forEach(btn => {
  btn.addEventListener('click', () => {
    setForm({
      id: btn.dataset.id,
      nombre: btn.dataset.nombre,
      rfc: btn.dataset.rfc,
      contacto: btn.dataset.contacto,
      telefono: btn.dataset.telefono,
      email: btn.dataset.email,
      credito: btn.dataset.credito,
      dias: btn.dataset.dias || '',
      direccion: btn.dataset.direccion,
      notas: btn.dataset.notas
    });
  });
});

// ----- Ver proveedor -----
function fm(n){ return new Intl.NumberFormat('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n||0); }
document.querySelectorAll('.btnVer').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id = btn.dataset.id;
    const nombre = btn.dataset.nombre;
    const modalEl = document.getElementById('modalVer');
    const modal = new bootstrap.Modal(modalEl);

    // Estado inicial
    document.getElementById('ver_nombre').textContent = nombre;
    document.getElementById('ver_credito').textContent = '$0.00';
    document.getElementById('ver_deuda').textContent   = '$0.00';
    document.getElementById('ver_disp').textContent    = '$0.00';
    document.getElementById('ver_rfc').textContent     = '—';
    document.getElementById('ver_contacto').textContent= '—';
    document.getElementById('ver_tel').textContent     = '—';
    document.getElementById('ver_email').textContent   = '—';
    document.getElementById('ver_dias').textContent    = '—';
    document.getElementById('ver_dir').textContent     = '—';
    document.getElementById('ver_notas').textContent   = '—';
    document.getElementById('ver_tbl_compras').innerHTML = '<tr><td colspan="7" class="text-muted text-center">Cargando…</td></tr>';
    document.getElementById('ver_tbl_pagos').innerHTML   = '<tr><td colspan="4" class="text-muted text-center">Cargando…</td></tr>';

    modal.show(); // abre ya, y luego llenamos

    try {
      const resp = await fetch(`proveedores.php?ajax=provinfo&id=${id}`);
      const j = await resp.json();
      if (!j.ok) throw new Error(j.error || 'Error');

      const p = j.proveedor, t = j.totales;
      document.getElementById('ver_credito').textContent = '$'+fm(p.credito_limite);
      document.getElementById('ver_deuda').textContent   = '$'+fm(t.saldo);
      document.getElementById('ver_disp').textContent    = '$'+fm(t.credito_disponible);
      document.getElementById('ver_rfc').textContent     = p.rfc || '—';
      document.getElementById('ver_contacto').textContent= p.contacto || '—';
      document.getElementById('ver_tel').textContent     = p.telefono || '—';
      document.getElementById('ver_email').textContent   = p.email || '—';
      document.getElementById('ver_dias').textContent    = (p.dias_credito ?? '') || '—';
      document.getElementById('ver_dir').textContent     = p.direccion || '—';
      document.getElementById('ver_notas').textContent   = p.notas || '—';

      const tc = document.getElementById('ver_tbl_compras');
      tc.innerHTML = j.compras.length ? '' : '<tr><td colspan="7" class="text-muted text-center">Sin registros</td></tr>';
      j.compras.forEach(c => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${c.num_factura ?? ''}</td>
          <td>${c.fecha_factura ?? ''}</td>
          <td>${c.fecha_vencimiento ?? ''}</td>
          <td class="text-end">$${fm(c.total)}</td>
          <td class="text-end">$${fm(c.pagado)}</td>
          <td class="text-end">$${fm(c.saldo)}</td>
          <td>${c.estatus ?? ''}</td>
        `;
        tc.appendChild(tr);
      });

      const tp = document.getElementById('ver_tbl_pagos');
      tp.innerHTML = j.pagos.length ? '' : '<tr><td colspan="4" class="text-muted text-center">Sin registros</td></tr>';
      j.pagos.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${p.fecha_pago ?? ''}</td>
          <td>${p.metodo_pago ?? ''}</td>
          <td>${p.referencia ?? ''}</td>
          <td class="text-end">$${fm(p.monto)}</td>
        `;
        tp.appendChild(tr);
      });

    } catch (e) {
      document.getElementById('ver_tbl_compras').innerHTML = '<tr><td colspan="7" class="text-danger text-center">Error al cargar</td></tr>';
      document.getElementById('ver_tbl_pagos').innerHTML   = '<tr><td colspan="4" class="text-danger text-center">Error al cargar</td></tr>';
    }
  });
});
</script>

<script>
  (function () {
    try { document.title = 'Catálogo · Proveedores — Central2.0'; } catch(e) {}
  })();
</script>

</body>
</html>
