<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }
$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin','Operaciones','Soporte','GerenteZona'], true)) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badgeEstatus($e) {
  return match ($e) {
    'Nueva' => 'secondary',
    'En revisión' => 'info',
    'Aprobada' => 'primary',
    'En proceso' => 'warning',
    'Resuelta' => 'success',
    'Rechazada' => 'danger',
    default => 'secondary',
  };
}
$ESTADOS   = ['Nueva','En revisión','Aprobada','En proceso','Resuelta','Rechazada'];
$PRIORIDAD = ['Alta','Media','Baja'];

$msg = '';

/* =========================================================
   HANDLERS LIGEROS (MISMO ARCHIVO)
   - ?view=detalle&id=123  → fragmento HTML para modal
   - ?view=folio&id=123    → folio imprimible
========================================================= */
if (isset($_GET['view']) && isset($_GET['id'])) {
  $view = $_GET['view']; $idSol = (int)$_GET['id'];

  // Admin/Gerencia puede ver cualquiera
  $sqlSol = "
    SELECT ms.*, s.nombre AS sucursal, u.nombre AS solicitante
    FROM mantenimiento_solicitudes ms
    JOIN sucursales s ON s.id = ms.id_sucursal
    JOIN usuarios   u ON u.id = ms.id_usuario
    WHERE ms.id = ?
    LIMIT 1
  ";
  $st = $conn->prepare($sqlSol);
  $st->bind_param("i", $idSol);
  $st->execute();
  $sol = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$sol) { http_response_code(404); echo "<div class='text-danger p-3'>No encontrado.</div>"; exit; }

  // Adjuntos
  $files = [];
  $qf = $conn->prepare("SELECT ruta_relativa, nombre_archivo, mime_type FROM mantenimiento_adjuntos WHERE id_solicitud=? ORDER BY id");
  $qf->bind_param("i", $idSol);
  $qf->execute();
  $rf = $qf->get_result();
  while($f=$rf->fetch_assoc()) $files[]=$f;
  $qf->close();

  // Timeline básico
  $etapas = ['Nueva','En revisión','Aprobada','En proceso','Resuelta'];
  $esRech = ($sol['estatus']==='Rechazada');
  $idx = array_search($sol['estatus'], $etapas, true);
  if ($idx === false) { $idx = 0; }
  $pct = $esRech ? 100 : (int)round(($idx) / (count($etapas)-1) * 100);

  if ($view==='detalle') {
    ?>
    <style>
      .timeline { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
      .t-step { display:flex; align-items:center; gap:.5rem; }
      .t-dot { width:12px; height:12px; border-radius:50%; background:#d1d5db; }
      .t-step.active .t-dot { background:#0d6efd; }
      .t-step.done   .t-dot { background:#22c55e; }
      .t-label { font-size:.9rem; }
      .progress{ height:10px; border-radius:999px; }
      .file-pill{ display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .6rem; border:1px solid #e5e7eb; border-radius:999px; margin:.2rem .25rem .2rem 0; background:#fff; }
    </style>
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h5 class="mb-1">Solicitud #<?= (int)$sol['id'] ?> — <?= h($sol['titulo']) ?></h5>
        <div class="text-muted small">Sucursal: <b><?= h($sol['sucursal']) ?></b> · Solicitante: <b><?= h($sol['solicitante']) ?></b></div>
      </div>
      <div class="text-end">
        <div>
          <span class="badge text-bg-<?= badgeEstatus($sol['estatus']) ?>"><?= h($sol['estatus']) ?></span>
          <span class="badge <?= $sol['prioridad']=='Alta'?'text-bg-danger':($sol['prioridad']=='Media'?'text-bg-warning text-dark':'text-bg-secondary') ?>">
            <?= h($sol['prioridad']) ?>
          </span>
        </div>
        <a class="btn btn-sm btn-outline-secondary mt-2" target="_blank" href="mantenimiento_admin.php?view=folio&id=<?= (int)$sol['id'] ?>">
          <i class="bi bi-printer"></i> Imprimir folio
        </a>
      </div>
    </div>

    <div class="mt-3">
      <div class="timeline mb-2">
        <?php
          $to = $esRech ? ['Nueva','En revisión','Rechazada'] : $etapas;
          foreach ($to as $i=>$et):
            $cl = '';
            if ($esRech) { $cl = ($et==='Rechazada') ? 'active' : 'done'; }
            else { $cl = ($i < $idx) ? 'done' : (($i===$idx)?'active':''); }
        ?>
          <div class="t-step <?= $cl ?>">
            <div class="t-dot"></div>
            <div class="t-label"><?= h($et) ?></div>
          </div>
          <?php if ($i < count($to)-1): ?>
            <div class="flex-grow-1" style="height:2px; background:#e5e7eb; min-width:20px;"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="progress"><div class="progress-bar" role="progressbar" style="width: <?= (int)$pct ?>%;"></div></div>
    </div>

    <div class="row g-3 mt-3">
      <div class="col-md-8">
        <h6 class="mb-1">Descripción</h6>
        <p class="mb-2"><?= nl2br(h($sol['descripcion'])) ?></p>
        <?php if (!empty($sol['contacto'])): ?>
          <div class="small text-muted">Contacto en sucursal: <?= h($sol['contacto']) ?></div>
        <?php endif; ?>
        <div class="small text-muted">Creada: <?= h(date('Y-m-d H:i', strtotime($sol['fecha_solicitud']))) ?>
          <?php if (!empty($sol['fecha_actualizacion'])): ?> · Actualizada: <?= h(date('Y-m-d H:i', strtotime($sol['fecha_actualizacion']))) ?><?php endif; ?>
        </div>
      </div>
      <div class="col-md-4">
        <h6 class="mb-2">Actualizar estatus</h6>
        <form id="formStatus" class="d-flex gap-2">
          <input type="hidden" name="action" value="cambiar">
          <input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
          <select class="form-select form-select-sm" name="estatus">
            <?php foreach ($ESTADOS as $st): ?>
              <option value="<?= h($st) ?>" <?= $st===$sol['estatus']?'selected':'' ?>><?= h($st) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary">Guardar</button>
        </form>
        <div id="statusMsg" class="small mt-2 text-muted"></div>
      </div>
    </div>

    <div class="mt-3">
      <h6 class="mb-2">Adjuntos</h6>
      <?php if (count($files)===0): ?>
        <span class="text-muted">— Sin adjuntos —</span>
      <?php else: foreach ($files as $f): 
        $isImg = isset($f['mime_type']) && strpos($f['mime_type'],'image/')===0;
      ?>
        <a class="file-pill" target="_blank" href="<?= h($f['ruta_relativa']) ?>">
          <i class="bi <?= $isImg ? 'bi-image' : 'bi-file-earmark-pdf' ?>"></i>
          <span class="text-truncate" style="max-width:240px;"><?= h($f['nombre_archivo']) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
    <?php
    exit;
  }

  if ($view==='folio') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Folio Solicitud #<?= (int)$sol['id'] ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        @media print { .no-print { display:none !important; } }
        body{ background:#fff; }
        .folio{ max-width:900px; margin:24px auto; padding:24px; border:1px solid #e5e7eb; border-radius:12px; }
        .muted{ color:#6b7280; }
        .lh-tight{ line-height:1.25; }
      </style>
    </head>
    <body>
      <div class="folio">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h3 class="mb-1">Folio de Mantenimiento</h3>
            <div class="muted">Solicitud #<?= (int)$sol['id'] ?> · <?= h(date('Y-m-d H:i', strtotime($sol['fecha_solicitud']))) ?></div>
          </div>
          <div class="text-end">
            <div class="lh-tight"><b>Sucursal:</b> <?= h($sol['sucursal']) ?></div>
            <div class="lh-tight"><b>Solicitante:</b> <?= h($sol['solicitante']) ?></div>
            <div class="lh-tight"><b>Estatus:</b> <?= h($sol['estatus']) ?></div>
            <div class="lh-tight"><b>Prioridad:</b> <?= h($sol['prioridad']) ?></div>
          </div>
        </div>
        <hr>
        <h5 class="mb-2"><?= h($sol['titulo']) ?></h5>
        <p><?= nl2br(h($sol['descripcion'])) ?></p>
        <?php if (!empty($sol['contacto'])): ?>
          <p class="muted"><b>Contacto:</b> <?= h($sol['contacto']) ?></p>
        <?php endif; ?>
        <hr>
        <h6>Adjuntos</h6>
        <?php
          $files = [];
          $qf = $conn->prepare("SELECT ruta_relativa, nombre_archivo FROM mantenimiento_adjuntos WHERE id_solicitud=? ORDER BY id");
          $qf->bind_param("i", $idSol);
          $qf->execute();
          $rf = $qf->get_result();
          while($f=$rf->fetch_assoc()) $files[]=$f;
          $qf->close();
        ?>
        <?php if (count($files)===0): ?>
          <div class="muted">— Sin adjuntos —</div>
        <?php else: ?>
          <ul class="mb-0">
            <?php foreach ($files as $f): ?>
              <li><a href="<?= h($f['ruta_relativa']) ?>" target="_blank"><?= h($f['nombre_archivo']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="mt-4 no-print">
          <button class="btn btn-primary" onclick="window.print()">Imprimir</button>
          <a class="btn btn-outline-secondary" href="mantenimiento_admin.php">Cerrar</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
  }

  http_response_code(400);
  echo "<div class='text-danger p-3'>Vista no válida.</div>";
  exit;
}

/* =========================================================
   ACCIONES POST (inline y masivo)
========================================================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($_POST['action']??'')==='cambiar') {
    $id  = (int)($_POST['id'] ?? 0);
    $st  = $_POST['estatus'] ?? 'En revisión';
    if ($id>0 && in_array($st, $ESTADOS, true)) {
      $stmt = $conn->prepare("UPDATE mantenimiento_solicitudes SET estatus=?, fecha_actualizacion=NOW() WHERE id=?");
      $stmt->bind_param("si", $st, $id);
      $stmt->execute();
      $stmt->close();
      $msg = "Estatus actualizado (#$id → $st)";
    }
  }
  if (($_POST['action']??'')==='cambiar_masivo') {
    $st = $_POST['estatus_masivo'] ?? '';
    $ids = $_POST['ids'] ?? [];
    $ok=0;
    if (in_array($st, $ESTADOS, true) && is_array($ids) && count($ids)>0) {
      $stmt = $conn->prepare("UPDATE mantenimiento_solicitudes SET estatus=?, fecha_actualizacion=NOW() WHERE id=?");
      foreach ($ids as $id) {
        $id = (int)$id;
        if ($id<=0) continue;
        $stmt->bind_param("si", $st, $id);
        $stmt->execute(); $ok++;
      }
      $stmt->close();
      $msg = "Estatus actualizado en $ok solicitud(es) → $st";
    }
  }
}

/* =========================================================
   FILTROS (GET)
========================================================= */
$estatusF   = $_GET['estatus']   ?? '';
$prioridadF = $_GET['prioridad'] ?? '';
$sucursalF  = (int)($_GET['sucursal'] ?? 0);
$q          = trim($_GET['q'] ?? '');
$fi         = $_GET['fi'] ?? ''; // YYYY-MM-DD
$ff         = $_GET['ff'] ?? '';

if (!$fi || !$ff) {
  // por defecto, últimos 90 días
  $fi = date('Y-m-d', strtotime('-90 days'));
  $ff = date('Y-m-d');
}

$where  = " WHERE DATE(ms.fecha_solicitud) BETWEEN ? AND ? ";
$params = [$fi, $ff];
$types  = "ss";

if ($estatusF && in_array($estatusF, $ESTADOS, true)) {
  $where .= " AND ms.estatus=? "; $params[] = $estatusF; $types .= "s";
}
if ($prioridadF && in_array($prioridadF, $PRIORIDAD, true)) {
  $where .= " AND ms.prioridad=? "; $params[] = $prioridadF; $types .= "s";
}
if ($sucursalF > 0) {
  $where .= " AND ms.id_sucursal=? "; $params[] = $sucursalF; $types .= "i";
}
if ($q !== '') {
  $like = "%$q%";
  $where .= " AND (ms.titulo LIKE ? OR ms.descripcion LIKE ? OR s.nombre LIKE ? OR u.nombre LIKE ?) ";
  array_push($params, $like, $like, $like, $like);
  $types .= "ssss";
}

/* =========================================================
   QUERIES: sucursales, KPI por estatus, tabla, adjuntos
========================================================= */
$catSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

// KPIs por estatus (respetando rango/otros filtros excepto estatus)
$stKpi = $conn->prepare("
  SELECT ms.estatus, COUNT(*) c
  FROM mantenimiento_solicitudes ms
  JOIN sucursales s ON s.id=ms.id_sucursal
  JOIN usuarios   u ON u.id=ms.id_usuario
  $where
  GROUP BY ms.estatus
");
$stKpi->bind_param($types, ...$params);
$stKpi->execute();
$resKpi = $stKpi->get_result();
$kpiMap = array_fill_keys($ESTADOS, 0);
while($row=$resKpi->fetch_assoc()){ $kpiMap[$row['estatus']] = (int)$row['c']; }
$stKpi->close();

// Tabla principal
$sqlTbl = "
 SELECT ms.*, s.nombre AS sucursal, u.nombre AS solicitante
 FROM mantenimiento_solicitudes ms
 JOIN sucursales s ON s.id=ms.id_sucursal
 JOIN usuarios   u ON u.id=ms.id_usuario
 $where
 ORDER BY ms.fecha_solicitud DESC
 LIMIT 500
";
$stTbl = $conn->prepare($sqlTbl);
$stTbl->bind_param($types, ...$params);
$stTbl->execute();
$resTbl = $stTbl->get_result();
$solicitudes = [];
$ids = [];
while($r = $resTbl->fetch_assoc()) { $solicitudes[] = $r; $ids[] = (int)$r['id']; }
$stTbl->close();

// Adjuntos por solicitud (única consulta)
$adjuntosBySolicitud = [];
if (count($ids)>0) {
  $in = implode(',', array_map('intval',$ids));
  $qr = $conn->query("SELECT id_solicitud, ruta_relativa, nombre_archivo, mime_type FROM mantenimiento_adjuntos WHERE id_solicitud IN ($in) ORDER BY id");
  while ($f = $qr->fetch_assoc()) {
    $sid = (int)$f['id_solicitud'];
    $adjuntosBySolicitud[$sid][] = $f;
  }
}
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Mantenimiento - Administración</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; margin:0; letter-spacing:.2px; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .chip{ display:inline-flex; align-items:center; gap:.45rem; padding:.35rem .7rem; border-radius:999px; border:1px solid rgba(0,0,0,.06); background:#fff; font-weight:600; font-size:.85rem; }
    .kpis .chip i{ font-size:1rem; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .thumb { width:38px; height:38px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; }
    .file-chip { font-size:.8rem; }
    .row-select .form-check-input{ transform:scale(1.1); }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-3">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🧰 Administración de Mantenimiento</h1>
      <div class="small-muted">Filtros rápidos, seguimiento en modal y cambios masivos de estatus.</div>
    </div>
    <div class="kpis d-flex flex-wrap gap-2">
      <?php foreach ($ESTADOS as $st): ?>
        <span class="chip"><i class="bi bi-circle-fill text-<?= badgeEstatus($st) ?>"></i> <?= h($st) ?>: <b><?= (int)$kpiMap[$st] ?></b></span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if($msg): ?><div class="alert alert-success card-surface mt-2"><?= h($msg) ?></div><?php endif; ?>

  <!-- Filtros -->
  <form class="card card-surface p-3 mt-2">
    <div class="row g-3 align-items-end">
      <div class="col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="fi" value="<?= h($fi) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="ff" value="<?= h($ff) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Estatus</label>
        <select name="estatus" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($ESTADOS as $st): ?>
            <option value="<?= h($st) ?>" <?= $estatusF===$st?'selected':'' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Prioridad</label>
        <select name="prioridad" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($PRIORIDAD as $p): ?>
            <option value="<?= h($p) ?>" <?= $prioridadF===$p?'selected':'' ?>><?= h($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Sucursal</label>
        <select name="sucursal" class="form-select">
          <option value="0">Todas</option>
          <?php foreach ($catSuc as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $sucursalF===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Buscar</label>
        <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Título, desc, sucursal…">
      </div>
      <div class="col-12 text-end">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
        <a href="mantenimiento_admin.php" class="btn btn-outline-secondary">Limpiar</a>
      </div>
    </div>
  </form>

  <!-- Masivo -->
  <div class="card card-surface p-3 mt-3">
    <form id="formMasivo" method="POST" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="action" value="cambiar_masivo">
      <div class="row-select d-flex align-items-center gap-2">
        <span class="small-muted">Seleccionados:</span>
        <span class="badge text-bg-secondary" id="badgeSel">0</span>
      </div>
      <select name="estatus_masivo" class="form-select form-select-sm" style="width:auto">
        <option value="">Estatus masivo…</option>
        <?php foreach ($ESTADOS as $st): ?>
          <option value="<?= h($st) ?>"><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" id="btnAplicar" disabled><i class="bi bi-check2-circle"></i> Aplicar</button>
      <div class="ms-auto d-flex align-items-center gap-2">
        <label class="form-check-label small-muted" for="checkAll">Marcar todos</label>
        <input class="form-check-input" type="checkbox" id="checkAll">
      </div>
    </form>
  </div>

  <!-- Tabla -->
  <div class="card card-surface mt-3">
    <div class="p-3 pt-3 tbl-wrap">
      <table class="table table-hover align-middle mb-0" id="tabla">
        <thead class="table-light">
          <tr>
            <th style="width:42px"></th>
            <th>ID</th>
            <th>Sucursal</th>
            <th>Solicitante</th>
            <th>Título</th>
            <th>Prioridad</th>
            <th>Estatus</th>
            <th>Fecha</th>
            <th>Evidencias</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($solicitudes as $r):
          $sid   = (int)$r['id'];
          $files = $adjuntosBySolicitud[$sid] ?? [];
          $shown = 0;
        ?>
          <tr data-row-id="<?= $sid ?>">
            <td><input type="checkbox" class="chk-row form-check-input" value="<?= $sid ?>"></td>
            <td><span class="badge text-bg-secondary">#<?= $sid ?></span></td>
            <td><?= h($r['sucursal']) ?></td>
            <td><?= h($r['solicitante']) ?></td>
            <td><?= h($r['titulo']) ?></td>
            <td>
              <span class="badge <?= $r['prioridad']=='Alta'?'text-bg-danger':($r['prioridad']=='Media'?'text-bg-warning text-dark':'text-bg-secondary') ?>">
                <?= h($r['prioridad']) ?>
              </span>
            </td>
            <td data-estatus-cell="<?= $sid ?>"><span class="badge text-bg-<?= badgeEstatus($r['estatus']) ?>"><?= h($r['estatus']) ?></span></td>
            <td><?= h(date('Y-m-d H:i', strtotime($r['fecha_solicitud']))) ?></td>
            <td style="min-width:160px">
              <?php if (count($files)===0): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <?php foreach ($files as $f):
                  if ($shown>=3) break;
                  $isImg = isset($f['mime_type']) && strpos($f['mime_type'],'image/')===0;
                  $url   = h($f['ruta_relativa']);
                  if ($isImg) { echo '<a target="_blank" href="'.$url.'"><img class="thumb me-1 mb-1" src="'.$url.'" alt="evidencia"></a>'; }
                  else { echo '<a class="btn btn-outline-secondary btn-sm file-chip me-1 mb-1" target="_blank" href="'.$url.'">PDF</a>'; }
                  $shown++;
                endforeach; ?>
                <?php if (count($files) > $shown): ?>
                  <span class="small-muted">+<?= (count($files)-$shown) ?> más</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="btn-group">
                <button type="button" class="btn btn-soft btn-sm verDetalle" data-id="<?= $sid ?>">
                  <i class="bi bi-search"></i> Detalle
                </button>
                <button class="btn btn-soft btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach ($ESTADOS as $st): ?>
                    <li>
                      <form method="POST" class="px-3 py-1">
                        <input type="hidden" name="action" value="cambiar">
                        <input type="hidden" name="id" value="<?= $sid ?>">
                        <input type="hidden" name="estatus" value="<?= h($st) ?>">
                        <button class="dropdown-item" type="submit"><?= h($st) ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" target="_blank" href="mantenimiento_admin.php?view=folio&id=<?= $sid ?>"><i class="bi bi-printer"></i> Imprimir folio</a></li>
                </ul>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: Detalle/Seguimiento -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-activity me-2"></i>Detalle de la solicitud</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="detalleBody">
        <div class="text-muted">Cargando…</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function(){
  const badgeSel = document.getElementById('badgeSel');
  const btnAplicar = document.getElementById('btnAplicar');
  const checkAll = document.getElementById('checkAll');
  const checks = Array.from(document.querySelectorAll('.chk-row'));
  const formMasivo = document.getElementById('formMasivo');

  function refreshCount(){
    const count = checks.filter(c=>c.checked && c.closest('tr').style.display!=='none').length;
    badgeSel.textContent = count;
    btnAplicar.disabled = count===0 || !formMasivo.elements['estatus_masivo'].value;
    const visibles = checks.filter(c=>c.closest('tr').style.display!=='none');
    const visChecked = visibles.filter(c=>c.checked).length;
    checkAll.indeterminate = visChecked>0 && visChecked<visibles.length;
    checkAll.checked = visChecked>0 && visChecked===visibles.length;
  }
  checks.forEach(c=>c.addEventListener('change', refreshCount));
  document.querySelector('select[name="estatus_masivo"]').addEventListener('change', refreshCount);
  checkAll.addEventListener('change', ()=>{
    const visibles = checks.filter(c=>c.closest('tr').style.display!=='none');
    visibles.forEach(c=>c.checked = checkAll.checked);
    refreshCount();
  });
  formMasivo.addEventListener('submit', (e)=>{
    const old = formMasivo.querySelectorAll('input[name="ids[]"]');
    old.forEach(n=>n.remove());
    checks.filter(c=>c.checked && c.closest('tr').style.display!=='none').forEach(c=>{
      const i = document.createElement('input');
      i.type='hidden'; i.name='ids[]'; i.value=c.value;
      formMasivo.appendChild(i);
    });
  });

  // Modal detalle (fetch)
  const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
  let currentDetailId = null;

  document.querySelectorAll('.verDetalle').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = btn.getAttribute('data-id');
      currentDetailId = id;
      const body = document.getElementById('detalleBody');
      body.innerHTML = '<div class="text-muted">Cargando…</div>';
      try{
        const res = await fetch(`mantenimiento_admin.php?view=detalle&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
        const html = await res.text();
        body.innerHTML = html;
      }catch(e){
        body.innerHTML = '<div class="text-danger">No se pudo cargar el detalle.</div>';
      }
      modal.show();
    });
  });

  // Delegación: guardar estatus dentro del modal sin recargar
  document.getElementById('modalDetalle').addEventListener('submit', async (e)=>{
    if (e.target && e.target.id === 'formStatus') {
      e.preventDefault();
      const fd = new FormData(e.target);
      const statusMsg = document.getElementById('statusMsg');
      try{
        const r = await fetch('mantenimiento_admin.php', { method:'POST', body: fd, credentials:'same-origin' });
        await r.text();
        statusMsg.textContent = 'Estatus actualizado.';
        const nuevo = fd.get('estatus');
        // Actualiza badge en la tabla
        const cell = document.querySelector(`[data-estatus-cell="${currentDetailId}"]`);
        if (cell) {
          cell.innerHTML = `<span class="badge text-bg-${badgeClass(nuevo)}">${escapeHtml(nuevo)}</span>`;
        }
      }catch(err){
        statusMsg.textContent = 'No se pudo actualizar.';
      }
    }
  });

  function badgeClass(st){
    switch(st){
      case 'Nueva': return 'secondary';
      case 'En revisión': return 'info';
      case 'Aprobada': return 'primary';
      case 'En proceso': return 'warning';
      case 'Resuelta': return 'success';
      case 'Rechazada': return 'danger';
      default: return 'secondary';
    }
  }
  function escapeHtml(s){return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
})();
</script>
</body>
</html>
