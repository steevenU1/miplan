<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$idSucursalUsuario = (int)$_SESSION['id_sucursal'];
$rolUsuario        = $_SESSION['rol'] ?? '';
$mensaje = "";

// Mensaje de eliminación (opcional)
if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado') {
    $mensaje = "<div class='alert alert-success'>✅ Traspaso eliminado correctamente.</div>";
}

// Escapar seguro
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n0($v){ return number_format((float)$v,0); }
function n1($v){ return number_format((float)$v,1); }

// Utilidad: detectar si existe una columna (para usar bitácoras si existen)
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $rs && $rs->num_rows > 0;
}
$hasDT_Resultado       = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado  = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasT_FechaRecep       = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio   = hasColumn($conn, 'traspasos', 'usuario_recibio');

/* =========================================================
   PENDIENTES (salientes de la SUCURSAL, no por usuario)
========================================================= */
$sqlPend = "
    SELECT t.id, t.fecha_traspaso, s.nombre AS sucursal_destino, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.id_sucursal_origen = ? AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
";
$stmtPend = $conn->prepare($sqlPend);
$stmtPend->bind_param("i", $idSucursalUsuario);
$stmtPend->execute();
$resPend = $stmtPend->get_result();
$stmtPend->close();

// Guardamos los pendientes en arreglo para poder usarlos varias veces
$pendRows = [];
while($r = $resPend->fetch_assoc()) { $pendRows[] = $r; }

/* ===================== KPIs (cards arriba) ===================== */
// 1) Piezas en tránsito (suma de detalles de todos los pendientes)
$stmtPzas = $conn->prepare("
  SELECT COUNT(*) AS piezas
  FROM detalle_traspaso dt
  INNER JOIN traspasos t ON t.id = dt.id_traspaso
  WHERE t.id_sucursal_origen=? AND t.estatus='Pendiente'
");
$stmtPzas->bind_param("i", $idSucursalUsuario);
$stmtPzas->execute();
$rowPzas = $stmtPzas->get_result()->fetch_assoc();
$stmtPzas->close();
$kpi_piezas = (int)($rowPzas['piezas'] ?? 0);

// 2) Antigüedad promedio / máxima y pendientes >= 3 días
$stmtAges = $conn->prepare("
  SELECT
    COALESCE(AVG(DATEDIFF(CURDATE(), DATE(t.fecha_traspaso))),0) AS avg_dias,
    COALESCE(MAX(DATEDIFF(CURDATE(), DATE(t.fecha_traspaso))),0) AS max_dias,
    SUM(DATEDIFF(CURDATE(), DATE(t.fecha_traspaso)) >= 3)        AS ge3
  FROM traspasos t
  WHERE t.id_sucursal_origen=? AND t.estatus='Pendiente'
");
$stmtAges->bind_param("i", $idSucursalUsuario);
$stmtAges->execute();
$rowAges = $stmtAges->get_result()->fetch_assoc();
$stmtAges->close();

$kpi_avg   = (float)($rowAges['avg_dias'] ?? 0);
$kpi_max   = (int)($rowAges['max_dias'] ?? 0);
$kpi_ge3   = (int)($rowAges['ge3'] ?? 0);

// 3) Enviados últimos 7 días
$stmt7 = $conn->prepare("
  SELECT COUNT(*) AS c7
  FROM traspasos t
  WHERE t.id_sucursal_origen=? AND DATE(t.fecha_traspaso) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
");
$stmt7->bind_param("i", $idSucursalUsuario);
$stmt7->execute();
$row7 = $stmt7->get_result()->fetch_assoc();
$stmt7->close();
$kpi_7d = (int)($row7['c7'] ?? 0);

// 4) Enviados mes en curso (MTD)
$stmtMTD = $conn->prepare("
  SELECT COUNT(*) AS mtd
  FROM traspasos t
  WHERE t.id_sucursal_origen=? 
    AND YEAR(t.fecha_traspaso)=YEAR(CURDATE())
    AND MONTH(t.fecha_traspaso)=MONTH(CURDATE())
");
$stmtMTD->bind_param("i", $idSucursalUsuario);
$stmtMTD->execute();
$rowMTD = $stmtMTD->get_result()->fetch_assoc();
$stmtMTD->close();
$kpi_mtd = (int)($rowMTD['mtd'] ?? 0);

// 5) Tasa de rechazo (mes): por detalle si existe; si no, por estatus del traspaso
if ($hasDT_Resultado) {
  $stmtRej = $conn->prepare("
    SELECT 
      SUM(dt.resultado='Rechazado') AS rej,
      SUM(dt.resultado IN ('Rechazado','Recibido')) AS proc
    FROM detalle_traspaso dt
    INNER JOIN traspasos t ON t.id = dt.id_traspaso
    WHERE t.id_sucursal_origen=?
      AND YEAR(t.fecha_traspaso)=YEAR(CURDATE())
      AND MONTH(t.fecha_traspaso)=MONTH(CURDATE())
  ");
} else {
  $stmtRej = $conn->prepare("
    SELECT 
      SUM(t.estatus='Rechazado') AS rej,
      SUM(t.estatus IN ('Rechazado','Completado','Parcial')) AS proc
    FROM traspasos t
    WHERE t.id_sucursal_origen=?
      AND YEAR(t.fecha_traspaso)=YEAR(CURDATE())
      AND MONTH(t.fecha_traspaso)=MONTH(CURDATE())
  ");
}
$stmtRej->bind_param("i", $idSucursalUsuario);
$stmtRej->execute();
$rowRej = $stmtRej->get_result()->fetch_assoc();
$stmtRej->close();

$rej = (int)($rowRej['rej'] ?? 0);
$proc = (int)($rowRej['proc'] ?? 0);
$kpi_rej_pct = $proc > 0 ? round(($rej / $proc) * 100, 1) : null;

/* =========================================================
   HISTÓRICO: filtros (también por SUCURSAL origen)
========================================================= */
$desde   = $_GET['desde']   ?? date('Y-m-01');
$hasta   = $_GET['hasta']   ?? date('Y-m-d');
$estatus = $_GET['estatus'] ?? 'Todos'; // Todos / Pendiente / Parcial / Completado / Rechazado
$idDest  = (int)($_GET['destino'] ?? 0);

// Para combo de destinos (solo los que han recibido algo de mi suc)
$destinos = [];
$qDest = $conn->prepare("
    SELECT DISTINCT s.id, s.nombre
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    WHERE t.id_sucursal_origen=?
    ORDER BY s.nombre
");
$qDest->bind_param("i", $idSucursalUsuario);
$qDest->execute();
$rDest = $qDest->get_result();
while ($row = $rDest->fetch_assoc()) {
    $destinos[(int)$row['id']] = $row['nombre'];
}
$qDest->close();

// WHERE dinámico para histórico
$whereH = "t.id_sucursal_origen = ? AND DATE(t.fecha_traspaso) BETWEEN ? AND ?";
$params = [$idSucursalUsuario, $desde, $hasta];
$types  = "iss";

if ($estatus !== 'Todos') {
    $whereH .= " AND t.estatus = ?";
    $params[] = $estatus;
    $types   .= "s";
}
if ($idDest > 0) {
    $whereH .= " AND t.id_sucursal_destino = ?";
    $params[] = $idDest;
    $types   .= "i";
}

$sqlHist = "
    SELECT 
      t.id, t.fecha_traspaso, t.estatus,
      s.nombre  AS sucursal_destino,
      u.nombre  AS usuario_creo".
      ($hasT_FechaRecep       ? ", t.fecha_recepcion" : "").
      ($hasT_UsuarioRecibio   ? ", u2.nombre AS usuario_recibio" : "").
    "
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_destino
    INNER JOIN usuarios  u ON u.id = t.usuario_creo ".
    ($hasT_UsuarioRecibio ? " LEFT JOIN usuarios u2 ON u2.id = t.usuario_recibio " : "").
    "WHERE $whereH
    ORDER BY t.fecha_traspaso DESC, t.id DESC
";
$stmtHist = $conn->prepare($sqlHist);
$stmtHist->bind_param($types, ...$params);
$stmtHist->execute();
$historial = $stmtHist->get_result();
$stmtHist->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traspasos Salientes</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

  <style>
    :root{
      --brand:#0d6efd;
      --brand-100: rgba(13,110,253,.08);
    }
    body.bg-light{
      background:
        radial-gradient(1100px 420px at 110% -80%, var(--brand-100), transparent),
        radial-gradient(1100px 420px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }

    /* ✅ Ajustes del NAVBAR para móviles (sin tocar navbar.php) */
    #topbar, .navbar-luga{ font-size:16px; }
    @media (max-width: 576px){
      #topbar, .navbar-luga{
        font-size:16px;
        --brand-font:1.0em; --nav-font:.95em; --drop-font:.95em; --icon-em:1.05em;
        --pad-y:.44em; --pad-x:.62em;
      }
      #topbar .navbar-brand img, .navbar-luga .navbar-brand img{ width:1.9em; height:1.9em; }
      #topbar .navbar-toggler, .navbar-luga .navbar-toggler{ padding:.45em .7em; }
      #topbar .nav-avatar, #topbar .nav-initials,
      .navbar-luga .nav-avatar, .navbar-luga .nav-initials{ width:2.1em; height:2.1em; }
      .navbar .dropdown-menu{ font-size:.95em; }
    }
    @media (max-width: 360px){
      #topbar, .navbar-luga{ font-size:15px; }
    }

    .badge-status{font-size:.85rem}
    .table-sm td, .table-sm th{vertical-align: middle;}
    .btn-link{padding:0}
    .card{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .card-header{ border-top-left-radius:1rem; border-top-right-radius:1rem; }
    .page-title{
      border:0; border-radius:1rem;
      background: linear-gradient(135deg, #22c55e 0%, #0ea5e9 55%, #6366f1 100%);
      color:#fff; padding:1rem 1.25rem; box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
    }

    .actions{ gap:.5rem; display:flex; flex-wrap:wrap; }

    /* KPI cards */
    .kpi{ background:#fff; border-radius:1rem; padding:1rem; height:100%; position:relative; overflow:hidden; }
    .kpi .label{ font-size:.9rem; color:#6b7280; }
    .kpi .value{ font-size:1.6rem; font-weight:700; line-height:1.2; }
    .kpi .hint{ font-size:.85rem; color:#6b7280; }
    .kpi::after{
      content:""; position:absolute; right:-30px; top:-30px; width:120px; height:120px;
      background: radial-gradient(60px 60px at 60px 60px, rgba(13,110,253,.12), transparent 60%);
    }
    .kpi-danger { box-shadow:0 8px 24px rgba(220,38,38,.08); }
    .kpi-warning{ box-shadow:0 8px 24px rgba(234,179,8,.08); }
    .kpi-ok     { box-shadow:0 8px 24px rgba(34,197,94,.08); }

    /* Chips del resumen */
    .chip{ display:inline-block; border:1px solid rgba(0,0,0,.08); background:#fff; border-radius:999px; padding:.3rem .7rem; margin:.2rem; font-size:.9rem }
    .chip b{ font-weight:600 }

    /* Modal acuse */
    #acuseFrame{ width:100%; height:70vh; border:0; }
    #acuseSpinner{ height:70vh; }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">
  <div class="page-title mb-3">
    <h2 class="mb-0">📦 Traspasos Salientes</h2>
    <p class="mb-0 opacity-75">Traspasos enviados por tu <b>sucursal</b> (no solo por tu usuario).</p>
  </div>

  <?= $mensaje ?>

  <!-- =========================== KPI CARDS =========================== -->
  <?php
    $kpi_pend = count($pendRows);
    $sla = 3; // umbral para KPI >= 3 días (ajustable)
    $class_ge3 = $kpi_ge3 > 0 ? 'kpi-warning' : 'kpi-ok';
    $class_max = $kpi_max >= $sla ? 'kpi-warning' : 'kpi-ok';
    $rej_text  = ($kpi_rej_pct === null) ? 'Sin datos' : (n1($kpi_rej_pct) . '%');
  ?>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Pendientes (traspasos)</div>
        <div class="value"><?= n0($kpi_pend) ?></div>
        <div class="hint">Salientes esperando recepción</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Piezas en tránsito</div>
        <div class="value"><?= n0($kpi_piezas) ?></div>
        <div class="hint">Equipos dentro de pendientes</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi <?= $class_max ?>">
        <div class="label">Antigüedad máx</div>
        <div class="value"><?= n0($kpi_max) ?> d</div>
        <div class="hint">Desde el envío más antiguo</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Promedio en tránsito</div>
        <div class="value"><?= n1($kpi_avg) ?> d</div>
        <div class="hint">Días promedio de espera</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi <?= $class_ge3 ?>">
        <div class="label">Pendientes ≥ <?= $sla ?> días</div>
        <div class="value"><?= n0($kpi_ge3) ?></div>
        <div class="hint">Sugerido: dar seguimiento</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Enviados (últimos 7 días)</div>
        <div class="value"><?= n0($kpi_7d) ?></div>
        <div class="hint">Actividad reciente</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Enviados (mes)</div>
        <div class="value"><?= n0($kpi_mtd) ?></div>
        <div class="hint">Mes en curso</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Tasa de rechazo (mes)</div>
        <div class="value"><?= h($rej_text) ?></div>
        <div class="hint">De piezas procesadas</div>
      </div>
    </div>
  </div>

  <!-- =========================== TABS =========================== -->
  <ul class="nav nav-tabs mb-3" id="tabTraspasos" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-pendientes-tab" data-bs-toggle="tab" data-bs-target="#tab-pendientes" type="button" role="tab" aria-controls="tab-pendientes" aria-selected="true">⏳ Pendientes</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-historico-tab" data-bs-toggle="tab" data-bs-target="#tab-historico" type="button" role="tab" aria-controls="tab-historico" aria-selected="false">📜 Histórico</button>
    </li>
  </ul>

  <div class="tab-content" id="tabTraspasosContent">
    <!-- ========================= TAB: PENDIENTES ========================= -->
    <div class="tab-pane fade show active" id="tab-pendientes" role="tabpanel" aria-labelledby="tab-pendientes-tab">
      <?php if (!empty($pendRows)): ?>
        <?php foreach($pendRows as $traspaso): ?>
          <?php
          $idTraspaso = (int)$traspaso['id'];

          // Resumen compacto por modelo/color/capacidad
          $resumen = $conn->query("
            SELECT p.marca, p.modelo, p.color, COALESCE(p.capacidad,'') AS capacidad, COUNT(*) AS piezas
            FROM detalle_traspaso dt
            INNER JOIN inventario i ON i.id = dt.id_inventario
            INNER JOIN productos  p ON p.id = i.id_producto
            WHERE dt.id_traspaso = $idTraspaso
            GROUP BY p.marca, p.modelo, p.color, p.capacidad
            ORDER BY p.marca, p.modelo, p.color, p.capacidad
          ");

          // Detalle completo (se muestra colapsado)
          $detalles = $conn->query("
              SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2
              FROM detalle_traspaso dt
              INNER JOIN inventario i ON i.id = dt.id_inventario
              INNER JOIN productos  p ON p.id = i.id_producto
              WHERE dt.id_traspaso = $idTraspaso
              ORDER BY p.marca, p.modelo, i.id
          ");

          // Total piezas (rápido usando resumen)
          $totalPzas = 0;
          $resumenRows = [];
          while($rx = $resumen->fetch_assoc()){ $totalPzas += (int)$rx['piezas']; $resumenRows[]=$rx; }

          $collapseId = "det_pend_" . $idTraspaso;
          ?>
          <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                  Traspaso #<?= $idTraspaso ?> |
                  Destino: <b><?= h($traspaso['sucursal_destino']) ?></b> |
                  Fecha: <?= h($traspaso['fecha_traspaso']) ?>
                </span>
                <span>
                  Creado por: <?= h($traspaso['usuario_creo']) ?> ·
                  <span class="badge bg-light text-dark">Total piezas: <?= $totalPzas ?></span>
                </span>
              </div>
            </div>

            <div class="card-body">
              <!-- Resumen compacto (chips) -->
              <?php if (!empty($resumenRows)): ?>
                <div class="mb-2">
                  <?php foreach ($resumenRows as $rx): ?>
                    <?php
                      $cap = trim((string)$rx['capacidad']);
                      $txt = h($rx['marca'] . ' ' . $rx['modelo'])
                          . ($cap ? ' ' . h($cap) : '')
                          . ' ' . h($rx['color']);
                    ?>
                    <span class="chip"><b><?= (int)$rx['piezas'] ?>×</b> <?= $txt ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <!-- Botón para expandir/colapsar detalle -->
              <a class="btn btn-link" data-bs-toggle="collapse" href="#<?= $collapseId ?>">
                🔍 Ver detalle
              </a>

              <!-- Detalle colapsable -->
              <div id="<?= $collapseId ?>" class="collapse mt-2">
                <div class="table-responsive">
                  <table class="table table-striped table-bordered table-sm mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th>
                        <th>IMEI1</th><th>IMEI2</th><th>Estatus</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php while ($row = $detalles->fetch_assoc()): ?>
                        <tr>
                          <td><?= (int)$row['id'] ?></td>
                          <td><?= h($row['marca']) ?></td>
                          <td><?= h($row['modelo']) ?></td>
                          <td><?= h($row['color']) ?></td>
                          <td><?= $row['capacidad'] ?: '-' ?></td>
                          <td><?= h($row['imei1']) ?></td>
                          <td><?= $row['imei2'] ? h($row['imei2']) : '-' ?></td>
                          <td><span class="badge text-bg-warning">En tránsito</span></td>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
              <span class="text-muted">Esperando confirmación de <b><?= h($traspaso['sucursal_destino']) ?></b>…</span>
              <div class="actions">
                <!-- 🖨️ Reimprimir acuse (abre modal) -->
                <button type="button" class="btn btn-sm btn-outline-secondary btn-acuse" data-id="<?= $idTraspaso ?>">
                  🖨️ Reimprimir acuse
                </button>

                <!-- 🗑️ Eliminar -->
                <form method="POST" action="eliminar_traspaso.php"
                      onsubmit="return confirm('¿Eliminar este traspaso? Esta acción no se puede deshacer.')">
                  <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
                  <button type="submit" class="btn btn-sm btn-danger">🗑️ Eliminar Traspaso</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="alert alert-info">No hay traspasos salientes pendientes para tu sucursal.</div>
      <?php endif; ?>
    </div>

    <!-- ========================= TAB: HISTÓRICO ========================= -->
    <div class="tab-pane fade" id="tab-historico" role="tabpanel" aria-labelledby="tab-historico-tab">
      <div class="border rounded p-3 mb-3">
        <h5 class="mb-3">Filtros</h5>
        <form method="GET" class="row g-2">
          <input type="hidden" name="x" value="1"><!-- evita reenvío POST -->
          <div class="col-md-3">
            <label class="form-label">Desde</label>
            <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Estatus</label>
            <select name="estatus" class="form-select">
              <?php foreach (['Todos','Pendiente','Parcial','Completado','Rechazado'] as $op): ?>
                <option value="<?= $op ?>" <?= $op===$estatus?'selected':'' ?>><?= $op ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Destino</label>
            <select name="destino" class="form-select">
              <option value="0">Todos</option>
              <?php foreach ($destinos as $id=>$nom): ?>
                <option value="<?= $id ?>" <?= $id===$idDest?'selected':'' ?>><?= h($nom) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 d-flex gap-2 mt-2">
            <button class="btn btn-primary" onclick="localStorage.setItem('ts_activeTab','#tab-historico');">Filtrar</button>
            <a class="btn btn-outline-secondary" href="traspasos_salientes.php" onclick="localStorage.setItem('ts_activeTab','#tab-historico');">Limpiar</a>
          </div>
        </form>
      </div>

      <?php if ($historial && $historial->num_rows > 0): ?>
        <?php while($h = $historial->fetch_assoc()): ?>
          <?php
          $idT = (int)$h['id'];

          // Conteos del detalle (si hay columnas de resultado)
          $total = $rec = $rej = null;
          if ($hasDT_Resultado) {
            $q = $conn->prepare("
              SELECT 
                COUNT(*) AS total,
                SUM(resultado='Recibido')   AS recibidos,
                SUM(resultado='Rechazado')  AS rechazados
              FROM detalle_traspaso
              WHERE id_traspaso=?
            ");
            $q->bind_param("i", $idT);
            $q->execute();
            $cnt = $q->get_result()->fetch_assoc();
            $q->close();
            $total = (int)($cnt['total'] ?? 0);
            $rec   = (int)($cnt['recibidos'] ?? 0);
            $rej   = (int)($cnt['rechazados'] ?? 0);
          } else {
            $q = $conn->prepare("SELECT COUNT(*) AS total FROM detalle_traspaso WHERE id_traspaso=?");
            $q->bind_param("i", $idT);
            $q->execute();
            $total = (int)($q->get_result()->fetch_assoc()['total'] ?? 0);
            $q->close();
          }

          // Color de estatus
          $badge = 'bg-secondary';
          if ($h['estatus']==='Completado') $badge='bg-success';
          elseif ($h['estatus']==='Parcial') $badge='bg-warning text-dark';
          elseif ($h['estatus']==='Rechazado') $badge='bg-danger';
          elseif ($h['estatus']==='Pendiente') $badge='bg-info text-dark';

          $collapseIdH = "det_hist_" . $idT;
          ?>
          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div>
                <span class="badge badge-status <?= $badge ?>"><?= h($h['estatus']) ?></span>
                &nbsp; Traspaso #<?= $idT ?> · Destino: <b><?= h($h['sucursal_destino']) ?></b>
              </div>
              <div class="text-muted">
                Enviado: <?= h($h['fecha_traspaso']) ?>
                <?php if ($hasT_FechaRecep && $h['estatus']!=='Pendiente' && !empty($h['fecha_recepcion'])): ?>
                  &nbsp;·&nbsp; Recibido: <?= h($h['fecha_recepcion']) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="card-body">
              <div class="d-flex flex-wrap justify-content-between">
                <div>
                  <div>Creado por: <b><?= h($h['usuario_creo']) ?></b></div>
                  <?php if ($hasT_UsuarioRecibio && $h['estatus']!=='Pendiente' && !empty($h['usuario_recibio'])): ?>
                    <div>Recibido por: <b><?= h($h['usuario_recibio']) ?></b></div>
                  <?php endif; ?>
                </div>
                <div class="text-end">
                  <div>Total piezas: <b><?= ($total ?? '-') ?></b></div>
                  <?php if ($hasDT_Resultado): ?>
                    <div>Recibidas: <b><?= $rec ?></b> · Rechazadas: <b><?= $rej ?></b></div>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Detalle colapsable -->
              <a class="btn btn-link mt-2" data-bs-toggle="collapse" href="#<?= $collapseIdH ?>">🔍 Ver detalle</a>
              <div id="<?= $collapseIdH ?>" class="collapse mt-2">
              <?php
                $det = $conn->query("
                  SELECT i.id, p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2 " .
                  ($hasDT_Resultado ? ", dt.resultado" : "") .
                  ($hasDT_FechaResultado ? ", dt.fecha_resultado" : "") .
                  " FROM detalle_traspaso dt
                    INNER JOIN inventario i ON i.id = dt.id_inventario
                    INNER JOIN productos  p ON p.id = i.id_producto
                  WHERE dt.id_traspaso = $idT
                  ORDER BY p.marca, p.modelo, i.id
                ");
              ?>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>Capacidad</th>
                        <th>IMEI1</th><th>IMEI2</th>
                        <?php if ($hasDT_Resultado): ?><th>Resultado</th><?php endif; ?>
                        <?php if ($hasDT_FechaResultado): ?><th>Fecha resultado</th><?php endif; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php while($r = $det->fetch_assoc()): ?>
                        <tr>
                          <td><?= (int)$r['id'] ?></td>
                          <td><?= h($r['marca']) ?></td>
                          <td><?= h($r['modelo']) ?></td>
                          <td><?= h($r['color']) ?></td>
                          <td><?= $r['capacidad'] ?: '-' ?></td>
                          <td><?= h($r['imei1']) ?></td>
                          <td><?= $r['imei2'] ? h($r['imei2']) : '-' ?></td>
                          <?php if ($hasDT_Resultado): ?>
                            <td><?= h($r['resultado'] ?? 'Pendiente') ?></td>
                          <?php endif; ?>
                          <?php if ($hasDT_FechaResultado): ?>
                            <td><?= h($r['fecha_resultado'] ?? '') ?></td>
                          <?php endif; ?>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
              <div class="actions">
                <!-- 🖨️ Reimprimir acuse (abre modal) -->
                <button type="button" class="btn btn-sm btn-outline-secondary btn-acuse" data-id="<?= $idT ?>">
                  🖨️ Reimprimir acuse
                </button>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="alert alert-warning">No hay resultados con los filtros aplicados.</div>
      <?php endif; ?>
    </div>
  </div> <!-- /tab-content -->
</div>

<!-- ====================== MODAL ACUSE ====================== -->
<div class="modal fade" id="acuseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Acuse de traspaso</h5>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-sm btn-primary" id="btnPrintAcuse">Imprimir</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
      </div>
      <div class="modal-body p-0">
        <div class="d-flex justify-content-center align-items-center" id="acuseSpinner">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <span class="ms-2">Cargando acuse…</span>
        </div>
        <iframe id="acuseFrame" class="d-none" src="about:blank"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS (tabs + collapse + modal) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

<script>
// Modal + iframe loader + persistencia de pestaña
(function(){
  const acuseModalEl = document.getElementById('acuseModal');
  const acuseModal   = new bootstrap.Modal(acuseModalEl);
  const frame        = document.getElementById('acuseFrame');
  const spinner      = document.getElementById('acuseSpinner');
  const btnPrint     = document.getElementById('btnPrintAcuse');

  // Abrir modal y cargar acuse en iframe
  document.querySelectorAll('.btn-acuse').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.dataset.id;
      spinner.classList.remove('d-none');
      frame.classList.add('d-none');
      frame.src = 'acuse_traspaso.php?id=' + encodeURIComponent(id) + '&inline=1';
      acuseModal.show();
    });
  });

  // Quitar spinner cuando cargue el iframe
  frame.addEventListener('load', ()=>{
    spinner.classList.add('d-none');
    frame.classList.remove('d-none');
  });

  // Imprimir contenido del iframe
  btnPrint.addEventListener('click', ()=>{
    try{
      if (frame && frame.contentWindow) {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      }
    }catch(e){
      alert('No se pudo imprimir el acuse. Intenta abrirlo directamente.');
    }
  });

  // Limpiar src al cerrar para liberar memoria (opcional)
  acuseModalEl.addEventListener('hidden.bs.modal', ()=>{
    frame.src = 'about:blank';
    spinner.classList.remove('d-none');
    frame.classList.add('d-none');
  });

  // Guardar/restaurar pestaña activa (UX nice-to-have)
  const tabsEl = document.getElementById('tabTraspasos');
  if (tabsEl) {
    const stored = localStorage.getItem('ts_activeTab');
    if (stored) {
      const trigger = document.querySelector(`[data-bs-target="${stored}"]`);
      if (trigger) new bootstrap.Tab(trigger).show();
    }
    tabsEl.addEventListener('shown.bs.tab', (e)=>{
      const target = e.target.getAttribute('data-bs-target');
      if (target) localStorage.setItem('ts_activeTab', target);
    });
  }
})();
</script>
</body>
</html>
