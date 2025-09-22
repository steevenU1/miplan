<?php
// navbar.php — LUGA (compacto: textos de menús reducidos + sin carets en dropdowns)

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'db.php';
date_default_timezone_set('America/Mexico_City');

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = trim($_SESSION['nombre'] ?? 'Usuario');
$idUsuario     = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

/* Helpers */
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n)
  {
    return (string)$n !== '' && strncmp($h, $n, strlen($n)) === 0;
  }
}
function e($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function initials($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $p = preg_split('/\s+/', $name);
  $a = mb_substr($p[0] ?? '', 0, 1, 'UTF-8');
  $b = mb_substr($p[count($p) - 1] ?? '', 0, 1, 'UTF-8');
  $ini = mb_strtoupper($a . $b, 'UTF-8');
  return $ini ?: 'U';
}
function first_name($name)
{
  $name = trim((string)$name);
  if ($name === '') return 'Usuario';
  $p = preg_split('/\s+/', $name);
  return $p[0] ?? $name;
}
function resolveAvatarUrl(?string $f): ?string
{
  $f = trim((string)$f);
  if ($f === '') return null;
  if (preg_match('#^(https?://|data:image/)#i', $f)) return $f;
  $f = str_replace('\\', '/', $f);
  $doc = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $app = rtrim(str_replace('\\', '/', __DIR__), '/');
  $base = '';
  if ($doc && str_starts_with($app, $doc)) $base = substr($app, strlen($doc));
  if (preg_match('#^[A-Za-z]:/|^/#', $f)) {
    if ($doc && str_starts_with($f, $doc . '/')) return substr($f, strlen($doc));
    $bn = basename($f);
    foreach (['uploads/expedientes', 'expedientes', 'uploads', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'] as $d) {
      $abs = $app . '/' . $d . '/' . $bn;
      if (is_file($abs)) return $base . '/' . $d . '/' . $bn;
    }
    return null;
  }
  if (str_starts_with($f, '/')) {
    if ($doc && is_file($doc . $f)) return $f;
    return $f;
  }
  if (is_file($app . '/' . $f)) return $base . '/' . ltrim($f, '/');
  if ($doc && is_file($doc . '/' . $f)) return '/' . ltrim($f, '/');
  $bn = basename($f);
  foreach (['uploads/expedientes', 'expedientes', 'uploads', 'uploads/usuarios', 'usuarios', 'uploads/perfiles', 'perfiles'] as $d) {
    $abs = $app . '/' . $d . '/' . $bn;
    if (is_file($abs)) return $base . '/' . $d . '/' . $bn;
  }
  return null;
}

/* Avatar */
$avatarUrl = null;
if ($idUsuario > 0) {
  $st = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id=? ORDER BY id DESC LIMIT 1");
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $st->bind_result($f);
  if ($st->fetch()) $avatarUrl = resolveAvatarUrl($f);
  $st->close();
}

/* Sucursal actual */
$sucursalNombre = '';
$sucursalSubtipo = '';
if ($idSucursal > 0) {
  $st = $conn->prepare("SELECT nombre, COALESCE(subtipo,'') AS subtipo FROM sucursales WHERE id=?");
  $st->bind_param("i", $idSucursal);
  $st->execute();
  $st->bind_result($sucursalNombre, $sucursalSubtipo);
  $st->fetch();
  $st->close();
}
/* ¿Es sucursal propia (no subdistribuidor)? */
$esSucursalPropia = true;
if ($sucursalSubtipo !== '') {
  $esSucursalPropia = (mb_strtolower(trim($sucursalSubtipo), 'UTF-8') !== 'subdistribuidor');
}

/* Sucursales del usuario (para cambio) */
$misSucursales = [];
if ($idUsuario > 0) {
  $st = $conn->prepare("
    SELECT s.id, s.nombre FROM usuario_sucursales us
    JOIN sucursales s ON s.id=us.sucursal_id
    WHERE us.usuario_id=? ORDER BY s.nombre
  ");
  $st->bind_param('i', $idUsuario);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $misSucursales[] = $r;
  $st->close();
}

/* ⛳️ EXCEPCIÓN DINÁMICA:
   Ejecutivos con acceso a >1 sucursal (según usuario_sucursales)
   NO heredan permisos cuando “no hay gerente”. */
$omitirReglaEjecutivo = ($rolUsuario === 'Ejecutivo' && count($misSucursales) > 1);

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* Badges */
$badgeEquip = 0;
if ($idSucursal > 0) {
  $st = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'");
  $st->bind_param("i", $idSucursal);
  $st->execute();
  $st->bind_result($badgeEquip);
  $st->fetch();
  $st->close();
}
$badgeSims = 0;
if ($idSucursal > 0) {
  if ($st = $conn->prepare("SELECT COUNT(*) FROM traspasos_sims WHERE id_sucursal_destino=? AND estatus='Pendiente'")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($badgeSims);
    $st->fetch();
    $st->close();
  }
}
$badgePendZona = 0;
if (($rolUsuario === 'GerenteZona')) {
  $zonaGZ = null;
  $st = $conn->prepare("SELECT s.zona FROM usuarios u INNER JOIN sucursales s ON s.id=u.id_sucursal WHERE u.id=? LIMIT 1");
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $zonaGZ = $st->get_result()->fetch_assoc()['zona'] ?? null;
  $st->close();

  $idEulalia = 0;
  if ($st = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")) {
    $st->execute();
    $idEulalia = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
    $st->close();
  }

  if ($zonaGZ) {
    if ($idEulalia > 0) {
      $st = $conn->prepare("SELECT COUNT(*) FROM traspasos t INNER JOIN sucursales sd ON sd.id=t.id_sucursal_destino WHERE t.estatus='Pendiente' AND sd.zona=? AND sd.id<>?");
      $st->bind_param("si", $zonaGZ, $idEulalia);
    } else {
      $st = $conn->prepare("SELECT COUNT(*) FROM traspasos t INNER JOIN sucursales sd ON sd.id=t.id_sucursal_destino WHERE t.estatus='Pendiente' AND sd.zona=?");
      $st->bind_param("s", $zonaGZ);
    }
    $st->execute();
    $badgePendZona = (int)($st->get_result()->fetch_assoc()['COUNT(*)'] ?? 0);
    $st->close();
  }
}

$esAdmin = in_array($rolUsuario, ['Admin', 'Super'], true);
$primerNombre = first_name($nombreUsuario);

/* ===== Dinámica: ¿sucursal sin gerente activo? y permisos derivados ===== */
$sucursalSinGerente = false;
if ($idSucursal > 0) {
  if ($st = $conn->prepare(
    "SELECT COUNT(*) FROM usuarios
     WHERE id_sucursal=? AND rol IN('Gerente','GerenteSucursal') AND activo=1"
  )) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($cnt);
    $st->fetch();
    $st->close();
    $sucursalSinGerente = ((int)$cnt === 0);
  }
}
/* Permisos para menús especiales */
$puedeTraspasos = in_array($rolUsuario, ['Gerente', 'GerenteSucursal', 'Admin', 'Super'], true)
  || ($rolUsuario === 'Ejecutivo' && $sucursalSinGerente && !$omitirReglaEjecutivo);

$puedeCortesYDepositos = in_array($rolUsuario, ['Gerente', 'GerenteSucursal', 'Admin', 'Super'], true)
  || ($rolUsuario === 'Ejecutivo' && $sucursalSinGerente && !$omitirReglaEjecutivo);

/* Activo por URL */
$current = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$grpDashboard  = ['productividad_dia.php', 'dashboard_unificado.php', 'dashboard_mensual.php'];
$grpVentas     = [
  'nueva_venta.php',
  'venta_sim_prepago.php',
  'venta_sim_pospago.php',
  'payjoy_tc_nueva.php',        // ✅ NUEVO
  'historial_ventas.php',
  'historial_ventas_sims.php',
  'historial_payjoy_tc.php'     // ✅ NUEVO
];
$grpInventario = ['panel.php', 'inventario_subdistribuidor.php', 'inventario_global.php', 'inventario_resumen.php', 'inventario_eulalia.php', 'inventario_retiros.php', 'inventario_historico.php', 'generar_traspaso_zona.php', 'traspasos_pendientes_zona.php', 'inventario_sims_resumen.php'];
$grpCompras    = ['compras_nueva.php', 'compras_resumen.php', 'modelos.php', 'proveedores.php', 'compras_ingreso.php'];
$grpTraspasos  = ['generar_traspaso.php', 'generar_traspaso_sims.php', 'traspasos_sims_pendientes.php', 'traspasos_sims_salientes.php', 'traspasos_pendientes.php', 'traspasos_salientes.php', 'traspaso_nuevo.php'];
$grpEfectivo   = ['cobros.php', 'cortes_caja.php', 'generar_corte.php', 'depositos_sucursal.php', 'depositos.php', 'recoleccion_comisiones.php'];
$grpOperacion  = ['lista_precios.php', 'prospectos.php', 'insumos_pedido.php', 'insumos_admin.php', 'mantenimiento_solicitar.php', 'mantenimiento_admin.php', 'gestionar_usuarios.php', 'zona_asistencias.php', 'nomina_mi_semana.php'];
$grpRH         = ['reporte_nomina.php', 'reporte_nomina_gerentes_zona.php', 'admin_expedientes.php', 'admin_asistencias.php', 'productividad_ejecutivo.php'];
/* ✅ Incluimos tareas.php al grupo Operativos para resaltar activo */
$grpOperativos = [
  'tareas.php', // NEW
  'insumos_catalogo.php',
  'actualizar_precios_modelo.php',
  'cuotas_mensuales.php',
  'cuotas_mensuales_ejecutivos.php',
  'cuotas_sucursales.php',
  'cargar_cuotas_semanales.php',
  'esquemas_comisiones_ejecutivos.php',
  'esquemas_comisiones_gerentes.php',
  'esquemas_comisiones_pospago.php',
  'comisiones_especiales_equipos.php',
  'carga_masiva_productos.php',
  'carga_masiva_sims.php',
  'alta_usuario.php',
  'alta_sucursal.php',
  'incidencias_matriz.php'
];
$grpCeleb      = ['cumples_aniversarios.php'];

function parent_active(array $g, string $c): bool
{
  return in_array($c, $g, true);
}
function item_active(string $f, string $c): string
{
  return $c === $f ? 'active' : '';
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<style>
  /* Tamaño base consistente */
  #topbar {
    --nav-base: 16px;
    font-size: var(--nav-base);
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
  }

  /* ↓ Ajustes globales de tipografía/padding de NAV */
  #topbar {
    --brand-font: clamp(13px, 1.6vw, 20px);
    --nav-font: .80em;
    --drop-font: .84em;
    --icon-em: .90em;
    --pad-y: .30em;
    --pad-x: .42em;
  }

  #topbar * {
    font-size: inherit;
  }

  .navbar-luga {
    background: radial-gradient(1200px 600px at 10% -20%, rgba(255, 255, 255, .18), rgba(255, 255, 255, 0)),
      linear-gradient(90deg, #0b0f14, #0f141a 60%, #121922);
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    backdrop-filter: blur(6px);
  }

  .brand-title {
    font-weight: 900;
    letter-spacing: .1px;
    line-height: 1;
    font-size: var(--brand-font);
    background: linear-gradient(92deg, #eaf2ff 0%, #cfe0ff 45%, #9ec5ff 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: 0 1px 0 rgba(0, 0, 0, .25);
    white-space: nowrap;
  }

  .navbar-brand {
    margin-right: .5rem;
  }

  .navbar-brand img {
    width: 1.625em;
    height: 1.625em;
    object-fit: cover;
  }

  .navbar-luga .nav-link {
    padding: var(--pad-y) var(--pad-x);
    font-size: var(--nav-font);
    border-radius: .6rem;
    color: #e7eef7 !important;
    line-height: 1.1;
    letter-spacing: .1px;
  }

  .navbar-luga .nav-link i {
    font-size: var(--icon-em);
    margin-right: .24rem;
  }

  .navbar-luga .nav-link:hover {
    background: rgba(255, 255, 255, .06);
  }

  /* Quitar carets */
  .navbar-luga .dropdown-toggle::after {
    display: none !important;
  }

  .navbar-luga .dropdown-menu {
    --bs-dropdown-bg: #0f141a;
    --bs-dropdown-color: #e7eef7;
    --bs-dropdown-link-color: #e7eef7;
    --bs-dropdown-link-hover-color: #fff;
    --bs-dropdown-link-hover-bg: rgba(255, 255, 255, .06);
    --bs-dropdown-link-active-bg: rgba(255, 255, 255, .12);
    --bs-dropdown-border-color: rgba(255, 255, 255, .08);
    --bs-dropdown-header-color: #aab8c7;
    --bs-dropdown-divider-bg: rgba(255, 255, 255, .12);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, .35);
    overflow: hidden;
    font-size: var(--drop-font);
  }

  /* Dropdown largo: que no se salga de la pantalla */
  .navbar-luga .dropdown-menu {
    --bs-dropdown-bg: #0f141a;
    --bs-dropdown-color: #e7eef7;
    --bs-dropdown-link-color: #e7eef7;
    --bs-dropdown-link-hover-color: #fff;
    --bs-dropdown-link-hover-bg: rgba(255, 255, 255, .06);
    --bs-dropdown-link-active-bg: rgba(255, 255, 255, .12);
    --bs-dropdown-border-color: rgba(255, 255, 255, .08);
    --bs-dropdown-header-color: #aab8c7;
    --bs-dropdown-divider-bg: rgba(255, 255, 255, .12);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, .35);
    overflow: auto;
    /* ⬅️ habilita scroll */
    max-height: calc(100vh - 110px);
    /* ⬅️ no más alto que la ventana */
    overscroll-behavior: contain;
    /* evita “brincos” al hacer scroll */
    -webkit-overflow-scrolling: touch;
    /* scroll suave en iOS */
    font-size: var(--drop-font);
    padding-bottom: .25rem;
    /* respirito al final */
  }

  /* Opcional: headers pegajosos dentro del dropdown para ubicarnos */
  .navbar-luga .dropdown-menu .dropdown-header {
    position: sticky;
    top: 0;
    background: #0f141a;
    z-index: 2;
    padding-top: .5rem;
  }

  /* Scrollbar sutil (opcional) */
  .navbar-luga .dropdown-menu::-webkit-scrollbar {
    width: 8px;
  }

  .navbar-luga .dropdown-menu::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, .25);
    border-radius: 8px;
  }

  .navbar-luga .dropdown-menu::-webkit-scrollbar-track {
    background: transparent;
  }

  /* === MODO MÓVIL / < xl  ============================================= */
  /* La navbar es expand-xl; por debajo de xl (1199.98px) está colapsada */
  @media (max-width: 1199.98px) {

    /* El panel colapsado debe poder hacer scroll para mostrar todo el menú */
    #navbarMain {
      max-height: calc(100svh - 64px);
      /* 64px aprox. altura de la barra */
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding-bottom: .5rem;
      /* respiro al fondo */
    }

    /* El dropdown dentro del panel debe fluir "en bloque", no flotante */
    .navbar-luga .dropdown-menu {
      position: static !important;
      /* que no se “despegue” */
      transform: none !important;
      max-height: none;
      /* el scroll lo maneja #navbarMain */
      overflow: visible;
      box-shadow: none;
      /* más natural en panel lateral */
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, .12);
      margin-top: .25rem;
    }

    /* Headers del dropdown no necesitan sticky en móvil */
    .navbar-luga .dropdown-menu .dropdown-header {
      position: static;
    }
  }

  .navbar-luga .dropdown-item {
    padding: .46em .70em;
    line-height: 1.12;
  }

  .navbar-luga .nav-link.active-parent {
    background: rgba(255, 255, 255, .10);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12);
  }

  .navbar-luga .dropdown-item.active {
    background: rgba(255, 255, 255, .18);
    font-weight: 600;
  }

  .nav-avatar,
  .nav-initials {
    width: 2em;
    height: 2em;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .92em;
    object-fit: cover;
  }

  .dropdown-avatar,
  .dropdown-initials {
    width: 3.375em;
    height: 3.375em;
    border-radius: 16px;
    object-fit: cover;
  }

  .dropdown-initials {
    background: #25303a;
    color: #e8f0f8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
  }

  .user-chip {
    color: #e7eef7;
    font-weight: 600;
  }

  .user-chip small {
    color: #a7b4c2;
    font-weight: 500;
  }

  .nav-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .72em;
    font-weight: 700;
    line-height: 1;
    padding: .22em .46em;
    border-radius: 10px;
    vertical-align: middle;
    border: 1px solid transparent;
  }

  .badge-soft-danger {
    background: rgba(220, 53, 69, .18);
    color: #ffadb7;
    border-color: rgba(220, 53, 69, .35);
  }

  .badge-soft-info {
    background: rgba(13, 110, 253, .12);
    color: #a6d1ff;
    border-color: rgba(13, 110, 253, .35);
  }

  .btn-asistencia {
    font-weight: 800;
    letter-spacing: .2px;
    padding: .46em .86em !important;
    border: 2px solid #8fd0ff;
    border-radius: 14px;
    background: rgba(13, 110, 253, .10);
    display: flex;
    align-items: center;
    gap: .4em;
    box-shadow: 0 0 0 0 rgba(13, 110, 253, .55), 0 0 10px rgba(13, 110, 253, .25);
    animation: pulseGlow 1.9s ease-in-out infinite;
    text-transform: uppercase;
  }

  .btn-asistencia i {
    font-size: 1.05em;
    margin-right: .25em;
  }

  @keyframes pulseGlow {
    0% {
      box-shadow: 0 0 0 0 rgba(13, 110, 253, .55), 0 0 10px rgba(13, 110, 253, .25);
    }

    50% {
      box-shadow: 0 0 0 6px rgba(13, 110, 253, 0), 0 0 18px rgba(13, 110, 253, .75);
    }

    100% {
      box-shadow: 0 0 0 0 rgba(13, 110, 253, 0), 0 0 10px rgba(13, 110, 253, .25);
    }
  }

  .pulse-ring {
    position: relative;
    display: inline-block;
  }

  .pulse-ring::after {
    content: "";
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid rgba(13, 110, 253, .65);
    animation: ring 1.8s ease-out infinite;
  }

  @keyframes ring {
    0% {
      transform: scale(.8);
      opacity: .9;
    }

    70% {
      transform: scale(1.25);
      opacity: .1;
    }

    100% {
      transform: scale(1.4);
      opacity: 0;
    }
  }

  @media (min-width:1200px) and (max-width:1400px) {
    #topbar {
      --nav-font: .78em;
      --drop-font: .82em;
      --pad-x: .38em;
      --icon-em: .88em;
    }

    .navbar-luga .nav-link i {
      margin-right: .20rem;
    }
  }

  @media (min-width:1200px) and (max-width:1280px) {
    #topbar {
      --nav-font: .74em;
      --drop-font: .80em;
      --pad-x: .34em;
    }

    .brand-title {
      font-size: clamp(12px, 1.3vw, 18px);
    }
  }

  @media (min-width:1200px) and (max-width:1440px) {
    #topbar {
      font-size: 15px;
    }
  }

  @media (max-width:420px) {
    #topbar {
      font-size: clamp(15px, 3.8vw, 16px);
    }
  }
</style>

<!-- Mantener expandido en ≥1200px para evitar hamburguesa en pantallas medianas de PC -->
<nav id="topbar" class="navbar navbar-expand-xl navbar-dark navbar-luga sticky-top">
  <div class="container-fluid">

    <a class="navbar-brand d-flex align-items-center" href="dashboard_unificado.php">
      <img src="https://i.ibb.co/DDw7yjYV/43f8e23a-8877-4928-9407-32d18fb70f79.png" class="me-2 rounded-circle" alt="Logo">
      <span class="brand-title">Central&nbsp;<strong>2.0</strong></span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-label="Menú">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarMain">
      <ul class="navbar-nav me-auto mb-2 mb-xl-0">
        <?php $pActive = parent_active($grpDashboard, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-speedometer2"></i>Dashboard
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= item_active('productividad_dia.php', $current) ?>" href="productividad_dia.php">Dashboard diario</a></li>
            <li><a class="dropdown-item <?= item_active('dashboard_unificado.php', $current) ?>" href="dashboard_unificado.php">Dashboard semanal</a></li>
            <li><a class="dropdown-item <?= item_active('dashboard_mensual.php', $current) ?>" href="dashboard_mensual.php">Dashboard mensual</a></li>
          </ul>
        </li>

        <?php $pActive = parent_active($grpVentas, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-bag-check"></i>Ventas
          </a>
          <ul class="dropdown-menu">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <!-- Solo historiales para Logística -->
              <li class="dropdown-header">Historiales</li>
              <li><a class="dropdown-item <?= item_active('historial_ventas.php', $current) ?>" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item <?= item_active('historial_ventas_sims.php', $current) ?>" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
              <li><a class="dropdown-item <?= item_active('historial_payjoy_tc.php', $current) ?>" href="historial_payjoy_tc.php">Historial PayJoy TC</a></li>

            <?php else: ?>
              <!-- Ventas nuevas -->
              <li class="dropdown-header">Ventas nuevas</li>
              <li><a class="dropdown-item <?= item_active('nueva_venta.php', $current) ?>" href="nueva_venta.php">Venta equipos</a></li>
              <li><a class="dropdown-item <?= item_active('venta_sim_prepago.php', $current) ?>" href="venta_sim_prepago.php">Venta SIM prepago</a></li>
              <li><a class="dropdown-item <?= item_active('venta_sim_pospago.php', $current) ?>" href="venta_sim_pospago.php">Venta SIM pospago</a></li>
              <li><a class="dropdown-item <?= item_active('payjoy_tc_nueva.php', $current) ?>" href="payjoy_tc_nueva.php">PayJoy TC – Nueva</a></li>

              <li>
                <hr class="dropdown-divider">
              </li>

              <!-- Historiales -->
              <li class="dropdown-header">Historiales</li>
              <li><a class="dropdown-item <?= item_active('historial_ventas.php', $current) ?>" href="historial_ventas.php">Historial de ventas</a></li>
              <li><a class="dropdown-item <?= item_active('historial_ventas_sims.php', $current) ?>" href="historial_ventas_sims.php">Historial ventas SIM</a></li>
              <li><a class="dropdown-item <?= item_active('historial_payjoy_tc.php', $current) ?>" href="historial_payjoy_tc.php">Historial PayJoy TC</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <?php $pActive = parent_active($grpInventario, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-box-seam"></i>Inventario
          </a>
          <ul class="dropdown-menu">
            <?php if ($rolUsuario === 'Logistica'): ?>
              <li><a class="dropdown-item <?= item_active('inventario_global.php', $current) ?>" href="inventario_global.php">Inventario global</a></li>
              <li><a class="dropdown-item <?= item_active('inventario_historico.php', $current) ?>" href="inventario_historico.php">Inventario histórico</a></li>
            <?php else: ?>
              <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
                <li><a class="dropdown-item <?= item_active('panel.php', $current) ?>" href="panel.php">Inventario sucursal</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'Subdistribuidor', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('inventario_subdistribuidor.php', $current) ?>" href="inventario_subdistribuidor.php">Inventario subdistribuidor</a></li>
              <?php endif; ?>
              <?php if (in_array($rolUsuario, ['Admin', 'GerenteZona', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('inventario_global.php', $current) ?>" href="inventario_global.php">Inventario global</a></li>
              <?php endif; ?>

              <!-- ✅ NUEVO: SIMs (Resumen) SOLO para Gerente y Admin -->
              <?php if (in_array($rolUsuario, ['Gerente', 'Admin'], true)): ?>
                <li><a class="dropdown-item <?= item_active('inventario_resumen_sim.php', $current) ?>" href="inventario_sims_resumen.php">Invenario SIMs</a></li>
              <?php endif; ?>

              <?php if ($rolUsuario === 'GerenteZona'): ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li class="dropdown-header">Zona (GZ)</li>
                <li>
                  <a class="dropdown-item <?= item_active('inventario_resumen.php', $current) ?>" href="inventario_resumen.php">
                    Resumen Global
                  </a>
                </li>
                <li><a class="dropdown-item <?= item_active('generar_traspaso_zona.php', $current) ?>" href="generar_traspaso_zona.php"><i class="bi bi-arrow-left-right me-1"></i>Generar traspaso (Zona)</a></li>
                <li>
                  <a class="dropdown-item d-flex justify-content-between align-items-center <?= item_active('traspasos_pendientes_zona.php', $current) ?>" href="traspasos_pendientes_zona.php">
                    <span><i class="bi bi-clock-history me-1"></i>Pendientes de zona</span>
                    <?php if ($badgePendZona > 0): ?><span class="nav-badge badge-soft-danger"><?= (int)$badgePendZona ?></span><?php endif; ?>
                  </a>
                </li>
              <?php endif; ?>

              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li class="dropdown-header">Administrador</li>
                <li><a class="dropdown-item <?= item_active('inventario_resumen.php', $current) ?>" href="inventario_resumen.php">Resumen Global</a></li>
                <li><a class="dropdown-item <?= item_active('inventario_eulalia.php', $current) ?>" href="inventario_eulalia.php">Inventario Eulalia</a></li>
                <li><a class="dropdown-item <?= item_active('inventario_retiros.php', $current) ?>" href="inventario_retiros.php">🛑 Retiros de Inventario</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <?php if (in_array($rolUsuario, ['Admin', 'Super', 'Logistica'])): ?>
          <?php $pActive = parent_active($grpCompras, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-cart-check"></i>Compras
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('compras_nueva.php', $current) ?>" href="compras_nueva.php">Nueva factura</a></li>
              <li><a class="dropdown-item <?= item_active('compras_resumen.php', $current) ?>" href="compras_resumen.php">Resumen de compras</a></li>
              <li><a class="dropdown-item <?= item_active('modelos.php', $current) ?>" href="modelos.php">Catálogo de modelos</a></li>
              <li><a class="dropdown-item <?= item_active('proveedores.php', $current) ?>" href="proveedores.php">Proveedores</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li><a class="dropdown-item" href="compras_resumen.php?estado=Pendiente">Ingreso a almacén (pendientes)</a></li>
              <li><a class="dropdown-item disabled" href="#" tabindex="-1" aria-disabled="true" title="Se accede desde el Resumen">compras_ingreso.php (directo)</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($puedeTraspasos): ?>
          <?php $pActive = parent_active($grpTraspasos, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-arrow-left-right"></i>Traspasos
              <?php if ($badgeEquip > 0): ?><span class="nav-badge badge-soft-danger badge-pulse ms-1"><?= (int)$badgeEquip ?></span><?php endif; ?>
              <?php if ($badgeSims > 0):  ?><span class="nav-badge badge-soft-info  badge-pulse-blue ms-1"><?= (int)$badgeSims  ?></span><?php endif; ?>
            </a>
            <ul class="dropdown-menu">
              <?php if (in_array($rolUsuario, ['Admin', 'Super'])): ?>
                <li><a class="dropdown-item <?= item_active('generar_traspaso.php', $current) ?>" href="generar_traspaso.php">Generar traspaso desde Eulalia</a></li>
              <?php endif; ?>

              <li><a class="dropdown-item <?= item_active('generar_traspaso_sims.php', $current) ?>" href="generar_traspaso_sims.php">Generar traspaso SIMs</a></li>

              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">SIMs</li>
              <li>
                <a class="dropdown-item d-flex justify-content-between align-items-center <?= item_active('traspasos_sims_pendientes.php', $current) ?>" href="traspasos_sims_pendientes.php">
                  <span>SIMs pendientes</span>
                  <?php if ($badgeSims > 0): ?><span class="nav-badge badge-soft-info"><?= (int)$badgeSims ?></span><?php endif; ?>
                </a>
              </li>
              <li><a class="dropdown-item <?= item_active('traspasos_sims_salientes.php', $current) ?>" href="traspasos_sims_salientes.php">SIMs salientes</a></li>

              <?php if ($rolUsuario === 'Gerente' || ($rolUsuario === 'Ejecutivo' && $sucursalSinGerente && !$omitirReglaEjecutivo)): ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li class="dropdown-header">Equipos</li>
                <li><a class="dropdown-item <?= item_active('traspaso_nuevo.php', $current) ?>" href="traspaso_nuevo.php">Generar traspaso entre sucursales</a></li>
              <?php endif; ?>

              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Historial de equipos</li>
              <li>
                <a class="dropdown-item d-flex justify-content-between align-items-center <?= item_active('traspasos_pendientes.php', $current) ?>" href="traspasos_pendientes.php">
                  <span>Traspasos entrantes</span>
                  <?php if ($badgeEquip > 0): ?><span class="nav-badge badge-soft-danger"><?= (int)$badgeEquip ?></span><?php endif; ?>
                </a>
              </li>
              <li><a class="dropdown-item <?= item_active('traspasos_salientes.php', $current) ?>" href="traspasos_salientes.php">Traspasos salientes</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($rolUsuario !== 'Logistica'): ?>
          <?php $pActive = parent_active($grpEfectivo, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-cash-coin"></i>Efectivo
            </a>
            <ul class="dropdown-menu">
              <?php if ($rolUsuario === 'GerenteZona'): ?>
                <li><a class="dropdown-item <?= item_active('recoleccion_comisiones.php', $current) ?>" href="recoleccion_comisiones.php">Recolección comisiones</a></li>
              <?php else: ?>
                <li><a class="dropdown-item <?= item_active('cobros.php', $current) ?>" href="cobros.php">Generar cobro</a></li>

                <?php if ($puedeCortesYDepositos): ?>
                  <li><a class="dropdown-item <?= item_active('cortes_caja.php', $current) ?>" href="cortes_caja.php">Historial Cortes</a></li>
                  <li><a class="dropdown-item <?= item_active('generar_corte.php', $current) ?>" href="generar_corte.php">Generar corte sucursal</a></li>
                  <li><a class="dropdown-item <?= item_active('depositos_sucursal.php', $current) ?>" href="depositos_sucursal.php">Depósitos sucursal</a></li>
                <?php endif; ?>

                <?php if ($esAdmin): ?>
                  <li><a class="dropdown-item <?= item_active('depositos.php', $current) ?>" href="depositos.php">Validar depósitos</a></li>
                <?php endif; ?>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php $pActive = parent_active($grpOperacion, $current); ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-gear-wide-connected"></i>Operación
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= item_active('lista_precios.php', $current) ?>" href="lista_precios.php">Lista de precios</a></li>

            <?php if (in_array($rolUsuario, ['Gerente', 'Ejecutivo'], true) && $esSucursalPropia): ?>
              <li><a class="dropdown-item <?= item_active('nomina_mi_semana.php', $current) ?>" href="nomina_mi_semana.php">Mi nómina</a></li>
            <?php endif; ?>

            <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
              <li><a class="dropdown-item <?= item_active('prospectos.php', $current) ?>" href="prospectos.php">Prospectos</a></li>
            <?php endif; ?>

            <?php if ($rolUsuario === 'Gerente'): ?>
              <li><a class="dropdown-item <?= item_active('insumos_pedido.php', $current) ?>" href="insumos_pedido.php">Pedido de insumos</a></li>
            <?php endif; ?>

            <?php if ($esAdmin): ?>
              <li><a class="dropdown-item <?= item_active('insumos_admin.php', $current) ?>" href="insumos_admin.php">Administrar insumos</a></li>
              <li><a class="dropdown-item <?= item_active('gestionar_usuarios.php', $current) ?>" href="gestionar_usuarios.php">Gestionar usuarios</a></li>
            <?php endif; ?>

            <?php if ($rolUsuario === 'GerenteZona'): ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Zona (GZ)</li>
              <li><a class="dropdown-item <?= item_active('zona_asistencias.php', $current) ?>" href="zona_asistencias.php"><i class="bi bi-people-fill me-1"></i>Asistencias de zona</a></li>
            <?php endif; ?>

            <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal', 'Admin', 'Super'])): ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Mantenimiento</li>
              <?php if (in_array($rolUsuario, ['Gerente', 'GerenteZona', 'GerenteSucursal'])): ?>
                <li><a class="dropdown-item <?= item_active('mantenimiento_solicitar.php', $current) ?>" href="mantenimiento_solicitar.php">Solicitar mantenimiento</a></li>
              <?php endif; ?>
              <?php if ($esAdmin): ?>
                <li><a class="dropdown-item <?= item_active('mantenimiento_admin.php', $current) ?>" href="mantenimiento_admin.php">Administrar solicitudes</a></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </li>

        <?php if ($esAdmin): ?>
          <?php $pActive = parent_active($grpOperativos, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-tools"></i>Operativos
            </a>
            <ul class="dropdown-menu">
              <!-- 🔝 Bitacora Sistema (solo Admin) -->
              <?php if ($rolUsuario === 'Admin'): ?>
                <li><a class="dropdown-item <?= item_active('tareas.php', $current) ?>" href="tareas.php">Bitacora Sistema</a></li>
                <li>
                  <hr class="dropdown-divider">
                </li>
              <?php endif; ?>

              <li class="dropdown-header">Insumos & Precios</li>
              <li><a class="dropdown-item <?= item_active('insumos_catalogo.php', $current) ?>" href="insumos_catalogo.php">Catálogo de insumos</a></li>
              <li><a class="dropdown-item <?= item_active('actualizar_precios_modelo.php', $current) ?>" href="actualizar_precios_modelo.php">Actualizar precios por modelo</a></li>

              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Cuotas & Comisiones</li>
              <li><a class="dropdown-item <?= item_active('cuotas_mensuales.php', $current) ?>" href="cuotas_mensuales.php">Cuotas sucursales (mensual)</a></li>
              <li><a class="dropdown-item <?= item_active('cuotas_mensuales_ejecutivos.php', $current) ?>" href="cuotas_mensuales_ejecutivos.php">Cuotas ejecutivos (mensual)</a></li>
              <li><a class="dropdown-item <?= item_active('cuotas_sucursales.php', $current) ?>" href="cuotas_sucursales.php">Cuotas semanales (sucursales)</a></li>
              <li><a class="dropdown-item <?= item_active('cargar_cuotas_semanales.php', $current) ?>" href="cargar_cuotas_semanales.php">Cargar cuotas semanales</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_ejecutivos.php', $current) ?>" href="esquemas_comisiones_ejecutivos.php">Esquemas comisiones (ejecutivos)</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_gerentes.php', $current) ?>" href="esquemas_comisiones_gerentes.php">Esquemas comisiones (gerentes)</a></li>
              <li><a class="dropdown-item <?= item_active('esquemas_comisiones_pospago.php', $current) ?>" href="esquemas_comisiones_pospago.php">Esquemas comisiones (pospago)</a></li>
              <li><a class="dropdown-item <?= item_active('comisiones_especiales_equipos.php', $current) ?>" href="comisiones_especiales_equipos.php">Comisiones especiales equipos</a></li>

              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Cargas masivas</li>
              <li><a class="dropdown-item <?= item_active('carga_masiva_productos.php', $current) ?>" href="carga_masiva_productos.php">Carga masiva de productos</a></li>
              <li><a class="dropdown-item <?= item_active('carga_masiva_sims.php', $current) ?>" href="carga_masiva_sims.php">Carga masiva de SIMs</a></li>

              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Altas</li>
              <li><a class="dropdown-item <?= item_active('alta_usuario.php', $current) ?>" href="alta_usuario.php">Alta de usuario</a></li>
              <li><a class="dropdown-item <?= item_active('alta_sucursal.php', $current) ?>" href="alta_sucursal.php">Alta de sucursal</a></li>

              <li class="dropdown-header">Calidad</li>
              <li>
                <a class="dropdown-item <?= item_active('incidencias_matriz.php', $current) ?>" href="incidencias_matriz.php">
                  <i class="bi bi-bug me-1"></i>Matriz de incidencias
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($esAdmin): ?>
          <?php $pActive = parent_active($grpRH, $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-people"></i>RH
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('reporte_nomina.php', $current) ?>" href="reporte_nomina.php">Reporte semanal</a></li>
              <li><a class="dropdown-item <?= item_active('reporte_nomina_gerentes_zona.php', $current) ?>" href="reporte_nomina_gerentes_zona.php">Gerentes zona</a></li>
              <li><a class="dropdown-item <?= item_active('admin_asistencias.php', $current) ?>" href="admin_asistencias.php">Asistencias (Admin)</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="dropdown-header">Expedientes</li>
              <li><a class="dropdown-item <?= item_active('admin_expedientes.php', $current) ?>" href="admin_expedientes.php">Panel de expedientes</a></li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <?php if ($rolUsuario === 'Admin'): ?>
                <li class="dropdown-header">Efectividad</li>
                <li><a class="dropdown-item <?= item_active('productividad_ejecutivo.php', $current) ?>" href="productividad_ejecutivo.php"><i class="bi bi-clipboard-data me-1"></i>Efectividad ejecutivos</a></li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($rolUsuario !== 'Logistica'): ?>
          <?php $pActive = parent_active(array_merge($grpCeleb, ['cuadro_honor.php']), $current); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $pActive ? ' active-parent' : '' ?>" href="#" data-bs-toggle="dropdown">
              <i class="bi bi-balloon-heart"></i>Celebraciones
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item <?= item_active('cumples_aniversarios.php', $current) ?>" href="cumples_aniversarios.php">🎉 Cumpleaños & Aniversarios</a></li>
              <li><a class="dropdown-item <?= item_active('cuadro_honor.php', $current) ?>" href="cuadro_honor.php">🏅 Cuadro de Honor</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <!-- Derecha -->
      <ul class="navbar-nav ms-auto align-items-center">
        <?php if (in_array($rolUsuario, ['Ejecutivo', 'Gerente'])): ?>
          <li class="nav-item my-1 my-xl-0 me-xl-2">
            <a class="nav-link btn-asistencia <?= item_active('asistencia.php', $current) ?>" href="asistencia.php" title="Registrar asistencia">
              <i class="bi bi-fingerprint"></i> Asistencia
            </a>
          </li>
        <?php endif; ?>

        <!-- Perfil / Cambio sucursal -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" data-bs-toggle="dropdown">
            <span class="me-2 position-relative">
              <?php if ($avatarUrl): ?>
                <img src="<?= e($avatarUrl) ?>" class="nav-avatar" alt="avatar"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="nav-initials" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="nav-initials"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
            </span>
            <span class="user-chip"><?= e($primerNombre) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-3 py-3">
              <?php if ($avatarUrl): ?>
                <img src="<?= e($avatarUrl) ?>" class="dropdown-avatar me-3"
                  onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="dropdown-initials me-3" style="display:none;"><?= e(initials($nombreUsuario)) ?></span>
              <?php else: ?>
                <span class="dropdown-initials me-3"><?= e(initials($nombreUsuario)) ?></span>
              <?php endif; ?>
              <div class="d-inline-block align-middle">
                <div class="fw-semibold"><?= e($nombreUsuario) ?></div>
                <?php if ($sucursalNombre): ?><div class="text-secondary small"><i class="bi bi-shop me-1"></i><?= e($sucursalNombre) ?></div><?php endif; ?>
                <div class="text-secondary small"><i class="bi bi-person-badge me-1"></i><?= e($rolUsuario) ?></div>
              </div>
            </li>

            <?php if (count($misSucursales) > 1): ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li class="px-3 pb-2 text-secondary small"><i class="bi bi-arrow-repeat me-1"></i>Cambiar de sucursal</li>
              <?php if (count($misSucursales) === 2): ?>
                <?php $actual = (int)$idSucursal;
                $otra = ($misSucursales[0]['id'] == $actual) ? $misSucursales[1] : $misSucursales[0]; ?>
                <li class="px-3 pb-2">
                  <form action="cambiar_sucursal.php" method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="sucursal_id" value="<?= (int)$otra['id'] ?>">
                    <button class="btn btn-outline-light btn-sm w-100" type="submit">Cambiar a: <?= e($otra['nombre']) ?></button>
                  </form>
                </li>
              <?php else: ?>
                <li class="px-3 pb-3">
                  <form action="cambiar_sucursal.php" method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <select name="sucursal_id" class="form-select form-select-sm">
                      <?php foreach ($misSucursales as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($s['id'] === $idSucursal ? 'selected' : '') ?>><?= e($s['nombre']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-secondary btn-sm" type="submit">Cambiar</button>
                  </form>
                </li>
              <?php endif; ?>
            <?php endif; ?>

            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item" href="mi_expediente.php"><i class="bi bi-folder-person me-2"></i>Mi expediente</a></li>
            <li><a class="dropdown-item" href="documentos_historial.php"><i class="bi bi-files me-2"></i>Mis documentos</a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Salir</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<?php if (!empty($avatarUrl) ? false : true): /* nudge solo si no hay foto */ ?>
  <div id="toast-foto" class="toast align-items-center text-bg-light border-0 shadow"
    role="alert" aria-live="assertive" aria-atomic="true"
    style="position:fixed; right:1rem; bottom:1rem; z-index:1080; min-width:320px;">
    <div class="d-flex">
      <div class="toast-body">
        <div class="d-flex align-items-start">
          <div class="me-2" style="width:.8rem;height:.8rem;border-radius:50%;background:#0d6efd;position:relative;flex:0 0 auto;">
            <span style="content:'';position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(13,110,253,.5);animation:ring 1.8s ease-out infinite;"></span>
          </div>
          <div>
            <div class="fw-semibold mb-1">¡Dale personalidad a tu perfil!</div>
            <div class="text-muted small">Sube tu foto. Se verá en dashboards, celebraciones y reportes.</div>
            <div class="mt-2 d-flex gap-2">
              <a href="documentos_historial.php" class="btn btn-primary btn-sm">Subir foto</a>
              <button id="btn-foto-despues" type="button" class="btn btn-outline-secondary btn-sm">Después</button>
            </div>
          </div>
        </div>
      </div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>

  <script>
    (function() {
      var el = document.getElementById('toast-foto');
      if (el) {
        var t = new bootstrap.Toast(el, {
          autohide: false
        });
        t.show();
      }

      function posponer24h() {
        var d = new Date();
        d.setTime(d.getTime() + 24 * 60 * 60 * 1000);
        document.cookie = "foto_nudge_24h=1; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
      }
      document.getElementById('btn-foto-despues')?.addEventListener('click', function() {
        posponer24h();
        bootstrap.Toast.getInstance(el)?.hide();
      });
      el?.addEventListener('hidden.bs.toast', posponer24h);
    })();
  </script>
<?php endif; ?>