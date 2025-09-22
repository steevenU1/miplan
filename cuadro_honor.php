<?php
// cuadro_honor.php — LUGA (podium + export PNG) — header incluido en la imagen

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ========= Helpers ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function iniciales($nombreCompleto){
  $p = preg_split('/\s+/', trim((string)$nombreCompleto));
  $ini = '';
  foreach ($p as $w) { if ($w !== '') { $ini .= mb_substr($w, 0, 1, 'UTF-8'); } if (mb_strlen($ini,'UTF-8')>=2) break; }
  return mb_strtoupper($ini ?: 'U', 'UTF-8');
}
function nombreMes($m){
  static $meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
  return $meses[(int)$m] ?? '';
}

/* ========= Rango de fechas ========= */
function rangoSemanaMarLun(int $offset = 0): array {
  $hoy = new DateTime('today'); $n = (int)$hoy->format('N'); $dif = $n - 2; if ($dif < 0) $dif += 7;
  $ini = (clone $hoy)->modify("-$dif days"); if ($offset !== 0) $ini->modify(($offset*7).' days');
  $fin = (clone $ini)->modify('+7 days'); $ini->setTime(0,0,0); $fin->setTime(0,0,0); return [$ini,$fin];
}
function rangoMesActual(?int $y=null, ?int $m=null): array {
  $base=new DateTime('today'); $year=$y??(int)$base->format('Y'); $mon=$m??(int)$base->format('n');
  $ini=(new DateTime())->setDate($year,$mon,1)->setTime(0,0,0); $fin=(clone $ini)->modify('+1 month'); return [$ini,$fin];
}

/* ========= Utilidades de ruta / fotos ========= */
function appBaseWebAbs(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $base   = rtrim(str_replace(basename($script), '', $script), '/'); // p.ej. /luga_php
  return $scheme.'://'.$host.$base.'/';
}
function fotoUsuarioUrl(mysqli $conn, int $idUsuario): ?string {
  $stmt = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id = ? LIMIT 1");
  $stmt->bind_param("i", $idUsuario);
  $stmt->execute();
  $stmt->bind_result($foto);
  $value = null;
  if ($stmt->fetch() && $foto) $value = trim($foto);
  $stmt->close();
  if (!$value) return null;

  if (preg_match('~^https?://~i', $value)) return $value;

  $baseWeb = appBaseWebAbs();

  if (strpos($value, '/') !== false) {
    $abs = __DIR__ . '/' . ltrim($value, '/');
    if (is_file($abs)) {
      $ts = @filemtime($abs);
      return $baseWeb . ltrim($value, '/') . ($ts ? ('?t='.$ts) : '');
    }
    return $baseWeb . ltrim($value, '/');
  }

  $candidatos = [
    'uploads/fotos_usuarios/','uploads/expediente/','uploads/expediente/fotos/','uploads/usuarios/','uploads/',
  ];
  $enc = rawurlencode($value);
  foreach ($candidatos as $rel) {
    $abs = __DIR__ . '/' . $rel . $value;
    if (is_file($abs)) {
      $ts = @filemtime($abs);
      return $baseWeb . $rel . $enc . ($ts ? ('?t='.$ts) : '');
    }
  }
  return $baseWeb . 'uploads/fotos_usuarios/' . $enc;
}

/* ========= Parámetros UI ========= */
$tab = $_GET['tab'] ?? 'semana';  // semana | mes
$w   = (int)($_GET['w'] ?? 0);
$yy  = isset($_GET['yy']) ? (int)$_GET['yy'] : null;
$mm  = isset($_GET['mm']) ? (int)$_GET['mm'] : null;

if ($tab === 'mes'){
  [$ini,$fin] = rangoMesActual($yy,$mm);
  $tituloRango = nombreMes((int)$ini->format('n')).' '.$ini->format('Y');
} else {
  [$ini,$fin] = rangoSemanaMarLun($w);
  $tituloRango = 'Del '.$ini->format('d/m/Y').' al '.(clone $fin)->modify('-1 day')->format('d/m/Y');
}
$iniStr = $ini->format('Y-m-d H:i:s');
$finStr = $fin->format('Y-m-d H:i:s');

/* ========= Consultas ========= */
$ejecutivos = [];
$sucursales = [];

try {

  /* ====== Top 3 Ejecutivos (unidades) – misma regla que el dash ======
     - unidades por venta:
         * si tipo_venta = 'Financiamiento+Combo' => 2
         * en otro caso => #de detalles que NO sean modem/mifi (tipo_producto)
     - modem/mifi NO suman unidades
  */
  $sqlTopEjecutivos = "
    SELECT
      u.id              AS id_usuario,
      u.nombre          AS nombre_usuario,
      s.nombre          AS sucursal,
      SUM(va.unidades)  AS unidades
    FROM (
      SELECT
        v.id,
        v.id_usuario,
        CASE
          WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 2
          ELSE COALESCE(SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END),0)
        END AS unidades
      FROM ventas v
      LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
      LEFT JOIN productos     p  ON p.id       = dv.id_producto
      WHERE v.fecha_venta >= ? AND v.fecha_venta < ?
      GROUP BY v.id
    ) va
    JOIN usuarios u   ON u.id = va.id_usuario
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    GROUP BY u.id, u.nombre, s.nombre
    HAVING unidades > 0
    ORDER BY unidades DESC, nombre_usuario ASC
    LIMIT 3
  ";
  $stmt = $conn->prepare($sqlTopEjecutivos);
  $stmt->bind_param("ss", $iniStr, $finStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row = $res->fetch_assoc()){
    $row['foto_url'] = fotoUsuarioUrl($conn,(int)$row['id_usuario']);
    $ejecutivos[] = $row;
  }
  $stmt->close();

  /* ====== Top 3 Sucursales (monto) – misma regla que el dash ======
     - monto por venta = v.precio_venta (cabecera)
     - PERO solo si la venta tiene al menos 1 producto NO modem/mifi
       (si la venta es 100% modem/mifi => 0)
  */
  $sqlTopSucursales = "
    SELECT
      s.id                 AS id_sucursal,
      s.nombre             AS sucursal,
      ger.id               AS id_gerente,
      ger.nombre           AS gerente,
      SUM(va.monto)        AS monto
    FROM (
      SELECT
        v.id,
        v.id_usuario,
        /* ¿La venta tiene algún no-modem? */
        CASE WHEN COALESCE(SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END),0) > 0
             THEN COALESCE(MAX(v.precio_venta),0)
             ELSE 0
        END AS monto
      FROM ventas v
      LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
      LEFT JOIN productos     p  ON p.id       = dv.id_producto
      WHERE v.fecha_venta >= ? AND v.fecha_venta < ?
      GROUP BY v.id
    ) va
    JOIN usuarios   u ON u.id = va.id_usuario
    JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN (
      SELECT id_sucursal, MIN(id) AS id_gerente
      FROM usuarios
      WHERE rol = 'Gerente'
      GROUP BY id_sucursal
    ) pick ON pick.id_sucursal = s.id
    LEFT JOIN usuarios ger ON ger.id = pick.id_gerente
    GROUP BY s.id, s.nombre, ger.id, ger.nombre
    HAVING monto > 0
    ORDER BY monto DESC, sucursal ASC
    LIMIT 3
  ";
  $stmt = $conn->prepare($sqlTopSucursales);
  $stmt->bind_param("ss", $iniStr, $finStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row = $res->fetch_assoc()){
    $row['foto_url'] = !empty($row['id_gerente']) ? fotoUsuarioUrl($conn,(int)$row['id_gerente']) : null;
    $sucursales[] = $row;
  }
  $stmt->close();

} catch (Throwable $e) {
  echo '<div style="max-width:900px;margin:20px auto" class="alert alert-danger"><b>Error al generar el Cuadro de Honor:</b><br>'.h($e->getMessage()).'</div>';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cuadro de Honor | Luga</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f7fb; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
      --card:#ffffff; --shadow:0 12px 40px rgba(16,24,40,.10);
      --gold:#f59e0b; --gold-soft:#fff7e6;
      --silver:#94a3b8; --silver-soft:#f3f4f6;
      --bronze:#b45309; --bronze-soft:#fff0e6;
    }
    body{ background:var(--bg); color:var(--ink); }
    .container{ max-width: 1040px; }

    @media (max-width:576px){
      .navbar{ --bs-navbar-padding-y:.65rem; font-size:1rem; }
      .navbar .navbar-brand{ font-size:1.125rem; font-weight:700; }
      .navbar .nav-link, .navbar .dropdown-item{ font-size:1rem; padding:.55rem .75rem; }
      .navbar .navbar-toggler{ padding:.45rem .6rem; font-size:1.1rem; border-width:2px; }
      .container{ padding-left:12px; padding-right:12px; }
    }

    .hero{ display:flex; align-items:end; gap:14px; flex-wrap:wrap; margin:20px 0 10px; }
    .hero h1{ font-weight:800; letter-spacing:.2px; margin:0; font-size: clamp(1.3rem, 2.2vw + .2rem, 2rem); }
    .subtle{ color:var(--muted); }
    .period-chip{ display:inline-flex; align-items:center; gap:.45rem; background:#eef2ff; color:#1d4ed8; border:1px solid #c7d2fe; border-radius:999px; padding:.25rem .6rem; font-weight:700; }

    .filter-card{ background:var(--card); border:1px solid var(--line); border-radius:16px; box-shadow:var(--shadow); padding:10px 12px; }
    .filter-card .form-select, .filter-card .form-control{ height:42px; }
    .tabs-pills .nav-link{ border-radius:999px; padding:.4rem .9rem; font-weight:700; }
    .tabs-pills .nav-link.active{ background:#1d4ed8; color:#fff; }

    .podium{ display:grid; grid-template-columns: repeat(1, 1fr); gap:12px; }
    @media (min-width:768px){
      .podium{ grid-template-columns: repeat(3, 1fr); align-items:stretch; }
      .podium .rank-1{ order: 2; transform: translateY(-4px); }
      .podium .rank-2{ order: 1; }
      .podium .rank-3{ order: 3; }
    }

    .card-portrait{
      height:100%;
      background: var(--card);
      border:1px solid var(--line);
      border-radius:18px;
      box-shadow:var(--shadow);
      padding:16px;
      display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px;
      transition: transform .08s ease, box-shadow .12s ease;
    }
    .card-portrait:hover{ transform: translateY(-2px); box-shadow: 0 16px 50px rgba(16,24,40,.14); }

    .rank-1 .card-portrait{ background: linear-gradient(180deg,#fff 0%, #fffaf0 100%); border-color:#fde68a; }
    .rank-2 .card-portrait{ background: linear-gradient(180deg,#fff 0%, #f7f7f9 100%); }
    .rank-3 .card-portrait{ background: linear-gradient(180deg,#fff 0%, #fff6ee 100%); }

    .avatar-xl{ width:110px; height:110px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 1px 2px rgba(0,0,0,.06); background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:34px; color:#475569; }
    .rank-1 .avatar-xl{ width:128px; height:128px; box-shadow: 0 0 0 4px #fff, 0 0 0 7px rgba(245,158,11,.35), 0 8px 22px rgba(245,158,11,.25); }

    .rank-badge{
      display:inline-flex; align-items:center; gap:.4rem; font-weight:900;
      font-size:.85rem; padding:4px 10px; border-radius:999px; border:1px solid transparent;
      background:#eef2ff; color:#1d4ed8;
    }
    .rank-1 .rank-badge{ background:var(--gold-soft); color:#92400e; border-color:#fcd34d; }
    .rank-2 .rank-badge{ background:var(--silver-soft); color:#334155; border-color:#cbd5e1; }
    .rank-3 .rank-badge{ background:var(--bronze-soft); color:#7c2d12; border-color:#f59e0b; }

    .name-big{ font-size:1.12rem; font-weight:900; line-height:1.2; }
    .branch{ color:#64748b; font-size:.95rem; }

    .metric-wrap{ display:flex; flex-direction:column; align-items:center; gap:2px; margin-top:4px; }
    .metric{ font-size:1.9rem; font-weight:900; letter-spacing:.3px; }
    .rank-1 .metric{ font-size:2.2rem; }
    .metric-label{ color:#64748b; font-size:.85rem; }
    .divider{ width:100%; height:1px; background:#eef2f7; margin:6px 0 2px; }
  </style>
</head>
<body>

<?php if (file_exists(__DIR__.'/navbar.php')) include __DIR__.'/navbar.php'; ?>

<div class="container py-3">

  <!-- ====== ÁREA QUE SE EXPORTA (incluye header) ====== -->
  <section id="honorExport">

    <!-- Header con título + chip (se exporta). Controles = .no-export -->
    <div class="hero" id="honorHeader">
      <div>
        <h1>🏅 Cuadro de Honor</h1>
        <div class="subtle">Periodo <span class="period-chip"><i class="bi bi-calendar3"></i> <?=h($tituloRango)?></span></div>
      </div>

      <div class="ms-auto d-flex flex-wrap gap-2 align-items-end no-export">
        <form class="filter-card" method="get" action="">
          <ul class="nav tabs-pills mb-2">
            <li class="nav-item"><a class="nav-link <?=($tab==='semana'?'active':'')?>" href="?tab=semana&w=<?= $w ?>">Semanal</a></li>
            <li class="nav-item"><a class="nav-link <?=($tab==='mes'?'active':'')?>" href="?tab=mes&yy=<?= $yy ?? $ini->format('Y') ?>&mm=<?= $mm ?? $ini->format('n') ?>">Mensual</a></li>
          </ul>
          <?php
            $base=new DateTime('today');
            $mAct = (int)($mm ?? $base->format('n'));
            $yAct = (int)($yy ?? $base->format('Y'));
            $prev=(clone (new DateTime()))->setDate($yAct,$mAct,1)->modify('-1 month');
            $next=(clone (new DateTime()))->setDate($yAct,$mAct,1)->modify('+1 month');
          ?>
          <div class="d-flex gap-2">
            <?php if ($tab==='semana'): ?>
              <a class="btn btn-outline-secondary w-50" href="?tab=semana&w=<?=($w-1)?>"><i class="bi bi-arrow-left"></i> Semana</a>
              <a class="btn btn-outline-secondary w-50" href="?tab=semana&w=<?=($w+1)?>">Semana <i class="bi bi-arrow-right"></i></a>
            <?php else: ?>
              <a class="btn btn-outline-primary w-50" href="?tab=mes&yy=<?=$prev->format('Y')?>&mm=<?=$prev->format('n')?>"><i class="bi bi-arrow-left"></i> Mes</a>
              <a class="btn btn-outline-primary w-50" href="?tab=mes&yy=<?=$next->format('Y')?>&mm=<?=$next->format('n')?>">Mes <i class="bi bi-arrow-right"></i></a>
            <?php endif; ?>
          </div>
        </form>

        <button type="button" id="btnExportAll" class="btn btn-success">
          <i class="bi bi-image"></i> Descargar imagen
        </button>
      </div>
    </div>

    <!-- Top 3 Ejecutivos -->
    <h2 class="h6 fw-bold mt-2 mb-2">Top 3 Ejecutivos</h2>
    <?php if(empty($ejecutivos)): ?>
      <div class="alert alert-light border">Sin ventas en este periodo.</div>
    <?php else: ?>
    <div class="podium mb-3" id="podioEjecutivos">
      <?php foreach($ejecutivos as $i=>$e): $rank=$i+1; ?>
        <div class="rank-<?= $rank ?>">
          <div class="card-portrait">
            <div class="rank-badge">
              <?php if($rank===1): ?>🥇<?php elseif($rank===2): ?>🥈<?php else: ?>🥉<?php endif; ?>
              #<?= $rank ?> Ejecutivo
            </div>

            <?php if(!empty($e['foto_url'])): ?>
              <img src="<?=h($e['foto_url'])?>" class="avatar-xl" alt="Foto"
                   loading="lazy" crossorigin="anonymous" referrerpolicy="no-referrer">
            <?php else: ?>
              <div class="avatar-xl"><?=h(iniciales($e['nombre_usuario']))?></div>
            <?php endif; ?>

            <div class="name-big"><?=h($e['nombre_usuario'])?></div>
            <div class="branch"><i class="bi bi-building me-1"></i><?=h($e['sucursal'] ?? '—')?></div>

            <div class="divider"></div>
            <div class="metric-wrap">
              <div class="metric"><?= (int)$e['unidades'] ?></div>
              <div class="metric-label">unidades</div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top 3 Sucursales -->
    <h2 class="h6 fw-bold mt-4 mb-2">Top 3 Sucursales</h2>
    <?php if(empty($sucursales)): ?>
      <div class="alert alert-light border">Sin ventas en este periodo.</div>
    <?php else: ?>
    <div class="podium" id="podioSucursales">
      <?php foreach($sucursales as $i=>$s): $rank=$i+1; ?>
        <div class="rank-<?= $rank ?>">
          <div class="card-portrait">
            <div class="rank-badge">
              <?php if($rank===1): ?>🏆<?php elseif($rank===2): ?>🥈<?php else: ?>🥉<?php endif; ?>
              #<?= $rank ?> Sucursal
            </div>

            <?php if(!empty($s['foto_url'])): ?>
              <img src="<?=h($s['foto_url'])?>" class="avatar-xl" alt="Foto"
                   loading="lazy" crossorigin="anonymous" referrerpolicy="no-referrer">
            <?php else: ?>
              <div class="avatar-xl"><?=h(iniciales($s['gerente'] ?? ''))?></div>
            <?php endif; ?>

            <div class="name-big"><?=h($s['sucursal'])?></div>
            <div class="branch"><i class="bi bi-person-vcard me-1"></i>Gerente: <?=h($s['gerente'] ?? '—')?></div>

            <div class="divider"></div>
            <div class="metric-wrap">
              <div class="metric">$<?= number_format((float)$s['monto'], 2) ?></div>
              <div class="metric-label">monto</div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </section>
  <!-- ====== /ÁREA QUE SE EXPORTA ====== -->

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- Loader robusto: local -> jsDelivr -> unpkg -->
<script>
(function(){
  const $ = (sel) => document.querySelector(sel);
  const exportArea = $('#honorExport');
  const btnExport  = $('#btnExportAll');

  function loadScript(src){
    return new Promise((resolve, reject)=>{
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Fallo al cargar: ' + src));
      document.head.appendChild(s);
    });
  }
  async function ensureHtmlToImage(){
    if (window.htmlToImage) return true;
    try { await loadScript('/assets/html-to-image.min.js'); } catch(e){}
    if (window.htmlToImage) return true;
    try { await loadScript('https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.min.js'); } catch(e){}
    if (window.htmlToImage) return true;
    try { await loadScript('https://unpkg.com/html-to-image@1.11.11/dist/html-to-image.min.js'); } catch(e){}
    return !!window.htmlToImage;
  }

  btnExport?.addEventListener('click', async () => {
    try{
      const ok = await ensureHtmlToImage();
      if (!ok) throw new Error('No se pudo cargar la librería (revisa tu CSP o coloca /assets/html-to-image.min.js).');
      await exportNode(exportArea, 'cuadro_honor');
    }catch(err){
      alert('No se pudo exportar la imagen: ' + err.message);
      console.error(err);
    }
  });

  async function exportNode(node, baseName){
    if (!node) return;

    // Oculta elementos marcados como no exportables DENTRO del área
    const hidden = [];
    node.querySelectorAll('.no-export').forEach(el=>{
      hidden.push([el, el.style.display]);
      el.style.display = 'none';
    });

    const opts = {
      backgroundColor: '#ffffff',
      pixelRatio: Math.max(2, Math.min(3, window.devicePixelRatio || 2)),
      cacheBust: true,
      filter: (el) => !(el.classList && el.classList.contains('no-export'))
    };

    try{
      const blob = await window.htmlToImage.toBlob(node, opts);
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      const ymd  = new Date().toISOString().slice(0,10);
      a.href = url;
      a.download = `${baseName || 'export'}_${ymd}.png`;
      a.click();
      setTimeout(()=>URL.revokeObjectURL(url), 1500);
    } finally {
      hidden.forEach(([el, ds]) => el.style.display = ds);
    }
  }
})();
</script>
</body>
</html>
