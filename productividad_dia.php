<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =====================
// Parámetros
// =====================
$diasProductivos = 5; // para cuotas diarias

$hoyLocal  = (new DateTime('now',       new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
$ayerLocal = (new DateTime('yesterday', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');

$fecha = $_GET['fecha'] ?? $ayerLocal;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = $ayerLocal;

/* ===============================================================
   Detectar nombre real de la columna de tipo en productos:
   puede ser `tipo` o `tipo_producto`
================================================================ */
$colTipoProd = null;
$stmt = $conn->prepare("
  SELECT 1
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'productos'
    AND COLUMN_NAME  = 'tipo'
  LIMIT 1
");
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) $colTipoProd = 'tipo';
$stmt->close();

if ($colTipoProd === null) {
  $stmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'productos'
      AND COLUMN_NAME  = 'tipo_producto'
    LIMIT 1
  ");
  $stmt->execute();
  if ($stmt->get_result()->num_rows > 0) $colTipoProd = 'tipo_producto';
  $stmt->close();
}

/* Si no existe ninguna, no marcamos nada como módem (seguir operando) */
$modemCase = $colTipoProd
  ? "CASE WHEN LOWER(p.`$colTipoProd`) IN ('modem','mifi') THEN 1 ELSE 0 END"
  : "0";

/* ==================================================================
   Subconsulta re-usable (AGREGADA POR VENTA para el día seleccionado)
   Reglas:
   - Monto = ventas.precio_venta
   - Unidades = 2 si tipo_venta = 'Financiamiento+Combo', de lo contrario 1
   - Si la venta incluye algún producto de tipo Modem/MiFi => unidades = 0 y monto = 0
================================================================== */
$subVentasAggDay = "
  SELECT
      v.id,
      v.id_usuario,
      v.id_sucursal,
      CASE 
        WHEN IFNULL(vm.es_modem,0) = 1 THEN 0
        WHEN LOWER(v.tipo_venta) = 'financiamiento+combo' THEN 2
        ELSE 1
      END AS unidades,
      CASE 
        WHEN IFNULL(vm.es_modem,0) = 1 THEN 0
        ELSE v.precio_venta
      END AS monto
  FROM ventas v
  LEFT JOIN (
      SELECT dv.id_venta,
             MAX($modemCase) AS es_modem
      FROM detalle_venta dv
      JOIN productos p ON p.id = dv.id_producto
      GROUP BY dv.id_venta
  ) vm ON vm.id_venta = v.id
  WHERE DATE(v.fecha_venta) = ?
";

/* =====================
   TARJETAS GLOBALES
===================== */
$sqlGlobal = "
  SELECT
    COUNT(*)                            AS tickets,
    IFNULL(SUM(va.monto),0)             AS ventas_validas,
    IFNULL(SUM(va.unidades),0)          AS unidades_validas
  FROM ( $subVentasAggDay ) va
";
$stmt = $conn->prepare($sqlGlobal);
$stmt->bind_param("s", $fecha);
$stmt->execute();
$glob = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tickets         = (int)($glob['tickets'] ?? 0);
$ventasValidas   = (float)($glob['ventas_validas'] ?? 0);
$unidadesValidas = (int)($glob['unidades_validas'] ?? 0);
$ticketProm      = $tickets > 0 ? ($ventasValidas / $tickets) : 0.0;

/* =====================
   Cuota diaria GLOBAL (u.)
===================== */
$sqlCuotaDiariaGlobalU = "
  SELECT IFNULL(SUM(cuota_calc),0) AS cuota_diaria_global_u FROM (
    SELECT 
      s.id,
      (
        IFNULL((
          SELECT css.cuota_unidades
          FROM cuotas_semanales_sucursal css
          WHERE css.id_sucursal = s.id
            AND ? BETWEEN css.semana_inicio AND css.semana_fin
          ORDER BY css.semana_inicio DESC
          LIMIT 1
        ), 0)
        *
        GREATEST((
          SELECT COUNT(*) FROM usuarios u2
          WHERE u2.id_sucursal = s.id AND u2.activo = 1 AND u2.rol = 'Ejecutivo'
        ), 0)
      ) / ? AS cuota_calc
    FROM sucursales s
    WHERE s.tipo_sucursal='Tienda'
  ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalU);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgU = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalU = (float)($cdgU['cuota_diaria_global_u'] ?? 0);

/* =====================
   Cuota diaria GLOBAL ($)
===================== */
$sqlCuotaDiariaGlobalM = "
  SELECT IFNULL(SUM(cuota_diaria),0) AS cuota_diaria_global_monto FROM (
    SELECT s.id,
           IFNULL((
             SELECT cs.cuota_monto
             FROM cuotas_sucursales cs
             WHERE cs.id_sucursal = s.id
               AND cs.fecha_inicio <= ?
             ORDER BY cs.fecha_inicio DESC
             LIMIT 1
           ), 0) / ? AS cuota_diaria
    FROM sucursales s
    WHERE s.tipo_sucursal='Tienda'
  ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalM);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgM = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalM = (float)($cdgM['cuota_diaria_global_monto'] ?? 0);
$cumplGlobalM = $cuotaDiariaGlobalM > 0 ? ($ventasValidas / $cuotaDiariaGlobalM) * 100 : 0;

/* =====================
   RANKING EJECUTIVOS + GERENTES (por venta)
===================== */
$sqlEjecutivos = "
  SELECT
    u.id,
    u.nombre,
    s.nombre AS sucursal,

    IFNULL((
      SELECT css.cuota_unidades
      FROM cuotas_semanales_sucursal css
      WHERE css.id_sucursal = s.id
        AND ? BETWEEN css.semana_inicio AND css.semana_fin
      ORDER BY css.semana_inicio DESC
      LIMIT 1
    ) / ?, 0) AS cuota_diaria_ejecutivo,

    IFNULL(COUNT(va.id),0)       AS tickets,
    IFNULL(SUM(va.monto),0)      AS ventas_validas,
    IFNULL(SUM(va.unidades),0)   AS unidades_validas

  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN ( $subVentasAggDay ) va ON va.id_usuario = u.id
  WHERE s.tipo_sucursal='Tienda' AND u.activo=1 AND u.rol IN ('Ejecutivo','Gerente')
  GROUP BY u.id
  ORDER BY unidades_validas DESC, ventas_validas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
/* params: fecha (cuota), dias, fecha (subconsulta) */
$stmt->bind_param("sis", $fecha, $diasProductivos, $fecha);
$stmt->execute();
$resEj = $stmt->get_result();

$ejecutivos = [];
while ($r = $resEj->fetch_assoc()) {
    $r['cuota_diaria_ejecutivo'] = (float)$r['cuota_diaria_ejecutivo'];
    $r['unidades_validas'] = (int)$r['unidades_validas'];
    $r['ventas_validas']   = (float)$r['ventas_validas'];
    $r['tickets']          = (int)$r['tickets'];
    $r['cumplimiento']     = $r['cuota_diaria_ejecutivo']>0 ? ($r['unidades_validas'] / $r['cuota_diaria_ejecutivo'] * 100) : 0;
    $ejecutivos[] = $r;
}
$stmt->close();

/* =====================
   RANKING SUCURSALES (cumplimiento MONTO) – por venta
===================== */
$sqlSucursales = "
  SELECT
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    s.zona,

    IFNULL((
      SELECT cs.cuota_monto
      FROM cuotas_sucursales cs
      WHERE cs.id_sucursal = s.id
        AND cs.fecha_inicio <= ?
      ORDER BY cs.fecha_inicio DESC
      LIMIT 1
    ) / ?, 0) AS cuota_diaria_monto,

    IFNULL(SUM(va.monto),0)     AS ventas_validas,
    IFNULL(SUM(va.unidades),0)  AS unidades_validas

  FROM sucursales s
  LEFT JOIN ( $subVentasAggDay ) va ON va.id_sucursal = s.id
  WHERE s.tipo_sucursal='Tienda'
  GROUP BY s.id
  ORDER BY ventas_validas DESC
";
$stmt = $conn->prepare($sqlSucursales);
/* params: fecha (cuota), dias, fecha (subconsulta) */
$stmt->bind_param("sis", $fecha, $diasProductivos, $fecha);
$stmt->execute();
$resSuc = $stmt->get_result();

$sucursales = [];
while ($s = $resSuc->fetch_assoc()) {
    $s['cuota_diaria_monto'] = (float)$s['cuota_diaria_monto'];
    $s['ventas_validas']     = (float)$s['ventas_validas'];
    $s['unidades_validas']   = (int)$s['unidades_validas'];
    $s['cumplimiento_monto'] = $s['cuota_diaria_monto']>0 ? ($s['ventas_validas'] / $s['cuota_diaria_monto'] * 100) : 0;
    $sucursales[] = $s;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Productividad del Día (<?= h($fecha) ?>)</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

  <style>
    .num { font-variant-numeric: tabular-nums; letter-spacing: -.2px; }
    .clip { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .progress{height:20px}
    .progress-bar{font-size:.75rem}
    #topbar, .navbar-luga{ font-size:16px; }
    @media (max-width:576px){
      #topbar, .navbar-luga{
        font-size:16px;
        --brand-font:1.00em; --nav-font:.95em; --drop-font:.95em;
        --icon-em:1.05em; --pad-y:.44em; --pad-x:.62em;
      }
      #topbar .navbar-brand img, .navbar-luga .navbar-brand img{ width:1.8em; height:1.8em; }
      #topbar .btn-asistencia, .navbar-luga .btn-asistencia{ font-size:.95em; padding:.5em .9em !important; border-radius:12px; }
      #topbar .nav-avatar, #topbar .nav-initials,
      .navbar-luga .nav-avatar, .navbar-luga .nav-initials{ width:2.1em; height:2.1em; }
      #topbar .navbar-toggler, .navbar-luga .navbar-toggler{ padding:.45em .7em; }
    }
    @media (max-width:360px){ #topbar, .navbar-luga{ font-size:15px; } }
    @media (max-width:576px){
      body { font-size: 14px; }
      .container { padding-left: 8px; padding-right: 8px; }
      .table { font-size: 12px; table-layout: fixed; }
      .table thead th { font-size: 11px; }
      .table td, .table th { padding: .30rem .40rem; }
      .person-name, .suc-col, .suc-name{
        max-width: none !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word;
      }
      .clip { max-width: 120px; }
    }
    @media (max-width:360px){
      .table { font-size: 11px; }
      .table td, .table th { padding: .28rem .35rem; }
      .clip { max-width: 96px; }
    }
  </style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="m-0">📅 Productividad del Día — <?= date('d/m/Y', strtotime($fecha)) ?></h2>
    <form method="GET" class="d-flex gap-2">
      <input type="date" name="fecha" class="form-control" value="<?= h($fecha) ?>" max="<?= h($hoyLocal) ?>">
      <button class="btn btn-primary">Ver</button>
      <a class="btn btn-outline-secondary" href="productividad_dia.php?fecha=<?= h($ayerLocal) ?>">Ayer</a>
    </form>
  </div>

  <!-- Tarjetas globales -->
  <div class="row mt-3 g-3">
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Unidades</div>
        <div class="card-body"><h3><?= (int)$unidadesValidas ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ventas $</div>
        <div class="card-body"><h3>$<?= number_format($ventasValidas,2) ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Tickets</div>
        <div class="card-body"><h3><?= (int)$tickets ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ticket Prom.</div>
        <div class="card-body"><h3>$<?= number_format($ticketProm,2) ?></h3></div>
      </div>
    </div>
  </div>

  <!-- Global en UNIDADES -->
  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global (u.)</div>
        <div class="card-body"><h4><?= number_format($cuotaDiariaGlobalU,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php
            $cumplGlobalU = $cuotaDiariaGlobalU > 0 ? ($unidadesValidas / $cuotaDiariaGlobalU) * 100 : 0;
            $clsU = ($cumplGlobalU>=100?'bg-success':($cumplGlobalU>=60?'bg-warning':'bg-danger'));
          ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día (u.)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalU),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsU ?>" style="width:<?= min(100,$cumplGlobalU) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Global en MONTO -->
  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global ($)</div>
        <div class="card-body"><h4>$<?= number_format($cuotaDiariaGlobalM,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php $clsM = ($cumplGlobalM>=100?'bg-success':($cumplGlobalM>=60?'bg-warning':'bg-danger')); ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día ($)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalM),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsM ?>" style="width:<?= min(100,$cumplGlobalM) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mt-4" id="tabsDia">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEjecutivos">Ejecutivos / Gerentes 👔</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSucursales">Sucursales 🏢</button></li>
  </ul>

  <div class="tab-content">
    <!-- Ejecutivos + Gerentes -->
    <div class="tab-pane fade show active" id="tabEjecutivos">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Ranking del Día (<?= date('d/m/Y', strtotime($fecha)) ?>)</div>
        <div class="card-body p-0">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Nombre</th>
                  <th>Sucursal</th>
                  <th>Unidades</th>
                  <th class="d-none d-sm-table-cell">Ventas $</th>
                  <th class="d-none d-sm-table-cell">Tickets</th>
                  <th>Cuota <span class="d-none d-sm-inline">diaria </span>(u.)</th>
                  <th>% Cumpl.<span class="d-none d-sm-inline">imiento</span></th>
                  <th class="d-none d-sm-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ejecutivos as $e):
                  $cuotaDiaU = $e['cuota_diaria_ejecutivo'];
                  $cumpl = $e['cumplimiento'];
                  $fila = $cumpl>=100?'table-success':($cumpl>=60?'table-warning':'table-danger');
                  $cls  = $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger');
                ?>
                <tr class="<?= $fila ?>">
                  <td class="person-name" title="<?= h($e['nombre']) ?>"><?= h($e['nombre']) ?></td>
                  <td class="suc-col" title="<?= h($e['sucursal']) ?>"><?= h($e['sucursal']) ?></td>
                  <td class="num"><?= (int)$e['unidades_validas'] ?></td>
                  <td class="d-none d-sm-table-cell num">$<?= number_format($e['ventas_validas'],2) ?></td>
                  <td class="d-none d-sm-table-cell num"><?= (int)$e['tickets'] ?></td>
                  <td class="num"><?= number_format($cuotaDiaU,2) ?></td>
                  <td class="num"><?= number_format($cumpl,1) ?>%</td>
                  <td class="d-none d-sm-table-cell" style="min-width:160px">
                    <div class="progress">
                      <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$cumpl) ?>%"></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Sucursales -->
    <div class="tab-pane fade" id="tabSucursales">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">Ranking de Sucursales (<?= date('d/m/Y', strtotime($fecha)) ?>)</div>
        <div class="card-body p-0">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="d-none d-sm-table-cell">Zona</th>
                  <th class="d-none d-sm-table-cell">Unidades</th>
                  <th class="w-120">Ventas $</th>
                  <th>Cuota diaria ($)</th>
                  <th>% Cumpl. <span class="d-none d-sm-inline">(monto)</span></th>
                  <th class="d-none d-sm-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sucursales as $s):
                  $cumpl = $s['cumplimiento_monto'];
                  $fila = $cumpl>=100?'table-success':($cumpl>=60?'table-warning':'table-danger');
                  $cls  = $cumpl>=100?'bg-success':($cumpl>=60?'bg-warning':'bg-danger');
                ?>
                <tr class="<?= $fila ?>">
                  <td class="suc-name" title="<?= h($s['sucursal']) ?>"><?= h($s['sucursal']) ?></td>
                  <td class="d-none d-sm-table-cell">Zona <?= h($s['zona']) ?></td>
                  <td class="d-none d-sm-table-cell num"><?= (int)$s['unidades_validas'] ?></td>
                  <td class="num">$<?= number_format($s['ventas_validas'],2) ?></td>
                  <td class="num">$<?= number_format($s['cuota_diaria_monto'],2) ?></td>
                  <td class="num"><?= number_format($cumpl,1) ?>%</td>
                  <td class="d-none d-sm-table-cell" style="min-width:160px">
                    <div class="progress">
                      <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$cumpl) ?>%"></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

</body>
</html>
