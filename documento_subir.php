<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No autenticado'); }

/* Include con fallback */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) require_once __DIR__ . '/includes/docs_lib.php';
else require_once __DIR__ . '/docs_lib.php';

$mi_id   = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol  = $_SESSION['rol'] ?? 'Ejecutivo';

$usuario_id  = (int)($_POST['usuario_id'] ?? 0);
$doc_tipo_id = (int)($_POST['doc_tipo_id'] ?? 0);

/* A dónde regresar (por defecto, a mi_expediente con ancla #docs) */
$rt = trim($_POST['return_to'] ?? 'mi_expediente.php#docs');
if ($rt === '') $rt = 'mi_expediente.php#docs';

/* Helpers para armar la URL con params + ancla */
function build_redirect(string $baseWithHash, array $params): string {
  $hash = '';
  $pos  = strpos($baseWithHash, '#');
  if ($pos !== false) { $hash = substr($baseWithHash, $pos); $base = substr($baseWithHash, 0, $pos); }
  else { $base = $baseWithHash; }
  $sep = (strpos($base, '?') !== false) ? '&' : '?';
  return $base . $sep . http_build_query($params) . $hash;
}

if ($usuario_id<=0 || $doc_tipo_id<=0 || !isset($_FILES['archivo'])) {
  header('Location: ' . build_redirect($rt, ['err_doc' => 'Parámetros incompletos']));
  exit;
}

/* Permisos básicos: Admin/Gerente o dueño del expediente */
$puede_subir = in_array($mi_rol, ['Admin','Gerente'], true) || ($usuario_id === $mi_id);
if (!$puede_subir) {
  header('Location: ' . build_redirect($rt, ['err_doc' => 'Sin permiso para subir']));
  exit;
}

/* Guardar */
[$ok,$err] = save_user_doc($conn, $usuario_id, $doc_tipo_id, $mi_id, $_FILES['archivo']);

if ($ok) {
  header('Location: ' . build_redirect($rt, ['ok_doc' => 1]));
} else {
  header('Location: ' . build_redirect($rt, ['err_doc' => $err ?: 'No se pudo subir']));
}
exit;
