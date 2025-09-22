<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['GerenteZona','Admin'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';

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

/* -----------------------------------------------------------
   Columnas opcionales (bitácoras)
----------------------------------------------------------- */
$hasDT_Resultado      = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep      = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio  = hasColumn($conn, 'traspasos', 'usuario_recibio');

/* -----------------------------------------------------------
   ID de Eulalia (para excluirla a GerenteZona)
----------------------------------------------------------- */
$idEulalia = 0;
if ($st = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")) {
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $idEulalia = (int)($row['id'] ?? 0);
    $st->close();
}

/* -----------------------------------------------------------
   Determinar zona del GerenteZona (Admin ve todo)
----------------------------------------------------------- */
$zonaUsuario = null;
if ($rolUsuario === 'GerenteZona') {
    $st = $conn->prepare("
        SELECT s.zona
        FROM usuarios u
        INNER JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.id = ? LIMIT 1
    ");
    $st->bind_param("i", $idUsuario);
    $st->execute();
    $zonaUsuario = $st->get_result()->fetch_assoc()['zona'] ?? null;
    $st->close();

    if (!$zonaUsuario) {
        echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se pudo determinar tu zona.</div></div>";
        exit();
    }
}

/* -----------------------------------------------------------
   Filtro opcional por sucursal destino dentro de la zona
   (excluye Eulalia para GerenteZona)
----------------------------------------------------------- */
$destinoFiltro = isset($_GET['sucursal']) ? (int)$_GET['sucursal'] : 0;

if ($rolUsuario === 'GerenteZona') {
    if ($idEulalia > 0) {
        $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE zona=? AND id<>? ORDER BY nombre");
        $stmt->bind_param("si", $zonaUsuario, $idEulalia);
    } else {
        $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE zona=? ORDER BY nombre");
        $stmt->bind_param("s", $zonaUsuario);
    }
    $stmt->execute();
    $sucursalesZona = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Si alguien forzó en la URL el filtro a Eulalia, lo ignoramos
    if ($destinoFiltro === $idEulalia && $idEulalia > 0) {
        $destinoFiltro = 0;
    }
} else {
    $res = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
    $sucursalesZona = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/* -----------------------------------------------------------
   Columna de fecha segura (evita Unknown column)
----------------------------------------------------------- */
$fechaExpr = 'NULL';
if (hasColumn($conn, 'traspasos', 'fecha_traspaso'))      $fechaExpr = 't.fecha_traspaso';
elseif (hasColumn($conn, 'traspasos', 'fecha_creacion'))  $fechaExpr = 't.fecha_creacion';

/* ==========================================================
   POST: Recepción parcial / total
   (bloquea si destino es Eulalia para GerenteZona)
========================================================== */
$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
    $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);
    $marcados   = array_map('intval', $_POST['aceptar'] ?? []);

    if ($rolUsuario === 'GerenteZona') {
        if ($idEulalia > 0) {
            $stmt = $conn->prepare("
                SELECT t.id_sucursal_origen, t.id_sucursal_destino
                FROM traspasos t
                INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
                WHERE t.id=? AND t.estatus='Pendiente' AND sd.zona=? AND sd.id<>?
                LIMIT 1
            ");
            $stmt->bind_param("isi", $idTraspaso, $zonaUsuario, $idEulalia);
        } else {
            $stmt = $conn->prepare("
                SELECT t.id_sucursal_origen, t.id_sucursal_destino
                FROM traspasos t
                INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
                WHERE t.id=? AND t.estatus='Pendiente' AND sd.zona=?
                LIMIT 1
            ");
            $stmt->bind_param("is", $idTraspaso, $zonaUsuario);
        }
    } else { // Admin
        $stmt = $conn->prepare("
            SELECT id_sucursal_origen, id_sucursal_destino
            FROM traspasos
            WHERE id=? AND estatus='Pendiente'
            LIMIT 1
        ");
        $stmt->bind_param("i", $idTraspaso);
    }
    $stmt->execute();
    $tinfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tinfo) {
        $mensaje = "<div class='alert alert-danger mt-3 shadow-sm'>❌ Traspaso inválido, ya procesado o no autorizado.</div>";
    } else {
        $idOrigen  = (int)$tinfo['id_sucursal_origen'];
        $idDestino = (int)$tinfo['id_sucursal_destino'];

        // Guardia extra: si de todos modos el destino es Eulalia y es GerenteZona, bloquea
        if ($rolUsuario === 'GerenteZona' && $idEulalia > 0 && $idDestino === $idEulalia) {
            $mensaje = "<div class='alert alert-warning mt-3 shadow-sm'>⚠️ Los traspasos con destino <b>Eulalia</b> solo los procesa <b>Almacén</b>.</div>";
        } else {
            // Traer todos los equipos del traspaso
            $stmt = $conn->prepare("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=?");
            $stmt->bind_param("i", $idTraspaso);
            $stmt->execute();
            $res = $stmt->get_result();

            $todos = [];
            while ($r = $res->fetch_assoc()) $todos[] = (int)$r['id_inventario'];
            $stmt->close();

            if (empty($todos)) {
                $mensaje = "<div class='alert alert-warning mt-3 shadow-sm'>⚠️ El traspaso no contiene productos.</div>";
            } else {
                // Sanitizar marcados: que existan en el traspaso
                $marcados   = array_values(array_intersect($marcados, $todos));
                $rechazados = [];
                foreach ($todos as $idInv) if (!in_array($idInv, $marcados, true)) $rechazados[] = $idInv;

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
                            $stmtI->bind_param("ii", $idDestino, $idInv); $stmtI->execute();
                            if ($stmtD) { $stmtD->bind_param("ii", $idTraspaso, $idInv); $stmtD->execute(); }
                        }
                        $stmtI->close(); if ($stmtD) $stmtD->close();
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
                            $stmtI->bind_param("ii", $idOrigen, $idInv); $stmtI->execute();
                            if ($stmtD) { $stmtD->bind_param("ii", $idTraspaso, $idInv); $stmtD->execute(); }
                        }
                        $stmtI->close(); if ($stmtD) $stmtD->close();
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
                    $stmt->execute(); $stmt->close();

                    $conn->commit();
                    $mensaje = "<div class='alert alert-success mt-3 shadow-sm'>
                        ✅ Traspaso #$idTraspaso procesado. Recibidos: <b>$ok</b> · Rechazados: <b>$rej</b> · Estatus: <b>$estatus</b>.
                    </div>";
                } catch (Throwable $e) {
                    $conn->rollback();
                    $mensaje = "<div class='alert alert-danger mt-3 shadow-sm'>❌ Error al procesar: ".h($e->getMessage())."</div>";
                }
            }
        }
    }
}

/* ==========================================================
   Listado de traspasos PENDIENTES de la zona (o todos si Admin)
   (excluye Eulalia para GerenteZona)
========================================================== */
$params = []; $types  = "";
$sql = "
    SELECT
      t.id,
      {$fechaExpr} AS fecha_mov,
      so.nombre AS sucursal_origen,
      sd.nombre AS sucursal_destino,
      u.nombre  AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales so ON so.id = t.id_sucursal_origen
    INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
    INNER JOIN usuarios  u  ON u.id = t.usuario_creo
    WHERE t.estatus='Pendiente'
";

if ($rolUsuario === 'GerenteZona') {
    $sql   .= " AND sd.zona = ? ";
    $types .= "s";
    $params[] = $zonaUsuario;

    if ($idEulalia > 0) {
        $sql   .= " AND sd.id <> ? ";
        $types .= "i";
        $params[] = $idEulalia;
    }
}
if ($destinoFiltro > 0) {
    $sql   .= " AND sd.id = ? ";
    $types .= "i";
    $params[] = $destinoFiltro;
}

$orderExpr = ($fechaExpr !== 'NULL') ? $fechaExpr : 't.id';
$sql .= " ORDER BY {$orderExpr} ASC, t.id ASC";

$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$traspasos = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Traspasos Pendientes — Zona</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{
      --card-br:16px;
      --soft-border:#eef0f6;
    }
    body{ background:#f7f8fb; }
    .page-header{
      background:linear-gradient(180deg,#ffffff,#f4f6fb);
      border:1px solid var(--soft-border);
      border-radius:18px;
      padding:18px 20px;
      box-shadow:0 6px 20px rgba(18,38,63,.06);
    }
    .muted{ color:#6c757d; }

    .card{ border:1px solid var(--soft-border); border-radius:var(--card-br); overflow:hidden; }
    .card-header{ background:#fff; border-bottom:1px solid var(--soft-border); }
    .card.shadow{ box-shadow:0 8px 24px rgba(16,24,40,.06)!important; }

    .chip{ border:1px solid var(--soft-border); border-radius:999px; padding:.25rem .6rem; background:#fff; font-size:.8rem; }
    .badge-soft{ background:#eef4ff; color:#2c5bff; border:1px solid #dfe8ff; }

    .table-modern thead th{ position:sticky; top:0; z-index:2; background:#fff; }
    .table-modern tbody tr:hover{ background:#f7faff; }
    .chk-cell{ width:72px; text-align:center }
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:10px; border-top:1px solid var(--soft-border) }

    .btn-soft-warning{ background:#fff6e6; border:1px solid #ffe6b3; color:#9a6c00; }
    .btn-soft-warning:hover{ background:#ffefcc; }

    .modal-warning .modal-header{ background:#fff9e6; border-bottom:1px solid #ffe6b3; }
    .modal-warning .modal-footer{ background:#fafafa; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <!-- Header -->
  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1"><i class="bi bi-box-seam me-2 text-primary"></i>Traspasos Pendientes — <?= $rolUsuario==='GerenteZona' ? 'Mi Zona' : 'Admin' ?></h1>
      <div class="muted">
        <?php if($rolUsuario==='GerenteZona'): ?>
          <span class="badge rounded-pill text-bg-light border"><i class="bi bi-geo-alt me-1"></i>Zona: <?= h($zonaUsuario) ?></span>
        <?php else: ?>
          <span class="badge rounded-pill text-bg-light border"><i class="bi bi-shield-lock me-1"></i>Administrador</span>
        <?php endif; ?>
      </div>
    </div>
    <form class="d-flex align-items-center gap-2" method="GET">
      <label class="form-label m-0">Destino</label>
      <select name="sucursal" class="form-select form-select-sm" style="min-width:260px" onchange="this.form.submit()">
        <option value="0">(Todas las sucursales <?= $rolUsuario==='GerenteZona' ? 'de mi zona' : '' ?>)</option>
        <?php foreach ($sucursalesZona as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $destinoFiltro==(int)$s['id']?'selected':'' ?>>
            <?= h($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
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
      <div class="card shadow mb-4">
        <div class="card-header">
          <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="d-flex align-items-center flex-wrap gap-2">
              <span class="badge text-bg-dark"><i class="bi bi-hash me-1"></i><?= $idTraspaso ?></span>
              <span class="chip"><i class="bi bi-building me-1"></i>Origen: <b><?= h($traspaso['sucursal_origen']) ?></b></span>
              <span class="chip"><i class="bi bi-geo me-1"></i>Destino: <b><?= h($traspaso['sucursal_destino']) ?></b></span>
              <span class="chip"><i class="bi bi-calendar3 me-1"></i><?= h($traspaso['fecha_mov'] ?? '-') ?></span>
            </div>
            <span class="muted small">Creado por: <?= h($traspaso['usuario_creo']) ?></span>
          </div>
        </div>

        <form method="POST" id="formT_<?= $idTraspaso ?>">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">

          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-modern table-hover table-bordered table-sm mb-0">
                <thead>
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
                  <?php while ($row = $detalles->fetch_assoc()): ?>
                    <tr>
                      <td class="chk-cell">
                        <input type="checkbox" class="form-check-input chk-item-<?= $idTraspaso ?>"
                               name="aceptar[]" value="<?= (int)$row['id'] ?>" checked>
                      </td>
                      <td class="fw-semibold"><?= (int)$row['id'] ?></td>
                      <td><?= h($row['marca']) ?></td>
                      <td><?= h($row['modelo']) ?></td>
                      <td><span class="badge rounded-pill text-bg-light border"><?= h($row['color']) ?></span></td>
                      <td><code><?= h($row['imei1']) ?></code></td>
                      <td><?= $row['imei2'] ? "<code>".h($row['imei2'])."</code>" : '—' ?></td>
                      <td><span class="badge badge-soft">En tránsito</span></td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="sticky-actions d-flex justify-content-between align-items-center">
            <div class="text-muted small">
              Marca lo que <b>SÍ recibieron</b> en la sucursal destino. Lo demás se <b>rechaza</b> y regresa al origen.
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, true)"><i class="bi bi-check2-square me-1"></i>Marcar todo</button>
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, false)"><i class="bi bi-square me-1"></i>Desmarcar todo</button>
              <!-- Botón que abre el modal -->
              <button type="button" class="btn btn.success btn-sm btn-success"
                      onclick="openConfirmModal(<?= $idTraspaso ?>, '<?= h($traspaso['sucursal_destino']) ?>')">
                <i class="bi bi-check-circle me-1"></i>Procesar recepción
              </button>
            </div>
          </div>

          <!-- Modal de confirmación -->
          <div class="modal fade" id="modal_<?= $idTraspaso ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content modal-warning">
                <div class="modal-header">
                  <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Confirmar recepción</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                  <p class="mb-2">
                    Estás a punto de <b>confirmar la recepción</b> del <b>Traspaso #<?= $idTraspaso ?></b> para la sucursal
                    <b id="dest_<?= $idTraspaso ?>"></b>.
                  </p>
                  <div class="alert alert-warning py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Los equipos <b>marcados</b> se moverán al inventario de la sucursal destino inmediatamente.
                    Es posible que el <b>gerente de tienda no esté al tanto</b> de este traspaso.
                  </div>
                  <ul class="mb-0">
                    <li>Aceptados: <b id="conf_ok_<?= $idTraspaso ?>">0</b></li>
                    <li>Rechazados: <b id="conf_rej_<?= $idTraspaso ?>">0</b></li>
                    <li>Total: <b id="conf_total_<?= $idTraspaso ?>">0</b></li>
                  </ul>
                  <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="ack_<?= $idTraspaso ?>">
                    <label class="form-check-label" for="ack_<?= $idTraspaso ?>">
                      Sí, estoy seguro/a y <b>entiendo el impacto en inventario</b>.
                    </label>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                  <button type="submit" name="procesar_traspaso" class="btn btn-success"
                          onclick="return document.getElementById('ack_<?= $idTraspaso ?>').checked;">
                    <i class="bi bi-check2-circle me-1"></i>Confirmar recepción
                  </button>
                </div>
              </div>
            </div>
          </div>
          <!-- /Modal -->
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="alert alert-info mt-3 shadow-sm"><i class="bi bi-inboxes me-1"></i>No hay traspasos pendientes para los criterios seleccionados.</div>
  <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}

// Abre el modal y muestra conteos Aceptados / Rechazados / Total
function openConfirmModal(idT, destinoNombre){
  const form = document.getElementById('formT_' + idT);
  const total = form.querySelectorAll('.chk-item-' + idT).length;
  const ok    = form.querySelectorAll('.chk-item-' + idT + ':checked').length;
  const rej   = total - ok;

  document.getElementById('conf_total_' + idT).textContent = total;
  document.getElementById('conf_ok_'    + idT).textContent = ok;
  document.getElementById('conf_rej_'   + idT).textContent = rej;
  document.getElementById('dest_'       + idT).textContent = destinoNombre || '';

  const modalEl = document.getElementById('modal_' + idT);
  const modal = new bootstrap.Modal(modalEl);
  modal.show();
}
</script>
</body>
</html>
