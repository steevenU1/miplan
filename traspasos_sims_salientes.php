<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}
require_once __DIR__ . '/db.php';

$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';
$mensaje    = "";

/* ==========================
   Helpers / Config
========================== */
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function canDeletePending($rol,$idSucursalOrigen,$idSucursalUser){
    if ($rol === 'Admin') return true;
    if ($rol === 'Gerente' && (int)$idSucursalOrigen === (int)$idSucursalUser) return true;
    return false;
}
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

// Aumentar límite por si hay muchas cajas
$conn->query("SET SESSION group_concat_max_len = 8192");

// Detectar columna de caja
$CANDIDATES = ['caja_id','id_caja','caja','id_caja_sello'];
$COL_CAJA = null;
foreach ($CANDIDATES as $c) {
    if (hasColumn($conn, 'inventario_sims', $c)) { $COL_CAJA = $c; break; }
}
if ($COL_CAJA === null) {
    $mensaje .= "<div class='alert alert-warning'>⚠️ No se encontró ninguna columna de caja en <code>inventario_sims</code>. Revísalo (opciones buscadas: ".esc(implode(', ', $CANDIDATES)).").</div>";
}

/* ==========================
   CSRF
========================== */
if (empty($_SESSION['csrf_trs'])) {
    $_SESSION['csrf_trs'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_trs'];

/* ==========================
   POST: Eliminar traspaso **PENDIENTE**
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_pendiente') {
    $idTraspasoDel = (int)($_POST['id_traspaso'] ?? 0);
    $csrfIn = $_POST['csrf'] ?? '';
    if (!$idTraspasoDel || !hash_equals($csrf, $csrfIn)) {
        $mensaje .= "<div class='alert alert-danger'>Solicitud inválida.</div>";
    } else {
        // Cabecera
        $sqlCab = "SELECT id, id_sucursal_origen, id_sucursal_destino, estatus
                   FROM traspasos_sims WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sqlCab);
        $stmt->bind_param("i", $idTraspasoDel);
        $stmt->execute();
        $cab = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cab) {
            $mensaje .= "<div class='alert alert-danger'>El traspaso no existe.</div>";
        } else if (strcasecmp($cab['estatus'], 'Pendiente') !== 0) {
            $mensaje .= "<div class='alert alert-warning'>Solo se pueden eliminar traspasos con estatus <b>Pendiente</b>.</div>";
        } else if (!canDeletePending($rolUsuario, (int)$cab['id_sucursal_origen'], $idSucursal)) {
            $mensaje .= "<div class='alert alert-danger'>No tienes permisos para eliminar este traspaso pendiente.</div>";
        } else {
            // Traer detalle (SIMs)
            $sqlDet = "
                SELECT ds.id_sim, i.iccid, i.dn, i.id_sucursal AS sucursal_actual, i.estatus, i.`$COL_CAJA` AS caja_val
                FROM detalle_traspaso_sims ds
                INNER JOIN inventario_sims i ON i.id = ds.id_sim
                WHERE ds.id_traspaso = ?";
            $stmt = $conn->prepare($sqlDet);
            $stmt->bind_param("i", $idTraspasoDel);
            $stmt->execute();
            $resDet = $stmt->get_result();
            $sims = [];
            while ($r = $resDet->fetch_assoc()) { $sims[] = $r; }
            $stmt->close();

            if (!$sims) {
                $mensaje .= "<div class='alert alert-danger'>El traspaso pendiente no tiene detalle de SIMs.</div>";
            } else {
                // Validaciones: ninguna SIM vendida/asignada
                $errores = [];
                foreach ($sims as $s) {
                    $est = (string)$s['estatus'];
                    if (preg_match('/vendid|asignad/i', $est)) {
                        $errores[] = "SIM {$s['iccid']} tiene estatus '{$est}' y no se puede revertir.";
                    }
                }

                if ($errores) {
                    $mensaje .= "<div class='alert alert-danger'><b>No se puede eliminar:</b><ul><li>" . implode("</li><li>", array_map('esc', $errores)) . "</li></ul></div>";
                } else {
                    // Transacción
                    $conn->begin_transaction();
                    try {
                        $idOrigen = (int)$cab['id_sucursal_origen'];

                        // Reversión de SIMs (sin tocar caja)
                        $sqlUpd = "UPDATE inventario_sims
                                   SET id_sucursal=?, estatus='Disponible'
                                   WHERE id=?";
                        $stmtU = $conn->prepare($sqlUpd);
                        foreach ($sims as $s) {
                            $idSim = (int)$s['id_sim'];
                            $stmtU->bind_param("ii", $idOrigen, $idSim);
                            if (!$stmtU->execute()) {
                                throw new Exception("Error al revertir SIM id={$idSim}");
                            }
                        }
                        $stmtU->close();

                        // Borrar detalle
                        $stmtD = $conn->prepare("DELETE FROM detalle_traspaso_sims WHERE id_traspaso=?");
                        $stmtD->bind_param("i", $idTraspasoDel);
                        if (!$stmtD->execute()) throw new Exception("No se pudo borrar el detalle.");
                        $stmtD->close();

                        // Borrar cabecera
                        $stmtH = $conn->prepare("DELETE FROM traspasos_sims WHERE id=? AND estatus='Pendiente'");
                        $stmtH->bind_param("i", $idTraspasoDel);
                        if (!$stmtH->execute()) throw new Exception("No se pudo borrar el traspaso pendiente.");
                        $stmtH->close();

                        $conn->commit();
                        $mensaje .= "<div class='alert alert-success'>✅ Traspaso #".esc($idTraspasoDel)." eliminado. SIMs regresadas a origen como <b>Disponible</b>.</div>";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $mensaje .= "<div class='alert alert-danger'>Ocurrió un error y se revirtieron los cambios: ".esc($e->getMessage())."</div>";
                    }
                }
            }
        }
    }
}

/* ==========================
   Consulta: traspasos Pendientes salientes + conteos + lista de cajas
========================== */
$selectListaCajas = $COL_CAJA
  ? "(SELECT GROUP_CONCAT(DISTINCT i.`$COL_CAJA` ORDER BY i.`$COL_CAJA` SEPARATOR ',')
        FROM detalle_traspaso_sims dts
        JOIN inventario_sims i ON i.id = dts.id_sim
       WHERE dts.id_traspaso = ts.id) AS lista_cajas,"
  : "NULL AS lista_cajas,";

$selectTotalCajas = $COL_CAJA
  ? "(SELECT COUNT(DISTINCT i.`$COL_CAJA`)
        FROM detalle_traspaso_sims dts
        JOIN inventario_sims i ON i.id = dts.id_sim
       WHERE dts.id_traspaso = ts.id) AS total_cajas,"
  : "NULL AS total_cajas,";

$sqlPend = "
    SELECT ts.id, ts.fecha_traspaso, ts.id_sucursal_origen, ts.id_sucursal_destino,
           so.nombre AS sucursal_origen, sd.nombre AS sucursal_destino,
           u.nombre AS usuario_creo, ts.estatus,
           (SELECT COUNT(*) FROM detalle_traspaso_sims dts WHERE dts.id_traspaso = ts.id) AS total_sims,
           $selectTotalCajas
           $selectListaCajas
           1 AS _ok
    FROM traspasos_sims ts
    INNER JOIN sucursales so ON so.id = ts.id_sucursal_origen
    INNER JOIN sucursales sd ON sd.id = ts.id_sucursal_destino
    INNER JOIN usuarios u ON u.id = ts.usuario_creo
    WHERE ts.id_sucursal_origen = ? AND ts.estatus = 'Pendiente'
    ORDER BY ts.fecha_traspaso ASC, ts.id ASC
";
$stmtPend = $conn->prepare($sqlPend);
$stmtPend->bind_param("i", $idSucursal);
$stmtPend->execute();
$pendientes = $stmtPend->get_result();
$stmtPend->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Traspasos de SIMs Pendientes (Salientes)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- 🔧 hace que el navbar/ UI no se vea mini en móvil -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    .toggle-link { text-decoration: none; }
    .toggle-link[aria-expanded="true"] .lbl-open { display:none; }
    .toggle-link[aria-expanded="false"] .lbl-close { display:none; }
    .chips { display:flex; flex-wrap:wrap; gap:.35rem; }
    .chip-badge { border-radius: 9999px; }

    /* ====== Navbar: mejoras móviles ====== */
    @media (max-width: 576px){
      .navbar { 
        --bs-navbar-padding-y: .65rem;
        font-size: 1rem;                 /* 16px base */
      }
      .navbar .navbar-brand{
        font-size: 1.125rem;             /* ~18px */
        font-weight: 700;
      }
      .navbar .nav-link,
      .navbar .dropdown-item{
        font-size: 1rem;                  /* 16px */
        padding-top: .55rem;
        padding-bottom: .55rem;
      }
      .navbar .navbar-toggler{
        padding: .45rem .6rem;
        font-size: 1.1rem;
        border-width: 2px;
      }
      .navbar .bi{ font-size: 1.1rem; }
      /* Evita que el contenedor quede muy pegado a los lados en móvil */
      .container { padding-left: 12px; padding-right: 12px; }
    }

    /* Tablas más legibles en pantallas pequeñas */
    @media (max-width: 576px){
      .table { font-size: 12.5px; }
      .table td, .table th { padding: .4rem .5rem; }
      .card-header .badge { font-size: .78rem; }
    }
  </style>
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex align-items-center mb-2">
    <h2 class="me-3">📤 Traspasos de SIMs – Pendientes (Salientes)</h2>
    <span class="badge text-bg-secondary">Sucursal actual: <?= esc($idSucursal) ?></span>
  </div>
  <p class="text-muted mb-3">
    Aquí ves <b>únicamente</b> los traspasos <b>Pendientes</b>. Puedes <u>eliminar</u> un traspaso; al hacerlo, las SIMs regresan a la <b>sucursal de origen</b> con estatus <b>Disponible</b>.
  </p>

  <?= $mensaje ?>

  <?php if ($pendientes->num_rows > 0): ?>
    <?php while($t = $pendientes->fetch_assoc()): ?>
      <?php
        $idTr = (int)$t['id'];
        $permEliminar = canDeletePending($rolUsuario, (int)$t['id_sucursal_origen'], $idSucursal);
        $collapseId = "detTr_".$idTr;

        // Armar chips desde lista_cajas si viene; si no, los calculamos del detalle
        $chips = [];
        if (!empty($t['lista_cajas'])) {
            foreach (explode(',', $t['lista_cajas']) as $cx) {
                $cx = trim($cx);
                if ($cx !== '') $chips[] = $cx;
            }
        }

        // Detalle (también lo usaremos como fallback para chips)
        $sqlDetalle = "
          SELECT i.id, i.iccid, i.dn, i.`$COL_CAJA` AS caja_val, i.estatus, i.id_sucursal
          FROM detalle_traspaso_sims ds
          INNER JOIN inventario_sims i ON i.id = ds.id_sim
          WHERE ds.id_traspaso = ?
          ORDER BY i.`$COL_CAJA`, i.iccid
        ";
        $stmtDet = $conn->prepare($sqlDetalle);
        $stmtDet->bind_param("i", $idTr);
        $stmtDet->execute();
        $rows = $stmtDet->get_result()->fetch_all(MYSQLI_ASSOC);

        if (!$chips) {
            // Fallback: construir desde el detalle si lista_cajas vino vacía
            $uniq = [];
            foreach ($rows as $r) {
                $cv = trim((string)($r['caja_val'] ?? ''));
                if ($cv !== '') $uniq[$cv] = true;
            }
            $chips = array_keys($uniq);
            sort($chips, SORT_NATURAL);
        }
      ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <div class="d-flex flex-column flex-md-row gap-2">
              <div>
                <b>#<?= esc($idTr) ?></b> · Origen: <?= esc($t['sucursal_origen']) ?> · Destino: <?= esc($t['sucursal_destino']) ?> · Fecha: <?= esc($t['fecha_traspaso']) ?>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-light text-dark">SIMs: <?= (int)$t['total_sims'] ?></span>
                <span class="badge text-bg-light text-dark">Cajas: <?= esc((string)$t['total_cajas']) ?></span>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span>Estatus: <span class="badge bg-warning text-dark"><?= esc($t['estatus']) ?></span> · Creado por: <?= esc($t['usuario_creo']) ?></span>
            </div>
          </div>
          <?php if (!empty($chips)): ?>
            <div class="mt-2 chips">
              <?php foreach ($chips as $chip): ?>
                <span class="badge text-bg-info text-dark chip-badge">Caja: <?= esc($chip) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Resumen + Toggle -->
        <div class="card-body border-bottom py-2">
          <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">
              Resumen: <b><?= (int)$t['total_sims'] ?></b> SIMs en <b><?= esc((string)$t['total_cajas']) ?></b> caja(s).
            </div>
            <a class="btn btn-sm btn-outline-light text-primary border-primary toggle-link" data-bs-toggle="collapse" href="#<?= esc($collapseId) ?>" role="button" aria-expanded="false" aria-controls="<?= esc($collapseId) ?>">
              <span class="lbl-open">Ver SIMs (<?= (int)$t['total_sims'] ?>)</span>
              <span class="lbl-close">Ocultar SIMs</span>
            </a>
          </div>
        </div>

        <!-- Detalle colapsable -->
        <div id="<?= esc($collapseId) ?>" class="collapse">
          <div class="card-body p-0">
            <table class="table table-bordered table-sm mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID SIM</th>
                  <th>ICCID</th>
                  <th>DN</th>
                  <th>Caja</th>
                  <th>Estatus actual</th>
                  <th>Sucursal actual</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $sim): ?>
                <tr>
                  <td><?= esc($sim['id']) ?></td>
                  <td><?= esc($sim['iccid']) ?></td>
                  <td><?= $sim['dn'] ? esc($sim['dn']) : '-' ?></td>
                  <td><?= esc((string)$sim['caja_val']) ?></td>
                  <td><?= esc($sim['estatus']) ?></td>
                  <td><?= esc((string)$sim['id_sucursal']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="openAcuse(<?= $idTr ?>)">
            Reimprimir acuse
          </button>
          <?php if ($permEliminar): ?>
            <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalEliminar<?= $idTr ?>">
              Eliminar traspaso pendiente & revertir SIMs
            </button>
          <?php else: ?>
            <span class="text-muted">No tienes permisos para eliminar este traspaso pendiente.</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Modal confirmación -->
      <div class="modal fade" id="modalEliminar<?= $idTr ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post">
              <div class="modal-header">
                <h5 class="modal-title">Confirmar eliminación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">
                <p>Vas a eliminar el traspaso <b>#<?= esc($idTr) ?></b> (estatus: <b>Pendiente</b>).</p>
                <p>Las SIMs serán regresadas a la <b>sucursal de origen</b> con estatus <b>Disponible</b>. La caja se preserva.</p>
                <p class="mb-0 text-danger"><b>Esta acción es irreversible.</b></p>
              </div>
              <div class="modal-footer">
                <input type="hidden" name="accion" value="eliminar_pendiente">
                <input type="hidden" name="id_traspaso" value="<?= esc($idTr) ?>">
                <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Sí, eliminar y revertir</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <?php $stmtDet->close(); ?>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="alert alert-info">No tienes traspasos salientes pendientes.</div>
  <?php endif; ?>
</div>

<!-- MODAL: Reimprimir Acuse -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de Traspaso <span id="hdrAcuseId" class="text-muted"></span></h5>
        <div class="d-flex gap-2">
          <a id="btnNuevaPestana" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
          <button id="btnPrintAcuse" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Imprimir</button>
          <button class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
      <div class="modal-body p-0">
        <iframe id="acuseFrame" src="" style="width:100%; height:75vh; border:0;"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS + script para abrir/print del acuse -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function(){
  const modalAcuse    = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const acuseFrame    = document.getElementById('acuseFrame');
  const hdrAcuseId    = document.getElementById('hdrAcuseId');
  const btnPrintAcuse = document.getElementById('btnPrintAcuse');
  const btnNuevaPest  = document.getElementById('btnNuevaPestana');

  window.openAcuse = function(id){
    const url = 'acuse_traspaso_sims.php?id=' + encodeURIComponent(id);
    acuseFrame.src = url;
    hdrAcuseId.textContent = '#' + id;
    btnNuevaPest.href = url;
    modalAcuse.show();
  };

  btnPrintAcuse.addEventListener('click', ()=>{
    const frame = acuseFrame;
    if (!frame || !frame.contentWindow) return;
    if (frame.contentDocument && frame.contentDocument.readyState !== 'complete') {
      frame.addEventListener('load', () => frame.contentWindow.print(), { once:true });
    } else {
      frame.contentWindow.print();
    }
  });

  document.getElementById('modalAcuse').addEventListener('hidden.bs.modal', ()=> {
    acuseFrame.src = '';
  });
})();
</script>
</body>
</html>

