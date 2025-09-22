<?php
// expediente_subir_foto.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require 'db.php';

$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';

$usuario_id = (int)($_POST['usuario_id'] ?? 0);
$return_to  = $_POST['return_to'] ?? ('expediente_documentos.php?usuario_id='.$usuario_id);

function puede_subir(string $rol, int $mi_id, int $usuario_id): bool {
  return in_array($rol, ['Admin','Gerente'], true) || ($mi_id === $usuario_id);
}

function redirect_ok($url){ header('Location: '.$url.(str_contains($url,'?')?'&':'?').'ok_foto=1'); exit(); }
function redirect_err($url,$msg){ header('Location: '.$url.(str_contains($url,'?')?'&':'?').'err_foto='.rawurlencode($msg)); exit(); }

if ($usuario_id <= 0) redirect_err($return_to, 'Usuario inválido.');
if (!puede_subir($mi_rol, $mi_id, $usuario_id)) redirect_err($return_to, 'No autorizado.');
if (empty($_FILES['foto']) || !is_uploaded_file($_FILES['foto']['tmp_name'])) redirect_err($return_to, 'No se recibió archivo.');

$dir = __DIR__ . '/uploads/fotos_usuarios';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) redirect_err($return_to, 'No se pudo crear carpeta de fotos.');

$mimeOk = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['foto']['tmp_name']);
finfo_close($finfo);
if (!isset($mimeOk[$mime])) redirect_err($return_to, 'Formato no permitido. Usa JPG, PNG o WebP.');

$maxBytes = 5 * 1024 * 1024;
if ($_FILES['foto']['size'] > $maxBytes) redirect_err($return_to, 'La foto supera 5MB.');

$ext   = $mimeOk[$mime];
$fname = 'u'.$usuario_id.'_'.time().'.'.$ext;
$dest  = $dir.'/'.$fname;

if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) redirect_err($return_to, 'No se pudo guardar el archivo.');

$conn->begin_transaction();

try {
  // Foto anterior
  $prev = null; $rowId = null;
  $stmt = $conn->prepare("SELECT id, foto FROM usuarios_expediente WHERE usuario_id=? FOR UPDATE");
  $stmt->bind_param("i", $usuario_id);
  $stmt->execute();
  $stmt->bind_result($rowId, $prev);
  $stmt->fetch();
  $stmt->close();

  $rutaRel = 'uploads/fotos_usuarios/'.$fname;

  if ($rowId) {
    $stmt = $conn->prepare("UPDATE usuarios_expediente SET foto=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->bind_param("si", $rutaRel, $rowId);
    $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("INSERT INTO usuarios_expediente (usuario_id, foto, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("is", $usuario_id, $rutaRel);
    $stmt->execute();
    $stmt->close();
  }

  $conn->commit();

  // Borrar anterior si existía (fuera de la tx)
  if ($prev) {
    $old = __DIR__ . '/' . $prev;
    if (is_file($old)) @unlink($old);
  }

  redirect_ok($return_to);

} catch (Throwable $e) {
  $conn->rollback();
  // Limpia archivo nuevo si falló la tx
  @unlink($dest);
  redirect_err($return_to, 'Error al guardar: '.$e->getMessage());
}
