<?php
// api_conteo_guardar.php — Guarda una fila del conteo (resultado/motivo/comentario)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit;
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = ['Gerente','GerenteZona','Admin'];
if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'JSON inválido']); exit; }

$id_conteo  = (int)($data['id_conteo'] ?? 0);
$id_det     = (int)($data['id_det'] ?? 0);
$resultado  = trim((string)($data['resultado'] ?? 'No Verificable'));
$id_motivo  = ($data['id_motivo'] !== '' ? (int)$data['id_motivo'] : null);
$comentario = trim((string)($data['comentario'] ?? ''));

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// Validar cabecera y permisos
$stmt = $conn->prepare("SELECT id, id_sucursal, estado FROM conteos_ciclicos WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id_conteo);
$stmt->execute();
$cab = $stmt->get_result()->fetch_assoc();
if (!$cab) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Conteo no encontrado']); exit; }
if ($cab['estado']==='Cerrado'){ http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Conteo cerrado']); exit; }
if ($ROL==='Gerente' && (int)$cab['id_sucursal'] !== $ID_SUCURSAL){
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'No puedes editar otra sucursal']); exit;
}

// Validar que el detalle pertenezca al conteo
$stmt = $conn->prepare("SELECT id FROM conteos_ciclicos_det WHERE id=? AND id_conteo=? LIMIT 1");
$stmt->bind_param('ii', $id_det, $id_conteo);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()){
  http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Fila no pertenece al conteo']); exit;
}

// Reglas: si Ausente → motivo y comentario (>=5 chars)
if ($resultado === 'Ausente') {
  if (!$id_motivo)                { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Motivo requerido para Ausente']); exit; }
  if (mb_strlen($comentario) < 5) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Comentario mínimo 5 caracteres']); exit; }
} else {
  $id_motivo = null; // limpiar si no es Ausente
}

// Guardar
$stmt = $conn->prepare("UPDATE conteos_ciclicos_det
                        SET resultado=?, id_motivo=?, comentario=?, capturado_por=?, capturado_at=NOW()
                        WHERE id=? LIMIT 1");
$stmt->bind_param('sisii', $resultado, $id_motivo, $comentario, $ID_USUARIO, $id_det);
$stmt->execute();

echo json_encode(['ok'=>true]);
