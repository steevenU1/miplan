<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

date_default_timezone_set('America/Mexico_City');
require 'db.php';

$hoy       = new DateTime('today');
$anioHoy   = (int)$hoy->format('Y');
$mesHoy    = (int)$hoy->format('n');
$diaHoy    = (int)$hoy->format('j');

$anio = isset($_GET['anio']) ? max(1970, (int)$_GET['anio']) : $anioHoy;
$mes  = isset($_GET['mes'])  ? min(12, max(1, (int)$_GET['mes'])) : $mesHoy;

$nombreMeses = [1=>"Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
$nombreMes = $nombreMeses[$mes];

function edadQueCumple(string $fnac, int $anio): int { return $anio - (int)date('Y', strtotime($fnac)); }
function aniosServicioQueCumple(string $fing, int $anio): int { return $anio - (int)date('Y', strtotime($fing)); }
function dia(string $f): int { return (int)date('j', strtotime($f)); }
function placeholder($nombre='Colaborador'){
  return 'https://ui-avatars.com/api/?name='.urlencode($nombre).'&background=E0F2FE&color=0F172A&bold=true';
}

/* Cumpleaños */
$cumples = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_nacimiento, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_nacimiento IS NOT NULL
          AND MONTH(e.fecha_nacimiento) = ?
        ORDER BY DAY(e.fecha_nacimiento), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while($row = $r->fetch_assoc()) $cumples[] = $row;
$stmt->close();

/* Aniversarios */
$anv = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_ingreso, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_ingreso IS NOT NULL
          AND MONTH(e.fecha_ingreso) = ?
        ORDER BY DAY(e.fecha_ingreso), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while($row = $r->fetch_assoc()) $anv[] = $row;
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Celebraciones · <?= htmlspecialchars($nombreMes)." ".$anio ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#f7f8fb; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --pri:#2563eb;
    --today:#eaf3ff; --today-border:#c7dbff; --badge:#e9efff;
  }
  body{background:var(--bg); color:var(--ink)}
  /* Contenedor más estrecho en desktop para que no “se desparrame” */
  .container{ max-width: 1040px; }

  /* ===== Header / filtros ===== */
  .page-title{ font-weight:800; letter-spacing:.2px; margin:0; font-size: clamp(1.4rem, 2.1vw + .3rem, 2.1rem); }
  .subtle{ color:var(--muted); }
  .filter-card{ background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 6px 16px rgba(16,24,40,.06); }
  .filter-card .form-select, .filter-card .form-control{ height:42px; }
  .btn-ghost{ background:#fff; border:1px solid var(--line); border-radius:10px; padding:8px 12px; }
  .btn-ghost:hover{ background:#f1f5f9; }

  /* ===== Cards ===== */
  .grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px; }
  .card-p{ background:#fff; border:1px solid var(--line); border-radius:18px; padding:12px; transition:transform .06s ease, box-shadow .1s ease; }
  .card-p:hover{ transform:translateY(-1px); box-shadow:0 8px 22px rgba(16,24,40,.08); }
  .top{ display:flex; gap:12px; align-items:center; }
  .avatar{ width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 1px 2px rgba(0,0,0,.06) }
  .name{ font-weight:800; line-height:1.15; }
  .sub{ color:var(--muted); font-size:13px; }
  .badge-day{ margin-left:auto; background:var(--badge); border:1px solid #c7d2fe; border-radius:999px; padding:4px 9px; font-weight:800; font-size:12px; min-width:34px; text-align:center; }

  /* ===== Estilo especial para “Hoy” ===== */
  .card-p.today{
    background: linear-gradient(180deg,#f3f8ff 0%, #eaf2ff 100%);
    border-color: var(--today-border);
    position: relative;
  }
  .card-p.today::before{ /* cintilla celebratoria */
    content: "HOY";
    position:absolute; top:-10px; left:12px;
    background:#22c55e; color:#083d12; font-weight:900; font-size:.72rem;
    padding:3px 8px; border-radius:999px; box-shadow:0 2px 6px rgba(0,0,0,.08);
  }
  .card-p.today .avatar{
    box-shadow: 0 0 0 3px #bae6fd, 0 0 0 6px #e0f2fe;
  }
  .card-p.today .badge-day{
    background:#1d4ed8; color:#fff; border-color:#1d4ed8;
  }
  .spark{ background:#fff3; border:1px dashed #90cdf4; }

  /* ===== Navbar móvil más legible (mismos overrides que la otra vista) ===== */
  @media (max-width:576px){
    .navbar{ --bs-navbar-padding-y:.65rem; font-size:1rem; }
    .navbar .navbar-brand{ font-size:1.125rem; font-weight:700; }
    .navbar .nav-link, .navbar .dropdown-item{ font-size:1rem; padding:.55rem .75rem; }
    .navbar .navbar-toggler{ padding:.45rem .6rem; font-size:1.1rem; border-width:2px; }
    .navbar .bi{ font-size:1.1rem; }
    .container{ padding-left:12px; padding-right:12px; }
    .grid{ gap:10px; }
    .avatar{ width:56px; height:56px; }
    .badge-day{ font-size:.8rem; padding:4px 8px; }
  }

  /* ===== Desktop cómodo ===== */
  @media (min-width:992px){
    .filter-card{ min-width: 280px; }
  }
</style>
</head>
<body>

<?php if (file_exists('navbar.php')) include 'navbar.php'; ?>

<div class="container py-3">
  <!-- Header + Filtro -->
  <div class="row g-3 align-items-end mb-2">
    <div class="col-lg">
      <h1 class="page-title">Cumpleaños & Aniversarios</h1>
      <div class="subtle">Mes: <strong><?= htmlspecialchars($nombreMes) ?></strong> · Año: <strong><?= (int)$anio ?></strong></div>
    </div>
    <div class="col-lg-4">
      <form class="filter-card p-3" method="get" action="">
        <div class="row g-2 align-items-center">
          <div class="col-7">
            <label class="form-label mb-1 small subtle">Mes</label>
            <select class="form-select" name="mes" aria-label="Mes">
              <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= $nombreMeses[$m] ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label mb-1 small subtle">Año</label>
            <input type="number" class="form-control" name="anio" value="<?= (int)$anio ?>" min="1970" max="<?= (int)date('Y')+1 ?>" aria-label="Año">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-ghost"><i class="bi bi-funnel me-1"></i>Ver</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Cumpleaños -->
  <h5 class="mt-3 mb-2 fw-bold"><span class="me-2">🎂</span>Cumpleaños de <?= htmlspecialchars($nombreMes) ?></h5>
  <?php if (empty($cumples)): ?>
    <div class="alert alert-light border">No hay cumpleaños este mes.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($cumples as $p):
      $day  = dia($p['fecha_nacimiento']);
      $edad = edadQueCumple($p['fecha_nacimiento'], $anio);
      $foto = $p['foto'] ?: placeholder($p['nombre']);
      $esHoy = ($mes==$mesHoy && $day==$diaHoy);
    ?>
      <div class="card-p <?= $esHoy?'today':'' ?>">
        <div class="top">
          <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto">
          <div>
            <div class="name">
              <?= htmlspecialchars($p['nombre']) ?>
            </div>
            <div class="sub">
              <?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> ·
              Cumple <?= (int)$edad ?> años
              <?php if ($esHoy): ?><span class="ms-2 badge bg-warning text-dark">🎉 ¡Felicidades!</span><?php endif; ?>
            </div>
            <?php if(!empty($p['sucursal'])): ?>
              <div class="sub"><i class="bi bi-building me-1"></i><?= htmlspecialchars($p['sucursal']) ?></div>
            <?php endif; ?>
          </div>
          <div class="badge-day"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Aniversarios -->
  <h5 class="mt-4 mb-2 fw-bold"><span class="me-2">🏅</span>Aniversarios de <?= htmlspecialchars($nombreMes) ?></h5>
  <?php if (empty($anv)): ?>
    <div class="alert alert-light border">No hay aniversarios este mes.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($anv as $p):
      $day  = dia($p['fecha_ingreso']);
      $ann  = aniosServicioQueCumple($p['fecha_ingreso'], $anio);
      $foto = $p['foto'] ?: placeholder($p['nombre']);
      $esHoy = ($mes==$mesHoy && $day==$diaHoy);
    ?>
      <div class="card-p <?= $esHoy?'today':'' ?>">
        <div class="top">
          <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto">
          <div>
            <div class="name"><?= htmlspecialchars($p['nombre']) ?></div>
            <div class="sub">
              <?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> ·
              <?= (int)$ann ?> año(s) en la empresa
              <?php if ($esHoy): ?><span class="ms-2 badge bg-warning text-dark">🎉 ¡Hoy!</span><?php endif; ?>
            </div>
            <?php if(!empty($p['sucursal'])): ?>
              <div class="sub"><i class="bi bi-building me-1"></i><?= htmlspecialchars($p['sucursal']) ?></div>
            <?php endif; ?>
          </div>
          <div class="badge-day"><?= str_pad((string)$day,2,'0',STR_PAD_LEFT) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
