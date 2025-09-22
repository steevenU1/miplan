<?php
/**
 * env.app.php — Bandera global para habilitar/deshabilitar la captura.
 * false = BLOQUEADA (nadie puede capturar)
 * true  = HABILITADA
 *
 * Puedes dejar listas excepciones (usuarios/roles) si ocupas hacer pruebas.
 */

if (!defined('MODO_CAPTURA')) {
  // Para producción la próxima semana:
  define('MODO_CAPTURA', true);
  // En local podrías poner true si quieres seguir probando capturas ahí.
  // define('MODO_CAPTURA', (($_SERVER['HTTP_HOST'] ?? '') === 'localhost'));
}

/** IDs de usuario que SÍ pueden capturar aunque esté bloqueado (opcional) */
if (!defined('CAPTURE_BYPASS_USERS')) {
  define('CAPTURE_BYPASS_USERS', [
    // 1, 2, 3  // <-- agrega aquí tu id_usuario si necesitas probar
  ]);
}

/** Roles que SÍ pueden capturar aunque esté bloqueado (opcional) */
if (!defined('CAPTURE_BYPASS_ROLES')) {
  define('CAPTURE_BYPASS_ROLES', [
    // 'Admin'   // <-- si quieres permitir a Admin, descomenta
  ]);
}
