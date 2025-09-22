<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idSucursal = (int)$_SESSION['id_sucursal'];
$idUsuario  = (int)$_SESSION['id_usuario'];

// ✅ Mensaje de confirmación
$mensaje = "";

/* ================================
   Confirmar recepción de traspaso
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_traspaso'])) {
    $idTraspaso = (int)$_POST['id_traspaso'];

    // Obtener todas las SIMs de este traspaso (para esta sucursal y aún Pendiente)
    $sqlSims = "
        SELECT ds.id_sim
        FROM detalle_traspaso_sims ds
        INNER JOIN traspasos_sims ts ON ts.id = ds.id_traspaso
        WHERE ds.id_traspaso = ? 
          AND ts.id_sucursal_destino = ? 
          AND ts.estatus = 'Pendiente'
    ";
    $stmtSims = $conn->prepare($sqlSims);
    $stmtSims->bind_param("ii", $idTraspaso, $idSucursal);
    $stmtSims->execute();
    $resultSims = $stmtSims->get_result();

    $idsSims = [];
    while ($sim = $resultSims->fetch_assoc()) {
        $idsSims[] = (int)$sim['id_sim'];
    }
    $stmtSims->close();

    if (!empty($idsSims)) {
        // Actualizar inventario de SIMs a Disponible y asignar sucursal destino
        $idsPlaceholder = implode(',', array_fill(0, count($idsSims), '?'));
        $types = str_repeat('i', count($idsSims) + 1); // 1 por id_sucursal + N sims

        $sqlUpdate = "UPDATE inventario_sims SET estatus='Disponible', id_sucursal=? WHERE id IN ($idsPlaceholder)";
        $stmtUpdate = $conn->prepare($sqlUpdate);

        // Generar parámetros dinámicos (id_sucursal + lista de ids)
        $params = array_merge([$idSucursal], $idsSims);
        $stmtUpdate->bind_param($types, ...$params);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        // Marcar traspaso como Completado
        $stmtTraspaso = $conn->prepare("UPDATE traspasos_sims SET estatus='Completado' WHERE id=?");
        $stmtTraspaso->bind_param("i", $idTraspaso);
        $stmtTraspaso->execute();
        $stmtTraspaso->close();

        $mensaje = "<div class='alert alert-success mb-3'>✅ Traspaso #".htmlspecialchars((string)$idTraspaso,ENT_QUOTES). " confirmado. Las SIMs ya están disponibles en tu inventario.</div>";
    } else {
        $mensaje = "<div class='alert alert-warning mb-3'>⚠️ No se encontraron SIMs pendientes para este traspaso.</div>";
    }
}

/* ================================
   Obtener traspasos pendientes
================================ */
$sqlTraspasos = "
    SELECT ts.id, ts.fecha_traspaso, so.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos_sims ts
    INNER JOIN sucursales so ON so.id = ts.id_sucursal_origen
    INNER JOIN usuarios u ON u.id = ts.usuario_creo
    WHERE ts.id_sucursal_destino = ? AND ts.estatus = 'Pendiente'
    ORDER BY ts.fecha_traspaso ASC
";
$stmtTraspasos = $conn->prepare($sqlTraspasos);
$stmtTraspasos->bind_param("i", $idSucursal);
$stmtTraspasos->execute();
$traspasos = $stmtTraspasos->get_result();
$cntTraspasos = $traspasos ? $traspasos->num_rows : 0;
$stmtTraspasos->close();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- ✅ Navbar y layout responsive -->
  <title>Traspasos de SIMs Pendientes</title>
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
      background: linear-gradient(135deg, #22c55e 0%, #0ea5e9 55%, #6366f1 100%);
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

    .card-header-gradient{
      background: linear-gradient(135deg,#0f172a 0%,#111827 100%);
      color:#fff; border-top-left-radius:1rem; border-top-right-radius:1rem;
    }

    .table thead th{ letter-spacing:.4px; text-transform:uppercase; font-size:.78rem; }

    .btn-confirm{
      background:linear-gradient(90deg,#16a34a,#22c55e); border:0;
      box-shadow: 0 6px 18px rgba(22,163,74,.25);
    }
    .btn-confirm:hover{ filter:brightness(.98); }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Encabezado -->
  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="icon"><i class="bi bi-arrow-left-right fs-4"></i></div>
      <div class="flex-grow-1">
        <h2 class="mb-1 fw-bold">Traspasos de SIMs Pendientes</h2>
        <div class="opacity-75">Confirma la recepción para que las SIMs queden disponibles en tu inventario</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-box-seam me-1"></i> <?= (int)$cntTraspasos ?> traspaso(s)</span>
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <?php if ($cntTraspasos > 0): ?>
    <?php
      // Reejecutamos la consulta para iterar (el puntero ya fue consumido por num_rows en algunos entornos)
      $stmtTraspasos2 = $conn->prepare($sqlTraspasos);
      $stmtTraspasos2->bind_param("i", $idSucursal);
      $stmtTraspasos2->execute();
      $rs = $stmtTraspasos2->get_result();
    ?>
    <?php while($t = $rs->fetch_assoc()): ?>
      <?php
        $idTraspaso = (int)$t['id'];

        // Obtener detalle de SIMs
        $sqlDetalle = "
            SELECT i.id, i.iccid, i.dn, i.caja_id
            FROM detalle_traspaso_sims ds
            INNER JOIN inventario_sims i ON i.id = ds.id_sim
            WHERE ds.id_traspaso = ?
            ORDER BY i.caja_id
        ";
        $stmtDet = $conn->prepare($sqlDetalle);
        $stmtDet->bind_param("i", $idTraspaso);
        $stmtDet->execute();
        $detalle = $stmtDet->get_result();
      ?>

      <div class="card card-elev mb-4">
        <div class="card-header card-header-gradient">
          <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <div class="fw-semibold">
              <i class="bi bi-hash"></i> Traspaso <strong>#<?= $idTraspaso ?></strong>
              <span class="ms-2">• Origen: <strong><?= h($t['sucursal_origen']) ?></strong></span>
              <span class="ms-2">• Fecha: <strong><?= h($t['fecha_traspaso']) ?></strong></span>
            </div>
            <div class="opacity-75">
              Creado por: <strong><?= h($t['usuario_creo']) ?></strong>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th>ID SIM</th>
                  <th>ICCID</th>
                  <th>DN</th>
                  <th>Caja</th>
                  <th>Estatus</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($detalle->num_rows): ?>
                  <?php while($sim = $detalle->fetch_assoc()): ?>
                    <tr>
                      <td><?= (int)$sim['id'] ?></td>
                      <td><?= h($sim['iccid']) ?></td>
                      <td><?= $sim['dn'] ? h($sim['dn']) : '—' ?></td>
                      <td><?= h($sim['caja_id']) ?></td>
                      <td><span class="badge text-bg-warning">En tránsito</span></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" class="text-center text-muted py-3">Sin detalle</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-footer bg-white d-flex justify-content-end">
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Confirmar recepción del traspaso #<?= $idTraspaso ?>?');">
            <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
            <button type="submit" class="btn btn-confirm text-white">
              <i class="bi bi-check2-circle me-1"></i> Confirmar Recepción
            </button>
          </form>
        </div>
      </div>

      <?php $stmtDet->close(); ?>
    <?php endwhile; $stmtTraspasos2->close(); ?>

  <?php else: ?>
    <div class="card card-elev">
      <div class="card-body text-center py-5">
        <div class="display-6 mb-2">😌</div>
        <h5 class="mb-1">No hay traspasos de SIMs pendientes</h5>
        <div class="text-muted">Cuando tu almacén o alguna sucursal te envíe SIMs, aparecerán aquí para confirmar.</div>
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- Si en tu navbar ya cargas Bootstrap JS, puedes omitir esta línea -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
