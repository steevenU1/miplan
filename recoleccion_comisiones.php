<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'GerenteZona') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$idGerente = (int)($_SESSION['id_usuario'] ?? 0);

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mx($n){ return '$'.number_format((float)$n, 2); }

// 🔹 Zona del gerente
$sqlZona = "
    SELECT DISTINCT s.zona
    FROM sucursales s
    INNER JOIN usuarios u ON u.id_sucursal = s.id
    WHERE u.id = ?
";
$stmtZona = $conn->prepare($sqlZona);
$stmtZona->bind_param("i", $idGerente);
$stmtZona->execute();
$zona = $stmtZona->get_result()->fetch_assoc()['zona'] ?? '';
$stmtZona->close();

// 🔹 Saldos por sucursal (excluye anuladas)
function obtenerSaldos(mysqli $conn, string $zona){
    $sql = "
        SELECT 
            s.id AS id_sucursal,
            s.nombre AS sucursal,
            IFNULL(c.total_comisiones,0) AS total_comisiones,
            IFNULL(e.total_entregado,0) AS total_entregado,
            (IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0)) AS saldo_pendiente
        FROM sucursales s
        LEFT JOIN (
            SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
            FROM cobros
            WHERE comision_especial > 0
            GROUP BY id_sucursal
        ) c ON c.id_sucursal = s.id
        LEFT JOIN (
            SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
            FROM entregas_comisiones_especiales
            WHERE anulado = 0
            GROUP BY id_sucursal
        ) e ON e.id_sucursal = s.id
        WHERE s.zona = ?
        ORDER BY s.nombre
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// 🔹 Historial (muestra todo; marca anuladas)
function obtenerHistorial(mysqli $conn, string $zona){
    $sql = "
        SELECT e.id, s.nombre AS sucursal, e.monto_entregado, e.fecha_entrega, e.observaciones,
               e.anulado, e.anulado_por, e.anulado_at, e.anulado_motivo, s.zona
        FROM entregas_comisiones_especiales e
        INNER JOIN sucursales s ON s.id=e.id_sucursal
        WHERE s.zona = ?
        ORDER BY e.fecha_entrega DESC, e.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s",$zona);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$msg = "";

/* =========================================================
   POST:
   - accion=anular  → Soft-delete (anulado=1) con motivo obligatorio
   - default        → Registrar entrega normal
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['accion'] ?? '') === 'anular') {
        $idEntrega = (int)($_POST['id_entrega'] ?? 0);
        $motivo    = trim($_POST['motivo'] ?? '');

        if ($idEntrega <= 0) {
            $msg = "<div class='alert alert-warning shadow-sm'>⚠ ID de entrega inválido.</div>";
        } elseif ($motivo === '') {
            $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes capturar el motivo de la anulación.</div>";
        } else {
            try {
                $conn->begin_transaction();

                // Traer la entrega y validar zona y estado
                $sqlSel = "
                    SELECT e.id, e.id_sucursal, e.monto_entregado, e.anulado, s.zona
                    FROM entregas_comisiones_especiales e
                    INNER JOIN sucursales s ON s.id = e.id_sucursal
                    WHERE e.id = ?
                    FOR UPDATE
                ";
                $st = $conn->prepare($sqlSel);
                $st->bind_param("i",$idEntrega);
                $st->execute();
                $orig = $st->get_result()->fetch_assoc();
                $st->close();

                if (!$orig)                           throw new Exception("No se encontró la entrega.");
                if ((int)$orig['anulado'] === 1)      throw new Exception("La entrega ya estaba anulada.");
                if ((string)$orig['zona'] !== (string)$zona) throw new Exception("No puedes anular entregas fuera de tu zona.");

                // Anular con motivo obligatorio
                $sqlUpd = "
                    UPDATE entregas_comisiones_especiales
                    SET anulado = 1,
                        anulado_por = ?,
                        anulado_at = NOW(),
                        anulado_motivo = ?
                    WHERE id = ?
                    LIMIT 1
                ";
                $stU = $conn->prepare($sqlUpd);
                $stU->bind_param("isi", $idGerente, $motivo, $idEntrega);
                $stU->execute();
                $stU->close();

                $conn->commit();
                $msg = "<div class='alert alert-success shadow-sm'>🗑️ Entrega #".(int)$idEntrega." anulada. El saldo volvió a la sucursal.</div>";
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = "<div class='alert alert-danger shadow-sm'>❌ No se pudo anular: ".h($e->getMessage())."</div>";
            }
        }

    } else {
        // ===== Entrega normal =====
        $idSucursal    = intval($_POST['id_sucursal'] ?? 0);
        $monto         = floatval($_POST['monto_entregado'] ?? 0);
        $observaciones = trim($_POST['observaciones'] ?? '');

        if ($idSucursal > 0 && $monto > 0) {
            // Saldo pendiente actual (excluyendo anuladas)
            $sqlSaldo = "
                SELECT 
                    IFNULL(c.total_comisiones,0) - IFNULL(e.total_entregado,0) AS saldo_pendiente
                FROM (
                    SELECT id_sucursal, SUM(comision_especial) AS total_comisiones
                    FROM cobros
                    WHERE comision_especial > 0
                    GROUP BY id_sucursal
                ) c
                LEFT JOIN (
                    SELECT id_sucursal, SUM(monto_entregado) AS total_entregado
                    FROM entregas_comisiones_especiales
                    WHERE anulado = 0
                    GROUP BY id_sucursal
                ) e ON e.id_sucursal = c.id_sucursal
                WHERE c.id_sucursal = ?
                LIMIT 1
            ";
            $stmt = $conn->prepare($sqlSaldo);
            $stmt->bind_param("i",$idSucursal);
            $stmt->execute();
            $rowSaldo = $stmt->get_result()->fetch_assoc();
            $saldo = $rowSaldo['saldo_pendiente'] ?? 0;

            if ($monto > $saldo + 0.00001) {
                $msg = "<div class='alert alert-danger shadow-sm'>❌ El monto excede el saldo pendiente: ".mx($saldo)."</div>";
            } else {
                // INSERT limpio con NOW() y 4 parámetros (i i d s)
                $sqlInsert = "
                    INSERT INTO entregas_comisiones_especiales 
                        (id_sucursal, id_gerentezona, monto_entregado, fecha_entrega, observaciones, anulado)
                    VALUES 
                        (?,?,?,NOW(),?,0)
                ";
                $stmtIns = $conn->prepare($sqlInsert);
                $stmtIns->bind_param("iids", $idSucursal, $idGerente, $monto, $observaciones);
                if ($stmtIns->execute()) {
                    $msg = "<div class='alert alert-success shadow-sm'>✅ Entrega registrada correctamente.</div>";
                } else {
                    $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al registrar la entrega.</div>";
                }
                $stmtIns->close();
            }
        } else {
            $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes seleccionar sucursal y un monto válido.</div>";
        }
    }

    // Refrescar
    $saldos    = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);

} else {
    $saldos    = obtenerSaldos($conn,$zona);
    $historial = obtenerHistorial($conn,$zona);
}

// Totales
$totalComisiones = 0; $totalEntregado = 0; $totalSaldo = 0;
foreach ($saldos as $s) {
    $totalComisiones += (float)$s['total_comisiones'];
    $totalEntregado  += (float)$s['total_entregado'];
    $totalSaldo      += (float)$s['saldo_pendiente'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Recolección de Comisiones Abonos</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap / Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f6f8fb; }
.page-title { display:flex; align-items:center; gap:.6rem; }
.card-kpi{ border:0; border-radius:1rem; box-shadow:0 8px 24px rgba(29,78,216,.06); overflow:hidden; }
.card-kpi .card-body{ padding:1rem 1.25rem; }
.kpi-label{ font-size:.85rem; color:#64748b; }
.kpi-value{ font-size:1.25rem; font-weight:800; letter-spacing:.3px; }
.table thead th { position:sticky; top:0; background:#0d6efd; color:#fff; z-index:2; }
.table-hover tbody tr:hover{ background:#f1f5ff; }
.status-badge { font-weight:700; border-radius:999px; padding:.25rem .6rem; font-size:.8rem; }
.badge-ok{ background:#e8f7ef; color:#127a41; border:1px solid #bce8cf; }
.badge-pend{ background:#fff1f2; color:#b42318; border:1px solid #ffd7db; }
.card-form{ border:0; border-radius:1rem; overflow:hidden; box-shadow:0 10px 30px rgba(2,6,23,.06); }
.form-label{ font-weight:600; color:#334155; }
.btn-pill{ border-radius:999px; }
.search-wrap{ position:relative; }
.search-wrap .bi{ position:absolute; left:.75rem; top:50%; transform:translateY(-50%); opacity:.6; }
.search-input{ padding-left:2.2rem; }
.table-responsive{ border-radius:.75rem; overflow:hidden; }
.subtitle{ color:#475569; }
.small-muted{ font-size:.85rem; color:#6b7280; }
.badge-anulado{ background:#eee; color:#666; border:1px solid #ddd; }
.reversa { color:#b42318; font-weight:600; }
</style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="page-title mb-0">
                <i class="bi bi-cash-coin text-primary"></i>
                Recolección de Comisiones (Zona <?= h($zona) ?>)
            </h2>
            <div class="subtitle">Control y registro de entregas de comisiones especiales por sucursal.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small-muted">Actualizado:</span>
            <span class="badge text-bg-light border"><i class="bi bi-clock-history"></i> <?= h(date('Y-m-d H:i')) ?></span>
        </div>
    </div>

    <?= $msg ?>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="kpi-label"><i class="bi bi-coin"></i> Total comisiones</span>
                        <span class="badge text-bg-primary-subtle">Zona <?= h($zona) ?></span>
                    </div>
                    <div class="kpi-value mt-1"><?= mx($totalComisiones) ?></div>
                    <div class="progress mt-2" role="progressbar" aria-valuenow="<?= ($totalComisiones>0? ($totalEntregado/$totalComisiones*100):0) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= ($totalComisiones>0? ($totalEntregado/$totalComisiones*100):0) ?>%"></div>
                    </div>
                    <div class="small-muted mt-1">Avance de entrega vs. total</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="kpi-label"><i class="bi bi-box-arrow-up-right"></i> Total entregado</div>
                    <div class="kpi-value mt-1 text-success"><?= mx($totalEntregado) ?></div>
                    <div class="small-muted mt-2">Excluye entregas anuladas</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-kpi">
                <div class="card-body">
                    <div class="kpi-label"><i class="bi bi-exclamation-diamond"></i> Saldo pendiente</div>
                    <div class="kpi-value mt-1 text-danger"><?= mx($totalSaldo) ?></div>
                    <div class="small-muted mt-2">Suma de saldos por sucursal</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Saldos por sucursal -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h4 class="mb-0"><i class="bi bi-building-check"></i> Saldos de Comisiones por Sucursal</h4>
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="filtroSaldos" class="form-control form-control-sm search-input" placeholder="Filtrar sucursales...">
        </div>
    </div>

    <div class="table-responsive mb-4">
        <table class="table table-hover align-middle mb-0" id="tablaSaldos">
            <thead>
                <tr>
                    <th style="min-width:220px;">Sucursal</th>
                    <th class="text-end">Total Comisiones</th>
                    <th class="text-end">Total Entregado</th>
                    <th class="text-end">Saldo Pendiente</th>
                    <th class="text-center" style="width:160px;">Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($saldos as $s): 
                $tC = (float)$s['total_comisiones'];
                $tE = (float)$s['total_entregado'];
                $sp = (float)$s['saldo_pendiente'];
                $badge = $sp>0 ? "<span class='status-badge badge-pend'>Pendiente</span>": "<span class='status-badge badge-ok'>Al día</span>";
            ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-shop text-primary"></i>
                            <div class="fw-semibold"><?= h($s['sucursal']) ?></div>
                            <?= $badge ?>
                        </div>
                    </td>
                    <td class="text-end"><?= mx($tC) ?></td>
                    <td class="text-end text-success"><?= mx($tE) ?></td>
                    <td class="text-end <?= $sp>0?'text-danger fw-bold':'' ?>"><?= mx($sp) ?></td>
                    <td class="text-center">
                        <?php if ($sp>0): ?>
                        <button 
                            class="btn btn-sm btn-primary btn-pill" 
                            data-bs-toggle="modal" 
                            data-bs-target="#modalRecolectarTodo" 
                            data-sucursal="<?= h($s['sucursal']) ?>"
                            data-id="<?= (int)$s['id_sucursal'] ?>"
                            data-monto="<?= number_format($sp,2,'.','') ?>">
                            <i class="bi bi-recycle"></i> Recolectar todo
                        </button>
                        <?php else: ?>
                            <span class="text-success"><i class="bi bi-check2-circle"></i></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td class="text-end">Totales:</td>
                    <td class="text-end"><?= mx($totalComisiones) ?></td>
                    <td class="text-end"><?= mx($totalEntregado) ?></td>
                    <td class="text-end"><?= mx($totalSaldo) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Formulario de registro -->
    <h4 class="mb-2"><i class="bi bi-journal-plus"></i> Registrar Entrega de Comisiones</h4>
    <div class="card card-form mb-4">
        <div class="card-body">
            <form id="formEntrega" method="POST" class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Sucursal</label>
                    <select name="id_sucursal" id="selectSucursal" class="form-select" required>
                        <option value="">-- Selecciona Sucursal --</option>
                        <?php foreach($saldos as $s): if((float)$s['saldo_pendiente']>0): ?>
                            <option 
                                value="<?= (int)$s['id_sucursal'] ?>"
                                data-saldo="<?= number_format((float)$s['saldo_pendiente'],2,'.','') ?>">
                                <?= h($s['sucursal']) ?> — Pendiente <?= mx($s['saldo_pendiente']) ?>
                            </option>
                        <?php endif; endforeach; ?>
                    </select>
                    <div class="form-text">Solo se listan sucursales con saldo pendiente.</div>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">Monto Entregado</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" name="monto_entregado" id="inputMonto" class="form-control" required>
                    </div>
                    <div class="form-text">Puedes autollenar con el saldo pendiente.</div>
                </div>

                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-primary w-100 btn-pill" id="btnUsarSaldo">
                        <i class="bi bi-magic"></i> Usar saldo pendiente
                    </button>
                </div>

                <div class="col-12">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2" placeholder="Ej. Recolección parcial de efectivos, folio tal..."></textarea>
                </div>

                <div class="col-12">
                    <!-- Botón que abre el modal de confirmación -->
                    <button type="button" id="btnAbrirConfirmGuardar" class="btn btn-success w-100 btn-pill">
                        <i class="bi bi-save2"></i> Guardar entrega
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Historial -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h4 class="mb-0"><i class="bi bi-clock-history"></i> Historial de Entregas</h4>
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="filtroHistorial" class="form-control form-control-sm search-input" placeholder="Filtrar historial...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle" id="tablaHistorial">
            <thead class="table-light">
                <tr>
                    <th style="width:80px;">ID</th>
                    <th>Sucursal</th>
                    <th class="text-end">Monto Entregado</th>
                    <th style="min-width:160px;">Fecha</th>
                    <th>Observaciones</th>
                    <th class="text-center" style="width:140px;">Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($historial as $h): 
                $anulado = (int)$h['anulado'] === 1;
            ?>
                <tr class="<?= $anulado ? 'table-light' : '' ?>">
                    <td>#<?= (int)$h['id'] ?></td>
                    <td><i class="bi bi-shop"></i> <?= h($h['sucursal']) ?></td>
                    <td class="text-end <?= $anulado ? '' : 'text-success' ?>">
                        <?= mx($h['monto_entregado']) ?>
                        <?= $anulado ? " <span class='badge badge-anulado'>Anulado</span>" : "" ?>
                    </td>
                    <td><i class="bi bi-calendar2-event"></i> <?= h(date('Y-m-d H:i', strtotime($h['fecha_entrega']))) ?></td>
                    <td>
                        <?= h($h['observaciones']) ?>
                        <?php if ($anulado && $h['anulado_motivo']): ?>
                            <div class="text-muted small">Motivo: <?= h($h['anulado_motivo']) ?> (<?= h($h['anulado_at']) ?>)</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if (!$anulado): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmarAnular(<?= (int)$h['id'] ?>,'<?= h($h['sucursal']) ?>','<?= number_format((float)$h['monto_entregado'],2,'.','') ?>');">
                            <input type="hidden" name="accion" value="anular">
                            <input type="hidden" name="id_entrega" value="<?= (int)$h['id'] ?>">
                            <input type="hidden" name="motivo" id="motivo-<?= (int)$h['id'] ?>" value="">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-pill">
                                <i class="bi bi-x-octagon"></i> Anular
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Recolectar Todo -->
<div class="modal fade" id="modalRecolectarTodo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-recycle"></i> Confirmar recolección total</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Estás por recolectar el saldo completo de:</p>
        <div class="fw-bold" id="modalSucursal"></div>
        <div class="mt-2">Monto: <span class="fw-bold text-danger" id="modalMonto"></span></div>
        <input type="hidden" name="id_sucursal" id="modalIdSucursal">
        <input type="hidden" name="monto_entregado" id="modalMontoInput">
        <input type="hidden" name="observaciones" value="Recolección completa">
        <div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> Esta acción registrará una entrega por el total del saldo pendiente.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-pill" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-pill">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Confirmar Guardar Recolección -->
<div class="modal fade" id="modalConfirmarGuardar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-shield-check"></i> Confirmar recolección</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Vas a registrar una recolección para:</div>
        <div class="ps-2">
          <div><span class="fw-semibold">Sucursal:</span> <span id="confSucursal">—</span></div>
          <div><span class="fw-semibold">Monto:</span> <span id="confMonto" class="text-danger">—</span></div>
          <div class="mt-2"><span class="fw-semibold">Observaciones:</span></div>
          <div class="border rounded p-2 small bg-light" id="confObs">—</div>
        </div>
        <div class="alert alert-warning mt-3 mb-0">
          <i class="bi bi-exclamation-triangle"></i>
          Revisa bien los datos antes de continuar.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-pill" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="btnConfirmarGuardar" class="btn btn-success btn-pill">
          <i class="bi bi-check2-circle"></i> Confirmar y guardar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS (necesario para modales) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Filtros
function filtraTabla(inputId, tableId){
    const q = document.getElementById(inputId)?.value.toLowerCase() ?? '';
    document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
        tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
document.getElementById('filtroSaldos')?.addEventListener('input', ()=>filtraTabla('filtroSaldos','tablaSaldos'));
document.getElementById('filtroHistorial')?.addEventListener('input', ()=>filtraTabla('filtroHistorial','tablaHistorial'));

// Autollenar monto
const selectSucursal = document.getElementById('selectSucursal');
const inputMonto = document.getElementById('inputMonto');
document.getElementById('btnUsarSaldo')?.addEventListener('click', ()=>{
    if (!selectSucursal) return;
    const opt = selectSucursal.options[selectSucursal.selectedIndex];
    const saldo = opt?.getAttribute('data-saldo');
    if (saldo){ inputMonto.value = saldo; inputMonto.focus(); }
});

// Modal recolección total
const modal = document.getElementById('modalRecolectarTodo');
if (modal){
    modal.addEventListener('show.bs.modal', (ev)=>{
        const btn = ev.relatedTarget;
        const sucursal = btn.getAttribute('data-sucursal');
        const id       = btn.getAttribute('data-id');
        const monto    = btn.getAttribute('data-monto');
        document.getElementById('modalSucursal').textContent = sucursal;
        document.getElementById('modalMonto').textContent    = new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(parseFloat(monto||0));
        document.getElementById('modalIdSucursal').value     = id;
        document.getElementById('modalMontoInput').value     = monto;
    });
}

// ---------- Confirmación al guardar recolección normal ----------
const btnAbrir = document.getElementById('btnAbrirConfirmGuardar');
const btnConfirmar = document.getElementById('btnConfirmarGuardar');
const formEntrega = document.getElementById('formEntrega');

const confSucursalEl = document.getElementById('confSucursal');
const confMontoEl    = document.getElementById('confMonto');
const confObsEl      = document.getElementById('confObs');

let modalGuardar;
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modalConfirmarGuardar');
  if (window.bootstrap && modalEl){
    modalGuardar = new bootstrap.Modal(modalEl);
  }
});

function formatoMoneda(n){
  const v = parseFloat(n || 0);
  return new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(isNaN(v) ? 0 : v);
}

btnAbrir?.addEventListener('click', () => {
  const opt = selectSucursal?.options[selectSucursal.selectedIndex];
  const sucursal = opt ? opt.textContent.replace(/\s+—\s+Pendiente.*$/,'').trim() : '';
  const monto = parseFloat(inputMonto?.value || 0);
  const obs = (formEntrega?.querySelector('textarea[name="observaciones"]')?.value || '').trim();

  if (!opt || !selectSucursal.value){
    alert('Selecciona una sucursal.');
    selectSucursal?.focus();
    return;
  }
  if (!monto || monto <= 0){
    alert('Ingresa un monto mayor a 0.');
    inputMonto?.focus();
    return;
  }

  confSucursalEl.textContent = sucursal || '—';
  confMontoEl.textContent    = formatoMoneda(monto);
  confObsEl.textContent      = obs || 'Sin observaciones';

  modalGuardar?.show();
});

let enviando = false;
btnConfirmar?.addEventListener('click', () => {
  if (enviando) return;
  enviando = true;
  btnConfirmar.disabled = true;
  btnConfirmar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
  formEntrega?.submit();
});

// ---------- Confirmación anular con motivo OBLIGATORIO ----------
function confirmarAnular(id, sucursal, monto){
  const m = new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(parseFloat(monto||0));
  const motivo = prompt(`Motivo de anulación de la entrega #${id} de ${sucursal} por ${m}:\n(Obligatorio)`);
  if (motivo === null) return false; // Canceló
  if (!motivo || motivo.trim() === '') {
    alert('Debes capturar un motivo para anular.');
    return false;
  }
  document.getElementById(`motivo-${id}`).value = motivo.trim();
  return confirm('Confirma anulación. El saldo volverá a la sucursal.');
}
</script>
</body>
</html>
