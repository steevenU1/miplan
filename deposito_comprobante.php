<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Gerente','Admin'])) {
  http_response_code(403); exit('Sin permiso');
}
include 'db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { http_response_code(400); exit('ID inválido'); }

$stmt = $conn->prepare("SELECT id_sucursal, comprobante_archivo, comprobante_nombre, comprobante_mime, comprobante_size FROM depositos_sucursal WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$dep = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dep || empty($dep['comprobante_archivo'])) { http_response_code(404); exit('No encontrado'); }

$esAdmin = ($_SESSION['rol'] === 'Admin');
if (!$esAdmin && (int)$dep['id_sucursal'] !== (int)$_SESSION['id_sucursal']) {
  http_response_code(403); exit('Sin permiso');
}

$path = __DIR__ . '/' . $dep['comprobante_archivo'];
if (!is_file($path)) { http_response_code(404); exit('Archivo no disponible'); }

$mime = $dep['comprobante_mime'] ?: 'application/octet-stream';
$fname = $dep['comprobante_nombre'] ?: basename($path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="'. rawurlencode($fname) .'"');
readfile($path);
