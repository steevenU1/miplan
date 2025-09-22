<?php
/**
 * candado_captura.php — Guard de captura
 * Úsalo en scripts que HACEN cambios (INSERT/UPDATE/DELETE).
 * Ej.: procesar_venta.php, compras_guardar.php, traspasos_generar.php, etc.
 */

require_once __DIR__ . '/env.app.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/**
 * ¿La captura está bloqueada para el usuario actual?
 * Considera bypass por usuario/rol si los definiste en env.app.php
 */
function captura_bloqueada_para_usuario(): bool {
  if (!defined('MODO_CAPTURA')) return false;      // Sin bandera = no bloquear
  if (MODO_CAPTURA === true) return false;         // Habilitada global

  $id  = (int)($_SESSION['id_usuario'] ?? 0);
  $rol = (string)($_SESSION['rol'] ?? '');

  if (defined('CAPTURE_BYPASS_USERS') && in_array($id, CAPTURE_BYPASS_USERS, true)) return false;
  if (defined('CAPTURE_BYPASS_ROLES') && in_array($rol, CAPTURE_BYPASS_ROLES, true)) return false;

  return true; // Bloqueada global y sin bypass
}

/**
 * Llama esto al inicio de tus scripts de escritura.
 * Por defecto solo bloquea si el request es POST (típico en guardar/procesar).
 * Si quieres bloquear también GET con acciones, pasa 'all'.
 */
function abortar_si_captura_bloqueada(string $modo = 'post_only'): void {
  if (!captura_bloqueada_para_usuario()) return;

  $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

  $esMutacion =
    ($modo === 'all')
      ? true
      : ($metodo === 'POST');

  if ($esMutacion) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    $usuario  = htmlspecialchars($_SESSION['nombre'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
    $rol      = htmlspecialchars($_SESSION['rol'] ?? '', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
      <div style="max-width:720px;margin:40px auto;padding:20px;border:1px solid #ddd;border-radius:12px;font-family:system-ui,Segoe UI,Roboto,Arial">
        <h2 style="margin-top:0">🚫 Captura deshabilitada temporalmente</h2>
        <p>Hola <strong>{$usuario}</strong> <small>({$rol})</small>. La plataforma está en periodo de arranque y <strong>no se permiten altas/ediciones/bajas</strong> por ahora.</p>
        <p>Consulta y reportes siguen disponibles con normalidad.</p>
        <p style="opacity:.7">Si necesitas permiso de prueba, contacta a un administrador.</p>
        <a href="dashboard_unificado.php" style="display:inline-block;margin-top:10px;padding:10px 14px;border:1px solid #aaa;border-radius:8px;text-decoration:none">Volver al dashboard</a>
      </div>
    HTML;
    exit;
  }
}
