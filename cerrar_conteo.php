<?php
// cerrar_conteo.php — Cierra el conteo
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
$id_conteo = (int)($data['id_conteo'] ?? 0);
if ($id_conteo<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id_conteo requerido']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// Validar cabecera y permisos
$stmt = $conn->prepare("SELECT id, id_sucursal, estado FROM conteos_ciclicos WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id_conteo);
$stmt->execute();
$cab = $stmt->get_result()->fetch_assoc();
if (!$cab) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Conteo no encontrado']); exit; }
if ($cab['estado']==='Cerrado'){ echo json_encode(['ok'=>true,'msg'=>'Ya estaba cerrado']); exit; }
if ($ROL==='Gerente' && (int)$cab['id_sucursal'] !== $ID_SUCURSAL){
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'No puedes cerrar otra sucursal']); exit;
}

// Cerrar
$stmt = $conn->prepare("UPDATE conteos_ciclicos SET estado='Cerrado', cerrado_por=?, cerrado_at=NOW() WHERE id=? LIMIT 1");
$stmt->bind_param('ii', $ID_USUARIO, $id_conteo);
$stmt->execute();

echo json_encode(['ok'=>true]);
