<?php
/**
 * db.php – Conexión MySQL (local / producción) + constantes de uploads (idempotente)
 */

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Detectar si corremos en local */
$hostHttp = $_SERVER['HTTP_HOST'] ?? '';
$isLocal  = ($hostHttp === 'localhost' || $hostHttp === '127.0.0.1' || strpos($hostHttp, 'localhost:') === 0);

/* Credenciales LOCAL (Laragon) */
$DB_LOCAL = [
  'host' => '127.0.0.1',
  'user' => 'root',
  'pass' => '',
  'name' => 'miplan',
];

/* Credenciales PRODUCCIÓN (Hostinger) */
$DB_PROD = [
  'host' => 'localhost',
  'user' => 'u790246665_management',
  'pass' => 'Gmunozm2024*',
  'name' => 'u790246665_luga_php',
];

$DB = $isLocal ? $DB_LOCAL : $DB_PROD;

/* Conexión (solo si no existe ya $conn) */
if (!isset($conn) || !($conn instanceof mysqli)) {
  try {
    $conn = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['name']);
    $conn->set_charset('utf8mb4');

    // === Alinear la zona horaria de MySQL con la de PHP (CDMX) ===
    if (!ini_get('date.timezone')) {
      date_default_timezone_set('America/Mexico_City');
    }
    $tz  = new DateTimeZone(date_default_timezone_get());
    $now = new DateTime('now', $tz);
    $off = $tz->getOffset($now);                 // segundos vs UTC
    $sign = ($off >= 0 ? '+' : '-');
    $hh   = str_pad(intval(abs($off)/3600), 2, '0', STR_PAD_LEFT);
    $mm   = str_pad(intval((abs($off)%3600)/60), 2, '0', STR_PAD_LEFT);
    $conn->query("SET time_zone = '{$sign}{$hh}:{$mm}'");  // p.ej. -06:00 / -05:00

  } catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('Error de conexión a base de datos.');
  }
}

/* Rutas base y carpetas de uploads (protegidas con !defined) */
$__here   = str_replace('\\','/', __DIR__);
$APP_ROOT = preg_match('~/includes$~', $__here) ? dirname($__here) : $__here;

if (!defined('APP_ROOT'))          define('APP_ROOT', $APP_ROOT);
if (!defined('UPLOADS_DIR'))       define('UPLOADS_DIR', APP_ROOT . '/uploads');
if (!defined('UPLOADS_USERS_DIR')) define('UPLOADS_USERS_DIR', UPLOADS_DIR . '/usuarios');
if (!defined('UPLOADS_DEPOS_DIR')) define('UPLOADS_DEPOS_DIR', UPLOADS_DIR . '/depositos');

/* Crear carpetas si no existen (permiso 0755) */
foreach ([UPLOADS_DIR, UPLOADS_USERS_DIR, UPLOADS_DEPOS_DIR] as $d) {
  if (!is_dir($d)) @mkdir($d, 0755, true);
}

/* Límites y tipos permitidos (usados en docs/comprobantes) */
if (!defined('DOCS_MAX_SIZE'))     define('DOCS_MAX_SIZE', 10 * 1024 * 1024); // 10MB
if (!defined('DOCS_ALLOWED_EXT'))  define('DOCS_ALLOWED_EXT', ['pdf','png','jpg','jpeg']);
if (!defined('DEPOS_ALLOWED_EXT')) define('DEPOS_ALLOWED_EXT', ['pdf','png','jpg','jpeg']);
