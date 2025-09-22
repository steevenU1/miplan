<?php
// payjoy_tc_nueva.php — Captura de TC PayJoy (comisión fija $100, móvil first)
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php'; // navbar global
require_once __DIR__ . '/config_features.php'; // 👈 feature flags

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Flag efectivo: abierto o, si está cerrado, permitir preview a Admin
$flagOpen = PAYJOY_TC_CAPTURE_OPEN || ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW);

// Banner cuando está apagado
$bannerMsg = PAYJOY_TC_CAPTURE_OPEN
  ? null
  : '⚠️ Aún no está disponible para captura. Esta pantalla es informativa; cuando se habilite podrás guardar.';

// Sucursales para selector (solo roles de gestión)
$sucursales = [];
if (in_array($ROL, ['Admin','Gerente','Gerente General','GerenteZona','GerenteSucursal'], true)) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  while ($r = $rs->fetch_assoc()) { $sucursales[] = $r; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva venta TC PayJoy</title>
<meta name="viewport" content="width=device-width, initial-scale=1"> <!-- clave en móvil -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --card-radius: 18px;
  }
  body { background:#f7f8fa; }
  .page-wrap{ padding: 16px; padding-bottom: 100px; } /* espacio para bottom bar en móvil */
  .card-custom{
    border:none; border-radius: var(--card-radius);
    box-shadow: 0 8px 20px rgba(0,0,0,.06);
    background:#fff;
  }
  .section-title{ font-weight:700; letter-spacing:.2px; }
  .form-label{ font-weight:600; }
  .help-text{ font-size:.9rem; color:#6c757d; }

  /* Inputs cómodos al tacto */
  .form-control, .form-select, .btn{
    border-radius:12px;
    padding:.8rem .95rem;
  }

  /* Barra de acciones fija para móvil */
  .action-bar{
    position: fixed; left:0; right:0; bottom:0;
    background:#ffffff; border-top:1px solid rgba(0,0,0,.08);
    padding:10px 16px; z-index:1030;
    box-shadow: 0 -6px 18px rgba(0,0,0,.06);
  }
  .action-bar .btn{ border-radius: 14px; padding:.9rem 1rem; font-weight:600; }

  /* Desktop: la barra baja se integra normal */
  @media (min-width: 992px){
    .page-wrap{ padding-bottom: 24px; }
    .action-bar{ position: static; box-shadow:none; border-top:none; margin-top: 8px; }
  }

  /* Encabezado compacto */
  .header-chip{
    display:inline-flex; gap:.5rem; align-items:center;
    background:#eef5ff; color:#0a58ca; padding:.4rem .75rem; border-radius:999px;
    font-size:.9rem; font-weight:600;
  }

  /* Estado de error bonito */
  .is-invalid + .invalid-feedback{ display:block; }
</style>
</head>
<body>
<div class="page-wrap container">
  <div class="mb-3">
    <span class="header-chip">💳 PayJoy · Tarjeta de crédito</span>
  </div>

  <?php if ($bannerMsg): ?>
    <div class="alert <?= $isAdminLike ? 'alert-warning' : 'alert-secondary' ?> mb-3">
      <?= htmlspecialchars($bannerMsg) ?>
      <?php if ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW): ?>
        <div class="small text-muted">Vista para Administrador habilitada por <code>PAYJOY_TC_ADMIN_PREVIEW</code>.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card card-custom p-3 p-md-4">
    <h3 class="section-title mb-2">Nueva venta</h3>

    <?php if (isset($_GET['err'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <form id="formPayjoy" method="post" action="<?= $flagOpen ? 'payjoy_tc_guardar.php' : '#' ?>" class="row g-3 needs-validation" novalidate>
      <!-- 🔒 Deshabilita todo el formulario cuando está apagado -->
      <fieldset <?= $flagOpen ? '' : 'disabled' ?>>
        <!-- Sucursal -->
        <div class="col-12 col-lg-6">
          <label class="form-label">Sucursal</label>
          <?php if (!empty($sucursales)): ?>
            <select name="id_sucursal" class="form-select" required>
              <option value="">— Selecciona —</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ($idSucursal===(int)$s['id']?'selected':'') ?>>
                  <?= htmlspecialchars($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Selecciona una sucursal.</div>
          <?php else: ?>
            <input type="hidden" name="id_sucursal" value="<?= $idSucursal ?>">
            <input type="text" class="form-control" value="Sucursal actual (ID: <?= $idSucursal ?>)" disabled>
          <?php endif; ?>
        </div>

        <!-- Usuario (informativo) -->
        <div class="col-12 col-lg-6">
          <label class="form-label">Usuario</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($nombreUser) ?> (ID: <?= $idUsuario ?>)" disabled>
        </div>

        <!-- Nombre del cliente -->
        <div class="col-12 col-lg-6">
          <label class="form-label">Nombre del cliente</label>
          <input
            type="text"
            name="nombre_cliente"
            class="form-control"
            maxlength="120"
            autocomplete="name"
            required
            autofocus
            placeholder="Ej. Juan Pérez"
          >
          <div class="invalid-feedback">Escribe el nombre del cliente.</div>
        </div>

        <!-- TAG -->
        <div class="col-12 col-lg-6">
          <label class="form-label">TAG</label>
          <input
            type="text"
            name="tag"
            class="form-control"
            maxlength="60"
            inputmode="text"
            autocomplete="off"
            required
            placeholder="ID / referencia PayJoy"
          >
          <div class="invalid-feedback">El TAG es obligatorio.</div>
        </div>

        <!-- Comentarios -->
        <div class="col-12">
          <label class="form-label">Comentarios (opcional)</label>
          <textarea
            name="comentarios"
            class="form-control"
            maxlength="255"
            rows="2"
            placeholder="Observaciones..."
          ></textarea>
        </div>
      </fieldset>

      <!-- Acciones (se renderiza fija en móvil) -->
      <div class="action-bar d-flex gap-2">
        <a href="historial_payjoy_tc.php" class="btn btn-outline-secondary w-50">Historial</a>
        <button id="btnSubmit" type="submit" class="btn btn-success w-50" <?= $flagOpen ? '' : 'disabled' ?>>
          <?= $flagOpen ? 'Guardar venta' : 'No disponible' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($flagOpen): ?>
<script>
  // Validación nativa + anti doble envío + feedback rápido (solo si está ON)
  (function () {
    const form = document.getElementById('formPayjoy');
    const btn  = document.getElementById('btnSubmit');
    form.addEventListener('submit', function (e) {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      } else {
        // anti doble clic
        btn.disabled = true;
        btn.innerText = 'Guardando...';
      }
      form.classList.add('was-validated');
    }, false);
  })();
</script>
<?php endif; ?>
</body>
</html>
