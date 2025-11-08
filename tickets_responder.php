<?php
// tickets_responder.php — MiPlan responde por API (solo a sus tickets)
session_start();
require_once __DIR__.'/tickets_api_config.php';

if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit(''); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: miplan_tickets.php"); exit(); }

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$mensaje  = trim($_POST['mensaje'] ?? '');
$autorId  = (int)($_SESSION['id_usuario'] ?? 0);

if ($ticketId<=0 || $mensaje==='') { header("Location: miplan_tickets.php"); exit(); }

$resp = api_post_json('/tickets.reply.php', [
  'ticket_id' => $ticketId,
  'autor_id'  => $autorId,
  'mensaje'   => $mensaje,
]);

// No redirigimos a un show; el modal se cierra en la UI y la lista se refresca sola con F5 o cron suave.
// Si quieres feedback, guarda flash y regresa a la lista:
$_SESSION['flash_ok'] = ($resp['json']['ok'] ?? false) ? 'Respuesta enviada.' : 'No se pudo responder.';
header("Location: miplan_tickets.php");
