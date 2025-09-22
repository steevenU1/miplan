<?php
// documento_borrar.php — LUGA (usuario_documentos) con redirect a prueba de balas
ob_start(); // evita "headers already sent" por espacios/BOM en includes
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

$rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($rol, ['Admin','Super'], true)) {
  header("Location: documentos_historial.php?err_doc=Solo+Admin+o+Super+puede+borrar+documentos");
  exit;
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===== Helpers ===== */
if (!function_exists('str_starts_with')) {
  function str_starts_with($h,$n){ return $n !== '' && strncmp($h,$n,strlen($n))===0; }
}
function add_query_before_fragment(string $url, array $params): string {
  $frag = '';
  if (false !== ($p = strpos($url, '#'))) { $frag = substr($url, $p); $url = substr($url, 0, $p); }
  $sep = (strpos($url, '?') !== false) ? '&' : '?';
  return $url . $sep . http_build_query($params) . $frag;
}
function to_abs_path(?string $ruta): string {
  $ruta = trim((string)$ruta);
  if ($ruta === '') return '';
  if (str_starts_with($ruta,'/') || preg_match('~^[A-Z]:\\\\~i',$ruta)) return $ruta; // ya absoluta
  return __DIR__ . '/' . ltrim($ruta,'/');
}
function redirect_and_exit(string $url){
  // 1) Header estándar
  header("Location: $url");
  // 2) Fallback JS (por si headers ya están enviados)
  echo "<script>location.href=".json_encode($url).";</script>";
  // 3) Fallback meta refresh
  echo '<meta http-equiv="refresh" content="0;url='.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">';
  exit;
}

/* ===== Params ===== */
$doc_id    = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
$return_to = $_POST['return_to'] ?? 'documentos_historial.php';
if ($doc_id <= 0) {
  redirect_and_exit(add_query_before_fragment($return_to, ['err_doc'=>'Documento+invalido']));
}

try {
  // 1) Info del documento en usuario_documentos
  $stmt = $conn->prepare("SELECT id, usuario_id, doc_tipo_id, ruta FROM usuario_documentos WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $doc_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if (!$row = $res->fetch_assoc()) {
    $stmt->close();
    redirect_and_exit(add_query_before_fragment($return_to, ['err_doc'=>'No+se+encontro+el+documento']));
  }
  $stmt->close();

  $usuario_id = (int)$row['usuario_id'];
  $doc_tipo_id = (int)$row['doc_tipo_id'];

  // 2) Reunir todas las versiones de ese tipo para ese usuario (para borrar archivos)
  $stmt = $conn->prepare("SELECT id, ruta FROM usuario_documentos WHERE usuario_id=? AND doc_tipo_id=?");
  $stmt->bind_param('ii', $usuario_id, $doc_tipo_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $paths = [];
  while ($r = $res->fetch_assoc()) { $paths[] = to_abs_path($r['ruta'] ?? ''); }
  $stmt->close();

  // 3) Borrar en BD (todas las versiones del tipo)
  $conn->begin_transaction();
  $stmt = $conn->prepare("DELETE FROM usuario_documentos WHERE usuario_id=? AND doc_tipo_id=?");
  $stmt->bind_param('ii', $usuario_id, $doc_tipo_id);
  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  if ($affected <= 0) {
    $conn->rollback();
    redirect_and_exit(add_query_before_fragment($return_to, ['err_doc'=>'No+se+pudo+borrar+(0+filas)']));
  }
  $conn->commit();

  // 4) Borrar archivos físicos (fuera de la transacción)
  foreach ($paths as $p) { if ($p && file_exists($p)) { @unlink($p); } }

  // 5) Redirect OK (se respeta el #fragment al final)
  redirect_and_exit(add_query_before_fragment($return_to, ['ok_doc'=>1]));

} catch (Throwable $e) {
  if ($conn->errno) { @$conn->rollback(); }
  $msg = urlencode('Error al borrar: '.$e->getMessage());
  redirect_and_exit(add_query_before_fragment($return_to, ['err_doc'=>$msg]));
}
