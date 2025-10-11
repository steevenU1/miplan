<?php
// generar_traspaso_mp_almacen.php — Mi Plan (equipos + accesorios en carrito, fixes IMEI vacíos)
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin','Gerente'], true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';

$mensaje    = '';
$acuseUrl   = '';
$acuseReady = false;

/* ===============================
   1) Resolver ID de "MP Almacen General"
   =============================== */
$CENTRAL_NAME = 'MP Almacen General';
$idCentral = 0;

if ($stmt = $conn->prepare("
  SELECT id
  FROM sucursales
  WHERE LOWER(nombre) IN (
    'mp almacen general',
    'mp almacén general',
    'almacen general',
    'almacén general',
    'mp almacen',
    'mp almacén'
  )
  LIMIT 1
")) {
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $idCentral = (int)$row['id'];
  $stmt->close();
}
if ($idCentral <= 0) {
  $rs = $conn->query("
    SELECT id
    FROM sucursales
    WHERE LOWER(nombre) LIKE '%mp%' AND LOWER(nombre) LIKE '%almacen%' AND LOWER(nombre) LIKE '%general%'
    ORDER BY LENGTH(nombre) ASC
    LIMIT 1
  ");
  if ($rs && $r = $rs->fetch_assoc()) $idCentral = (int)$r['id'];
}
if ($idCentral <= 0) {
  $rs2 = $conn->query("
    SELECT id
    FROM sucursales
    WHERE LOWER(nombre) LIKE '%almacen%' AND LOWER(nombre) LIKE '%general%'
    ORDER BY LENGTH(nombre) ASC
    LIMIT 1
  ");
  if ($rs2 && $r2 = $rs2->fetch_assoc()) $idCentral = (int)$r2['id'];
}

if ($idCentral <= 0) {
  echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se encontró la sucursal central “{$CENTRAL_NAME}”.</div></div>";
  exit();
}

/* ===============================
   2) Procesar TRASPASO (POST)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $equiposSeleccionados = isset($_POST['equipos']) && is_array($_POST['equipos']) ? $_POST['equipos'] : [];
  $idSucursalDestino    = (int)($_POST['sucursal_destino'] ?? 0);
  $idUsuario            = (int)($_SESSION['id_usuario'] ?? 0);

  // Accesorios: arrays paralelos (id_producto[], qty[])
  $acc_ids = isset($_POST['acc_id_producto']) && is_array($_POST['acc_id_producto']) ? $_POST['acc_id_producto'] : [];
  $acc_qty = isset($_POST['acc_qty']) && is_array($_POST['acc_qty']) ? $_POST['acc_qty'] : [];

  $accToMove = [];
  foreach ($acc_ids as $i => $pidRaw) {
    $pid = (int)$pidRaw;
    $q   = isset($acc_qty[$i]) ? (int)$acc_qty[$i] : 0;
    if ($pid > 0 && $q > 0) $accToMove[] = ['id_producto'=>$pid, 'cantidad'=>$q];
  }

  if ($idSucursalDestino <= 0) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  Selecciona una sucursal destino.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } elseif (empty($equiposSeleccionados) && empty($accToMove)) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  No seleccionaste ningún equipo ni capturaste cantidades de accesorios.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } elseif ($idSucursalDestino === $idCentral) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                  El destino no puede ser {$CENTRAL_NAME}.
                  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                </div>";
  } else {
    $idsInv = array_values(array_unique(array_map('intval', $equiposSeleccionados)));

    $conn->begin_transaction();
    try {
      // Cabecera
      $stmt = $conn->prepare("
        INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus)
        VALUES (?,?,?, 'Pendiente')
      ");
      $stmt->bind_param("iii", $idCentral, $idSucursalDestino, $idUsuario);
      $stmt->execute();
      $idTraspaso = (int)$stmt->insert_id;
      $stmt->close();

      /* ===== Equipos (unitarios) ===== */
      if (!empty($idsInv)) {
        $placeholders = implode(',', array_fill(0, count($idsInv), '?'));
        $typesIds = str_repeat('i', count($idsInv));
        $sqlVal = "
          SELECT i.id
          FROM inventario i
          WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($placeholders)
        ";
        $stmtVal = $conn->prepare($sqlVal);
        $typesFull = 'i' . $typesIds;
        $stmtVal->bind_param($typesFull, $idCentral, ...$idsInv);
        $stmtVal->execute();
        $rsVal = $stmtVal->get_result();
        $validos = [];
        while ($r = $rsVal->fetch_assoc()) $validos[] = (int)$r['id'];
        $stmtVal->close();
        if (count($validos) !== count($idsInv)) {
          throw new Exception("Algunos equipos ya no están disponibles en {$CENTRAL_NAME} o cambiaron de estatus.");
        }

        $stmtDet = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?,?)");
        $stmtUpd = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=? AND id_sucursal=? AND estatus='Disponible'");

        foreach ($validos as $idInv) {
          $stmtDet->bind_param("ii", $idTraspaso, $idInv);
          $stmtDet->execute();

          $stmtUpd->bind_param("ii", $idInv, $idCentral);
          $stmtUpd->execute();
          if ($stmtUpd->affected_rows !== 1) throw new Exception("Fallo al actualizar inventario #$idInv (equipos)");
        }
        $stmtDet->close();
        $stmtUpd->close();
      }

      /* ===== Accesorios (por cantidad) ===== */
      if (!empty($accToMove)) {
        $stmtSum = $conn->prepare("
          SELECT COALESCE(SUM(i.cantidad),0) AS disp
          FROM inventario i
          INNER JOIN productos p ON p.id = i.id_producto
          WHERE i.id_sucursal=? AND i.estatus='Disponible' AND p.id=? 
            AND (
              p.tipo_producto='Accesorio'
              OR COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NULL
            )
        ");
        $stmtPickRows = $conn->prepare("
          SELECT i.id, i.cantidad
          FROM inventario i
          INNER JOIN productos p ON p.id = i.id_producto
          WHERE i.id_sucursal=? AND i.estatus='Disponible' AND p.id=?
          ORDER BY i.id ASC
          FOR UPDATE
        ");
        $stmtDec = $conn->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=? AND cantidad >= ?");
        $stmtDel = $conn->prepare("DELETE FROM inventario WHERE id=? AND cantidad <= 0");
        $stmtInsTransit = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus, cantidad) VALUES (?, ?, 'En tránsito', ?)");
        $stmtDetAcc = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?, ?)");

        foreach ($accToMove as $acc) {
          $pid = (int)$acc['id_producto'];
          $q   = (int)$acc['cantidad'];
          if ($pid <= 0 || $q <= 0) continue;

          // Validar disponible
          $stmtSum->bind_param("ii", $idCentral, $pid);
          $stmtSum->execute();
          $stmtSum->bind_result($disp);
          $stmtSum->fetch();
          $stmtSum->free_result();
          if ((int)$disp < $q) throw new Exception("Stock insuficiente de accesorio (#$pid). Disponible: {$disp}, solicitado: {$q}");

          // Descontar FIFO
          $rem = $q;
          $stmtPickRows->bind_param("ii", $idCentral, $pid);
          $stmtPickRows->execute();
          $resRows = $stmtPickRows->get_result();
          while ($rem > 0 && ($row = $resRows->fetch_assoc())) {
            $iid = (int)$row['id'];
            $can = (int)$row['cantidad'];
            if ($can <= 0) continue;
            if ($can >= $rem) {
              $stmtDec->bind_param("iii", $rem, $iid, $rem);
              if (!$stmtDec->execute()) throw new Exception("No se pudo descontar inventario accesorio #$iid");
              $stmtDel->bind_param("i", $iid); $stmtDel->execute();
              $rem = 0; break;
            } else {
              $stmtDec->bind_param("iii", $can, $iid, $can);
              if (!$stmtDec->execute()) throw new Exception("No se pudo descontar inventario accesorio #$iid");
              $stmtDel->bind_param("i", $iid); $stmtDel->execute();
              $rem -= $can;
            }
          }
          $resRows->free();
          if ($rem > 0) throw new Exception("No se pudo completar descuento de accesorio (#$pid).");

          // Crear fila En tránsito y detalle
          $stmtInsTransit->bind_param("iii", $pid, $idCentral, $q);
          if (!$stmtInsTransit->execute()) throw new Exception("No se pudo crear 'En tránsito' (accesorio).");
          $idInvTransit = (int)$stmtInsTransit->insert_id;

          $stmtDetAcc->bind_param("ii", $idTraspaso, $idInvTransit);
          if (!$stmtDetAcc->execute()) throw new Exception("No se pudo vincular detalle (accesorio).");
        }

        $stmtSum->close();
        $stmtPickRows->close();
        $stmtDec->close();
        $stmtDel->close();
        $stmtInsTransit->close();
        $stmtDetAcc->close();
      }

      $conn->commit();

      $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&print=1";
      $acuseReady = true;

      $mensaje = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                    <i class='bi bi-check-circle me-1'></i>
                    <strong>Traspaso #{$idTraspaso}</strong> generado con éxito. Artículos en <b>En tránsito</b>.
                    <div class='small text-muted mt-1'>Se abrirá el acuse en vista previa.</div>
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    } catch (Throwable $e) {
      $conn->rollback();
      $mensaje = "<div class='alert alert-danger alert-dismissible fade show shadow-sm' role='alert'>
                    Error al generar traspaso: ".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Cerrar'></button>
                  </div>";
    }
  }
}

/* ===============================
   3) Inventario EQUIPOS (unitarios / con IMEI)
   — FIX: aceptar IMEI no vacío ('' ≠ NULL)
   =============================== */
$sqlEq = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? 
  AND i.estatus='Disponible'
  AND COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NOT NULL
ORDER BY i.fecha_ingreso ASC, i.id ASC
";
$stmtEq = $conn->prepare($sqlEq);
$stmtEq->bind_param("i", $idCentral);
$stmtEq->execute();
$resultEq = $stmtEq->get_result();
$inventario = $resultEq->fetch_all(MYSQLI_ASSOC);
$stmtEq->close();

/* ===============================
   3b) Inventario ACCESORIOS (por cantidad, agrupado)
   — FIX: tratar IMEIs vacíos como NULL
   =============================== */
$sqlAcc = "
SELECT 
  p.id            AS id_producto,
  p.marca, p.modelo, p.color,
  COALESCE(SUM(i.cantidad),0) AS disponible
FROM inventario i
INNER JOIN productos p ON p.id = i.id_producto
WHERE i.id_sucursal=? 
  AND i.estatus='Disponible'
  AND (
    p.tipo_producto='Accesorio'
    OR COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NULL
  )
GROUP BY p.id, p.marca, p.modelo, p.color
HAVING COALESCE(SUM(i.cantidad),0) > 0
ORDER BY p.marca, p.modelo, p.color
";
$stmtAcc = $conn->prepare($sqlAcc);
$stmtAcc->bind_param("i", $idCentral);
$stmtAcc->execute();
$resAcc = $stmtAcc->get_result();
$accesorios = $resAcc->fetch_all(MYSQLI_ASSOC);
$stmtAcc->close();

/* ===============================
   4) Sucursales DESTINO
   =============================== */
$sucursales = [];
$resSuc = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE LOWER(tipo_sucursal)='tienda' AND id <> {$idCentral}
  ORDER BY nombre ASC
");
while ($row = $resSuc->fetch_assoc()) $sucursales[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Traspaso (<?php echo htmlspecialchars($CENTRAL_NAME); ?>)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap / Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    body { background:#f7f8fb; }
    .page-header{
      background:linear-gradient(180deg,#ffffff,#f4f6fb);
      border:1px solid #eef0f6; border-radius:18px; padding:18px 20px;
      box-shadow:0 6px 20px rgba(18,38,63,.06);
    }
    .muted{ color:#6c757d; }
    .card{ border:1px solid #eef0f6; border-radius:16px; overflow:hidden; }
    .card-header{ background:#fff; border-bottom:1px solid #eef0f6; }
    .form-select,.form-control{ border-radius:12px; border-color:#e6e9f2; }
    .form-select:focus,.form-control:focus{ box-shadow:0 0 0 .25rem rgba(13,110,253,.08); border-color:#c6d4ff; }
    .search-wrap{
      position:sticky; top:82px; z-index:7;
      background:linear-gradient(180deg,#ffffff 40%,rgba(255,255,255,.7));
      padding:10px; border-bottom:1px solid #eef0f6; border-radius:12px;
    }
    #tablaInventario thead.sticky{ position:sticky; top:0; z-index:5; background:#fff; }
    #tablaInventario tbody tr:hover{ background:#f1f5ff !important; }
    #tablaInventario th{ white-space:nowrap; }
    .chip{ border:1px solid #e6e9f2; border-radius:999px; padding:.25rem .6rem; background:#fff; font-size:.8rem; }
    .table code{ color:inherit; background:#f8fafc; padding:2px 6px; border-radius:6px; }
    .sticky-aside{ position:sticky; top:92px; }

    .modal-xxl { max-width: 1200px; }
    #frameAcuse { width:100%; min-height:72vh; border:0; background:#fff; }

    #tablaAccesorios thead.sticky{ position:sticky; top:0; z-index:5; background:#fff; }
    #tablaAccesorios th{ white-space:nowrap; }
    .qty-input{ max-width: 120px; }
  </style>
</head>
<body>

<?php include __DIR__.'/navbar.php'; ?>

<div class="container my-4">

  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1"><i class="bi bi-arrow-left-right me-2"></i>Generar traspaso</h1>
      <div class="muted">
        <span class="badge rounded-pill text-bg-light border">
          <i class="bi bi-house-gear me-1"></i>Origen: <?php echo htmlspecialchars($CENTRAL_NAME); ?>
        </span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($acuseUrl): ?>
        <a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?= htmlspecialchars($acuseUrl) ?>">
          <i class="bi bi-printer me-1"></i> Imprimir acuse
        </a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="traspasos_salientes.php">
        <i class="bi bi-clock-history me-1"></i>Histórico
      </a>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-4">
    <!-- Izquierda -->
    <div class="col-lg-8">
      <form id="formTraspaso" method="POST">
        <div class="card shadow-sm mb-4">
          <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-geo-alt text-primary"></i>
              <strong>Seleccionar sucursal destino</strong>
            </div>
            <span class="muted small">Requerido</span>
          </div>
          <div class="card-body">
            <div class="row g-2 mb-2">
              <div class="col-md-8">
                <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
                  <option value="">— Selecciona Sucursal —</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 d-flex align-items-center">
                <div class="muted small" id="miniDestino">Destino: —</div>
              </div>
            </div>

            <!-- Buscador sticky (EQUIPOS) -->
            <div class="search-wrap rounded-3 mb-2">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="buscadorIMEI" class="form-control" placeholder="Buscar por IMEI, marca o modelo (EQUIPOS)...">
                <button type="button" class="btn btn-outline-secondary" id="btnLimpiarBusqueda">
                  <i class="bi bi-x-circle"></i>
                </button>
              </div>
            </div>

            <!-- Inventario EQUIPOS -->
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-phone text-primary"></i>
                  <span><strong>Equipos (unitarios)</strong> disponibles en <?php echo htmlspecialchars($CENTRAL_NAME); ?></span>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="checkAll">
                  <label class="form-check-label" for="checkAll">Seleccionar visibles</label>
                </div>
              </div>

              <div class="table-responsive" style="max-height:360px; overflow:auto;">
                <table class="table table-hover align-middle mb-0" id="tablaInventario">
                  <thead class="sticky">
                    <tr>
                      <th class="text-center">Sel</th>
                      <th>ID Inv</th>
                      <th>Marca</th>
                      <th>Modelo</th>
                      <th>Color</th>
                      <th>IMEI1</th>
                      <th>IMEI2</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($inventario)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inboxes me-1"></i>Sin equipos disponibles en <?php echo htmlspecialchars($CENTRAL_NAME); ?></td></tr>
                    <?php else: foreach ($inventario as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>">
                        <td class="text-center">
                          <input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo form-check-input">
                        </td>
                        <td class="td-id fw-semibold"><?= (int)$row['id'] ?></td>
                        <td class="td-marca"><?= htmlspecialchars($row['marca']) ?></td>
                        <td class="td-modelo"><?= htmlspecialchars($row['modelo']) ?></td>
                        <td class="td-color"><span class="chip"><?= htmlspecialchars($row['color']) ?></span></td>
                        <td class="td-imei1"><code><?= htmlspecialchars($row['imei1']) ?></code></td>
                        <td class="td-imei2"><?= $row['imei2'] ? "<code>".htmlspecialchars($row['imei2'])."</code>" : "—" ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ACCESORIOS -->
            <div class="card shadow-sm mt-4">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-headphones text-success"></i>
                  <span><strong>Accesorios (por piezas)</strong> disponibles en <?php echo htmlspecialchars($CENTRAL_NAME); ?></span>
                </div>
                <div class="input-group" style="max-width: 360px;">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="text" id="buscadorACC" class="form-control" placeholder="Buscar accesorio por texto...">
                  <button type="button" class="btn btn-outline-secondary" id="btnLimpiarAcc"><i class="bi bi-x-circle"></i></button>
                </div>
              </div>
              <div class="table-responsive" style="max-height:360px; overflow:auto;">
                <table class="table table-hover align-middle mb-0" id="tablaAccesorios">
                  <thead class="sticky">
                    <tr>
                      <th>Producto</th>
                      <th>Color</th>
                      <th class="text-end">Disponible</th>
                      <th style="width:160px;">Traspasar</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($accesorios)): ?>
                      <tr><td colspan="4" class="text-center text-muted py-4"><i class="bi bi-inboxes me-1"></i>Sin accesorios disponibles en <?php echo htmlspecialchars($CENTRAL_NAME); ?></td></tr>
                    <?php else: foreach ($accesorios as $a): ?>
                      <tr>
                        <td>
                          <strong class="acc-label"><?= htmlspecialchars($a['marca'].' '.$a['modelo']) ?></strong>
                          <input type="hidden" name="acc_id_producto[]" value="<?= (int)$a['id_producto'] ?>">
                        </td>
                        <td class="acc-color"><?= htmlspecialchars($a['color'] ?? '—') ?></td>
                        <td class="text-end fw-semibold acc-disp"><?= (int)$a['disponible'] ?></td>
                        <td>
                          <input type="number" class="form-control qty-input acc-qty" name="acc_qty[]" min="0" max="<?= (int)$a['disponible'] ?>" value="0">
                          <div class="form-text small">Máx: <?= (int)$a['disponible'] ?></div>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <button type="button" id="btnConfirmar" class="btn btn-primary">
                <i class="bi bi-shuffle me-1"></i>Confirmar traspaso
              </button>
              <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-eraser me-1"></i>Limpiar
              </button>
            </div>

            <!-- Modal confirmación -->
            <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check2-square me-1"></i>Confirmar traspaso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-3 mb-2">
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Origen</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($CENTRAL_NAME); ?></div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Destino</div>
                        <div class="fw-semibold" id="resSucursal">—</div>
                      </div>
                      <div class="col-md-4">
                        <div class="small text-uppercase text-muted">Totales</div>
                        <div class="fw-semibold">
                          <span id="resCantidad">0</span> equipos ·
                          <span id="resAccSum">0</span> accesorios
                        </div>
                      </div>
                    </div>
                    <div class="mb-2 small text-muted">Equipos seleccionados:</div>
                    <div class="table-responsive">
                      <table class="table table-sm table-striped align-middle mb-0">
                        <thead><tr><th>ID</th><th>Marca</th><th>Modelo</th><th>IMEI1</th><th>IMEI2</th></tr></thead>
                        <tbody id="resTbody"></tbody>
                      </table>
                    </div>
                    <div class="mt-3">
                      <div class="small text-muted">Accesorios a traspasar (resumen):</div>
                      <ul id="resAccList" class="small mb-0"></ul>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>Generar traspaso</button>
                  </div>
                </div>
              </div>
            </div>

          </div> <!-- card-body -->
        </div> <!-- card -->
      </form>
    </div>

    <!-- Derecha / Carrito sticky (equipos + accesorios) -->
    <div class="col-lg-4">
      <div class="card shadow-sm sticky-aside">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check2-square text-info"></i><strong>Selección actual</strong>
          </div>
          <span class="badge bg-dark" id="badgeCount">0</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:360px; overflow:auto;">
            <table class="table table-sm mb-0" id="tablaSeleccion">
              <thead class="table-light">
                <tr><th>ID/Prod</th><th>Detalle</th><th>Info</th><th></th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted" id="miniDestinoFooter">Revisa la selección antes de confirmar</small>
          <button class="btn btn-primary btn-sm" id="btnAbrirModal" disabled>
            <i class="bi bi-clipboard-check me-1"></i>Confirmar (<span id="btnCount">0</span>)
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal ACUSE (iframe) -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de entrega</h5>
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

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// ------- Filtro EQUIPOS -------
const buscador = document.getElementById('buscadorIMEI');
buscador.addEventListener('keyup', () => {
  const f = buscador.value.toLowerCase();
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(f) ? '' : 'none';
  });
});
document.getElementById('btnLimpiarBusqueda').addEventListener('click', () => {
  buscador.value = ''; buscador.dispatchEvent(new Event('keyup')); buscador.focus();
});

// ------- Filtro ACCESORIOS -------
const buscAcc = document.getElementById('buscadorACC');
buscAcc.addEventListener('keyup', ()=>{
  const f = buscAcc.value.toLowerCase();
  document.querySelectorAll('#tablaAccesorios tbody tr').forEach(tr=>{
    const txt = tr.innerText.toLowerCase();
    tr.style.display = txt.includes(f) ? '' : 'none';
  });
});
document.getElementById('btnLimpiarAcc').addEventListener('click', ()=>{
  buscAcc.value=''; buscAcc.dispatchEvent(new Event('keyup')); buscAcc.focus();
});

// ------- Seleccionar visibles (equipos) -------
document.getElementById('checkAll').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('#tablaInventario tbody tr').forEach(tr=>{
    if (tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-equipo'); if (chk) chk.checked = checked;
    }
  });
  rebuildSelection();
});

// ------- Helpers accesorios -------
function accSumCurrent(){
  let s = 0;
  document.querySelectorAll('#tablaAccesorios input.acc-qty').forEach(inp=>{
    const v = parseInt(inp.value||'0',10);
    if (!isNaN(v) && v>0) s += v;
  });
  return s;
}
function enableConfirmIfAny(){
  const eqCount = document.querySelectorAll('.chk-equipo:checked').length;
  const accCount = accSumCurrent();
  const any = (eqCount>0 || accCount>0);
  document.getElementById('btnAbrirModal').disabled = !any;
}

// ------- Carrito (equipos + accesorios) -------
function rebuildSelection(){
  const tbody = document.querySelector('#tablaSeleccion tbody');
  tbody.innerHTML = '';
  let eqCount = 0;
  let accCount = 0;

  // Equipos
  document.querySelectorAll('.chk-equipo:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei  = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `
      <td class="fw-semibold">#${id}</td>
      <td>${marca} ${modelo}</td>
      <td><code>${imei}</code></td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger item-remove" data-kind="eq" data-id="${id}">
          <i class="bi bi-x-lg"></i>
        </button>
      </td>`;
    tbody.appendChild(row);
    eqCount++;
  });

  // Accesorios
  document.querySelectorAll('#tablaAccesorios tbody tr').forEach(tr=>{
    const qtyEl = tr.querySelector('input.acc-qty');
    const qty = parseInt(qtyEl?.value||'0',10);
    if (!qty || qty<=0) return;

    const idProd = tr.querySelector('input[name="acc_id_producto[]"]').value;
    const label  = tr.querySelector('.acc-label')?.innerText?.trim() || 'Accesorio';
    const color  = tr.querySelector('.acc-color')?.innerText?.trim() || '';
    const info   = `${color ? color+' · ' : ''}${qty} pza(s)`;

    const row = document.createElement('tr');
    row.innerHTML = `
      <td class="fw-semibold">P#${idProd}</td>
      <td>${label}</td>
      <td>${info}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger item-remove" data-kind="acc" data-pid="${idProd}">
          <i class="bi bi-x-lg"></i>
        </button>
      </td>`;
    tbody.appendChild(row);
    accCount += qty;
  });

  // Totales en chip y botón
  document.getElementById('badgeCount').textContent = `${eqCount}·${accCount}`;
  document.getElementById('btnCount').textContent = `${eqCount}·${accCount}`;

  enableConfirmIfAny();
}
document.querySelectorAll('.chk-equipo').forEach(chk => chk.addEventListener('change', rebuildSelection));
document.querySelectorAll('#tablaAccesorios input.acc-qty').forEach(inp=>{
  inp.addEventListener('input', ()=>{
    const max = parseInt(inp.getAttribute('max')||'0',10);
    let v = parseInt(inp.value||'0',10);
    if (v<0) v=0; if (v>max) v=max; inp.value = v;
    rebuildSelection();
  });
});

// Quitar desde carrito (equipo o accesorio)
document.querySelector('#tablaSeleccion tbody').addEventListener('click', (e)=>{
  const btn = e.target.closest('.item-remove'); if (!btn) return;
  const kind = btn.getAttribute('data-kind');
  if (kind === 'eq') {
    const id = btn.getAttribute('data-id');
    const chk = document.querySelector(`.chk-equipo[value="${id}"]`);
    if (chk) chk.checked = false;
  } else if (kind === 'acc') {
    const pid = btn.getAttribute('data-pid');
    document.querySelectorAll('#tablaAccesorios tbody tr').forEach(tr=>{
      const hidden = tr.querySelector('input[name="acc_id_producto[]"]');
      if (hidden && hidden.value === pid) {
        const qtyEl = tr.querySelector('input.acc-qty');
        if (qtyEl) qtyEl.value = 0;
      }
    });
  }
  rebuildSelection();
});

// ------- Destino en textos auxiliares -------
document.getElementById('sucursal_destino').addEventListener('change', function(){
  const txt = this.options[this.selectedIndex]?.text || '—';
  document.getElementById('miniDestino').textContent = `Destino: ${txt}`;
  document.getElementById('miniDestinoFooter').textContent = `Destino: ${txt}`;
});

// ------- Modal resumen -------
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen(){
  const sel = document.getElementById('sucursal_destino');
  const seleccionados = document.querySelectorAll('.chk-equipo:checked');

  const accItems = Array.from(document.querySelectorAll('#tablaAccesorios tbody tr'))
    .map(tr=>{
      const pid = tr.querySelector('input[name="acc_id_producto[]"]').value;
      const qty = parseInt(tr.querySelector('input.acc-qty').value||'0',10);
      const prodTxt = tr.querySelector('.acc-label')?.innerText?.trim() || 'Accesorio';
      const color = tr.querySelector('.acc-color')?.innerText?.trim() || '';
      return {pid, qty, prodTxt, color};
    })
    .filter(x=>x.qty>0);

  if (!sel.value){ alert('Selecciona una sucursal destino.'); sel.focus(); return; }
  if (seleccionados.length === 0 && accItems.length === 0){ alert('Selecciona equipos o captura cantidades de accesorios.'); return; }

  document.getElementById('resSucursal').textContent = sel.options[sel.selectedIndex].text;
  document.getElementById('resCantidad').textContent = seleccionados.length;
  document.getElementById('resAccSum').textContent = accItems.reduce((a,b)=>a+b.qty,0);

  const tbody = document.getElementById('resTbody'); tbody.innerHTML = '';
  seleccionados.forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const imei2 = tr.querySelector('.td-imei2').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>${id}</td><td>${marca}</td><td>${modelo}</td><td>${imei1}</td><td>${imei2 || '—'}</td>`;
    tbody.appendChild(row);
  });

  const ul = document.getElementById('resAccList'); ul.innerHTML = '';
  accItems.forEach(it=>{
    const li = document.createElement('li');
    li.textContent = `${it.prodTxt}${it.color ? ' · '+it.color: ''} — ${it.qty} pza(s)`;
    ul.appendChild(li);
  });

  modalResumen.show();
}
document.getElementById('btnAbrirModal').addEventListener('click', openResumen);
document.getElementById('btnConfirmar').addEventListener('click', openResumen);

// Inicial
enableConfirmIfAny();
rebuildSelection();

// ===== Modal ACUSE =====
const ACUSE_URL = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;
if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>
