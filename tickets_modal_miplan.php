<?php
// tickets_modal_miplan.php — Render del modal: lee ticket por API y muestra para responder (MiPlan solo sus tickets)
session_start();
require_once __DIR__.'/tickets_api_config.php';

if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit(''); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ ?>
<div class="modal-header">
  <h5 class="modal-title">ID inválido</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body"><div class="alert alert-danger">El ID proporcionado no es válido.</div></div>
<?php exit; }

// Carga ticket por API (filtra por origen=MiPlan vía token)
$resp = api_get('/tickets.get.php', ['id'=>$id]);
if (($resp['http'] ?? 0)!==200 || !($resp['json']['ok'] ?? false)){
?>
<div class="modal-header">
  <h5 class="modal-title">No disponible</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body"><div class="alert alert-warning">No se pudo cargar el ticket o no pertenece a MiPlan.</div></div>
<?php exit; }

$t   = $resp['json']['ticket']   ?? [];
$mens= $resp['json']['mensajes'] ?? [];
?>
<div class="modal-header">
  <h5 class="modal-title">Ticket #<?=h($t['id'] ?? '')?></h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
  <div class="d-flex justify-content-between align-items-start mb-2">
    <div>
      <div class="fs-5 fw-semibold"><?=h($t['asunto'] ?? '')?></div>
      <div class="small text-muted">
        Prioridad: <strong><?=h($t['prioridad'] ?? '')?></strong> ·
        Estado: <strong><?=h($t['estado'] ?? '')?></strong> ·
        Actualizado: <?=h($t['updated_at'] ?? '')?>
      </div>
    </div>
  </div>

  <div class="border rounded p-2 bg-light" style="max-height:45vh; overflow:auto">
    <?php if (!$mens): ?>
      <div class="text-muted">Sin mensajes.</div>
    <?php else: foreach ($mens as $m): ?>
      <div class="mb-3">
        <div class="small text-muted">
          <?=h($m['autor_sistema'])?> • <?=h($m['created_at'])?>
          <?php if(!empty($m['autor_id'])):?> • Usuario ID: <?=h($m['autor_id'])?><?php endif;?>
        </div>
        <div><?=nl2br(h($m['cuerpo']))?></div>
      </div>
      <hr class="my-1">
    <?php endforeach; endif; ?>
  </div>

  <form id="formResponder" class="mt-3" method="post" action="tickets_responder.php">
    <input type="hidden" name="ticket_id" value="<?=h((string)($t['id'] ?? 0))?>">
    <div class="mb-2">
      <label class="form-label">Responder</label>
      <textarea name="mensaje" class="form-control" rows="3" required></textarea>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary">Enviar</button>
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </div>
  </form>
</div>
<div class="modal-footer">
  <div class="text-muted small">#<?=h($t['id'] ?? '')?> · Última actualización: <?=h($t['updated_at'] ?? '')?></div>
</div>
