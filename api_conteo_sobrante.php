<?php
// api_conteo_sobrante.php — Agrega una fila "Sobrante" por IMEI
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

$id_conteo = (int)($_POST['id_conteo'] ?? 0);
$imei      = trim((string)($_POST['imei'] ?? ''));

if ($id_conteo<=0 || $imei===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Parámetros incompletos']); exit; }

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

// Duplicado dentro del conteo
$stmt = $conn->prepare("SELECT id FROM conteos_ciclicos_det WHERE id_conteo=? AND imei1=? LIMIT 1");
$stmt->bind_param('is', $id_conteo, $imei);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()){
  http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Ese IMEI ya está en el conteo']); exit;
}

// Buscar producto+inventario por IMEI (ajusta nombres de tablas/campos si difieren)
$stmt = $conn->prepare("SELECT p.id AS id_producto, p.marca, p.modelo, p.color, p.imei1, p.imei2,
                               i.id AS id_inventario, i.id_sucursal, i.estatus
                        FROM productos p
                        LEFT JOIN inventario i ON i.id_producto = p.id
                        WHERE p.imei1=? OR p.imei2=? LIMIT 1");
$stmt->bind_param('ss', $imei, $imei);
$stmt->execute();
$prod = $stmt->get_result()->fetch_assoc();

$marca=''; $modelo=''; $color=''; $estatus='No registrado'; $idInv=null; $imei1=$imei; $imei2=null;
if ($prod){
  $marca   = (string)$prod['marca'];
  $modelo  = (string)$prod['modelo'];
  $color   = (string)$prod['color'];
  $imei1   = (string)($prod['imei1'] ?: $imei);
  $imei2   = (string)($prod['imei2'] ?: '');
  $estatus = (string)($prod['estatus'] ?: 'Desconocido');
  $idInv   = $prod['id_inventario'] !== null ? (int)$prod['id_inventario'] : null;
}

// Insertar fila sobrante
$stmt = $conn->prepare("INSERT INTO conteos_ciclicos_det
  (id_conteo, id_inventario, imei1, imei2, marca, modelo, color, estatus_snapshot, resultado, capturado_por, capturado_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Sobrante', ?, NOW())");
$stmt->bind_param('iissssssii', $id_conteo, $idInv, $imei1, $imei2, $marca, $modelo, $color, $estatus, $ID_USUARIO);
$stmt->execute();
$newId = $conn->insert_id;

echo json_encode([
  'ok'=>true,
  'row'=>[
    'id'=>$newId,
    'imei1'=>$imei1,
    'imei2'=>$imei2,
    'marca'=>$marca,
    'modelo'=>$modelo,
    'color'=>$color,
    'estatus_snapshot'=>$estatus
  ]
]);
