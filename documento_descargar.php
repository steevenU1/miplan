<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No autenticado'); }

/* ==== Include con fallback ==== */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) {
  require_once __DIR__ . '/includes/docs_lib.php';
} else {
  require_once __DIR__ . '/docs_lib.php';
}

/* ==== Contexto ==== */
$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';

/* ==== Parámetros ==== */
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($doc_id <= 0) { http_response_code(400); exit('ID inválido'); }

/* ==== Buscar doc ==== */
$doc = get_doc_record($conn, $doc_id);
if (!$doc) { http_response_code(404); exit('No encontrado'); }

/* ==== Permisos básicos ==== 
   - Propietario puede ver
   - O rol autorizado por doc_tipo_roles
*/
$permitido = ($doc['usuario_id'] === $mi_id) || user_can_view_doc_type($conn, $mi_rol, (int)$doc['doc_tipo_id']);
if (!$permitido) { http_response_code(403); exit('Sin permiso'); }

/* ==== Servir archivo ==== */
$rel = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $doc['ruta']);
$abs = rtrim(DOCS_BASE_PATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($rel, '/\\');

$inline = empty($_GET['download']); // si agregas ?download=1, fuerza descarga
output_file_secure($abs, $doc['mime'] ?: 'application/octet-stream', $doc['nombre_original'] ?: 'documento', $inline);
