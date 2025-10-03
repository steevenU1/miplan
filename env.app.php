<?php
/**
 * env.app.php — Configuración global producción
 * MODO_CAPTURA = false → bloquea capturas
 * MODO_CAPTURA = true  → habilita capturas
 */

if (!defined('MODO_CAPTURA')) {
  define('MODO_CAPTURA', true); // en prod, normalmente true
}

/** IDs de usuario que SÍ pueden capturar aunque esté bloqueado (opcional) */
if (!defined('CAPTURE_BYPASS_USERS')) {
  define('CAPTURE_BYPASS_USERS', [
    // 1, 2, 3
  ]);
}

/** Roles que SÍ pueden capturar aunque esté bloqueado (opcional) */
if (!defined('CAPTURE_BYPASS_ROLES')) {
  define('CAPTURE_BYPASS_ROLES', [
    // 'Admin'
  ]);
}

/**
 * Configuración de uploads en PRODUCCIÓN
 * Ajusta BASE_DIR a la ruta real del servidor (ejemplo Hostinger).
 */
if (!defined('UPLOADS')) {
  define('UPLOADS', [
    'BASE_DIR' => __DIR__ . '/../uploads',  // ruta absoluta (carpeta uploads dentro del proyecto)
    'BASE_URL' => '/uploads',               // ruta pública
    'VENTAS' => [
      'IDENTIFICACIONES_DIR' => 'ventas/identificaciones',
      'CONTRATOS_DIR'        => 'ventas/contratos',
      'THUMBS_DIR'           => 'ventas/thumbs',
    ],
    'MAX_SIZE_BYTES' => 5 * 1024 * 1024, // 5 MB
    'ALLOW_PDF_IN_CONTRACT' => true,
  ]);
}
