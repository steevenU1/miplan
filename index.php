<?php
session_start();
include 'db.php';

/* =================== Brand Config (Mi Plan) =================== */
const BRAND_NAME      = 'Mi Plan';
const BRAND_SUFFIX    = '2.0';
const BRAND_COLOR     = '#00a37a'; // Ajustable
const BRAND_COLOR_600 = '#008a68'; // Hover/darken
const BRAND_FAVICON   = './img/favicon.ico'; // Cambia si tienes uno de Mi Plan
const BRAND_LOGO_URL  = 'https://i.ibb.co/kgsv30Dh/Dise-o-sin-t-tulo.png'; // Tu logo

/* ======= Switch temporal de construcción ======= */
/* Ponlo en false cuando quieras ocultarlo */
const SHOW_CONSTRUCTION = true;

$mensaje      = '';
$showWelcome  = false;
$fotoUsuario  = '';
$fotoUrl      = '';
$nombreSesion = '';
$saludo       = '';
$alertPend    = []; // datos para el modal de alertas (GZ)

/* ================= Helpers ================= */

// Escapado corto
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Iniciales (fallback)
function iniciales($nombreCompleto) {
  $p = preg_split('/\s+/', trim((string)$nombreCompleto));
  $ini = '';
  foreach ($p as $w) {
    if ($w !== '') { $ini .= mb_substr($w, 0, 1, 'UTF-8'); }
    if (mb_strlen($ini, 'UTF-8') >= 2) break;
  }
  return mb_strtoupper($ini, 'UTF-8') ?: 'U';
}

// Base web absoluta de la app (soporta subcarpeta)
function appBaseWebAbs() {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/');
  $base   = ($base === '') ? '/' : $base . '/';
  return $scheme . '://' . $host . $base;
}

// Normaliza ruta de foto desde BD a URL absoluta servible
function normalizarFoto($rawPath) {
  $rawPath = trim((string)$rawPath);
  if ($rawPath === '') return '';
  if (preg_match('#^https?://#i', $rawPath)) return $rawPath;

  $path   = str_replace('\\', '/', $rawPath);
  $baseAbs = appBaseWebAbs();

  $candidatos = [
    $path,
    "uploads/$path",
    "uploads/usuarios/$path",
    "uploads/fotos_usuarios/$path",
    "documentos/$path",
    "expediente/$path",
  ];
  foreach ($candidatos as $rel) {
    $abs = __DIR__ . '/' . $rel;
    if (file_exists($abs)) {
      $v = @filemtime($abs) ?: time();
      return $baseAbs . ltrim($rel, '/') . '?v=' . $v;
    }
  }
  return $baseAbs . ltrim($path, '/');
}

// ==== helpers de columnas/metadata de tablas ====
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}

// Detecta la columna fecha usable en traspasos (para DATEDIFF)
function traspasosFechaExpr(mysqli $conn): ?string {
  if (hasColumn($conn,'traspasos','fecha_traspaso')) return 't.fecha_traspaso';
  if (hasColumn($conn,'traspasos','fecha_creacion')) return 't.fecha_creacion';
  return null; // no hay columna de fecha
}

// Obtiene la foto del usuario desde usuarios_expediente
function detectarColumnaExpediente($conn) {
  $candidatas = ['id_usuario','usuario_id','user_id','idUser','id_empleado','id_usuario_fk'];
  $cols = [];
  if ($res = $conn->query("SHOW COLUMNS FROM usuarios_expediente")) {
    while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
    $res->close();
  }
  foreach ($candidatas as $c) if (in_array($c, $cols, true)) return $c;
  return null;
}
function obtenerFotoUsuario($conn, $idUsuario) {
  $col = detectarColumnaExpediente($conn);
  if ($col) {
    $sql = "SELECT foto FROM usuarios_expediente WHERE $col = ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $stmt->bind_result($foto);
    if ($stmt->fetch()) { $stmt->close(); return trim((string)$foto); }
    $stmt->close();
  } else {
    $sql = "SELECT foto FROM usuarios_expediente WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idUsuario);
    if ($stmt->execute()) {
      $stmt->bind_result($foto2);
      if ($stmt->fetch()) { $stmt->close(); return trim((string)$foto2); }
    }
    $stmt->close();
  }
  return '';
}

// Obtener zona del usuario (según su id_sucursal)
function obtenerZonaUsuario(mysqli $conn, int $idUsuario): ?string {
  $sql = "SELECT s.zona
          FROM usuarios u
          INNER JOIN sucursales s ON s.id = u.id_sucursal
          WHERE u.id=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $zona = $st->get_result()->fetch_assoc()['zona'] ?? null;
  $st->close();
  return $zona ?: null;
}

// ID de Eulalia (para excluirla)
function obtenerIdEulalia(mysqli $conn): int {
  $id = 0;
  if ($st = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")) {
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $id = (int)($row['id'] ?? 0);
    $st->close();
  }
  return $id;
}

/* ===== Cuenta regresiva de lanzamiento (fecha ejemplo) ===== */
$tz = new DateTimeZone('America/Mexico_City');
$launchDT   = new DateTime('2025-08-26 09:00', $tz); // FECHA FIJA (ajústala si aplica)
$LAUNCH_TS  = $launchDT->getTimestamp();
$showBanner = (time() < $LAUNCH_TS);
$launchHuman = $launchDT->format('d/m/Y H:i') . ' CDMX';

/* ============== Lógica de login ============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usuario  = $_POST['usuario'] ?? '';
  $password = $_POST['password'] ?? '';

  $sql  = "SELECT id, nombre, id_sucursal, rol, password, activo, must_change_password 
           FROM usuarios WHERE usuario = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $usuario);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($row = $result->fetch_assoc()) {
    if ((int)$row['activo'] !== 1) {
      $mensaje = "⚠️ Tu cuenta ha sido dada de baja.";
    } else {
      $hashInfo = password_get_info($row['password']);
      $ok = !empty($hashInfo['algo']) ? password_verify($password, $row['password'])
                                      : hash_equals($row['password'], $password);

      if ($ok) {
        session_regenerate_id(true);
        $_SESSION['id_usuario']  = (int)$row['id'];
        $_SESSION['nombre']      = $row['nombre'];
        $_SESSION['id_sucursal'] = (int)$row['id_sucursal'];
        $_SESSION['rol']         = $row['rol'];
        $_SESSION['must_change_password'] = (int)$row['must_change_password'] === 1;

        if (!empty($_SESSION['must_change_password'])) {
          header("Location: cambiar_password.php?force=1");
          exit();
        } else {
          $fotoUsuario = obtenerFotoUsuario($conn, $_SESSION['id_usuario']);
          $fotoUrl     = normalizarFoto($fotoUsuario);

          $dt = new DateTime('now', new DateTimeZone('America/Mexico_City'));
          $h  = (int)$dt->format('G');
          if     ($h < 12) $saludo = "Buenos días";
          elseif ($h < 19) $saludo = "Buenas tardes";
          else             $saludo = "Buenas noches";

          $nombreSesion = $_SESSION['nombre'];

          // ====== ALERTA PARA GERENTEZONA: traspasos pendientes de >= 3 días ======
          if (($_SESSION['rol'] ?? '') === 'GerenteZona') {
            $zona      = obtenerZonaUsuario($conn, $_SESSION['id_usuario']);
            $idEulalia = obtenerIdEulalia($conn);
            $fechaCol  = traspasosFechaExpr($conn); // 't.fecha_traspaso' | 't.fecha_creacion' | null

            if ($zona && $fechaCol) {
              $sqlA = "
                SELECT
                  sd.id    AS id_destino,
                  sd.nombre AS sucursal_destino,
                  COUNT(DISTINCT t.id) AS num_traspasos,
                  MIN($fechaCol) AS fecha_mas_antigua,
                  DATEDIFF(NOW(), MIN($fechaCol)) AS dias_antig
                FROM traspasos t
                INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
                WHERE t.estatus='Pendiente'
                  AND sd.zona = ?
                  ".($idEulalia>0 ? " AND sd.id <> ? " : "")."
                  AND DATEDIFF(NOW(), $fechaCol) >= 3
                GROUP BY sd.id, sd.nombre
                ORDER BY fecha_mas_antigua ASC
              ";
              if ($idEulalia>0) {
                $stA = $conn->prepare($sqlA);
                $stA->bind_param("si", $zona, $idEulalia);
              } else {
                $stA = $conn->prepare($sqlA);
                $stA->bind_param("s", $zona);
              }
              $stA->execute();
              $resA = $stA->get_result();

              $rows = [];
              $totalTr = 0;
              while ($r = $resA->fetch_assoc()) {
                $rows[] = [
                  'id'      => (int)$r['id_destino'],
                  'nombre'  => (string)$r['sucursal_destino'],
                  'trasps'  => (int)$r['num_traspasos'],
                  'dias'    => (int)$r['dias_antig'],
                  'fecha'   => $r['fecha_mas_antigua'],
                ];
                $totalTr += (int)$r['num_traspasos'];
              }
              $stA->close();

              if (!empty($rows)) {
                $alertPend = [
                  'total_suc' => count($rows),
                  'total_tr'  => $totalTr,
                  'rows'      => $rows
                ];
              }
            }
          }

          // Mostrar bienvenida si NO hay alertas; si hay alertas, mostramos el modal invasivo
          $showWelcome = empty($alertPend);
        }
      } else {
        $mensaje = "❌ Contraseña incorrecta";
      }
    }
  } else {
    $mensaje = "❌ Usuario no encontrado";
  }
}

$inits = iniciales($nombreSesion ?: 'Usuario');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login - Central <?= h(BRAND_NAME) ?> <?= h(BRAND_SUFFIX) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Favicon -->
<link rel="icon" type="image/png" href="<?= h(BRAND_FAVICON) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --brand: <?= h(BRAND_COLOR) ?>; --brand-600: <?= h(BRAND_COLOR_600) ?>; }
  html,body{height:100%}
  body{
    margin:0;
    background: linear-gradient(-45deg,#0f2027,#203a43,#2c5364,#1c2b33);
    background-size: 400% 400%;
    animation: bgshift 15s ease infinite;
    display:flex; align-items:center; justify-content:center;
  }
  @keyframes bgshift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  .login-card{
    width:min(420px,92vw);
    background:#fff; color:#333;
    border-radius:16px;
    box-shadow:0 12px 28px rgba(0,0,0,.18);
    padding:26px 22px;
    position:relative;
    <?php if (SHOW_CONSTRUCTION): ?>filter: saturate(0.9);<?php endif; ?>
  }
  .brand-logo{display:block; margin:0 auto 12px; width:120px; object-fit:contain}
  .title{ text-align:center; font-weight:800; font-size:1.6rem; margin-bottom:.25rem }
  .subtitle{ text-align:center; color:#56606a; margin-bottom:1.1rem }
  .pwd-field{position:relative}
  .pwd-field .form-control{padding-right:2.8rem}
  .btn-eye{
    position:absolute; inset:0 .6rem 0 auto;
    display:flex; align-items:center; justify-content:center;
    width:34px; background:transparent; border:0; color:#6c757d; cursor:pointer;
  }
  .btn-eye svg{width:20px;height:20px}
  .btn-brand{background:var(--brand); border:none; font-weight:700}
  .btn-brand:hover{background:var(--brand-600)}
  .welcome-avatar{
    width:96px; height:96px; border-radius:50%; overflow:hidden;
    margin:0 auto 8px; border:1px solid rgba(0,0,0,.08);
    background:#eef5ff; display:flex; align-items:center; justify-content:center;
  }
  .welcome-avatar img{width:100%; height:100%; object-fit:cover}
  .welcome-inits{font-weight:800; font-size:34px; color:#2b3d59}
  .progress{ height:8px; }

  /* Banner countdown */
  #launchBanner { border-radius:12px; border:1px solid #cfe2ff; }

  /* Modal invasivo (alerta) */
  .modal-danger .modal-header{
    background:#fff1f0; border-bottom:1px solid #ffd6d6;
  }
  .modal-danger .modal-title i{ color:#dc3545; }
  .list-slim .list-group-item{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .list-slim .meta{ white-space:nowrap; font-size:.9rem; color:#555; }

  /* ====== Construcción: cinta + ribbon ====== */
  <?php if (SHOW_CONSTRUCTION): ?>
  .construction-tape {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 42px;
    z-index: 9999;
    background:
      repeating-linear-gradient(
        -45deg,
        #f9d400 0 22px,
        #111 22px 44px
      );
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; letter-spacing:.08em;
    text-transform:uppercase;
    text-shadow: 0 1px 0 rgba(255,255,255,.4);
  }
  .construction-ribbon {
    position: fixed;
    top: 18px; right: -48px;
    width: 220px; text-align:center;
    transform: rotate(45deg);
    background:#111; color:#f9d400; font-weight:800;
    padding: 8px 0;
    box-shadow: 0 4px 12px rgba(0,0,0,.35);
    z-index: 10000;
    letter-spacing:.06em;
  }
  .construction-note {
    margin-top: .35rem;
    text-align:center;
  }
  <?php endif; ?>
</style>
</head>
<body>

<?php if (SHOW_CONSTRUCTION): ?>
  <div class="construction-tape">EN CONSTRUCCIÓN — CENTRAL <?= h(BRAND_NAME) ?> <?= h(BRAND_SUFFIX) ?></div>
  <div class="construction-ribbon">EN CONSTRUCCIÓN</div>
<?php endif; ?>

<div class="login-card" id="card">
  <img class="brand-logo" src="<?= h(BRAND_LOGO_URL) ?>" alt="Logo <?= h(BRAND_NAME) ?>">
  <div class="title">Central <?= h(BRAND_NAME) ?> <span style="color:var(--brand)"><?= h(BRAND_SUFFIX) ?></span></div>
  <div class="subtitle" id="welcomeMsg">Bienvenido</div>

  <?php if (SHOW_CONSTRUCTION): ?>
    <div class="construction-note">
      <span class="badge rounded-pill bg-warning text-dark fw-bold">
        🚧 En construcción – próximamente
      </span>
    </div>
  <?php endif; ?>

  <?php if ($showBanner): ?>
    <div class="alert alert-info d-flex align-items-center justify-content-between py-2 px-3 mb-3"
         id="launchBanner" data-launch="<?= (int)$LAUNCH_TS ?>">
      <div class="me-3">
        🚀 <strong>Lanzamiento</strong>: <?= h($launchHuman) ?>
        <span class="text-muted">— falta</span>
        <span id="lc" class="fw-bold">00:00:00</span>
      </div>
      <span class="badge text-bg-primary">Central <?= h(BRAND_NAME) ?> <?= h(BRAND_SUFFIX) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($mensaje): ?>
    <div class="alert alert-danger text-center"><?= h($mensaje) ?></div>
  <?php endif; ?>

  <form id="loginForm" method="POST" novalidate>
    <div class="mb-3">
      <label class="form-label">Usuario</label>
      <input type="text" name="usuario" class="form-control" autocomplete="username" required autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <div class="pwd-field">
        <input type="password" name="password" id="password" class="form-control" autocomplete="current-password" required>
        <button type="button" class="btn-eye" id="togglePwd" aria-label="Mostrar/ocultar contraseña">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7Z" stroke="currentColor"/>
            <circle cx="12" cy="12" r="3" stroke="currentColor"/>
          </svg>
        </button>
      </div>
    </div>
    <button class="btn btn-brand w-100 btn-lg" id="submitBtn">Ingresar</button>
  </form>
</div>

<!-- Modal Bienvenida (auto-redirect si no hay alertas) -->
<div class="modal fade" id="welcomeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <div class="welcome-avatar mb-2">
          <?php if (!empty($fotoUrl)): ?>
            <img src="<?= h($fotoUrl) ?>" alt="Foto de perfil">
          <?php else: ?>
            <div class="welcome-inits"><?= h($inits) ?></div>
          <?php endif; ?>
        </div>
        <h5 class="fw-bold mb-1"><?= h($saludo) ?>, <?= h($nombreSesion) ?>.</h5>
        <div class="text-muted mb-3">Bienvenido de nuevo 👋</div>
        <div class="progress mb-1">
          <div class="progress-bar" role="progressbar" style="width:0%" id="pb"></div>
        </div>
        <small class="text-muted">Entrando a tu panel…</small>
      </div>
    </div>
  </div>
</div>

<!-- Modal INVASIVO de Alertas (GZ) -->
<?php if (!empty($alertPend)): ?>
<div class="modal fade" id="alertPendModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content modal-danger">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-exclamation-octagon-fill me-2"></i>
          Atención: traspasos pendientes en tu zona
        </h5>
      </div>
      <div class="modal-body">
        <p class="mb-2">
          Se detectó <?= (int)$alertPend['total_tr'] ?> traspaso(s) <b>Pendiente</b> con antigüedad de <b>3 días o más</b> en
          <b><?= (int)$alertPend['total_suc'] ?></b> sucursal(es) de tu zona:
        </p>
        <ul class="list-group list-slim mb-3">
          <?php foreach ($alertPend['rows'] as $r):
            $fechaFmt = $r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '-';
          ?>
          <li class="list-group-item">
            <div>
              <strong><?= h($r['nombre']) ?></strong>
              <div class="small text-muted">Desde: <?= h($fechaFmt) ?> (<?= (int)$r['dias'] ?> días)</div>
            </div>
            <div class="meta">
              <span class="badge text-bg-danger me-2"><?= (int)$r['trasps'] ?> traspaso(s)</span>
              <a class="btn btn-outline-danger btn-sm" href="traspasos_pendientes_zona.php?sucursal=<?= (int)$r['id'] ?>">
                <i class="bi bi-arrow-right-circle me-1"></i>Revisar
              </a>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="alert alert-warning mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Por políticas de control, los traspasos a <b>Eulalia</b> los procesa exclusivamente <b>Almacén</b>; no aparecen en este aviso.
        </div>
      </div>
      <div class="modal-footer">
        <a href="traspasos_pendientes_zona.php" class="btn btn-danger">
          <i class="bi bi-clipboard-check me-1"></i>Revisar pendientes
        </a>
        <button type="button" class="btn btn-secondary" id="btnEntrarPanel">
          Entrar al panel
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Saludo del login
(function(){
  const h=new Date().getHours();
  document.getElementById('welcomeMsg').textContent =
    h<12?"Buenos días, ingresa tus credenciales para continuar."
      :h<19?"Buenas tardes, ingresa tus credenciales para continuar."
            :"Buenas noches, ingresa tus credenciales para continuar.";
})();

// Toggle contraseña
(function(){
  const pwd=document.getElementById('password');
  const btn=document.getElementById('togglePwd');
  btn.addEventListener('click',()=>{pwd.type = (pwd.type==="text" ? "password" : "text");});
})();

// Cuenta regresiva de lanzamiento (basada en timestamp del servidor)
(function(){
  const banner = document.getElementById('launchBanner');
  if (!banner) return;
  const targetMs = parseInt(banner.dataset.launch, 10) * 1000; // epoch -> ms
  const out = document.getElementById('lc');

  function pad(n){ return n < 10 ? '0'+n : ''+n; }
  function tick(){
    const diff = targetMs - Date.now();
    if (diff <= 0){
      out.textContent = '¡es hoy!';
      clearInterval(t);
      setTimeout(()=>{ banner.remove(); }, 3000);
      return;
    }
    const totalSec = Math.floor(diff/1000);
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    out.textContent = (d>0 ? d+'d ' : '') + pad(h)+':'+pad(m)+':'+pad(s);
  }
  const t = setInterval(tick, 1000);
  tick();
})();

// Bienvenida auto-redirect (sólo si NO hay alertas)
<?php if ($showWelcome): ?>
(function(){
  const modal=new bootstrap.Modal(document.getElementById('welcomeModal'),{backdrop:'static',keyboard:false});
  modal.show();
  const pb=document.getElementById('pb'); const total=1500; const t0=performance.now();
  function tick(now){ const p=Math.min(1,(now-t0)/total); pb.style.width=(p*100)+'%'; if(p<1) requestAnimationFrame(tick); }
  requestAnimationFrame(tick);
  setTimeout(()=>{ window.location.href='dashboard_unificado.php'; }, total+120);
})();
<?php endif; ?>

// Modal invasivo de alertas (bloqueante): muestra y obliga a elegir
<?php if (!empty($alertPend)): ?>
(function(){
  const modal=new bootstrap.Modal(document.getElementById('alertPendModal'),{backdrop:'static',keyboard:false});
  modal.show();

  document.getElementById('btnEntrarPanel').addEventListener('click', ()=>{
    modal.hide();
    window.location.href='dashboard_unificado.php';
  });
})();
<?php endif; ?>
</script>
</body>
</html>
