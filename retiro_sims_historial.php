<?php
// retiro_sims_historial.php — Listado de retiros y detalle con reversión
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rid = (int)($_GET['id'] ?? 0);

// flash
$ok  = $_SESSION['flash_ok']  ?? '';
$err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial de retiros</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container py-3">
  <h3 class="mb-3">Historial de retiros</h3>
  <?php if($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

  <?php if($rid>0):
    $hdr = $conn->query("SELECT r.*, u.nombre AS usuario
                         FROM retiros_sims r
                         LEFT JOIN usuarios u ON u.id=r.id_usuario
                         WHERE r.id={$rid}")->fetch_assoc();
    ?>
    <?php if($hdr): ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">Retiro #<?= (int)$hdr['id'] ?></h5>
          <p class="mb-1"><strong>Fecha:</strong> <?= h($hdr['fecha']) ?></p>
          <p class="mb-1"><strong>Usuario:</strong> <?= h($hdr['usuario'] ?? 'ID '.$hdr['id_usuario']) ?></p>
          <p class="mb-1"><strong>Motivo:</strong> <?= h($hdr['motivo']) ?></p>
          <p class="mb-0"><strong>Total:</strong> <?= (int)$hdr['total_items'] ?> SIM(s)</p>
        </div>
      </div>

      <?php
      $det = $conn->query("SELECT d.*, i.estatus
                           FROM retiros_sims_det d
                           LEFT JOIN inventario_sims i ON i.id=d.id_sim
                           WHERE d.id_retiro={$rid}
                           ORDER BY d.id DESC");
      ?>
      <form class="mb-2" method="post" action="retiro_sims_revertir.php" onsubmit="return confirm('¿Revertir TODO este retiro?');">
        <input type="hidden" name="id_retiro" value="<?= (int)$hdr['id'] ?>">
        <input type="hidden" name="accion" value="revertir_todo">
        <input type="text" name="motivo_rev" class="form-control mb-2" placeholder="Motivo de la reversión (obligatorio)" required minlength="5">
        <button class="btn btn-warning">Revertir TODO</button>
        <a class="btn btn-outline-secondary" href="retiro_sims_historial.php">Volver al listado</a>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-hover">
          <thead><tr>
            <th>ID SIM</th><th>ICCID</th><th>DN</th><th>Operador</th><th>CAJA</th><th>Sucursal</th>
            <th>Estado det.</th><th>Estatus actual</th><th></th>
          </tr></thead>
          <tbody>
          <?php while($r = $det->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$r['id_sim'] ?></td>
              <td><?= h($r['iccid_snap']) ?></td>
              <td><?= h($r['dn_snap']) ?></td>
              <td><?= h($r['operador_snap']) ?></td>
              <td><?= h($r['caja_snap']) ?></td>
              <td><?= (int)$r['id_sucursal_snap'] ?></td>
              <td><?= h($r['estado']) ?></td>
              <td><?= h($r['estatus'] ?? '-') ?></td>
              <td>
                <?php if($r['estado']==='Retirado'): ?>
                  <form method="post" action="retiro_sims_revertir.php" class="d-inline" onsubmit="return confirm('¿Revertir esta SIM?');">
                    <input type="hidden" name="accion" value="revertir_una">
                    <input type="hidden" name="id_det" value="<?= (int)$r['id'] ?>">
                    <input type="text" name="motivo_rev" class="form-control form-control-sm d-inline-block" style="width:220px" placeholder="Motivo" required minlength="5">
                    <button class="btn btn-sm btn-outline-warning">Revertir</button>
                  </form>
                <?php else: ?>
                  <span class="text-muted">Revertida</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">No se encontró el retiro solicitado.</div>
    <?php endif; ?>

  <?php else: // listado de últimos retiros ?>
    <?php $lst = $conn->query("SELECT r.*, u.nombre AS usuario
                               FROM retiros_sims r
                               LEFT JOIN usuarios u ON u.id=r.id_usuario
                               ORDER BY r.id DESC LIMIT 30"); ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead><tr>
          <th>ID</th><th>Fecha</th><th>Usuario</th><th>Motivo</th><th>Total</th><th></th>
        </tr></thead>
        <tbody>
        <?php while($r = $lst->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['fecha']) ?></td>
            <td><?= h($r['usuario'] ?? 'ID '.$r['id_usuario']) ?></td>
            <td><?= h($r['motivo']) ?></td>
            <td><?= (int)$r['total_items'] ?></td>
            <td><a class="btn btn-sm btn-primary" href="?id=<?= (int)$r['id'] ?>">Ver detalle</a></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
