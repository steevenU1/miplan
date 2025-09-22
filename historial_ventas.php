<?php
include 'navbar.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* =========================================================
   Helpers de compatibilidad de esquema
========================================================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $tableEsc  = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$tableEsc}'
          AND COLUMN_NAME  = '{$columnEsc}'
        LIMIT 1
    ";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
    $dif = $diaSemana - 2;          // martes=2
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-$dif days")->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7*$offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

// Semana seleccionada (para filtros/listado)
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

// Rango de la semana ACTUAL (para permitir edición)
list($inicioActualObj, $finActualObj) = obtenerSemanaPorIndice(0);

$msg              = $_GET['msg'] ?? '';
$id_sucursal      = (int)($_SESSION['id_sucursal'] ?? 0);
$ROL              = $_SESSION['rol'] ?? '';
$idUsuarioSesion  = (int)($_SESSION['id_usuario'] ?? 0);

// Subtipo sucursal
$subtipoSucursal = '';
if ($id_sucursal) {
    $stmtSubtipo = $conn->prepare("SELECT subtipo FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSubtipo->bind_param("i", $id_sucursal);
    $stmtSubtipo->execute();
    $rowSub = $stmtSubtipo->get_result()->fetch_assoc();
    $subtipoSucursal = $rowSub['subtipo'] ?? '';
    $stmtSubtipo->close();
}
$esSubdistribuidor = ($subtipoSucursal === 'Subdistribuidor');

/* =========================================================
   Detección del nombre de columna de tipo de producto
========================================================= */
$colTipoProd = hasColumn($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';

/* =========================================================
   Usuarios para filtro:
   - SIEMPRE activos de la sucursal
   - INACTIVOS solo si tuvieron ventas en la semana seleccionada
   Compat esquema: activo / estatus / fecha_baja
========================================================= */
$activosExpr = '';
if (hasColumn($conn, 'usuarios', 'activo')) {
    $activosExpr = "u.activo = 1";
} elseif (hasColumn($conn, 'usuarios', 'estatus')) {
    $activosExpr = "LOWER(u.estatus) IN ('activo','activa','alta')";
} elseif (hasColumn($conn, 'usuarios', 'fecha_baja')) {
    $activosExpr = "(u.fecha_baja IS NULL OR u.fecha_baja='0000-00-00')";
}

$existsVentas = "EXISTS (
    SELECT 1
    FROM ventas v
    WHERE v.id_usuario = u.id
      AND DATE(v.fecha_venta) BETWEEN ? AND ?
)";

$esInactivoCase = $activosExpr
    ? "CASE WHEN {$activosExpr} THEN 0 ELSE 1 END"
    : "CASE WHEN {$existsVentas} THEN 1 ELSE 0 END";

$sqlUsuarios = "
    SELECT u.id, u.nombre,
           {$esInactivoCase} AS es_inactivo
    FROM usuarios u
    WHERE u.id_sucursal = ?
      AND (
            " . ($activosExpr ? "{$activosExpr} OR " : "") . "
            {$existsVentas}
          )
    ORDER BY es_inactivo ASC, u.nombre ASC
";
$stmtUsuarios = $conn->prepare($sqlUsuarios);
$stmtUsuarios->bind_param("iss", $id_sucursal, $inicioSemana, $finSemana);
$stmtUsuarios->execute();
$resUsuarios = $stmtUsuarios->get_result();

$usuariosActivos = [];
$usuariosInactivos = [];
while ($row = $resUsuarios->fetch_assoc()) {
    if ((int)$row['es_inactivo'] === 1) {
        $usuariosInactivos[] = $row;
    } else {
        $usuariosActivos[] = $row;
    }
}
$stmtUsuarios->close();

/* =========================================================
   WHERE base para consultas de ventas
========================================================= */
$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

// Filtro por rol para el listado
if ($ROL === 'Ejecutivo') {
    $where .= " AND v.id_usuario=?";
    $params[] = $idUsuarioSesion;
    $types .= "i";
} elseif ($ROL === 'Gerente') {
    $where .= " AND v.id_sucursal=?";
    $params[] = $id_sucursal;
    $types .= "i";
}

// Filtros GET
if (!empty($_GET['tipo_venta'])) {
    $where .= " AND v.tipo_venta=?";
    $params[] = $_GET['tipo_venta'];
    $types .= "s";
}
if (!empty($_GET['usuario'])) {
    $where .= " AND v.id_usuario=?";
    $params[] = $_GET['usuario'];
    $types .= "i";
}
if (!empty($_GET['buscar'])) {
    $where .= " AND (v.nombre_cliente LIKE ? OR v.telefono_cliente LIKE ? OR v.tag LIKE ?
                     OR EXISTS(SELECT 1 FROM detalle_venta dv WHERE dv.id_venta=v.id AND dv.imei1 LIKE ?))";
    $busqueda = "%".$_GET['buscar']."%";
    array_push($params, $busqueda, $busqueda, $busqueda, $busqueda);
    $types .= "ssss";
}

/* =========================================================
   MÉTRICAS PARA CARDS (ajustadas)
   - Unidades (SIN módem/MiFi)
   - Módems (unidades)
   - Combos (unidades = 1 por venta F+Combo)
   - Monto vendido (0 si venta solo tiene módem/MiFi)
========================================================= */

// Unidades sin módem y módems (detalle_venta)
$sqlUnits = "
    SELECT
      SUM(CASE WHEN LOWER(COALESCE(p.$colTipoProd,'')) IN ('modem','mifi') THEN 0 ELSE 1 END) AS unidades_sin_modem,
      SUM(CASE WHEN LOWER(COALESCE(p.$colTipoProd,'')) IN ('modem','mifi') THEN 1 ELSE 0 END) AS unidades_modem
    FROM detalle_venta dv
    INNER JOIN ventas v ON dv.id_venta = v.id
    INNER JOIN productos p ON p.id = dv.id_producto
    $where
";
$stU = $conn->prepare($sqlUnits);
$stU->bind_param($types, ...$params);
$stU->execute();
$rowU = $stU->get_result()->fetch_assoc();
$totalUnidades          = (int)($rowU['unidades_sin_modem'] ?? 0);
$totalModemsUnidades    = (int)($rowU['unidades_modem'] ?? 0);
$stU->close();

// Combos (1 unidad por venta Financiamiento+Combo)
$sqlCombos = "
  SELECT SUM(CASE WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 1 ELSE 0 END) AS combos_u
  FROM ventas v
  $where
";
$stC = $conn->prepare($sqlCombos);
$stC->bind_param($types, ...$params);
$stC->execute();
$totalCombosUnidades = (int)($stC->get_result()->fetch_assoc()['combos_u'] ?? 0);
$stC->close();

// Monto vendido excluyendo ventas solo-módem
$sqlMonto = "
  SELECT IFNULL(SUM(CASE WHEN d.has_non_modem=1 THEN v.precio_venta ELSE 0 END),0) AS total_monto
  FROM ventas v
  LEFT JOIN (
    SELECT dv.id_venta,
           MAX(CASE WHEN LOWER(COALESCE(p.$colTipoProd,'')) IN ('modem','mifi') THEN 0 ELSE 1 END) AS has_non_modem
    FROM detalle_venta dv
    INNER JOIN productos p ON p.id = dv.id_producto
    GROUP BY dv.id_venta
  ) d ON d.id_venta = v.id
  $where
";
$stM = $conn->prepare($sqlMonto);
$stM->bind_param($types, ...$params);
$stM->execute();
$totalMonto = (float)($stM->get_result()->fetch_assoc()['total_monto'] ?? 0);
$stM->close();

// Comisiones (igual que antes)
$sqlResumen = "
    SELECT IFNULL(SUM(dv.comision_regular + dv.comision_especial),0) AS total_comisiones
    FROM detalle_venta dv
    INNER JOIN ventas v ON dv.id_venta = v.id
    $where
";
$stmtResumen = $conn->prepare($sqlResumen);
$stmtResumen->bind_param($types, ...$params);
$stmtResumen->execute();
$resumen = $stmtResumen->get_result()->fetch_assoc();
$totalComisiones = (float)($resumen['total_comisiones'] ?? 0);
$stmtResumen->close();

/* =========================================================
   Datos del listado
========================================================= */
// Ventas (con enganche y comentarios)
$sqlVentas = "
    SELECT v.id, v.tag, v.nombre_cliente, v.telefono_cliente, v.tipo_venta,
           v.precio_venta, v.fecha_venta,
           v.enganche, v.forma_pago_enganche, v.enganche_efectivo, v.enganche_tarjeta,
           v.comentarios,
           u.id AS id_usuario, u.nombre AS usuario
    FROM ventas v
    INNER JOIN usuarios u ON v.id_usuario = u.id
    $where
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$ventas = $stmt->get_result();
$totalVentas = $ventas->num_rows;

// Detalle por venta
$sqlDetalle = "
    SELECT dv.id_venta, p.marca, p.modelo, p.color, dv.imei1,
           dv.comision_regular, dv.comision_especial, dv.comision,
           p.precio_lista
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
    ORDER BY dv.id_venta, dv.id ASC
";
$detalleResult = $conn->query($sqlDetalle);
$detalles = [];
while ($row = $detalleResult->fetch_assoc()) {
    $detalles[$row['id_venta']][] = $row;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Historial de Ventas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root { --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color: var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#eef2ff; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select { height:42px; }
    .accordion-button{ gap:.5rem; }
    .venta-head .tag{ font-weight:700; }
    .sticky-tools{ position:sticky; top:0; z-index:2; background:var(--surface); }
    .comentarios-box{ background:#fffdf6; border:1px dashed #ffdca8; border-radius:12px; padding:.6rem .8rem; color:#7a591f; }
  </style>
</head>
<body>
<div class="container py-3">

  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🧾 Historial de Ventas</h1>
      <div class="small-muted">
        Usuario: <strong><?= h($_SESSION['nombre']) ?></strong> · Semana:
        <strong><?= $inicioSemanaObj->format('d/m/Y') ?> – <?= $finSemanaObj->format('d/m/Y') ?></strong>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="chip chip-info"><i class="bi bi-receipt"></i> Ventas: <?= (int)$totalVentas ?></span>
      <span class="chip chip-success"><i class="bi bi-bag-check"></i> Unidades: <?= (int)$totalUnidades ?></span>
      <span class="chip chip-success"><i class="bi bi-currency-dollar"></i> Monto: $<?= number_format($totalMonto,2) ?></span>
      <?php if (!$esSubdistribuidor): ?>
        <span class="chip chip-warn"><i class="bi bi-coin"></i> Comisiones: $<?= number_format($totalComisiones,2) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success card-surface mt-3"><?= h($msg) ?></div>
  <?php endif; ?>

  <!-- Tarjetas resumen -->
  <div class="row g-3 mt-1">
    <div class="col-12 col-md-3">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-bag-check"></i></div>
          <div>
            <div class="small-muted">Unidades vendidas</div>
            <div class="h4 m-0"><?= (int)$totalUnidades ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-router"></i></div>
          <div>
            <div class="small-muted">Módems</div>
            <div class="h4 m-0"><?= (int)$totalModemsUnidades ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="small-muted">Combos</div>
            <div class="h4 m-0"><?= (int)$totalCombosUnidades ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="card card-surface p-3 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-currency-dollar"></i></div>
          <div>
            <div class="small-muted">Monto vendido</div>
            <div class="h4 m-0">$<?= number_format($totalMonto,2) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="card card-surface p-3 mt-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-funnel me-2"></i>Filtros</h5>
      <div class="d-flex gap-2">
        <?php
          $prev = max(0, $semanaSeleccionada - 1);
          $next = $semanaSeleccionada + 1;
          $qsPrev = $_GET; $qsPrev['semana'] = $prev;
          $qsNext = $_GET; $qsNext['semana'] = $next;
        ?>
        <a class="btn btn-soft btn-sm" href="?<?= http_build_query($qsPrev) ?>"><i class="bi bi-arrow-left"></i> Semana previa</a>
        <a class="btn btn-soft btn-sm" href="?<?= http_build_query($qsNext) ?>">Siguiente semana <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <div class="row g-3 mt-1 filters">
      <div class="col-md-3">
        <label class="small-muted">Semana</label>
        <select name="semana" class="form-select" onchange="this.form.submit()">
          <?php for ($i=0; $i<8; $i++):
            list($ini, $fin) = obtenerSemanaPorIndice($i);
            $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
          ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="small-muted">Tipo de venta</label>
        <select name="tipo_venta" class="form-select">
          <option value="">Todas</option>
          <option value="Contado" <?= (($_GET['tipo_venta'] ?? '')=='Contado')?'selected':'' ?>>Contado</option>
          <option value="Financiamiento" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento')?'selected':'' ?>>Financiamiento</option>
          <option value="Financiamiento+Combo" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento+Combo')?'selected':'' ?>>Financiamiento + Combo</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="small-muted">Usuario</label>
        <select name="usuario" class="form-select">
          <option value="">Todos</option>

          <?php if (!empty($usuariosActivos)): ?>
            <optgroup label="Activos">
              <?php foreach ($usuariosActivos as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (($_GET['usuario'] ?? '')==$u['id'])?'selected':'' ?>>
                  <?= h($u['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>

          <?php if (!empty($usuariosInactivos)): ?>
            <optgroup label="Inactivos (con ventas en semana)">
              <?php foreach ($usuariosInactivos as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (($_GET['usuario'] ?? '')==$u['id'])?'selected':'' ?>>
                  <?= h($u['nombre']) ?> (inactivo)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="small-muted">Buscar (Cliente, Tel, IMEI, TAG)</label>
        <input type="text" name="buscar" class="form-control" value="<?= h($_GET['buscar'] ?? '') ?>" placeholder="Ej. Juan, 722..., 3520, LUGA-...">
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      <a href="historial_ventas.php" class="btn btn-secondary">Limpiar</a>
      <a href="exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success">
        <i class="bi bi-file-earmark-excel"></i> Exportar a Excel
      </a>
    </div>
  </form>

  <!-- Historial (accordion) -->
  <?php if ($totalVentas === 0): ?>
    <div class="alert alert-info card-surface mt-3 mb-0">
      <i class="bi bi-info-circle me-1"></i>No hay ventas para los filtros seleccionados.
    </div>
  <?php else: ?>
    <div class="accordion mt-3" id="ventasAccordion">
      <?php $idx = 0; while ($venta = $ventas->fetch_assoc()): $idx++; ?>
        <?php
          // Permisos y ventana actual
          $esPropia = ((int)$venta['id_usuario'] === $idUsuarioSesion);

          $fechaVentaDT = new DateTime($venta['fecha_venta']);
          $enSemanaActual = ($fechaVentaDT >= $inicioActualObj && $fechaVentaDT <= $finActualObj);

          $puedeEliminar = ($ROL === 'Admin'); // eliminar solo admin
          $puedeEditar   = (in_array($ROL, ['Ejecutivo','Gerente']) && $esPropia && $enSemanaActual);

          // icono tipo
          $chipIcon = 'bi-tag';
          if ($venta['tipo_venta'] === 'Contado') $chipIcon = 'bi-cash-coin';
          elseif ($venta['tipo_venta'] === 'Financiamiento') $chipIcon = 'bi-bank';
          elseif ($venta['tipo_venta'] === 'Financiamiento+Combo') $chipIcon = 'bi-box-seam';

          $accId = "venta".$idx;
        ?>
        <div class="accordion-item card-surface mb-2">
          <h2 class="accordion-header" id="h<?= $accId ?>">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= $accId ?>" aria-expanded="<?= $idx===1?'true':'false' ?>" aria-controls="c<?= $accId ?>">
              <div class="venta-head d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">#<?= (int)$venta['id'] ?></span>
                <span class="chip chip-info"><i class="bi <?= $chipIcon ?>"></i> <?= h($venta['tipo_venta']) ?></span>
                <span class="tag">TAG: <?= h($venta['tag']) ?></span>
                <span>Cliente: <strong><?= h($venta['nombre_cliente']) ?></strong> (<?= h($venta['telefono_cliente']) ?>)</span>
                <span class="ms-2"><i class="bi bi-calendar-event"></i> <?= h($venta['fecha_venta']) ?></span>
                <span class="ms-2"><i class="bi bi-person"></i> <?= h($venta['usuario']) ?></span>
                <span class="ms-2 fw-semibold"><i class="bi bi-currency-dollar"></i> $<?= number_format((float)$venta['precio_venta'],2) ?></span>
                <span class="ms-2 chip chip-success">
                  <i class="bi bi-wallet2"></i> Enganche: $<?= number_format((float)$venta['enganche'],2) ?>
                  <?php if (!empty($venta['forma_pago_enganche'])): ?>
                    &nbsp;(<em><?= h($venta['forma_pago_enganche']) ?></em>)
                  <?php endif; ?>
                </span>
                <?php if (!$enSemanaActual): ?>
                  <span class="ms-2 badge rounded-pill text-bg-secondary">Fuera de semana actual</span>
                <?php endif; ?>
              </div>
            </button>
          </h2>
          <div id="c<?= $accId ?>" class="accordion-collapse collapse <?= $idx===1?'show':'' ?>" aria-labelledby="h<?= $accId ?>" data-bs-parent="#ventasAccordion">
            <div class="accordion-body">

              <div class="d-flex justify-content-end gap-2 mb-2">
                <?php if ($puedeEditar): ?>
                  <button 
                    class="btn btn-outline-primary btn-sm btn-edit-venta"
                    data-bs-toggle="modal"
                    data-bs-target="#editarVentaModal"
                    data-id="<?= (int)$venta['id'] ?>"
                    data-tag="<?= h($venta['tag']) ?>"
                    data-precio="<?= number_format((float)$venta['precio_venta'], 2, '.', '') ?>"
                    data-enganche="<?= number_format((float)$venta['enganche'], 2, '.', '') ?>"
                    data-formapago="<?= h($venta['forma_pago_enganche']) ?>"
                    data-cliente="<?= h($venta['nombre_cliente']) ?>"
                    data-telefono="<?= h($venta['telefono_cliente']) ?>"
                    data-tipo="<?= h($venta['tipo_venta']) ?>"
                  >
                    <i class="bi bi-pencil-square"></i> Editar
                  </button>
                <?php endif; ?>

                <?php if ($puedeEliminar): ?>
                  <button 
                    class="btn btn-outline-danger btn-sm" 
                    data-bs-toggle="modal" 
                    data-bs-target="#confirmEliminarModal"
                    data-idventa="<?= (int)$venta['id'] ?>">
                    <i class="bi bi-trash"></i> Eliminar
                  </button>
                <?php endif; ?>
              </div>

              <div class="tbl-wrap">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Marca</th>
                      <th>Modelo</th>
                      <th>Color</th>
                      <th>IMEI</th>
                      <th>Precio Lista</th>
                      <?php if (!$esSubdistribuidor): ?>
                        <th>Comisión Regular</th>
                        <th>Comisión Especial</th>
                        <th>Total Comisión</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (isset($detalles[$venta['id']])): ?>
                      <?php $esPrincipal = true; foreach ($detalles[$venta['id']] as $equipo): ?>
                        <tr>
                          <td><?= h($equipo['marca']) ?></td>
                          <td><?= h($equipo['modelo']) ?></td>
                          <td><?= h($equipo['color']) ?></td>
                          <td><?= h($equipo['imei1']) ?></td>
                          <td>
                            <?php if ($esPrincipal): ?>
                              $<?= number_format((float)$equipo['precio_lista'], 2) ?>
                            <?php else: ?>
                              -
                            <?php endif; ?>
                          </td>
                          <?php if (!$esSubdistribuidor): ?>
                            <td>$<?= number_format((float)$equipo['comision_regular'], 2) ?></td>
                            <td>$<?= number_format((float)$equipo['comision_especial'], 2) ?></td>
                            <td>$<?= number_format((float)$equipo['comision'], 2) ?></td>
                          <?php endif; ?>
                        </tr>
                        <?php $esPrincipal = false; endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="<?= $esSubdistribuidor ? 5 : 8; ?>" class="text-center small-muted">Sin equipos registrados</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if ((float)$venta['enganche'] > 0): ?>
                <div class="mt-3 small-muted">
                  <strong>Desglose enganche:</strong>
                  Efectivo $<?= number_format((float)$venta['enganche_efectivo'],2) ?> ·
                  Tarjeta $<?= number_format((float)$venta['enganche_tarjeta'],2) ?>
                </div>
              <?php endif; ?>

              <?php if (trim((string)$venta['comentarios']) !== ''): ?>
                <div class="mt-3 comentarios-box">
                  <i class="bi bi-chat-text me-1"></i>
                  <strong>Comentarios:</strong> <?= nl2br(h($venta['comentarios'])) ?>
                </div>
              <?php endif; ?>

            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php endif; ?>

</div>

<!-- Modal: Editar venta (Ejecutivo/Gerente solo propias y semana actual) -->
<div class="modal fade" id="editarVentaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form action="editar_venta.php" method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_venta" id="ev_id_venta">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TAG</label>
            <input type="text" class="form-control" name="tag" id="ev_tag" maxlength="50">
            <div class="form-text" id="ev_tag_help">Obligatorio excepto en ventas de Contado.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Precio de venta</label>
            <input type="number" step="0.01" min="0" class="form-control" name="precio_venta" id="ev_precio" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Enganche</label>
            <input type="number" step="0.01" min="0" class="form-control" name="enganche" id="ev_enganche">
          </div>
          <div class="col-md-6">
            <label class="form-label">Forma de pago del enganche</label>
            <select class="form-select" name="forma_pago_enganche" id="ev_forma">
              <option value="">N/A</option>
              <option value="Efectivo">Efectivo</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Mixto">Mixto</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nombre del cliente</label>
            <input type="text" class="form-control" name="nombre_cliente" id="ev_cliente" maxlength="100">
          </div>
          <div class="col-md-6">
            <label class="form-label">Teléfono del cliente</label>
            <input type="text" class="form-control" name="telefono_cliente" id="ev_tel" maxlength="20">
          </div>
        </div>
        <div class="form-text mt-2">
          Solo puedes editar estos campos. Otros datos (equipos, comisiones, etc.) no se modifican aquí.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal de confirmación de eliminación (solo Admin) -->
<div class="modal fade" id="confirmEliminarModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form action="eliminar_venta.php" method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmar eliminación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_venta" id="modalIdVenta">
        <p class="mb-0">¿Seguro que deseas eliminar esta venta? <br>
        <small class="text-muted">Esto devolverá los equipos al inventario y quitará la comisión.</small></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Modal eliminar (Admin)
const modalDel = document.getElementById('confirmEliminarModal');
if (modalDel) {
  modalDel.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const id = btn?.getAttribute('data-idventa') || '';
    document.getElementById('modalIdVenta').value = id;
  });
}

// Modal editar (Ejecutivo/Gerente propios)
// TAG requerido solo si tipo != 'Contado'
document.querySelectorAll('.btn-edit-venta').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('ev_id_venta').value = btn.dataset.id || '';
    document.getElementById('ev_tag').value      = btn.dataset.tag || '';
    document.getElementById('ev_precio').value   = btn.dataset.precio || '';
    document.getElementById('ev_enganche').value = btn.dataset.enganche || '';
    document.getElementById('ev_forma').value    = btn.dataset.formapago || '';
    document.getElementById('ev_cliente').value  = btn.dataset.cliente || '';
    document.getElementById('ev_tel').value      = btn.dataset.telefono || '';

    const tipo = (btn.dataset.tipo || '').trim();
    const tagInput = document.getElementById('ev_tag');
    const help = document.getElementById('ev_tag_help');
    const requerido = (tipo !== 'Contado');
    tagInput.required = requerido;
    help.textContent = requerido
      ? 'Obligatorio para Financiamiento / Financiamiento+Combo.'
      : 'Opcional en ventas de Contado.';
  });
});
</script>
</body>
</html>
