<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idSucursalUsuario = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario         = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario        = $_SESSION['rol'] ?? '';
$whereSucursal     = "id_sucursal_destino = $idSucursalUsuario";

$mensaje    = "";
$acuseUrl   = "";
$acuseReady = false;

/* -----------------------------------------------------------
   Utilidades
----------------------------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$hasDT_Resultado      = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep      = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio  = hasColumn($conn, 'traspasos', 'usuario_recibio');

/* ==========================================================
   POST: Recepción parcial / total
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
    $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);
    $marcados   = array_map('intval', $_POST['aceptar'] ?? []); // ids de inventario recibidos

    // Validar traspaso pendiente de mi sucursal
    $stmt = $conn->prepare("
        SELECT id_sucursal_origen, id_sucursal_destino
        FROM traspasos
        WHERE id=? AND $whereSucursal AND estatus='Pendiente'
        LIMIT 1
    ");
    $stmt->bind_param("i", $idTraspaso);
    $stmt->execute();
    $tinfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tinfo) {
        $mensaje = "<div class='alert alert-danger mt-3'>❌ Traspaso inválido o ya procesado.</div>";
    } else {
        $idOrigen  = (int)$tinfo['id_sucursal_origen'];
        $idDestino = (int)$tinfo['id_sucursal_destino'];

        // Traer todos los equipos del traspaso
        $stmt = $conn->prepare("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=?");
        $stmt->bind_param("i", $idTraspaso);
        $stmt->execute();
        $res = $stmt->get_result();

        $todos = [];
        while ($r = $res->fetch_assoc()) $todos[] = (int)$r['id_inventario'];
        $stmt->close();

        if (empty($todos)) {
            $mensaje = "<div class='alert alert-warning mt-3'>⚠️ El traspaso no contiene productos.</div>";
        } else {
            // Sanitizar marcados: que existan en el traspaso
            $marcados   = array_values(array_intersect($marcados, $todos));
            $rechazados = [];
            foreach ($todos as $idInv) {
                if (!in_array($idInv, $marcados, true)) $rechazados[] = $idInv;
            }

            $conn->begin_transaction();
            try {
                // Aceptados -> destino + Disponible + (bitácora detalle)
                if (!empty($marcados)) {
                    $stmtI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stmtD = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Recibido'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ")
                        : null;

                    foreach ($marcados as $idInv) {
                        $stmtI->bind_param("ii", $idDestino, $idInv);
                        $stmtI->execute();

                        if ($stmtD) {
                            $stmtD->bind_param("ii", $idTraspaso, $idInv);
                            $stmtD->execute();
                        }
                    }
                    $stmtI->close();
                    if ($stmtD) $stmtD->close();
                }

                // Rechazados -> origen + Disponible + (bitácora detalle)
                if (!empty($rechazados)) {
                    $stmtI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                    $stmtD = ($hasDT_Resultado || $hasDT_FechaResultado)
                        ? $conn->prepare("
                            UPDATE detalle_traspaso
                            SET ".($hasDT_Resultado ? "resultado='Rechazado'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                            WHERE id_traspaso=? AND id_inventario=?
                          ")
                        : null;

                    foreach ($rechazados as $idInv) {
                        $stmtI->bind_param("ii", $idOrigen, $idInv);
                        $stmtI->execute();

                        if ($stmtD) {
                            $stmtD->bind_param("ii", $idTraspaso, $idInv);
                            $stmtD->execute();
                        }
                    }
                    $stmtI->close();
                    if ($stmtD) $stmtD->close();
                }

                // Estatus del traspaso
                $total   = count($todos);
                $ok      = count($marcados);
                $rej     = count($rechazados);
                $estatus = ($ok === 0) ? 'Rechazado' : (($ok < $total) ? 'Parcial' : 'Completado');

                // Actualizar traspaso (fecha/usuario si existen)
                if ($hasT_FechaRecep && $hasT_UsuarioRecibio) {
                    $stmt = $conn->prepare("UPDATE traspasos SET estatus=?, fecha_recepcion=NOW(), usuario_recibio=? WHERE id=?");
                    $stmt->bind_param("sii", $estatus, $idUsuario, $idTraspaso);
                } else {
                    $stmt = $conn->prepare("UPDATE traspasos SET estatus=? WHERE id=?");
                    $stmt->bind_param("si", $estatus, $idTraspaso);
                }
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                // ===== Preparar ACUSE (solo recibidos) =====
                if ($ok > 0) {
                    $idsCsv    = implode(',', array_map('intval', $marcados));
                    $acuseUrl  = "acuse_traspaso.php?id={$idTraspaso}&scope=recibidos&ids=" . urlencode($idsCsv) . "&print=1";
                    $acuseReady= true;
                }

                $mensaje = "<div class='alert alert-success mt-3'>
                    ✅ Traspaso #".h($idTraspaso)." procesado. Recibidos: <b>".h($ok)."</b> · Rechazados: <b>".h($rej)."</b> · Estatus: <b>".h($estatus)."</b>.
                    ".($ok>0 ? "<div class='small text-muted mt-1'>Se abrirá un acuse con los equipos <b>recibidos</b>.</div>" : "")."
                </div>";
            } catch (Throwable $e) {
                $conn->rollback();
                $mensaje = "<div class='alert alert-danger mt-3'>❌ Error al procesar: ".h($e->getMessage())."</div>";
            }
        }
    }
}

/* ==========================================================
   Traspasos pendientes de la sucursal + RESUMEN
========================================================== */
$sql = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
";
$traspasos = $conn->query($sql);
$cntTraspasos = $traspasos ? $traspasos->num_rows : 0;

// Nombre sucursal
$nomSucursal = '—';
$stS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=?");
$stS->bind_param("i",$idSucursalUsuario);
$stS->execute();
if ($rowS = $stS->get_result()->fetch_assoc()) $nomSucursal = $rowS['nombre'];
$stS->close();

// Totales de piezas por recibir y últimas fechas
$totItems = 0; $minFecha = null; $maxFecha = null;
$stRes = $conn->prepare("
    SELECT COUNT(*) AS items, MIN(t.fecha_traspaso) AS primero, MAX(t.fecha_traspaso) AS ultimo
    FROM detalle_traspaso dt
    INNER JOIN traspasos t ON t.id = dt.id_traspaso
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
");
$stRes->execute();
$rRes = $stRes->get_result()->fetch_assoc();
if ($rRes){
  $totItems = (int)$rRes['items'];
  $minFecha = $rRes['primero'];
  $maxFecha = $rRes['ultimo'];
}
$stRes->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traspasos Pendientes</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">

  <!-- Bootstrap & Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

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
    #topbar, .navbar-luga{ font-size:16px; }
    @media (max-width:576px){
      #topbar, .navbar-luga{
        font-size:16px;
        --brand-font:1.0em; --nav-font:.95em; --drop-font:.95em; --icon-em:1.05em;
        --pad-y:.44em; --pad-x:.62em;
      }
    }

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
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .bubble{
      width:42px; height:42px; display:grid; place-items:center;
      border-radius:12px; background:#eef2ff; color:#312e81;
    }

    /* ===== Cabeceras de cada traspaso: alto contraste ===== */
    .card-header-gradient{
      background: linear-gradient(135deg,#1f2937 0%,#0f172a 100%); /* slate-700 → slate-900 */
      color:#fff !important;
      border-top-left-radius:1rem; border-top-right-radius:1rem;
      text-shadow: 0 1px 0 rgba(0,0,0,.35);
    }
    /* Asegurar que TODO lo que esté dentro salga claro */
    .card-header-gradient *, 
    .card-header-gradient .bi,
    .card-header-gradient strong,
    .card-header-gradient a {
      color:#fff !important;
    }
    /* Si alguien dejó opacity-75 dentro, suavizamos sin perder legibilidad */
    .card-header-gradient .opacity-75{
      opacity:1 !important;
      color:rgba(255,255,255,.85) !important;
    }

    .table thead th{ letter-spacing:.4px; text-transform:uppercase; font-size:.78rem; }
    .sticky-actions{
      position:sticky; bottom:0; background:#fff; padding:12px; 
      border-top:1px solid #e5e7eb; border-bottom-left-radius:1rem; border-bottom-right-radius:1rem;
    }

    .btn-confirm{
      background:linear-gradient(90deg,#16a34a,#22c55e); border:0;
      box-shadow: 0 6px 18px rgba(22,163,74,.25);
    }
    .btn-confirm:hover{ filter:brightness(.98); }

    .chk-cell{ width:72px; text-align:center }

    /* Modal acuse */
    .modal-xxl { max-width: 1200px; }
    #frameAcuse{ width:100%; min-height:72vh; border:0; background:#fff; }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Encabezado -->
  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="icon"><i class="bi bi-boxes fs-4"></i></div>
      <div class="flex-grow-1">
        <h2 class="mb-1 fw-bold">Traspasos Pendientes</h2>
        <div class="opacity-75">Sucursal: <strong><?= h($nomSucursal) ?></strong></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <!-- Cards de información -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card card-elev h-100">
        <div class="card-body">
          <div class="stat">
            <div class="bubble"><i class="bi bi-arrow-left-right"></i></div>
            <div>
              <div class="text-muted small">Traspasos pendientes</div>
              <div class="fs-4 fw-bold"><?= (int)$cntTraspasos ?></div>
            </div>
          </div>
          <div class="mt-3 small text-muted">Confirma para liberar artículos al inventario local.</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card card-elev h-100">
        <div class="card-body">
          <div class="stat">
            <div class="bubble" style="background:#ecfeff;color:#164e63;"><i class="bi bi-phone"></i></div>
            <div>
              <div class="text-muted small">Piezas por revisar</div>
              <div class="fs-4 fw-bold"><?= (int)$totItems ?></div>
            </div>
          </div>
          <div class="mt-3 small text-muted">Marcarás solo lo que <b>SÍ</b> recibiste. Lo demás se rechaza.</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card card-elev h-100">
        <div class="card-body">
          <div class="stat">
            <div class="bubble" style="background:#fef3c7;color:#7c2d12;"><i class="bi bi-calendar-event"></i></div>
            <div>
              <div class="text-muted small">Rango de fechas</div>
              <div class="fw-semibold">
                <?= $minFecha ? h(date('d/m/Y', strtotime($minFecha))) : '—' ?> 
                <span class="text-muted">→</span>
                <?= $maxFecha ? h(date('d/m/Y', strtotime($maxFecha))) : '—' ?>
              </div>
            </div>
          </div>
          <div class="mt-3 small text-muted">Revisa primero los traspasos más antiguos.</div>
        </div>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <?php if ($traspasos && $traspasos->num_rows > 0): ?>
    <?php while($traspaso = $traspasos->fetch_assoc()): ?>
      <?php
      $idTraspaso = (int)$traspaso['id'];
      $detalles = $conn->query("
          SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
          FROM detalle_traspaso dt
          INNER JOIN inventario i ON i.id = dt.id_inventario
          INNER JOIN productos p  ON p.id = i.id_producto
          WHERE dt.id_traspaso = $idTraspaso
          ORDER BY p.marca, p.modelo, i.id
      ");
      ?>
      <div class="card card-elev mb-4">
        <div class="card-header card-header-gradient">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="fw-semibold">
              <i class="bi bi-hash"></i> Traspaso <strong>#<?= $idTraspaso ?></strong>
              <span class="ms-2">• Origen: <strong><?= h($traspaso['sucursal_origen']) ?></strong></span>
              <span class="ms-2">• Fecha: <strong><?= h($traspaso['fecha_traspaso']) ?></strong></span>
            </div>
            <div class="opacity-75">
              Creado por: <strong><?= h($traspaso['usuario_creo']) ?></strong>
            </div>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">

          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover table-sm mb-0">
                <thead class="table-dark">
                  <tr>
                    <th class="chk-cell">
                      <input type="checkbox" class="form-check-input" id="chk_all_<?= $idTraspaso ?>" checked
                        onclick="toggleAll(<?= $idTraspaso ?>, this.checked)">
                    </th>
                    <th>ID Inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>IMEI1</th>
                    <th>IMEI2</th>
                    <th>Estatus</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($detalles && $detalles->num_rows): ?>
                    <?php while ($row = $detalles->fetch_assoc()): ?>
                      <tr>
                        <td class="chk-cell">
                          <input type="checkbox" class="form-check-input chk-item-<?= $idTraspaso ?>"
                                 name="aceptar[]" value="<?= (int)$row['id'] ?>" checked>
                        </td>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= h($row['marca']) ?></td>
                        <td><?= h($row['modelo']) ?></td>
                        <td><?= h($row['color']) ?></td>
                        <td><?= h($row['imei1']) ?></td>
                        <td><?= $row['imei2'] ? h($row['imei2']) : '—' ?></td>
                        <td><span class="badge text-bg-warning">En tránsito</span></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">Sin detalle</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="sticky-actions d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="text-muted">
              Marca lo que <b>SÍ recibiste</b>. Lo demás se <b>rechaza</b> y regresa a la sucursal origen.
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, true)"><i class="bi bi-check2-all me-1"></i> Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, false)"><i class="bi bi-x-circle me-1"></i> Desmarcar todo</button>
              <button type="submit" name="procesar_traspaso" class="btn btn-confirm text-white btn-sm">
                <i class="bi bi-send-check me-1"></i> Procesar recepción
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="card card-elev">
      <div class="card-body text-center py-5">
        <div class="display-6 mb-2">😌</div>
        <h5 class="mb-1">No hay traspasos pendientes para tu sucursal</h5>
        <div class="text-muted">Cuando recibas traspasos, aparecerán aquí para su confirmación.</div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal ACUSE (iframe) -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de recepción</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="frameAcuse" src="about:blank"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary">
          <i class="bi bi-box-arrow-up-right me-1"></i> Abrir en pestaña
        </button>
        <button type="button" id="btnPrintAcuse" class="btn btn-primary">
          <i class="bi bi-printer me-1"></i> Reimprimir
        </button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- JS Bootstrap (necesario para Modals) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}

// ===== Modal ACUSE: auto-apertura posterior al procesamiento =====
const ACUSE_URL   = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick  = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>
