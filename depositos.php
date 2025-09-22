<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = '';

// 1) Validar un depósito (SIN CAMBIOS DE LÓGICA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
    $idDeposito = intval($_POST['id_deposito']);
    $accion = $_POST['accion'];

    if ($accion === 'Validar') {
        $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET estado='Validado', id_admin_valida=?, actualizado_en=NOW()
            WHERE id=? AND estado='Pendiente'
        ");
        $stmt->bind_param("ii", $_SESSION['id_usuario'], $idDeposito);
        $stmt->execute();

        // Cierra corte si ya se cubrió
        $sqlCorte = "
            SELECT ds.id_corte, cc.total_efectivo,
                   IFNULL(SUM(ds2.monto_depositado),0) AS suma_depositos
            FROM depositos_sucursal ds
            INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
            INNER JOIN depositos_sucursal ds2 ON ds2.id_corte = ds.id_corte AND ds2.estado='Validado'
            WHERE ds.id = ?
            GROUP BY ds.id_corte
        ";
        $stmtCorte = $conn->prepare($sqlCorte);
        $stmtCorte->bind_param("i", $idDeposito);
        $stmtCorte->execute();
        $corteData = $stmtCorte->get_result()->fetch_assoc();

        if ($corteData && $corteData['suma_depositos'] >= $corteData['total_efectivo']) {
            $stmtClose = $conn->prepare("
                UPDATE cortes_caja
                SET estado='Cerrado', depositado=1, monto_depositado=?, fecha_deposito=NOW()
                WHERE id=?
            ");
            $stmtClose->bind_param("di", $corteData['suma_depositos'], $corteData['id_corte']);
            $stmtClose->execute();
        }

        $msg = "<div class='alert alert-success mb-3'>✅ Depósito validado correctamente.</div>";
    }
}

// 2) Depósitos pendientes (agrupados por corte)
$sqlPendientes = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           cc.total_efectivo,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE ds.estado = 'Pendiente'
    ORDER BY cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

// 3) Filtros para Historial
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? ''); // YYYY-Www

// Semana → lunes a domingo
if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
    $yr = (int)$m[1]; $wk = (int)$m[2];
    $dt = new DateTime();
    $dt->setISODate($yr, $wk);
    $desde = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $hasta = $dt->format('Y-m-d');
}

// 3b) Historial con filtros
$sqlHistorial = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           ds.fecha_deposito,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE 1=1
";
$types = '';
$params = [];
if ($sucursal_id > 0) { $sqlHistorial .= " AND s.id = ? "; $types .= 'i'; $params[] = $sucursal_id; }
if ($desde !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito) >= ? "; $types .= 's'; $params[] = $desde; }
if ($hasta !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito) <= ? "; $types .= 's'; $params[] = $hasta; }
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stmtH = $conn->prepare($sqlHistorial);
if ($types) { $stmtH->bind_param($types, ...$params); }
$stmtH->execute();
$historial = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

// 4) Saldos por sucursal
$sqlSaldos = "
    SELECT 
        s.id,
        s.nombre AS sucursal,
        IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,
        IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_depositado,
        GREATEST(
            IFNULL(SUM(c.monto_efectivo),0) - IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0),
        0) AS saldo_pendiente
    FROM sucursales s
    LEFT JOIN cobros c 
        ON c.id_sucursal = s.id 
       AND c.corte_generado = 1
    GROUP BY s.id
    ORDER BY saldo_pendiente DESC
";
$saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);

// 5) Cortes de caja (con filtros)
$c_sucursal_id = isset($_GET['c_sucursal_id']) ? (int)$_GET['c_sucursal_id'] : 0;
$c_desde       = trim($_GET['c_desde'] ?? '');
$c_hasta       = trim($_GET['c_hasta'] ?? '');

$sqlCortes = "
  SELECT cc.id,
         s.nombre AS sucursal,
         cc.fecha_operacion,
         cc.fecha_corte,
         cc.estado,
         cc.total_efectivo,
         cc.total_tarjeta,
         cc.total_comision_especial,
         cc.total_general,
         cc.depositado,
         cc.monto_depositado,
         (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE 1=1
";
$typesC=''; $paramsC=[];
if ($c_sucursal_id > 0) { $sqlCortes .= " AND cc.id_sucursal = ? "; $typesC.='i'; $paramsC[]=$c_sucursal_id; }
if ($c_desde !== '')     { $sqlCortes .= " AND cc.fecha_operacion >= ? "; $typesC.='s'; $paramsC[]=$c_desde; }
if ($c_hasta !== '')     { $sqlCortes .= " AND cc.fecha_operacion <= ? "; $typesC.='s'; $paramsC[]=$c_hasta; }
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";
$stmtC = $conn->prepare($sqlCortes);
if ($typesC) { $stmtC->bind_param($typesC, ...$paramsC); }
$stmtC->execute();
$cortes = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

// Métricas UI
$pendCount = count($pendientes);
$pendMonto = 0.0; foreach ($pendientes as $p) { $pendMonto += (float)$p['monto_depositado']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Validación de Depósitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{
      --brand:#0d6efd;
      --bg1:#f8fafc;
      --ink:#0f172a;
      --muted:#64748b;
      --soft:#eef2ff;
    }
    body{ background:
      radial-gradient(1200px 400px at 120% -50%, rgba(13,110,253,.07), transparent),
      radial-gradient(1000px 380px at -10% 120%, rgba(25,135,84,.06), transparent),
      var(--bg1);
    }
    .page-title{font-weight:800; letter-spacing:.2px; color:var(--ink);}
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .section-title{font-size:.95rem; font-weight:700; color:#334155; letter-spacing:.6px; text-transform:uppercase; display:flex; align-items:center; gap:.5rem;}
    .badge-soft{background:var(--soft); color:#1e40af; border:1px solid #dbeafe;}
    .stat{display:flex; align-items:center; gap:.85rem;}
    .stat .ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#e7f1ff;}
    .help-text{color:var(--muted); font-size:.9rem;}
    .table thead th{ position:sticky; top:0; background:#0f172a; color:#fff; z-index:1;}
    .table-hover tbody tr:hover{ background: rgba(13,110,253,.06); }
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .nav-tabs .nav-link{border:0; border-bottom:2px solid transparent;}
    .nav-tabs .nav-link.active{border-bottom-color:var(--brand); font-weight:700;}
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:.5rem; border-top:1px solid #e5e7eb;}
    code{background:#f1f5f9; padding:.1rem .35rem; border-radius:.35rem;}
  </style>
</head>
<body>

<div class="container my-4">

  <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-bank2 me-2"></i>Validación de Depósitos</h2>
      <div class="help-text">Administra <b>pendientes</b>, consulta <b>historial</b>, revisa <b>cortes</b> y <b>saldos</b>.</div>
    </div>
    <?php if(!empty($msg)) echo $msg; ?>
  </div>

  <!-- RESUMEN -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
      <div class="card card-elev">
        <div class="card-body stat">
          <div class="ico"><i class="bi bi-hourglass-split"></i></div>
          <div><div class="text-muted">Pendientes</div><div class="h4 mb-0"><?= (int)$pendCount ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card card-elev">
        <div class="card-body stat">
          <div class="ico" style="background:#e6fff3"><i class="bi bi-cash-coin"></i></div>
          <div><div class="text-muted">Monto pendiente</div><div class="h4 mb-0">$<?= number_format($pendMonto,2) ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card card-elev">
        <div class="card-body stat">
          <div class="ico" style="background:#fff4e6"><i class="bi bi-receipt"></i></div>
          <div><div class="text-muted">Cortes cargados</div><div class="h4 mb-0"><?= count($cortes) ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card card-elev">
        <div class="card-body stat">
          <div class="ico" style="background:#f1e8ff"><i class="bi bi-building"></i></div>
          <div>
            <div class="text-muted">Sucursales con saldo</div>
            <div class="h4 mb-0">
              <?php $conSaldo=0; foreach($saldos as $s){ if((float)$s['saldo_pendiente']>0) $conSaldo++; } echo $conSaldo; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- EXPORTAR POR DÍA (MOVIDO AQUÍ, DEBAJO DE CARDS) -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-download"></i> Exportar transacciones por día</div>
      <span class="help-text">Cobros de todas las sucursales en CSV</span>
    </div>
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="export_transacciones_dia.php" target="_blank">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Día</label>
          <input type="date" name="dia" class="form-control" required>
        </div>
        <div class="col-sm-8 col-md-5">
          <div class="help-text">Descarga el detalle de <b>cobros</b> del día seleccionado, con sucursal y ejecutivo.</div>
        </div>
        <div class="col-md-4 text-end">
          <button class="btn btn-outline-success"><i class="bi bi-filetype-csv me-1"></i> Descargar CSV</button>
        </div>
      </form>
    </div>
  </div>

  <!-- PESTAÑAS -->
  <ul class="nav nav-tabs mb-3" id="depTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="pend-tab" data-bs-toggle="tab" data-bs-target="#pend" type="button" role="tab" aria-controls="pend" aria-selected="true">
        <i class="bi bi-inbox me-1"></i>Pendientes
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="hist-tab" data-bs-toggle="tab" data-bs-target="#hist" type="button" role="tab" aria-controls="hist" aria-selected="false">
        <i class="bi bi-clock-history me-1"></i>Historial
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="cortes-tab" data-bs-toggle="tab" data-bs-target="#cortes" type="button" role="tab" aria-controls="cortes" aria-selected="false">
        <i class="bi bi-clipboard-data me-1"></i>Cortes
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="saldos-tab" data-bs-toggle="tab" data-bs-target="#saldos" type="button" role="tab" aria-controls="saldos" aria-selected="false">
        <i class="bi bi-graph-up me-1"></i>Saldos
      </button>
    </li>
  </ul>

  <div class="tab-content" id="depTabsContent">
    <!-- TAB: PENDIENTES -->
    <div class="tab-pane fade show active" id="pend" role="tabpanel" aria-labelledby="pend-tab" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="section-title mb-0"><i class="bi bi-inbox"></i> Depósitos pendientes de validación</div>
          <span class="badge rounded-pill badge-soft"><?= (int)$pendCount ?> pendientes</span>
        </div>
        <div class="card-body p-0">
          <?php if ($pendCount === 0): ?>
            <div class="p-3"><div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay depósitos pendientes.</div></div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover table-xs align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID Depósito</th>
                    <th>Sucursal</th>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th class="text-end">Monto</th>
                    <th>Banco</th>
                    <th>Referencia</th>
                    <th>Comprobante</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php 
                $lastCorte = null;
                foreach ($pendientes as $p): 
                  if ($lastCorte !== $p['id_corte']): ?>
                    <tr class="table-secondary">
                      <td colspan="9" class="fw-semibold">
                        <i class="bi bi-journal-check me-1"></i>Corte #<?= (int)$p['id_corte'] ?> · 
                        <span class="text-primary"><?= htmlspecialchars($p['sucursal']) ?></span>
                        <span class="ms-2 text-muted">Fecha: <?= htmlspecialchars($p['fecha_corte']) ?></span>
                        <span class="ms-2 badge rounded-pill bg-light text-dark">Efectivo corte: $<?= number_format($p['total_efectivo'],2) ?></span>
                      </td>
                    </tr>
                  <?php endif; ?>
                  <tr>
                    <td>#<?= (int)$p['id_deposito'] ?></td>
                    <td><?= htmlspecialchars($p['sucursal']) ?></td>
                    <td><?= (int)$p['id_corte'] ?></td>
                    <td><?= htmlspecialchars($p['fecha_corte']) ?></td>
                    <td class="text-end">$<?= number_format($p['monto_depositado'],2) ?></td>
                    <td><?= htmlspecialchars($p['banco']) ?></td>
                    <td><code><?= htmlspecialchars($p['referencia']) ?></code></td>
                    <td>
                      <?php if (!empty($p['comprobante_archivo'])): ?>
                        <button class="btn btn-outline-primary btn-sm js-ver" 
                                data-src="deposito_comprobante.php?id=<?= (int)$p['id_deposito'] ?>" 
                                data-bs-toggle="modal" data-bs-target="#visorModal">
                          <i class="bi bi-eye"></i> Ver
                        </button>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                      <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$p['id_deposito'] ?>, '<?= htmlspecialchars($p['sucursal'],ENT_QUOTES) ?>', '<?= number_format($p['monto_depositado'],2) ?>');">
                        <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                        <button name="accion" value="Validar" class="btn btn-success btn-sm">
                          <i class="bi bi-check2-circle me-1"></i> Validar
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php $lastCorte = $p['id_corte']; endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- TAB: HISTORIAL -->
    <div class="tab-pane fade" id="hist" role="tabpanel" aria-labelledby="hist-tab" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header">
          <div class="section-title mb-0"><i class="bi bi-clock-history"></i> Historial de depósitos</div>
          <div class="help-text">Al elegir <b>semana</b>, se ignoran las fechas.</div>
        </div>
        <div class="card-body">
          <form class="row g-2 align-items-end mb-3" method="get">
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="desde" class="form-control form-control-sm" value="<?= htmlspecialchars($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Semana (ISO)</label>
              <input type="week" name="semana" class="form-control form-control-sm" value="<?= htmlspecialchars($semana) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Aplicar filtros</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Sucursal</th>
                  <th>ID Corte</th>
                  <th>Fecha Corte</th>
                  <th>Fecha Depósito</th>
                  <th class="text-end">Monto</th>
                  <th>Banco</th>
                  <th>Referencia</th>
                  <th>Comprobante</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$historial): ?>
                  <tr><td colspan="10" class="text-muted">Sin resultados con los filtros actuales.</td></tr>
                <?php endif; ?>
                <?php foreach ($historial as $h): ?>
                  <tr class="<?= $h['estado']=='Validado'?'table-success':'' ?>">
                    <td>#<?= (int)$h['id_deposito'] ?></td>
                    <td><?= htmlspecialchars($h['sucursal']) ?></td>
                    <td><?= (int)$h['id_corte'] ?></td>
                    <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
                    <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
                    <td class="text-end">$<?= number_format($h['monto_depositado'],2) ?></td>
                    <td><?= htmlspecialchars($h['banco']) ?></td>
                    <td><code><?= htmlspecialchars($h['referencia']) ?></code></td>
                    <td>
                      <?php if (!empty($h['comprobante_archivo'])): ?>
                        <button class="btn btn-outline-primary btn-sm js-ver" 
                                data-src="deposito_comprobante.php?id=<?= (int)$h['id_deposito'] ?>" 
                                data-bs-toggle="modal" data-bs-target="#visorModal">
                          <i class="bi bi-eye"></i> Ver
                        </button>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                      <span class="badge <?= $h['estado']=='Validado'?'bg-success':'bg-warning text-dark' ?>"><?= htmlspecialchars($h['estado']) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- TAB: CORTES -->
    <div class="tab-pane fade" id="cortes" role="tabpanel" aria-labelledby="cortes-tab" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header">
          <div class="section-title mb-0"><i class="bi bi-clipboard-data"></i> Cortes de caja</div>
          <div class="help-text">Filtra por sucursal y fechas; despliega cobros por corte.</div>
        </div>
        <div class="card-body">
          <form class="row g-2 align-items-end mb-3" method="get">
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="c_sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $c_sucursal_id===(int)$s['id']?'selected':'' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="c_desde" class="form-control form-control-sm" value="<?= htmlspecialchars($c_desde) ?>">
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="c_hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($c_hasta) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filtrar cortes</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>ID Corte</th>
                  <th>Sucursal</th>
                  <th>Fecha Operación</th>
                  <th>Fecha Corte</th>
                  <th class="text-end">Efectivo</th>
                  <th class="text-end">Tarjeta</th>
                  <th class="text-end">Com. Esp.</th>
                  <th class="text-end">Total</th>
                  <th>Depositado</th>
                  <th>Estado</th>
                  <th>Detalle</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$cortes): ?>
                  <tr><td colspan="11" class="text-muted">Sin cortes con los filtros seleccionados.</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                  <tr>
                    <td>#<?= (int)$c['id'] ?></td>
                    <td><?= htmlspecialchars($c['sucursal']) ?></td>
                    <td><?= htmlspecialchars($c['fecha_operacion']) ?></td>
                    <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                    <td class="text-end">$<?= number_format($c['total_efectivo'],2) ?></td>
                    <td class="text-end">$<?= number_format($c['total_tarjeta'],2) ?></td>
                    <td class="text-end">$<?= number_format($c['total_comision_especial'],2) ?></td>
                    <td class="text-end fw-semibold">$<?= number_format($c['total_general'],2) ?></td>
                    <td><?= $c['depositado'] ? ('$'.number_format($c['monto_depositado'],2)) : '<span class="text-muted">No</span>' ?></td>
                    <td><span class="badge <?= $c['estado']==='Cerrado'?'bg-success':'bg-warning text-dark' ?>"><?= htmlspecialchars($c['estado']) ?></span></td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#det<?= $c['id'] ?>">
                        <i class="bi bi-list-ul me-1"></i> Ver cobros (<?= (int)$c['num_cobros'] ?>)
                      </button>
                    </td>
                  </tr>
                  <tr class="collapse" id="det<?= $c['id'] ?>">
                    <td colspan="11" class="bg-light">
                      <?php
                        $qc = $conn->prepare("
                          SELECT cb.id, cb.motivo, cb.tipo_pago, cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta,
                                 cb.comision_especial, cb.fecha_cobro, u.nombre AS ejecutivo
                          FROM cobros cb
                          LEFT JOIN usuarios u ON u.id = cb.id_usuario
                          WHERE cb.id_corte = ?
                          ORDER BY cb.fecha_cobro ASC, cb.id ASC
                        ");
                        $qc->bind_param('i', $c['id']);
                        $qc->execute();
                        $rows = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
                        $qc->close();
                      ?>
                      <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                          <thead>
                            <tr>
                              <th>ID Cobro</th>
                              <th>Fecha/Hora</th>
                              <th>Ejecutivo</th>
                              <th>Motivo</th>
                              <th>Tipo pago</th>
                              <th class="text-end">Total</th>
                              <th class="text-end">Efectivo</th>
                              <th class="text-end">Tarjeta</th>
                              <th class="text-end">Com. Especial</th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach ($rows as $r): ?>
                            <tr>
                              <td><?= (int)$r['id'] ?></td>
                              <td><?= htmlspecialchars($r['fecha_cobro']) ?></td>
                              <td><?= htmlspecialchars($r['ejecutivo'] ?? 'N/D') ?></td>
                              <td><?= htmlspecialchars($r['motivo']) ?></td>
                              <td><?= htmlspecialchars($r['tipo_pago']) ?></td>
                              <td class="text-end">$<?= number_format($r['monto_total'],2) ?></td>
                              <td class="text-end">$<?= number_format($r['monto_efectivo'],2) ?></td>
                              <td class="text-end">$<?= number_format($r['monto_tarjeta'],2) ?></td>
                              <td class="text-end">$<?= number_format($r['comision_especial'],2) ?></td>
                            </tr>
                          <?php endforeach; if(!$rows): ?>
                            <tr><td colspan="9" class="text-muted">Sin cobros ligados a este corte.</td></tr>
                          <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- TAB: SALDOS -->
    <div class="tab-pane fade" id="saldos" role="tabpanel" aria-labelledby="saldos-tab" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="section-title mb-0"><i class="bi bi-graph-up"></i> Saldos por sucursal</div>
          <span class="help-text">Comparativo de efectivo cobrado vs depositado</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>Sucursal</th>
                  <th class="text-end">Total Efectivo Cobrado</th>
                  <th class="text-end">Total Depositado</th>
                  <th class="text-end">Saldo Pendiente</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($saldos as $s): ?>
                <tr class="<?= $s['saldo_pendiente']>0?'table-warning':'' ?>">
                  <td><?= htmlspecialchars($s['sucursal']) ?></td>
                  <td class="text-end">$<?= number_format($s['total_efectivo'],2) ?></td>
                  <td class="text-end">$<?= number_format($s['total_depositado'],2) ?></td>
                  <td class="text-end fw-semibold">$<?= number_format($s['saldo_pendiente'],2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="sticky-actions text-end">
            <span class="help-text"><i class="bi bi-info-circle me-1"></i>Los saldos consideran cobros con corte generado y depósitos validados.</span>
          </div>
        </div>
      </div>
    </div>
  </div> <!-- /tab-content -->

</div>

<!-- Modal visor -->
<div class="modal fade" id="visorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-image me-1"></i> Comprobante de depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="visorFrame" src="" style="width:100%;height:80vh;border:0;"></iframe>
      </div>
      <div class="modal-footer">
        <a id="btnAbrirNueva" href="#" target="_blank" class="btn btn-outline-secondary">Abrir en nueva pestaña</a>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Listo</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Historial: toggle fechas cuando se selecciona semana
  const semanaInput = document.querySelector('input[name="semana"]');
  const desdeInput  = document.querySelector('input[name="desde"]');
  const hastaInput  = document.querySelector('input[name="hasta"]');
  if (semanaInput) {
    semanaInput.addEventListener('input', () => {
      const usingWeek = semanaInput.value.trim() !== '';
      if (desdeInput && hastaInput){
        desdeInput.disabled = usingWeek;
        hastaInput.disabled = usingWeek;
        if (usingWeek) { desdeInput.value=''; hastaInput.value=''; }
      }
    });
  }

  // Visor modal
  const visorModal  = document.getElementById('visorModal');
  const visorFrame  = document.getElementById('visorFrame');
  const btnAbrir    = document.getElementById('btnAbrirNueva');
  document.querySelectorAll('.js-ver').forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-src');
      visorFrame.src = src;
      btnAbrir.href  = src;
    });
  });
  if (visorModal) {
    visorModal.addEventListener('hidden.bs.modal', () => {
      visorFrame.src = '';
      btnAbrir.href  = '#';
    });
  }

  // Confirmación al validar depósito (UX)
  function confirmarValidacion(id, sucursal, monto){
    return confirm(`¿Validar el depósito #${id} de ${sucursal} por $${monto}?`);
  }
  window.confirmarValidacion = confirmarValidacion;
</script>
</body>
</html>
