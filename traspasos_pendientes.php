<?php
// traspasos_pendientes.php — RECEPCIÓN con consolidación de accesorios (sin duplicar filas)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

$idSucursalUsuario = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario         = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario        = $_SESSION['rol'] ?? '';
$whereSucursal     = "id_sucursal_destino = $idSucursalUsuario";

$mensaje    = "";
$acuseUrl   = "";
$acuseReady = false;

/* ------------------------- Utils ------------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}
$hasDT_Resultado      = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep      = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio  = hasColumn($conn, 'traspasos', 'usuario_recibio');

/* -------- helper: accesorio o equipo -------- */
function es_accesorio(mysqli $conn, int $idInventario): bool {
  $sql = "SELECT p.tipo_producto, p.imei1, p.imei2
          FROM inventario i INNER JOIN productos p ON p.id=i.id_producto
          WHERE i.id=?";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $idInventario);
  $st->execute();
  $st->bind_result($tipo, $i1, $i2);
  $ok = $st->fetch();
  $st->close();
  if (!$ok) return false;
  $i1 = trim((string)$i1); $i2 = trim((string)$i2);
  return ($tipo === 'Accesorio') || ($i1 === '' && $i2 === '');
}

/* -------- helper: consolidar cantidad en sucursal destino/origen --------
   Devuelve el id_inventario final al que quedó apuntando el movimiento.   */
function consolidar_accesorio(mysqli $conn, int $idInventarioTransito, int $idSucursalFinal, int $idTraspaso): int {
  // Traer datos del renglón en tránsito
  $stInfo = $conn->prepare("
    SELECT i.id_producto, i.cantidad
    FROM inventario i
    WHERE i.id=? FOR UPDATE
  ");
  $stInfo->bind_param("i", $idInventarioTransito);
  $stInfo->execute();
  $stInfo->bind_result($idProd, $cant);
  if (!$stInfo->fetch()) { $stInfo->close(); throw new Exception("Inventario #$idInventarioTransito no existe."); }
  $stInfo->close();
  $cant = (int)$cant;

  // Buscar renglón disponible en sucursal final para ese producto
  $stFind = $conn->prepare("
    SELECT id FROM inventario 
    WHERE id_sucursal=? AND estatus='Disponible' AND id_producto=?
    LIMIT 1 FOR UPDATE
  ");
  $stFind->bind_param("ii", $idSucursalFinal, $idProd);
  $stFind->execute();
  $res = $stFind->get_result();
  $row = $res->fetch_assoc();
  $stFind->close();

  if ($row) {
    // Sumamos en la fila existente y borramos la fila en tránsito
    $idFinal = (int)$row['id'];

    $stAdd = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");
    $stAdd->bind_param("ii", $cant, $idFinal);
    if (!$stAdd->execute()) { $stAdd->close(); throw new Exception("No se pudo incrementar stock en destino."); }
    $stAdd->close();

    // Reapuntar el detalle al renglón final
    $stRelink = $conn->prepare("UPDATE detalle_traspaso SET id_inventario=? WHERE id_traspaso=? AND id_inventario=?");
    $stRelink->bind_param("iii", $idFinal, $idTraspaso, $idInventarioTransito);
    $stRelink->execute();
    $stRelink->close();

    // Borrar fila tránsito ya vaciada
    $stDel = $conn->prepare("DELETE FROM inventario WHERE id=?");
    $stDel->bind_param("i", $idInventarioTransito);
    if (!$stDel->execute()) { $stDel->close(); throw new Exception("No se pudo eliminar fila en tránsito (FK)."); }
    $stDel->close();

    return $idFinal;
  } else {
    // Reutilizamos la fila en tránsito como definitiva (sin borrar → sin FK)
    $stUpd = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
    $stUpd->bind_param("ii", $idSucursalFinal, $idInventarioTransito);
    if (!$stUpd->execute()) { $stUpd->close(); throw new Exception("No se pudo finalizar fila en tránsito."); }
    $stUpd->close();
    return $idInventarioTransito;
  }
}

/* ==========================================================
   POST: Recepción (parcial/total) con consolidación de accesorios
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
  $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);
  $marcados   = array_map('intval', $_POST['aceptar'] ?? []); // inventario.id recibidos

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

    // Traer todos los ids de inventario del traspaso
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
      $marcados   = array_values(array_intersect($marcados, $todos));
      $rechazados = array_values(array_diff($todos, $marcados));

      $conn->begin_transaction();
      try {
        /* ---------- Recibidos ---------- */
        foreach ($marcados as $idInv) {
          if (es_accesorio($conn, $idInv)) {
            // Consolidar por piezas en DESTINO
            $idFinal = consolidar_accesorio($conn, $idInv, $idDestino, $idTraspaso);
            if ($hasDT_Resultado || $hasDT_FechaResultado) {
              $sql = "UPDATE detalle_traspaso SET ".
                     ($hasDT_Resultado ? "resultado='Recibido'," : "").
                     ($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "").
                     " id_traspaso=id_traspaso WHERE id_traspaso=? AND id_inventario=?";
              $st = $conn->prepare($sql);
              $st->bind_param("ii", $idTraspaso, $idFinal);
              $st->execute(); $st->close();
            }
          } else {
            // Equipo unitario → mover la MISMA fila
            $stI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
            $stI->bind_param("ii", $idDestino, $idInv);
            $stI->execute(); $stI->close();

            if ($hasDT_Resultado || $hasDT_FechaResultado) {
              $sql = "UPDATE detalle_traspaso SET ".
                     ($hasDT_Resultado ? "resultado='Recibido'," : "").
                     ($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "").
                     " id_traspaso=id_traspaso WHERE id_traspaso=? AND id_inventario=?";
              $st = $conn->prepare($sql);
              $st->bind_param("ii", $idTraspaso, $idInv);
              $st->execute(); $st->close();
            }
          }
        }

        /* ---------- Rechazados ---------- */
        foreach ($rechazados as $idInv) {
          if (es_accesorio($conn, $idInv)) {
            // Consolidar por piezas de vuelta al ORIGEN
            $idFinal = consolidar_accesorio($conn, $idInv, $idOrigen, $idTraspaso);
            if ($hasDT_Resultado || $hasDT_FechaResultado) {
              $sql = "UPDATE detalle_traspaso SET ".
                     ($hasDT_Resultado ? "resultado='Rechazado'," : "").
                     ($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "").
                     " id_traspaso=id_traspaso WHERE id_traspaso=? AND id_inventario=?";
              $st = $conn->prepare($sql);
              $st->bind_param("ii", $idTraspaso, $idFinal);
              $st->execute(); $st->close();
            }
          } else {
            // Equipo unitario → regresar la MISMA fila
            $stI = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
            $stI->bind_param("ii", $idOrigen, $idInv);
            $stI->execute(); $stI->close();

            if ($hasDT_Resultado || $hasDT_FechaResultado) {
              $sql = "UPDATE detalle_traspaso SET ".
                     ($hasDT_Resultado ? "resultado='Rechazado'," : "").
                     ($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "").
                     " id_traspaso=id_traspaso WHERE id_traspaso=? AND id_inventario=?";
              $st = $conn->prepare($sql);
              $st->bind_param("ii", $idTraspaso, $idInv);
              $st->execute(); $st->close();
            }
          }
        }

        // Estatus global del traspaso
        $total = count($todos); $ok = count($marcados); $rej = count($rechazados);
        $estatus = ($ok === 0) ? 'Rechazado' : (($ok < $total) ? 'Parcial' : 'Completado');

        if ($hasT_FechaRecep && $hasT_UsuarioRecibio) {
          $st = $conn->prepare("UPDATE traspasos SET estatus=?, fecha_recepcion=NOW(), usuario_recibio=? WHERE id=?");
          $st->bind_param("sii", $estatus, $idUsuario, $idTraspaso);
        } else {
          $st = $conn->prepare("UPDATE traspasos SET estatus=? WHERE id=?");
          $st->bind_param("si", $estatus, $idTraspaso);
        }
        $st->execute(); $st->close();

        $conn->commit();

        // Acuse solo de recibidos (ojo: algunos accesorios podrían haber relinkeado id_inventario)
        if ($ok > 0) {
          // Vuelve a leer ids actuales marcados como 'Recibido' por seguridad
          $qr = $conn->prepare("
            SELECT dt.id_inventario
            FROM detalle_traspaso dt
            WHERE dt.id_traspaso=? ".($hasDT_Resultado ? "AND dt.resultado='Recibido'" : "")."
          ");
          $qr->bind_param("i", $idTraspaso);
          $qr->execute();
          $ids = [];
          $r = $qr->get_result();
          while ($x=$r->fetch_assoc()) $ids[]=(int)$x['id_inventario'];
          $qr->close();

          if (!empty($ids)) {
            $idsCsv = implode(',', $ids);
            $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&scope=recibidos&ids=" . urlencode($idsCsv) . "&print=1";
            $acuseReady = true;
          }
        }

        $mensaje = "<div class='alert alert-success mt-3'>
          ✅ Traspaso #".h($idTraspaso)." procesado. Recibidos: <b>".h($ok)."</b> · Rechazados: <b>".h($rej)."</b> · Estatus: <b>".h($estatus)."</b>.
          ".($ok>0 ? "<div class='small text-muted mt-1'>Se abrirá un acuse con los artículos <b>recibidos</b>.</div>" : "")."
        </div>";
      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "<div class='alert alert-danger mt-3'>❌ Error al procesar: ".h($e->getMessage())."</div>";
      }
    }
  }
}

/* ==========================================================
   Listado de pendientes (igual que antes)
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

// Resumen piezas
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
  $minFecha = $rRes['primero']; $maxFecha = $rRes['ultimo'];
}
$stRes->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traspasos Pendientes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --brand:#0d6efd; --brand-100:rgba(13,110,253,.08); }
    body.bg-light{ background: radial-gradient(1200px 420px at 110% -80%, var(--brand-100), transparent),
                              radial-gradient(1200px 420px at -10% 120%, rgba(25,135,84,.06), transparent), #f8fafc; }
    .page-head{ border:0; border-radius:1rem; background:linear-gradient(135deg,#22c55e 0%,#0ea5e9 55%,#6366f1 100%); color:#fff;
      box-shadow:0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06); }
    .page-head .icon{ width:48px;height:48px; display:grid;place-items:center; background:rgba(255,255,255,.15); border-radius:14px; }
    .chip{ background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.25); color:#fff; padding:.35rem .6rem; border-radius:999px; font-weight:600; }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 10px 28px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .bubble{ width:42px; height:42px; display:grid; place-items:center; border-radius:12px; background:#eef2ff; color:#312e81; }
    .card-header-gradient{ background:linear-gradient(135deg,#1f2937 0%,#0f172a 100%); color:#fff !important; border-top-left-radius:1rem; border-top-right-radius:1rem; }
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:12px; border-top:1px solid #e5e7eb;
      border-bottom-left-radius:1rem; border-bottom-right-radius:1rem; }
    .btn-confirm{ background:linear-gradient(90deg,#16a34a,#22c55e); border:0; box-shadow:0 6px 18px rgba(22,163,74,.25); }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

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

  <?= $mensaje ?>

  <?php
  $sqlPend = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.$whereSucursal AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
  ";
  $traspasos = $conn->query($sqlPend);
  ?>
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
            <div class="opacity-75">Creado por: <strong><?= h($traspaso['usuario_creo']) ?></strong></div>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
          <div class="table-responsive">
            <table class="table table-striped table-hover table-sm mb-0">
              <thead class="table-dark">
                <tr>
                  <th style="width:72px;"><input type="checkbox" class="form-check-input" id="chk_all_<?= $idTraspaso ?>" checked
                    onclick="toggleAll(<?= $idTraspaso ?>, this.checked)"></th>
                  <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>IMEI1</th><th>IMEI2</th><th>Estatus</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($detalles && $detalles->num_rows): while ($row = $detalles->fetch_assoc()): ?>
                  <tr>
                    <td><input type="checkbox" class="form-check-input chk-item-<?= $idTraspaso ?>" name="aceptar[]" value="<?= (int)$row['id'] ?>" checked></td>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= h($row['marca']) ?></td>
                    <td><?= h($row['modelo']) ?></td>
                    <td><?= h($row['color']) ?></td>
                    <td><?= h($row['imei1']) ?></td>
                    <td><?= $row['imei2'] ? h($row['imei2']) : '—' ?></td>
                    <td><span class="badge text-bg-warning">En tránsito</span></td>
                  </tr>
                <?php endwhile; else: ?>
                  <tr><td colspan="8" class="text-center text-muted py-3">Sin detalle</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="sticky-actions d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div class="text-muted">Marca lo que <b>SÍ recibiste</b>. Lo demás se <b>rechaza</b> y regresa a la sucursal origen.</div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(<?= $idTraspaso ?>, true)"><i class="bi bi-check2-all me-1"></i> Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(<?= $idTraspaso ?>, false)"><i class="bi bi-x-circle me-1"></i> Desmarcar todo</button>
              <button type="submit" name="procesar_traspaso" class="btn btn-confirm text-white btn-sm"><i class="bi bi-send-check me-1"></i> Procesar recepción</button>
            </div>
          </div>
        </form>
      </div>
    <?php endwhile; else: ?>
      <div class="card card-elev"><div class="card-body text-center py-5">
        <div class="display-6 mb-2">😌</div>
        <h5 class="mb-1">No hay traspasos pendientes para tu sucursal</h5>
        <div class="text-muted">Cuando recibas traspasos, aparecerán aquí para su confirmación.</div>
      </div></div>
  <?php endif; ?>

</div>

<!-- Modal acuse auto-print -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de recepción</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0"><iframe id="frameAcuse" src="about:blank" style="width:100%;min-height:72vh;border:0;background:#fff;"></iframe></div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i> Abrir en pestaña</button>
        <button type="button" id="btnPrintAcuse" class="btn btn-primary"><i class="bi bi-printer me-1"></i> Reimprimir</button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}
const ACUSE_URL   = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;
if (ACUSE_READY && ACUSE_URL) {
  const modal = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modal.show();
  document.getElementById('btnOpenAcuse').onclick  = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => { try { frame.contentWindow.print(); } catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); } };
}
</script>
</body>
</html>
