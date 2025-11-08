<?php
// tickets_enviar.php — MiPlan crea ticket en LUGA por API (diagnóstico + redirección correcta)
session_start();
require_once __DIR__.'/tickets_api_config.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: tickets_miplan.php"); exit(); }

// --- CSRF ---
$csrf = $_POST['csrf'] ?? '';
if ($csrf === '' || $csrf !== ($_SESSION['ticket_csrf_miplan'] ?? '')) {
  $_SESSION['flash_err'] = 'CSRF inválido.';
  header("Location: tickets_miplan.php");
  exit();
}

// --- Inputs ---
$asunto  = trim($_POST['asunto']  ?? '');
$mensaje = trim($_POST['mensaje'] ?? '');
$prior   = $_POST['prioridad']    ?? 'media';
$sucId   = (int)($_POST['sucursal_origen_id'] ?? ($_SESSION['id_sucursal'] ?? 0));
$autorId = (int)($_SESSION['id_usuario'] ?? 0);

if ($asunto==='' || $mensaje==='' || $sucId<=0) {
  $_SESSION['flash_err'] = 'Completa los campos requeridos.';
  header("Location: tickets_miplan.php");
  exit();
}

// --- Llamada API ---
$payload = [
  'asunto' => $asunto,
  'mensaje'=> $mensaje,
  'prioridad'=> $prior,
  'sucursal_origen_id'=> $sucId,
  'autor_id'=> $autorId,
];

$resp = api_post_json('/tickets.create.php', $payload);

// --- Diagnóstico extendido ---
$http = (int)($resp['http'] ?? 0);
$ok   = (bool)($resp['json']['ok'] ?? false);
$raw  = (string)($resp['raw'] ?? '');
$err  = (string)($resp['err'] ?? '');
$url  = (string)($resp['url'] ?? '');
$json = $resp['json'] ?? [];

if ($http === 200 && $ok) {
  $id = $json['id'] ?? ($json['ticket_id'] ?? '');
  $_SESSION['flash_ok'] = '✅ Ticket creado (#'.htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8').').';
} else {
  $msg = "❌ No se pudo crear el ticket.\n";
  $msg .= "HTTP: ".$http."\n";
  $msg .= "URL: ".$url."\n";
  $msg .= "cURL: ".($err ?: 'n/a')."\n";
  if ($raw) {
    $msg .= "RAW: ".substr($raw, 0, 500)."\n";
  }
  if (!empty($json)) {
    $msg .= "JSON: ".json_encode($json, JSON_UNESCAPED_UNICODE)."\n";
  }
  $_SESSION['flash_err'] = nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
}

header("Location: tickets_miplan.php");
