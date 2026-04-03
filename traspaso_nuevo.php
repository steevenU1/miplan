<?php
// traspaso_nuevo.php — Traspaso entre sucursales (origen = sucursal actual; destino = otra sucursal o Eulalia)
// Versión con pestañas: Equipos / Accesorios / Scooters + modal de ACUSE auto-impreso
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');

$mensaje     = "";
$acuseUrl    = "";
$acuseReady  = false;

// Helper seguro
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================
   Procesar traspaso (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sucursal_destino'])) {
    $sucursalDestino = (int)$_POST['sucursal_destino'];

    // Equipos (unitarios generales)
    $equiposSeleccionados = isset($_POST['equipos']) && is_array($_POST['equipos']) ? $_POST['equipos'] : [];
    // Scooters (unitarios)
    $scootersSeleccionados = isset($_POST['scooters']) && is_array($_POST['scooters']) ? $_POST['scooters'] : [];

    // Accesorios por cantidad
    $acc_ids = isset($_POST['acc_id_producto']) && is_array($_POST['acc_id_producto']) ? $_POST['acc_id_producto'] : [];
    $acc_qty = isset($_POST['acc_qty']) && is_array($_POST['acc_qty']) ? $_POST['acc_qty'] : [];

    $accToMove = [];
    foreach ($acc_ids as $i => $pidRaw) {
        $pid = (int)$pidRaw;
        $q   = isset($acc_qty[$i]) ? (int)$acc_qty[$i] : 0;
        if ($pid > 0 && $q > 0) {
            $accToMove[] = ['id_producto' => $pid, 'cantidad' => $q];
        }
    }

    $idsEquipos   = array_values(array_unique(array_map('intval', $equiposSeleccionados)));
    $idsScooters  = array_values(array_unique(array_map('intval', $scootersSeleccionados)));
    $idsUnitarios = array_values(array_unique(array_merge($idsEquipos, $idsScooters)));

    if ($sucursalDestino <= 0) {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>Selecciona una sucursal destino.</div>";
    } elseif ($sucursalDestino === $idSucursal) {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>El destino no puede ser la misma sucursal de origen.</div>";
    } elseif (empty($idsUnitarios) && empty($accToMove)) {
        $mensaje = "<div class='alert alert-warning card-surface mt-3'>⚠️ Debes seleccionar al menos un artículo (equipo, scooter o accesorio).</div>";
    } else {
        $conn->begin_transaction();
        try {
            /* 1) Cabecera (Pendiente) */
            $stmtCab = $conn->prepare("
                INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, fecha_traspaso, estatus, usuario_creo)
                VALUES ( ?, ?, NOW(), 'Pendiente', ? )
            ");
            $stmtCab->bind_param("iii", $idSucursal, $sucursalDestino, $idUsuario);
            $stmtCab->execute();
            $idTraspaso = (int)$stmtCab->insert_id;
            $stmtCab->close();

            /* 2) Unitarios (EQUIPOS + SCOOTERS) -> detalle_traspaso + inventario En tránsito */
            if (!empty($idsUnitarios)) {
                $placeholders = implode(',', array_fill(0, count($idsUnitarios), '?'));
                $typesIds     = str_repeat('i', count($idsUnitarios));
                $sqlVal = "
                  SELECT i.id
                  FROM inventario i
                  WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($placeholders)
                ";
                $stmtVal  = $conn->prepare($sqlVal);
                $typesFull = 'i' . $typesIds;
                $stmtVal->bind_param($typesFull, $idSucursal, ...$idsUnitarios);
                $stmtVal->execute();
                $rsVal = $stmtVal->get_result();
                $validos = [];
                while ($r = $rsVal->fetch_assoc()) {
                    $validos[] = (int)$r['id'];
                }
                $stmtVal->close();

                if (count($validos) !== count($idsUnitarios)) {
                    throw new Exception("Algunos equipos/scooters ya no están disponibles en esta sucursal o cambiaron de estatus. Refresca e intenta de nuevo.");
                }

                // Guardamos cantidad=1 en detalle_traspaso
                $stmtDet = $conn->prepare("
                    INSERT INTO detalle_traspaso (id_traspaso, id_inventario, cantidad)
                    VALUES (?, ?, 1)
                ");
                $stmtUpd = $conn->prepare("
                    UPDATE inventario
                    SET estatus='En tránsito'
                    WHERE id=? AND id_sucursal=? AND estatus='Disponible'
                ");

                foreach ($validos as $idInv) {
                    $stmtDet->bind_param("ii", $idTraspaso, $idInv);
                    $stmtDet->execute();

                    $stmtUpd->bind_param("ii", $idInv, $idSucursal);
                    $stmtUpd->execute();
                    if ($stmtUpd->affected_rows !== 1) {
                        throw new Exception("Fallo al actualizar inventario #$idInv");
                    }
                }
                $stmtDet->close();
                $stmtUpd->close();
            }

            /* 3) Accesorios (por cantidad) -> mismo patrón que MP Almacén */
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
                $stmtDetAcc = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario, cantidad) VALUES (?, ?, ?)");

                foreach ($accToMove as $acc) {
                    $pid = (int)$acc['id_producto'];
                    $q   = (int)$acc['cantidad'];
                    if ($pid <= 0 || $q <= 0) continue;

                    // Validar disponible
                    $stmtSum->bind_param("ii", $idSucursal, $pid);
                    $stmtSum->execute();
                    $stmtSum->bind_result($disp);
                    $stmtSum->fetch();
                    $stmtSum->free_result();

                    if ((int)$disp < $q) {
                        throw new Exception("Stock insuficiente de accesorio (#$pid). Disponible: {$disp}, solicitado: {$q}");
                    }

                    // Descontar FIFO en origen
                    $rem = $q;
                    $stmtPickRows->bind_param("ii", $idSucursal, $pid);
                    $stmtPickRows->execute();
                    $resRows = $stmtPickRows->get_result();
                    while ($rem > 0 && ($row = $resRows->fetch_assoc())) {
                        $iid = (int)$row['id'];
                        $can = (int)$row['cantidad'];
                        if ($can <= 0) continue;

                        if ($can >= $rem) {
                            $stmtDec->bind_param("iii", $rem, $iid, $rem);
                            if (!$stmtDec->execute()) {
                                throw new Exception("No se pudo descontar inventario accesorio #$iid");
                            }
                            $stmtDel->bind_param("i", $iid);
                            $stmtDel->execute();
                            $rem = 0;
                            break;
                        } else {
                            $stmtDec->bind_param("iii", $can, $iid, $can);
                            if (!$stmtDec->execute()) {
                                throw new Exception("No se pudo descontar inventario accesorio #$iid");
                            }
                            $stmtDel->bind_param("i", $iid);
                            $stmtDel->execute();
                            $rem -= $can;
                        }
                    }
                    $resRows->free();
                    if ($rem > 0) {
                        throw new Exception("No se pudo completar descuento de accesorio (#$pid).");
                    }

                    // Crear fila En tránsito en origen y ligarla al traspaso
                    $stmtInsTransit->bind_param("iii", $pid, $idSucursal, $q);
                    if (!$stmtInsTransit->execute()) {
                        throw new Exception("No se pudo crear 'En tránsito' (accesorio).");
                    }
                    $idInvTransit = (int)$stmtInsTransit->insert_id;

                    $stmtDetAcc->bind_param("iii", $idTraspaso, $idInvTransit, $q);
                    if (!$stmtDetAcc->execute()) {
                        throw new Exception("No se pudo vincular detalle (accesorio).");
                    }
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

            $mensaje = "<div class='alert alert-success card-surface mt-3'>
                ✅ <strong>Traspaso #{$idTraspaso}</strong> generado. Los artículos ahora están <b>En tránsito</b>.
                <div class='small text-muted mt-1'>El acuse se abrirá en un cuadro de vista previa para imprimir.</div>
            </div>";
        } catch (Throwable $e) {
            $conn->rollback();
            $mensaje = "<div class='alert alert-danger card-surface mt-3'>Error al generar traspaso: ".h($e->getMessage())."</div>";
        }
    }
}

/* =========================
   Sucursales destino (todas menos origen)
========================= */
$sqlSucursales = "SELECT id, nombre FROM sucursales WHERE id != ? ORDER BY nombre";
$stmtSuc = $conn->prepare($sqlSucursales);
$stmtSuc->bind_param("i", $idSucursal);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtSuc->close();

/* =========================
   Inventario disponible en origen
========================= */

// EQUIPOS
$sqlEquipos = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ?
      AND i.estatus = 'Disponible'
      AND COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NOT NULL
      AND (p.tipo_producto IS NULL OR p.tipo_producto NOT IN ('Accesorio','Scooter'))
    ORDER BY p.marca, p.modelo, i.id ASC
";
$stmtEq = $conn->prepare($sqlEquipos);
$stmtEq->bind_param("i", $idSucursal);
$stmtEq->execute();
$resEq = $stmtEq->get_result();
$inventarioEquipos = $resEq->fetch_all(MYSQLI_ASSOC);
$stmtEq->close();

// SCOOTERS
$sqlScooters = "
    SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ?
      AND i.estatus = 'Disponible'
      AND p.tipo_producto = 'Scooter'
    ORDER BY p.marca, p.modelo, i.id ASC
";
$stmtSc = $conn->prepare($sqlScooters);
$stmtSc->bind_param("i", $idSucursal);
$stmtSc->execute();
$resSc = $stmtSc->get_result();
$inventarioScooters = $resSc->fetch_all(MYSQLI_ASSOC);
$stmtSc->close();

// ACCESORIOS
$sqlAcc = "
    SELECT 
      p.id            AS id_producto,
      p.marca, p.modelo, p.color,
      COALESCE(SUM(i.cantidad),0) AS disponible
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id_sucursal = ?
      AND i.estatus = 'Disponible'
      AND (
        p.tipo_producto = 'Accesorio'
        OR COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NULL
      )
    GROUP BY p.id, p.marca, p.modelo, p.color
    HAVING COALESCE(SUM(i.cantidad),0) > 0
    ORDER BY p.marca, p.modelo, p.color
";
$stmtAcc = $conn->prepare($sqlAcc);
$stmtAcc->bind_param("i", $idSucursal);
$stmtAcc->execute();
$resAcc = $stmtAcc->get_result();
$accesorios = $resAcc->fetch_all(MYSQLI_ASSOC);
$stmtAcc->close();

$totalEquipos   = count($inventarioEquipos);
$totalScooters  = count($inventarioScooters);
$totalAccDisp   = 0;
foreach ($accesorios as $a) {
    $totalAccDisp += (int)$a['disponible'];
}
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Nuevo Traspaso</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .row-selected{ background:#f3f6ff !important; }

    .nav-tabs .nav-link{ border:none; border-bottom:2px solid transparent; font-weight:500; color:#6b7280; }
    .nav-tabs .nav-link.active{ border-bottom-color:#0d6efd; color:#0d6efd; }

    .modal-xxl { max-width: 1200px; }
    #frameAcuse{ width:100%; min-height:72vh; border:0; background:#fff; }

    .qty-input{ max-width: 120px; }
  </style>
</head>
<body>

<?php include __DIR__.'/navbar.php'; ?>

<div class="container py-3">
  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🚚 Generar Traspaso entre Sucursales</h1>
      <div class="small-muted">
        Sucursal origen: <strong>#<?= (int)$idSucursal ?></strong> &middot; Usuario: <strong><?= h($nombreUser) ?></strong>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip chip-info">
        <i class="bi bi-phone me-1"></i> Equipos: <?= (int)$totalEquipos ?>
      </span>
      <span class="chip chip-info">
        <i class="bi bi-scooter me-1"></i> Scooters: <?= (int)$totalScooters ?>
      </span>
      <span class="chip chip-success">
        <i class="bi bi-headphones me-1"></i> Accesorios: <?= (int)$totalAccDisp ?>
      </span>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-3 mt-1">
    <div class="col-lg-8">
      <form id="formTraspaso" method="POST" class="card card-surface p-3">

        <div class="row g-3 filters">
          <div class="col-md-6">
            <label class="small-muted">Sucursal destino</label>
            <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="small-muted">Búsqueda rápida (equipos)</label>
            <input type="text" id="buscarIMEI" class="form-control" placeholder="IMEI, marca o modelo… (EQUIPOS)">
          </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mt-3" id="trasTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-equipos-tab" data-bs-toggle="tab" data-bs-target="#tab-equipos" type="button" role="tab">
              <i class="bi bi-phone me-1"></i>Equipos
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-accesorios-tab" data-bs-toggle="tab" data-bs-target="#tab-accesorios" type="button" role="tab">
              <i class="bi bi-headphones me-1"></i>Accesorios
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-scooters-tab" data-bs-toggle="tab" data-bs-target="#tab-scooters" type="button" role="tab">
              <i class="bi bi-scooter me-1"></i>Scooters
            </button>
          </li>
        </ul>

        <div class="tab-content mt-3">
          <!-- TAB EQUIPOS -->
          <div class="tab-pane fade show active" id="tab-equipos" role="tabpanel">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="m-0"><i class="bi bi-phone me-2"></i>Equipos disponibles</h5>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="checkAllEquipos">
                <label class="form-check-label" for="checkAllEquipos">Seleccionar todos (visibles)</label>
              </div>
            </div>
            <div class="mt-1 tbl-wrap">
              <table class="table table-hover align-middle mb-0" id="tablaEquipos">
                <thead class="table-light">
                  <tr>
                    <th style="width:44px"></th>
                    <th>ID Inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>Capacidad</th>
                    <th>IMEI1</th>
                    <th>IMEI2</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($totalEquipos > 0): ?>
                    <?php foreach ($inventarioEquipos as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>">
                        <td><input type="checkbox" name="equipos[]" value="<?= (int)$row['id'] ?>" class="chk-equipo"></td>
                        <td class="td-id"><?= (int)$row['id'] ?></td>
                        <td class="td-marca"><?= h($row['marca']) ?></td>
                        <td class="td-modelo"><?= h($row['modelo']) ?></td>
                        <td><?= h($row['color']) ?></td>
                        <td><?= h($row['capacidad'] ?: '-') ?></td>
                        <td class="td-imei1"><span class="font-monospace"><?= h($row['imei1']) ?></span></td>
                        <td class="td-imei2"><span class="font-monospace"><?= h($row['imei2'] ?: '-') ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="8" class="text-center small-muted">No hay equipos disponibles en esta sucursal.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- TAB ACCESORIOS -->
          <div class="tab-pane fade" id="tab-accesorios" role="tabpanel">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="m-0"><i class="bi bi-headphones me-2"></i>Accesorios por cantidad</h5>
              <div class="input-group" style="max-width: 360px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="buscadorACC" class="form-control" placeholder="Buscar accesorio por texto...">
                <button type="button" class="btn btn-outline-secondary" id="btnLimpiarAcc"><i class="bi bi-x-circle"></i></button>
              </div>
            </div>
            <div class="tbl-wrap">
              <table class="table table-hover align-middle mb-0" id="tablaAccesorios">
                <thead class="table-light">
                  <tr>
                    <th>Producto</th>
                    <th>Color</th>
                    <th class="text-end">Disponible</th>
                    <th style="width:160px;">Traspasar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($accesorios)): ?>
                    <tr><td colspan="4" class="text-center small-muted py-4"><i class="bi bi-inboxes me-1"></i>Sin accesorios disponibles en esta sucursal.</td></tr>
                  <?php else: foreach ($accesorios as $a): ?>
                    <tr>
                      <td>
                        <strong class="acc-label"><?= h($a['marca'].' '.$a['modelo']) ?></strong>
                        <input type="hidden" name="acc_id_producto[]" value="<?= (int)$a['id_producto'] ?>">
                      </td>
                      <td class="acc-color"><?= h($a['color'] ?? '—') ?></td>
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

          <!-- TAB SCOOTERS -->
          <div class="tab-pane fade" id="tab-scooters" role="tabpanel">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="m-0"><i class="bi bi-scooter me-2"></i>Scooters disponibles</h5>
              <div class="d-flex align-items-center gap-2">
                <input type="text" id="buscarScooter" class="form-control form-control-sm" placeholder="Buscar scooter...">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="checkAllScooters">
                  <label class="form-check-label" for="checkAllScooters">Todos (visibles)</label>
                </div>
              </div>
            </div>
            <div class="mt-1 tbl-wrap">
              <table class="table table-hover align-middle mb-0" id="tablaScooters">
                <thead class="table-light">
                  <tr>
                    <th style="width:44px"></th>
                    <th>ID Inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>Capacidad</th>
                    <th>Serie/IMEI1</th>
                    <th>IMEI2</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($totalScooters > 0): ?>
                    <?php foreach ($inventarioScooters as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>">
                        <td><input type="checkbox" name="scooters[]" value="<?= (int)$row['id'] ?>" class="chk-scooter"></td>
                        <td class="td-id"><?= (int)$row['id'] ?></td>
                        <td class="td-marca"><?= h($row['marca']) ?></td>
                        <td class="td-modelo"><?= h($row['modelo']) ?></td>
                        <td><?= h($row['color']) ?></td>
                        <td><?= h($row['capacidad'] ?: '-') ?></td>
                        <td class="td-imei1"><span class="font-monospace"><?= h($row['imei1']) ?></span></td>
                        <td class="td-imei2"><span class="font-monospace"><?= h($row['imei2'] ?: '-') ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr><td colspan="8" class="text-center small-muted">No hay scooters disponibles en esta sucursal.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <div class="text-end mt-3 d-flex justify-content-end gap-2">
          <button type="button" id="btnConfirmar" class="btn btn-primary">
            <i class="bi bi-arrow-right-circle"></i> Confirmar traspaso
          </button>
        </div>

        <!-- Modal de confirmación -->
        <div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check2-square me-2"></i>Confirmar traspaso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">
                <div class="row mb-3">
                  <div class="col-md-4">
                    <p class="mb-1"><b>Destino:</b></p>
                    <p class="mb-0"><span id="resSucursal">—</span></p>
                  </div>
                  <div class="col-md-8">
                    <p class="mb-1"><b>Totales:</b></p>
                    <p class="mb-0">
                      Equipos: <span id="resCantEquipos">0</span> ·
                      Scooters: <span id="resCantScooters">0</span> ·
                      Accesorios: <span id="resCantAcc">0</span>
                    </p>
                  </div>
                </div>

                <div class="tbl-wrap mb-3">
                  <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>Tipo</th><th>ID</th><th>Marca</th><th>Modelo</th><th>IMEI/Serie</th>
                      </tr>
                    </thead>
                    <tbody id="resTbody"></tbody>
                  </table>
                </div>

                <div class="mb-0">
                  <div class="small-muted mb-1">Accesorios:</div>
                  <ul id="resAccList" class="small mb-0"></ul>
                </div>

                <div class="alert alert-warning mt-3 mb-0">
                  <i class="bi bi-info-circle"></i> Los artículos seleccionados cambiarán a <b>“En tránsito”</b> desde la sucursal origen.
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-send-check"></i> Generar traspaso
                </button>
              </div>
            </div>
          </div>
        </div>

      </form>
    </div>

    <!-- Panel lateral de seleccionados -->
    <div class="col-lg-4">
      <div class="card card-surface sticky-top" style="top: 90px;">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-list-check"></i>
            <strong>Selección actual</strong>
          </div>
          <span class="badge text-bg-secondary" id="badgeCount">0</span>
        </div>
        <div class="card-body p-0">
          <div class="tbl-wrap" style="max-height: 360px;">
            <table class="table table-sm mb-0" id="tablaSeleccion">
              <thead class="table-light">
                <tr><th>Tipo</th><th>Detalle</th><th>Info</th><th></th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted" id="miniDestino">Destino: —</small>
          <button type="button" class="btn btn-soft btn-sm" id="btnAbrirModal" disabled>Confirmar (0)</button>
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

<script>
// Helpers front
function norm(s){
  // a minúsculas y sin acentos, sin usar \p{...}
  s = (s || '').toString().toLowerCase();
  try {
    return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  } catch(e){
    return s;
  }
}
function esc(s){
  return (s??'').toString().replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
}

// ===== EQUIPOS =====
const buscarEquipos = document.getElementById('buscarIMEI');
const tablaEquipos  = document.getElementById('tablaEquipos');
const rowsEquipos   = tablaEquipos ? Array.from(tablaEquipos.querySelectorAll('tbody tr')) : [];
const checkAllEquipos = document.getElementById('checkAllEquipos');

function applyFilterEquipos(){
  if (!tablaEquipos) return;
  const q = norm(buscarEquipos?.value || '');
  let visibles = 0, visiblesChecked = 0;
  rowsEquipos.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    tr.style.display = match ? '' : 'none';
    if (match) {
      visibles++;
      const chk = tr.querySelector('.chk-equipo');
      if (chk && chk.checked) visiblesChecked++;
    }
  });
  if (checkAllEquipos) {
    checkAllEquipos.indeterminate = visiblesChecked>0 && visiblesChecked<visibles;
    checkAllEquipos.checked = visibles>0 && visiblesChecked===visibles;
  }
}
buscarEquipos?.addEventListener('input', applyFilterEquipos);

checkAllEquipos?.addEventListener('change', () => {
  const q = norm(buscarEquipos?.value || '');
  rowsEquipos.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    if (match && tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-equipo');
      if (chk) {
        chk.checked = checkAllEquipos.checked;
        tr.classList.toggle('row-selected', chk.checked);
      }
    }
  });
  rebuildSelection();
});

// Click por fila equipos
rowsEquipos.forEach(tr => {
  const chk = tr.querySelector('.chk-equipo');
  tr.addEventListener('click', (e) => {
    if (e.target.tagName.toLowerCase() === 'input') return;
    if (!chk) return;
    chk.checked = !chk.checked;
    tr.classList.toggle('row-selected', chk.checked);
    rebuildSelection();
    applyFilterEquipos();
  });
  if (chk) {
    chk.addEventListener('change', () => {
      tr.classList.toggle('row-selected', chk.checked);
      rebuildSelection();
      applyFilterEquipos();
    });
  }
});

// ===== SCOOTERS =====
const buscarScooter   = document.getElementById('buscarScooter');
const tablaScooters   = document.getElementById('tablaScooters');
const rowsScooters    = tablaScooters ? Array.from(tablaScooters.querySelectorAll('tbody tr')) : [];
const checkAllScooters= document.getElementById('checkAllScooters');

function applyFilterScooters(){
  if (!tablaScooters) return;
  const q = norm(buscarScooter?.value || '');
  let visibles = 0, visiblesChecked = 0;
  rowsScooters.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    tr.style.display = match ? '' : 'none';
    if (match) {
      visibles++;
      const chk = tr.querySelector('.chk-scooter');
      if (chk && chk.checked) visiblesChecked++;
    }
  });
  if (checkAllScooters) {
    checkAllScooters.indeterminate = visiblesChecked>0 && visiblesChecked<visibles;
    checkAllScooters.checked = visibles>0 && visiblesChecked===visibles;
  }
}
buscarScooter?.addEventListener('input', applyFilterScooters);

checkAllScooters?.addEventListener('change', () => {
  const q = norm(buscarScooter?.value || '');
  rowsScooters.forEach(tr => {
    const match = !q || norm(tr.innerText).includes(q);
    if (match && tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-scooter');
      if (chk) {
        chk.checked = checkAllScooters.checked;
        tr.classList.toggle('row-selected', chk.checked);
      }
    }
  });
  rebuildSelection();
});

rowsScooters.forEach(tr => {
  const chk = tr.querySelector('.chk-scooter');
  tr.addEventListener('click', (e) => {
    if (e.target.tagName.toLowerCase() === 'input') return;
    if (!chk) return;
    chk.checked = !chk.checked;
    tr.classList.toggle('row-selected', chk.checked);
    rebuildSelection();
    applyFilterScooters();
  });
  if (chk) {
    chk.addEventListener('change', () => {
      tr.classList.toggle('row-selected', chk.checked);
      rebuildSelection();
      applyFilterScooters();
    });
  }
});

// ===== ACCESORIOS (filtro) =====
const buscAcc = document.getElementById('buscadorACC');
const tablaAcc = document.getElementById('tablaAccesorios');
const rowsAcc  = tablaAcc ? Array.from(tablaAcc.querySelectorAll('tbody tr')) : [];
const btnLimpiarAcc = document.getElementById('btnLimpiarAcc');

function applyFilterAcc(){
  if (!tablaAcc) return;
  const f = norm(buscAcc?.value || '');
  rowsAcc.forEach(tr => {
    const txt = norm(tr.innerText);
    tr.style.display = !f || txt.includes(f) ? '' : 'none';
  });
}
buscAcc?.addEventListener('input', applyFilterAcc);
btnLimpiarAcc?.addEventListener('click', ()=>{ buscAcc.value=''; applyFilterAcc(); buscAcc.focus(); });

// ===== Selección lateral =====
const badgeSel = document.getElementById('badgeCount');
const btnModal = document.getElementById('btnAbrirModal');

// Sumatoria accesorios
function accSumCurrent(){
  let s = 0;
  document.querySelectorAll('#tablaAccesorios input.acc-qty').forEach(inp=>{
    const v = parseInt(inp.value||'0',10);
    if (!isNaN(v) && v>0) s += v;
  });
  return s;
}

// Cambios en qty accesorios
document.querySelectorAll('#tablaAccesorios input.acc-qty').forEach(inp=>{
  inp.addEventListener('input', ()=>{
    const max = parseInt(inp.getAttribute('max')||'0',10);
    let v = parseInt(inp.value||'0',10);
    if (v<0) v=0;
    if (v>max) v=max;
    inp.value = v;
    rebuildSelection();
  });
});

// Construir panel lateral
function rebuildSelection(){
  const tbody = document.querySelector('#tablaSeleccion tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  let countEquipos = 0;
  let countScooters = 0;
  let countAcc = 0;

  // Equipos
  document.querySelectorAll('.chk-equipo:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    if (!tr) return;
    const id     = tr.querySelector('.td-id').textContent.trim();
    const marca  = tr.querySelector('.td-marca').textContent.trim();
    const modelo = tr.querySelector('.td-modelo').textContent.trim();
    const imei   = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `
      <td><span class="badge text-bg-primary">EQ</span></td>
      <td>${esc(marca)} ${esc(modelo)}</td>
      <td class="font-monospace">#${esc(id)} · ${esc(imei)}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger item-remove" data-kind="eq" data-id="${esc(id)}">
          <i class="bi bi-x"></i>
        </button>
      </td>`;
    tbody.appendChild(row);
    countEquipos++;
  });

  // Scooters
  document.querySelectorAll('.chk-scooter:checked').forEach(chk=>{
    const tr = chk.closest('tr');
    if (!tr) return;
    const id     = tr.querySelector('.td-id').textContent.trim();
    const marca  = tr.querySelector('.td-marca').textContent.trim();
    const modelo = tr.querySelector('.td-modelo').textContent.trim();
    const imei   = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `
      <td><span class="badge text-bg-warning">SC</span></td>
      <td>${esc(marca)} ${esc(modelo)}</td>
      <td class="font-monospace">#${esc(id)} · ${esc(imei)}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger item-remove" data-kind="sc" data-id="${esc(id)}">
          <i class="bi bi-x"></i>
        </button>
      </td>`;
    tbody.appendChild(row);
    countScooters++;
  });

  // Accesorios
  document.querySelectorAll('#tablaAccesorios tbody tr').forEach(tr=>{
    const qtyEl = tr.querySelector('input.acc-qty');
    if (!qtyEl) return;
    const qty = parseInt(qtyEl.value||'0',10);
    if (!qty || qty<=0) return;
    const idProd = tr.querySelector('input[name="acc_id_producto[]"]').value;
    const label  = tr.querySelector('.acc-label')?.innerText?.trim() || 'Accesorio';
    const color  = tr.querySelector('.acc-color')?.innerText?.trim() || '';
    const info   = `${color ? color+' · ' : ''}${qty} pza(s)`;

    const row = document.createElement('tr');
    row.innerHTML = `
      <td><span class="badge text-bg-success">AC</span></td>
      <td>${esc(label)}</td>
      <td>${esc(info)}</td>
      <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger item-remove" data-kind="acc" data-pid="${esc(idProd)}">
          <i class="bi bi-x"></i>
        </button>
      </td>`;
    tbody.appendChild(row);
    countAcc += qty;
  });

  const total = countEquipos + countScooters + countAcc;
  if (badgeSel) badgeSel.textContent = `${total}`;
  if (btnModal) {
    btnModal.textContent = `Confirmar (${total})`;
    btnModal.disabled    = (total === 0);
  }
}

// Quitar desde carrito
const tablaSelBody = document.querySelector('#tablaSeleccion tbody');
if (tablaSelBody) {
  tablaSelBody.addEventListener('click', (e)=>{
    const btn = e.target.closest('.item-remove');
    if (!btn) return;
    const kind = btn.getAttribute('data-kind');
    if (kind === 'eq') {
      const id = btn.getAttribute('data-id');
      const chk = document.querySelector(`.chk-equipo[value="${CSS.escape(id)}"]`);
      if (chk){ chk.checked = false; chk.closest('tr').classList.remove('row-selected'); }
      rebuildSelection();
      applyFilterEquipos();
    } else if (kind === 'sc') {
      const id = btn.getAttribute('data-id');
      const chk = document.querySelector(`.chk-scooter[value="${CSS.escape(id)}"]`);
      if (chk){ chk.checked = false; chk.closest('tr').classList.remove('row-selected'); }
      rebuildSelection();
      applyFilterScooters();
    } else if (kind === 'acc') {
      const pid = btn.getAttribute('data-pid');
      document.querySelectorAll('#tablaAccesorios tbody tr').forEach(tr=>{
        const hidden = tr.querySelector('input[name="acc_id_producto[]"]');
        if (hidden && hidden.value === pid) {
          const qtyEl = tr.querySelector('input.acc-qty');
          if (qtyEl) qtyEl.value = 0;
        }
      });
      rebuildSelection();
    }
  });
}

// Inicial
rebuildSelection();
applyFilterEquipos();
applyFilterScooters();
applyFilterAcc();

// Destino mini-label
const selDest  = document.getElementById('sucursal_destino');
const miniDest = document.getElementById('miniDestino');
selDest?.addEventListener('change', () => {
  const txt = selDest.value ? selDest.options[selDest.selectedIndex].text : '—';
  if (miniDest) miniDest.textContent = `Destino: ${txt}`;
});

// Modal de resumen
const modalResumen = new bootstrap.Modal(document.getElementById('modalResumen'));
function openResumen() {
  const seleccionEquipos  = Array.from(document.querySelectorAll('.chk-equipo:checked'));
  const seleccionScooters = Array.from(document.querySelectorAll('.chk-scooter:checked'));

  const accItems = Array.from(document.querySelectorAll('#tablaAccesorios tbody tr')).map(tr=>{
    const pidInput = tr.querySelector('input[name="acc_id_producto[]"]');
    const qtyEl = tr.querySelector('input.acc-qty');
    if (!pidInput || !qtyEl) return null;
    const pid = pidInput.value;
    const qty = parseInt(qtyEl.value||'0',10);
    if (!qty || qty<=0) return null;
    const prodTxt = tr.querySelector('.acc-label')?.innerText?.trim() || 'Accesorio';
    const color = tr.querySelector('.acc-color')?.innerText?.trim() || '';
    return {pid, qty, prodTxt, color};
  }).filter(Boolean);

  if (!selDest.value) { alert('Selecciona una sucursal destino.'); selDest.focus(); return; }
  if (seleccionEquipos.length === 0 && seleccionScooters.length === 0 && accItems.length === 0) {
    alert('Selecciona al menos un artículo (equipo, scooter o accesorio).');
    return;
  }

  document.getElementById('resSucursal').textContent    = selDest.options[selDest.selectedIndex].text;
  document.getElementById('resCantEquipos').textContent = seleccionEquipos.length;
  document.getElementById('resCantScooters').textContent= seleccionScooters.length;
  document.getElementById('resCantAcc').textContent     = accItems.reduce((a,b)=>a+b.qty,0);

  const tbody = document.getElementById('resTbody');
  tbody.innerHTML = '';

  seleccionEquipos.forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>Equipo</td><td>${esc(id)}</td><td>${esc(marca)}</td><td>${esc(modelo)}</td><td class="font-monospace">${esc(imei1)}</td>`;
    tbody.appendChild(row);
  });

  seleccionScooters.forEach(chk=>{
    const tr = chk.closest('tr');
    const id    = tr.querySelector('.td-id').textContent.trim();
    const marca = tr.querySelector('.td-marca').textContent.trim();
    const modelo= tr.querySelector('.td-modelo').textContent.trim();
    const imei1 = tr.querySelector('.td-imei1').textContent.trim();
    const row = document.createElement('tr');
    row.innerHTML = `<td>Scooter</td><td>${esc(id)}</td><td>${esc(marca)}</td><td>${esc(modelo)}</td><td class="font-monospace">${esc(imei1)}</td>`;
    tbody.appendChild(row);
  });

  const ul = document.getElementById('resAccList');
  ul.innerHTML = '';
  accItems.forEach(it=>{
    const li = document.createElement('li');
    li.textContent = `${it.prodTxt}${it.color ? ' · '+it.color: ''} — ${it.qty} pza(s)`;
    ul.appendChild(li);
  });

  modalResumen.show();
}
document.getElementById('btnAbrirModal').addEventListener('click', openResumen);
document.getElementById('btnConfirmar').addEventListener('click', openResumen);

// ===== Modal ACUSE: auto-apertura al terminar el traspaso =====
const ACUSE_URL = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => {
    try { frame.contentWindow.focus(); } catch(e){}
  });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
