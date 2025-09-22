<?php
// cambiar_sucursal.php — actualiza usuarios.id_sucursal, cierra sesión y redirige

session_start();
ob_start();

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit;
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ========== Helpers ========== */
function require_csrf(): void {
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400);
    exit('CSRF inválido');
  }
}

function usuario_puede_sucursal(mysqli $conn, int $usuario_id, int $sucursal_id): bool {
  $stmt = $conn->prepare("SELECT 1 FROM usuario_sucursales WHERE usuario_id=? AND sucursal_id=? LIMIT 1");
  $stmt->bind_param('ii', $usuario_id, $sucursal_id);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

function redirect_safe(string $url): void {
  header("Location: $url");
  echo "<script>location.href=".json_encode($url).";</script>";
  echo '<meta http-equiv="refresh" content="0;url='.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">';
  exit;
}

/* ========== Params ========== */
$mi_id            = (int)($_SESSION['id_usuario'] ?? 0);
$nueva_sucursal_id = isset($_POST['sucursal_id']) ? (int)$_POST['sucursal_id'] : 0;
$redirect_ok       = 'index.php'; // tras logout caerá al login

require_csrf();

if ($mi_id <= 0 || $nueva_sucursal_id <= 0) {
  redirect_safe($redirect_ok.'?err=req');
}

/* Validar que esa sucursal esté permitida a ese usuario */
if (!usuario_puede_sucursal($conn, $mi_id, $nueva_sucursal_id)) {
  redirect_safe($redirect_ok.'?err=sucursal_no_autorizada');
}

try {
  $conn->begin_transaction();

  // *** IMPORTANTE: el campo correcto en tu tabla es id_sucursal ***
  $stmt = $conn->prepare("UPDATE usuarios SET id_sucursal=? WHERE id=? LIMIT 1");
  $stmt->bind_param('ii', $nueva_sucursal_id, $mi_id);
  $stmt->execute();
  // $affected = $stmt->affected_rows;  // si quieres inspeccionar
  $stmt->close();

  $conn->commit();

  // Cerrar sesión y forzar re-login
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();

  redirect_safe($redirect_ok.'?msg=cambio_sucursal_ok');

} catch (Throwable $e) {
  if ($conn->errno) { @$conn->rollback(); }
  redirect_safe($redirect_ok.'?err=cambio_sucursal');
}
