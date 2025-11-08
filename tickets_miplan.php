<?php
// miplan_tickets.php — MiPlan: lista sus tickets (vía API LUGA), modal de detalle y modal para crear
session_start();
require_once __DIR__.'/tickets_api_config.php';

// Permisos locales MiPlan (ajusta a tus roles)
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

// Navbar si existe
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

// CSRF simple para creación
if (empty($_SESSION['ticket_csrf_miplan'])) {
  $_SESSION['ticket_csrf_miplan'] = bin2hex(random_bytes(16));
}

// Filtros locales (la API ya filtra por origen=MiPlan a partir del token)
$since = $_GET['since'] ?? date('Y-m-01 00:00:00');
$q     = trim($_GET['q'] ?? '');

// Cargar tickets por API
$resp = api_get('/tickets.list.php', ['since'=>$since, 'q'=>$q]);
$tickets = [];
if (($resp['http'] ?? 0) === 200 && ($resp['json']['ok'] ?? false)) {
  $tickets = $resp['json']['tickets'] ?? [];
}

// Datos de sesión útiles (para crear)
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursalU = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = (string)($_SESSION['nombre'] ?? 'Usuario');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Tickets (MiPlan)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Tickets (MiPlan)</h1>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevo">➕ Nuevo ticket</button>
      <a class="btn btn-outline-secondary" href="?since=<?=h(date('Y-m-d 00:00:00'))?>">Hoy</a>
      <a class="btn btn-outline-secondary" href="miplan_tickets.php">Todos</a>
    </div>
  </div>

  <!-- Filtros simples -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input name="q" class="form-control" placeholder="Buscar por asunto o #ID" value="<?=h($q)?>">
    </div>
    <div class="col-md-6">
      <input name="since" type="datetime-local" class="form-control" value="<?=h(str_replace(' ','T',$since))?>" title="Desde (updated)">
    </div>
    <div class="col-12 d-grid d-md-flex justify-content-md-end mt-2">
      <button class="btn btn-secondary">Filtrar</button>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle m-0" id="tblTickets">
          <thead class="table-light">
            <tr>
              <th style="width:80px">#</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Prioridad</th>
              <th style="width:180px">Actualizado</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Sin resultados.</td></tr>
          <?php endif; ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><?=h($t['id'])?></td>
              <td><?=h($t['asunto'])?></td>
              <td><span class="badge bg-secondary"><?=h($t['estado'])?></span></td>
              <td><?=h($t['prioridad'])?></td>
              <td><?=h($t['updated_at'])?></td>
              <td>
                <button class="btn btn-sm btn-outline-primary btnAbrir" data-id="<?=h($t['id'])?>">Abrir</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-muted small mt-2">Mostrando <?=count($tickets)?> tickets · Desde: <?=h($since)?></div>
</div>

<!-- Modal contenedor: detalle (se carga por fetch) -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" id="modalDetalleContent"></div>
  </div>
</div>

<!-- Modal: nuevo ticket -->
<div class="modal fade" id="modalNuevo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="tickets_enviar.php" id="formNuevo" novalidate>
        <div class="modal-header">
          <h5 class="modal-title">Nuevo ticket</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?=h($_SESSION['ticket_csrf_miplan'])?>">
          <div class="mb-3">
            <label class="form-label">Asunto <span class="text-danger">*</span></label>
            <input name="asunto" class="form-control" maxlength="255" required placeholder="Ej. Fallo en impresora de mostrador">
            <div class="invalid-feedback">Escribe el asunto.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Mensaje <span class="text-danger">*</span></label>
            <textarea name="mensaje" class="form-control" rows="5" required placeholder="Describe el problema…"></textarea>
            <div class="invalid-feedback">Escribe el detalle del ticket.</div>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Prioridad</label>
              <select name="prioridad" class="form-select">
                <option value="media" selected>Media</option>
                <option value="baja">Baja</option>
                <option value="alta">Alta</option>
                <option value="critica">Crítica</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sucursal origen</label>
              <input name="sucursal_origen_id" class="form-control" value="<?=h((string)$idSucursalU)?>" required>
              <div class="form-text">Tu sucursal actual.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Usuario</label>
              <input class="form-control" value="<?=h($nombreUser)?>" disabled>
              <div class="form-text">ID: <?=h((string)$idUsuario)?></div>
            </div>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnEnviarNuevo" type="submit">Crear ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const formNuevo = document.getElementById('formNuevo');
  const btnNuevo  = document.getElementById('btnEnviarNuevo');
  if (formNuevo) {
    formNuevo.addEventListener('submit', function(e){
      if (!formNuevo.checkValidity()) { e.preventDefault(); e.stopPropagation(); formNuevo.classList.add('was-validated'); return; }
      btnNuevo.disabled = true; btnNuevo.textContent = 'Guardando...';
    });
  }

  document.querySelectorAll('.btnAbrir').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = btn.getAttribute('data-id');
      const content = document.getElementById('modalDetalleContent');
      const modalEl = document.getElementById('modalDetalle');
      const modal   = new bootstrap.Modal(modalEl);

      content.innerHTML =
        '<div class="modal-header"><h5 class="modal-title">Cargando...</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
        '<div class="modal-body"><div class="text-center text-muted py-5">Cargando ticket #' + id + '...</div></div>';
      modal.show();

      try {
        const res  = await fetch('tickets_modal_miplan.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' });
        const html = await res.text();
        content.innerHTML = html;
        wireModalForms();
      } catch (e) {
        content.innerHTML =
          '<div class="modal-header"><h5 class="modal-title">Error</h5>' +
          '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
          '<div class="modal-body"><div class="alert alert-danger">No se pudo cargar el detalle.</div></div>';
      }
    });
  });

  // Enchufa eventos dentro del modal cargado
  window.wireModalForms = function(){
    const fr = document.getElementById('formResponder');
    if (fr) {
      const btn = fr.querySelector('button[type="submit"]');
      fr.addEventListener('submit', ()=>{
        if (btn){ btn.disabled = true; btn.textContent = 'Enviando...'; }
      });
    }
  };

  // Auto-refresh de la tabla cada 90s si no hay modal abierto
  setInterval(()=>{
    const modal = document.getElementById('modalDetalle');
    if (!(modal && modal.classList.contains('show'))) location.reload();
  }, 90000);
})();
</script>
</body>
</html>
