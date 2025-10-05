<?php
// ✅ Iniciamos sesión aquí porque moveremos el include del navbar más abajo
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

$id_usuario           = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal_usuario  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usuario       = trim($_SESSION['nombre'] ?? 'Usuario');

// Traer sucursales
$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

// Mapa id=>nombre para uso en JS
$mapSuc = [];
foreach ($sucursales as $s) {
  $mapSuc[(int)$s['id']] = $s['nombre'];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- ✅ importante para móvil -->
  <title>Nueva Venta</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <!-- ===== Overrides del NAVBAR SOLO para esta vista ===== -->
  <style>
    :root {
      --brand: #0d6efd;
      --brand-100: rgba(13, 110, 253, .08);
    }

    body.bg-light {
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25, 135, 84, .06), transparent),
        #f8fafc;
    }

    #topbar,
    .navbar-luga {
      font-size: 16px;
    }

    @media (max-width:576px) {

      #topbar,
      .navbar-luga {
        font-size: 16px;
        --brand-font: 1.00em;
        --nav-font: .95em;
        --drop-font: .95em;
        --icon-em: 1.05em;
        --pad-y: .44em;
        --pad-x: .62em;
      }

      #topbar .navbar-brand img,
      .navbar-luga .navbar-brand img {
        width: 1.8em;
        height: 1.8em;
      }

      #topbar .btn-asistencia,
      .navbar-luga .btn-asistencia {
        font-size: .95em;
        padding: .5em .9em !important;
        border-radius: 12px;
      }

      #topbar .nav-avatar,
      #topbar .nav-initials,
      .navbar-luga .nav-avatar,
      .navbar-luga .nav-initials {
        width: 2.1em;
        height: 2.1em;
      }

      #topbar .navbar-toggler,
      .navbar-luga .navbar-toggler {
        padding: .45em .7em;
      }
    }

    @media (max-width:360px) {

      #topbar,
      .navbar-luga {
        font-size: 15px;
      }
    }

    .page-title {
      font-weight: 700;
      letter-spacing: .3px;
    }

    .card-elev {
      border: 0;
      box-shadow: 0 10px 24px rgba(2, 8, 20, 0.06), 0 2px 6px rgba(2, 8, 20, 0.05);
      border-radius: 1rem;
    }

    .section-title {
      font-size: .95rem;
      font-weight: 700;
      color: #334155;
      text-transform: uppercase;
      letter-spacing: .8px;
      margin-bottom: .75rem;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .section-title .bi {
      opacity: .85;
    }

    .req::after {
      content: " *";
      color: #dc3545;
      font-weight: 600;
    }

    .help-text {
      font-size: .85rem;
      color: #64748b;
    }

    .select2-container--default .select2-selection--single {
      height: 38px;
      border-radius: .5rem;
    }

    .select2-container--default .select2-selection__rendered {
      line-height: 38px;
    }

    .select2-container--default .select2-selection__arrow {
      height: 38px
    }

    .alert-sucursal {
      border-left: 4px solid #f59e0b;
    }

    .btn-gradient {
      background: linear-gradient(90deg, #16a34a, #22c55e);
      border: 0;
    }

    .btn-gradient:disabled {
      opacity: .7;
    }

    .badge-soft {
      background: #eef2ff;
      color: #1e40af;
      border: 1px solid #dbeafe;
    }

    .list-compact {
      margin: 0;
      padding-left: 1rem;
    }

    .list-compact li {
      margin-bottom: .25rem;
    }

    .file-hint {
      font-size: .8rem;
      color: #475569;
    }
  </style>
</head>

<body class="bg-light">

  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h2 class="page-title mb-1"><i class="bi bi-bag-plus me-2"></i>Registrar Nueva Venta</h2>
        <div class="help-text">Selecciona primero el <strong>Tipo de Venta</strong> y confirma en el modal antes de enviar.</div>
      </div>
      <a href="panel.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="mb-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
          <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombre_usuario) ?></span>
          <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($mapSuc[$id_sucursal_usuario] ?? '—') ?></span>
          <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
        </div>
      </div>
    </div>

    <div id="alerta_sucursal" class="alert alert-warning alert-sucursal d-none">
      <i class="bi bi-exclamation-triangle me-1"></i><strong>Atención:</strong> Estás eligiendo una sucursal diferente a la tuya. La venta contará para tu usuario en esa sucursal.
    </div>

    <div id="errores" class="alert alert-danger d-none"></div>

    <!-- ⬇️ enctype para permitir archivos -->
    <form method="POST" action="procesar_venta.php" id="form_venta" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

      <div class="card card-elev mb-4">
        <div class="card-body">

          <div class="section-title"><i class="bi bi-phone"></i> Tipo de venta</div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label req">Tipo de Venta</label>
              <select name="tipo_venta" id="tipo_venta" class="form-control" required>
                <option value="">Seleccione...</option>
                <option value="Contado">Contado</option>
                <option value="Financiamiento">Financiamiento</option>
                <option value="Financiamiento+Combo">Financiamiento + Combo</option>
              </select>
            </div>
          </div>

          <hr class="my-4">

          <div class="section-title"><i class="bi bi-geo-alt"></i> Datos de operación</div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label req">Sucursal de la Venta</label>
              <select name="id_sucursal" id="id_sucursal" class="form-control" required>
                <?php foreach ($sucursales as $sucursal): ?>
                  <option value="<?= (int)$sucursal['id'] ?>" <?= (int)$sucursal['id'] === $id_sucursal_usuario ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sucursal['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Puedes registrar en otra sucursal si operaste ahí.</div>
            </div>
          </div>

          <hr class="my-4">

          <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4" id="tag_field">
              <label for="tag" class="form-label">TAG (ID del crédito)</label>
              <input type="text" name="tag" id="tag" class="form-control" placeholder="Ej. PJ-123ABC">
            </div>
            <div class="col-md-4">
              <label class="form-label">Nombre del Cliente</label>
              <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control" placeholder="Nombre y apellidos">
            </div>
            <div class="col-md-4">
              <label class="form-label">Teléfono del Cliente</label>
              <input type="text" name="telefono_cliente" id="telefono_cliente" class="form-control" placeholder="10 dígitos">
            </div>
          </div>

          <!-- 🆕 REFERENCIAS -->
          <div class="row g-3 mb-2 mt-1">
            <div class="col-12">
              <div class="section-title"><i class="bi bi-journal-check"></i> Referencias</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" id="label_ref1n">Referencia 1 - Nombre</label>
              <input type="text" name="referencia1_nombre" id="referencia1_nombre" class="form-control" placeholder="Nombre completo">
            </div>
            <div class="col-md-6">
              <label class="form-label" id="label_ref1t">Referencia 1 - Teléfono</label>
              <input type="text" name="referencia1_telefono" id="referencia1_telefono" class="form-control" placeholder="10 dígitos">
            </div>
            <div class="col-md-6">
              <label class="form-label" id="label_ref2n">Referencia 2 - Nombre</label>
              <input type="text" name="referencia2_nombre" id="referencia2_nombre" class="form-control" placeholder="Nombre completo">
            </div>
            <div class="col-md-6">
              <label class="form-label" id="label_ref2t">Referencia 2 - Teléfono</label>
              <input type="text" name="referencia2_telefono" id="referencia2_telefono" class="form-control" placeholder="10 dígitos">
            </div>
            <div class="col-12">
              <div class="help-text">En <strong>Financiamiento</strong> estas referencias son obligatorias.</div>
            </div>
          </div>

          <hr class="my-4">

          <div class="section-title"><i class="bi bi-device-ssd"></i> Equipos</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label req">Equipo Principal</label>
              <select name="equipo1" id="equipo1" class="form-control select2-equipo" required></select>
              <div class="form-text">Puedes buscar por modelo, <strong>IMEI1</strong> o <strong>IMEI2</strong>.</div>
            </div>
            <div class="col-md-4" id="combo" style="display:none;">
              <label class="form-label">Equipo Combo</label>
              <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
            </div>
          </div>

          <hr class="my-4">

          <div class="section-title"><i class="bi bi-cash-coin"></i> Datos financieros</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label req">Precio de Venta Total ($)</label>
              <input type="number" step="0.01" min="0.01" name="precio_venta" id="precio_venta" class="form-control" placeholder="0.00" required>
            </div>
            <div class="col-md-4" id="enganche_field">
              <label class="form-label">Enganche ($)</label>
              <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label id="label_forma_pago" class="form-label req">Forma de Pago</label>
              <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-control" required>
                <option value="Efectivo">Efectivo</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Mixto">Mixto</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-2" id="mixto_detalle" style="display:none;">
            <div class="col-md-6">
              <label class="form-label">Enganche Efectivo ($)</label>
              <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0" placeholder="0.00">
            </div>
            <div class="col-md-6">
              <label class="form-label">Enganche Tarjeta ($)</label>
              <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0" placeholder="0.00">
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-4" id="plazo_field">
              <label class="form-label">Plazo en Semanas</label>
              <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0" placeholder="Ej. 52">
            </div>
            <div class="col-md-4" id="financiera_field">
              <label class="form-label">Financiera</label>
              <select name="financiera" id="financiera" class="form-control">
                <option value="">N/A</option>
                <option value="PayJoy">PayJoy</option>
                <option value="Krediya">Krediya</option>
                <option value="MSI">MSI</option>
                <option value="Visa">Visa</option>
                <option value="PlataCard">PlataCard</option>
                <option value="Aplazo">Aplazo</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Comentarios</label>
              <input type="text" name="comentarios" class="form-control" placeholder="Notas adicionales (opcional)">
            </div>
          </div>

          <hr class="my-4">

          <!-- 🆕 Identidad & Contrato -->
          <div class="section-title"><i class="bi bi-file-earmark-image"></i> Identidad & Contrato</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label" id="label_identificacion">Imagen de Identificación</label>
              <input class="form-control" type="file" name="identificacion" id="identificacion" accept="image/*">
              <div class="file-hint">Formatos: JPG/PNG/WEBP. Máx 5 MB.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" id="label_contrato">Contrato (PDF o Imagen)</label>
              <input class="form-control" type="file" name="contrato" id="contrato" accept="application/pdf,image/*">
              <div class="file-hint">PDF recomendado. Máx 5 MB.</div>
            </div>
          </div>
          <div class="help-text">En <strong>Financiamiento</strong> estos archivos serán obligatorios.</div>

        </div>
        <div class="card-footer bg-white border-0 p-3">
          <button class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
            <i class="bi bi-check2-circle me-2"></i> Registrar Venta
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- Modal de Confirmación -->
  <div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-light">
          <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma los datos antes de enviar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Validación de identidad:</strong> verifica que la venta se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                  <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                  <ul class="list-compact">
                    <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombre_usuario) ?></span></li>
                    <li><strong>Sucursal:</strong> <span id="conf_sucursal">—</span></li>
                  </ul>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                  <div class="section-title"><i class="bi bi-receipt"></i> Venta</div>
                  <ul class="list-compact">
                    <li><strong>Tipo:</strong> <span id="conf_tipo">—</span></li>
                    <li><strong>Equipo principal:</strong> <span id="conf_equipo1">—</span></li>
                    <li class="d-none" id="li_equipo2"><strong>Equipo combo:</strong> <span id="conf_equipo2">—</span></li>
                    <li><strong>Precio total:</strong> $<span id="conf_precio">0.00</span></li>
                    <li class="d-none" id="li_enganche"><strong>Enganche:</strong> $<span id="conf_enganche">0.00</span></li>
                    <li class="d-none" id="li_financiera"><strong>Financiera:</strong> <span id="conf_financiera">—</span></li>
                    <li class="d-none" id="li_tag"><strong>TAG:</strong> <span id="conf_tag">—</span></li>
                  </ul>
                  <hr class="my-2">
                  <!-- 🆕 Resumen referencias y archivos -->
                  <ul class="list-compact">
                    <li><strong>Refs:</strong> <span id="conf_refs">—</span></li>
                    <li><strong>Identificación:</strong> <span id="conf_ident">—</span></li>
                    <li><strong>Contrato:</strong> <span id="conf_contrato">—</span></li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <hr>
          <div class="help-text">
            Si detectas un error, cierra este modal y corrige los datos. Si todo es correcto, confirma para enviar.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
            <i class="bi bi-pencil-square me-1"></i> Corregir
          </button>
          <button class="btn btn-primary" id="btn_confirmar_envio">
            <i class="bi bi-send-check me-1"></i> Confirmar y enviar
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS (bundle) -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

  <script>
    $(document).ready(function() {
      const idSucursalUsuario = <?= $id_sucursal_usuario ?>;
      const mapaSucursales = <?= json_encode($mapSuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));

      // Mostrar errores recibidos por GET (?err=...)
      (function() {
        const params = new URLSearchParams(window.location.search);
        const errMsg = params.get('err');
        if (errMsg) {
          $('#errores').removeClass('d-none')
            .html('<strong>Ups:</strong> ' + errMsg);
          if (history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.delete('err');
            history.replaceState({}, '', url);
          }
        }
      })();

      // Helpers
      const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB
      const tel10 = v => /^\d{10}$/.test(v);

      // Select2 (sin matcher custom)
      $('.select2-equipo').select2({
        placeholder: "Buscar por modelo, IMEI1 o IMEI2",
        allowClear: true,
        width: '100%'
      });

      $('#tipo_venta').on('change', function() {
        $('#combo').toggle(isFinanciamientoCombo());
        if (!isFinanciamientoCombo()) {
          $('#equipo2').val(null).trigger('change');
          $('#equipo1 option, #equipo2 option').prop('disabled', false);
        }
        toggleVenta();
        refreshEquipoLocks();
      });

      $('#forma_pago_enganche').on('change', function() {
        $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFinanciamiento());
      });

      function isFinanciamiento() {
        const tipo = $('#tipo_venta').val();
        return (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
      }

      function isFinanciamientoCombo() {
        return $('#tipo_venta').val() === 'Financiamiento+Combo';
      }

      // 🔧 Ajusta obligatorios según tipo de venta (incluye referencias)
      function toggleVenta() {
        const esFin = isFinanciamiento();
        $('#tag_field, #enganche_field, #plazo_field, #financiera_field').toggle(esFin);
        $('#mixto_detalle').toggle(esFin && $('#forma_pago_enganche').val() === 'Mixto');
        $('#label_forma_pago').text(esFin ? 'Forma de Pago Enganche' : 'Forma de Pago');

        $('#tag').prop('required', esFin);
        $('#nombre_cliente').prop('required', esFin);
        $('#telefono_cliente').prop('required', esFin);
        $('#enganche').prop('required', esFin);
        $('#plazo_semanas').prop('required', esFin);
        $('#financiera').prop('required', esFin);

        // 🆕 referencias obligatorias solo en financiamiento (visual con .req)
        $('#label_ref1n, #label_ref1t, #label_ref2n, #label_ref2t').toggleClass('req', esFin);
        // 🆕 archivos obligatorios solo en financiamiento (visual; la validación real es manual)
        $('#label_identificacion, #label_contrato').toggleClass('req', esFin);

        $('#precio_venta').prop('required', true);
        $('#forma_pago_enganche').prop('required', true);

        if (!esFin) {
          $('#tag').val('');
          $('#enganche').val(0);
          $('#plazo_semanas').val(0);
          $('#financiera').val('');
          $('#enganche_efectivo').val(0);
          $('#enganche_tarjeta').val(0);
        }
      }
      toggleVenta();

      // ===== bloqueo cruzado equipo1 != equipo2
      function refreshEquipoLocks() {
        const v1 = $('#equipo1').val();
        const v2 = $('#equipo2').val();
        $('#equipo1 option, #equipo2 option').prop('disabled', false);
        if (v1) {
          $('#equipo2 option[value="' + v1 + '"]').prop('disabled', true);
        }
        if (v2) {
          $('#equipo1 option[value="' + v2 + '"]').prop('disabled', true);
        }
        if (v1 && v2 && v1 === v2) {
          $('#equipo2').val(null).trigger('change');
        }
      }

      $('#equipo1, #equipo2').on('change', refreshEquipoLocks);
      $('#equipo2').on('select2:select', function(e) {
        const v1 = $('#equipo1').val();
        const elegido = e.params.data.id;
        if (v1 && elegido === v1) {
          $(this).val(null).trigger('change');
        }
        refreshEquipoLocks();
      });
      $('#equipo1').on('select2:select', function() {
        refreshEquipoLocks();
      });

      // ⚠️ Cargar inventario por sucursal (tu versión que sí jalaba)
      function cargarEquipos(sucursalId) {
        $.ajax({
          url: 'ajax_productos_por_sucursal.php',
          method: 'POST',
          data: {
            id_sucursal: sucursalId
          },
          success: function(response) {
            $('#equipo1, #equipo2').html(response).val('').trigger('change');
            refreshEquipoLocks();
          },
          error: function(xhr) {
            const msg = xhr.responseText || 'Error cargando inventario';
            $('#equipo1, #equipo2').html('<option value="">' + msg + '</option>').trigger('change');
            refreshEquipoLocks();
          }
        });
      }

      cargarEquipos($('#id_sucursal').val());

      $('#id_sucursal').on('change', function() {
        const seleccionada = parseInt($(this).val());
        if (seleccionada !== idSucursalUsuario) {
          $('#alerta_sucursal').removeClass('d-none');
        } else {
          $('#alerta_sucursal').addClass('d-none');
        }
        cargarEquipos(seleccionada);
      });

      // ========= Validaciones extra: referencias y archivos =========
      function validarReferencias(esFin, errores) {
        const r1n = $('#referencia1_nombre').val().trim();
        const r1t = $('#referencia1_telefono').val().trim();
        const r2n = $('#referencia2_nombre').val().trim();
        const r2t = $('#referencia2_telefono').val().trim();

        if (esFin) {
          if (!r1n) errores.push('Referencia 1: ingresa el nombre.');
          if (!tel10(r1t)) errores.push('Referencia 1: teléfono debe tener 10 dígitos.');
          if (!r2n) errores.push('Referencia 2: ingresa el nombre.');
          if (!tel10(r2t)) errores.push('Referencia 2: teléfono debe tener 10 dígitos.');
        } else {
          if (r1t && !tel10(r1t)) errores.push('Referencia 1: teléfono debe tener 10 dígitos.');
          if (r2t && !tel10(r2t)) errores.push('Referencia 2: teléfono debe tener 10 dígitos.');
        }
      }

      function validarArchivos(esFin, errores) {
        const idf = $('#identificacion')[0]?.files?.[0] || null;
        const cto = $('#contrato')[0]?.files?.[0] || null;

        if (esFin) {
          if (!idf) errores.push('Sube la imagen de la identificación (obligatoria en financiamiento).');
          if (!cto) errores.push('Sube el contrato en PDF o imagen (obligatorio en financiamiento).');
        }
        if (idf && idf.size > MAX_SIZE_BYTES) errores.push('La identificación excede 5 MB.');
        if (cto && cto.size > MAX_SIZE_BYTES) errores.push('El contrato excede 5 MB.');
      }

      // ========= Validación + modal =========
      let permitSubmit = false;

      function validarFormulario() {
        const errores = [];
        const esFin = isFinanciamiento();

        const nombre = $('#nombre_cliente').val().trim();
        const tel = $('#telefono_cliente').val().trim();
        const tag = $('#tag').val().trim();
        const tipo = $('#tipo_venta').val();

        const precio = parseFloat($('#precio_venta').val());
        const eng = parseFloat($('#enganche').val());
        const forma = $('#forma_pago_enganche').val();
        const plazo = parseInt($('#plazo_semanas').val(), 10);
        const finan = $('#financiera').val();

        if (!tipo) errores.push('Selecciona el tipo de venta.');
        if (!precio || precio <= 0) errores.push('El precio de venta debe ser mayor a 0.');
        if (!forma) errores.push('Selecciona la forma de pago.');
        if (!$('#equipo1').val()) errores.push('Selecciona el equipo principal.');

        // Reglas para Financiamento+Combo
        if (isFinanciamientoCombo()) {
          const v1 = $('#equipo1').val();
          const v2 = $('#equipo2').val();
          if (!v2) errores.push('Selecciona el equipo combo.');
          if (v1 && v2 && v1 === v2) errores.push('El equipo combo debe ser distinto del principal.');
        }

        if (esFin) {
          if (!nombre) errores.push('Ingresa el nombre del cliente (Financiamiento).');
          if (!tel) errores.push('Ingresa el teléfono del cliente (Financiamiento).');
          if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
          if (!tag) errores.push('El TAG (ID del crédito) es obligatorio.');
          if (isNaN(eng) || eng < 0) errores.push('El enganche es obligatorio (puede ser 0, no negativo).');
          if (!plazo || plazo <= 0) errores.push('El plazo en semanas debe ser mayor a 0.');
          if (!finan) errores.push('Selecciona una financiera (no N/A).');

          if (forma === 'Mixto') {
            const ef = parseFloat($('#enganche_efectivo').val()) || 0;
            const tj = parseFloat($('#enganche_tarjeta').val()) || 0;
            if (ef <= 0 && tj <= 0) errores.push('En Mixto, al menos uno de los montos debe ser > 0.');
            if ((eng || 0).toFixed(2) !== (ef + tj).toFixed(2)) errores.push('Efectivo + Tarjeta debe igualar al Enganche.');
          }
        } else {
          if (tel && !/^\d{10}$/.test(tel)) errores.push('El teléfono debe tener 10 dígitos.');
        }

        // 🆕 validaciones nuevas
        validarReferencias(esFin, errores);
        validarArchivos(esFin, errores);

        return errores;
      }

      function poblarModal() {
        const idSucSel = $('#id_sucursal').val();
        const sucNom = mapaSucursales[idSucSel] || '—';
        $('#conf_sucursal').text(sucNom);

        const tipo = $('#tipo_venta').val() || '—';
        $('#conf_tipo').text(tipo);

        const equipo1Text = $('#equipo1').find('option:selected').text() || '—';
        const equipo2Text = $('#equipo2').find('option:selected').text() || '';

        $('#conf_equipo1').text(equipo1Text);
        if ($('#combo').is(':visible') && $('#equipo2').val()) {
          $('#conf_equipo2').text(equipo2Text);
          $('#li_equipo2').removeClass('d-none');
        } else {
          $('#li_equipo2').addClass('d-none');
        }

        const precio = parseFloat($('#precio_venta').val()) || 0;
        $('#conf_precio').text(precio.toFixed(2));

        const esFin = isFinanciamiento();
        if (esFin) {
          const eng = parseFloat($('#enganche').val()) || 0;
          $('#conf_enganche').text(eng.toFixed(2));
          $('#li_enganche').removeClass('d-none');

          const finan = $('#financiera').val() || '—';
          $('#conf_financiera').text(finan);
          $('#li_financiera').removeClass('d-none');

          const tag = ($('#tag').val() || '').trim();
          if (tag) {
            $('#conf_tag').text(tag);
            $('#li_tag').removeClass('d-none');
          } else {
            $('#li_tag').addClass('d-none');
          }
        } else {
          $('#li_enganche, #li_financiera, #li_tag').addClass('d-none');
        }

        // 🆕 Resumen referencias y archivos
        const r1n = $('#referencia1_nombre').val().trim();
        const r1t = $('#referencia1_telefono').val().trim();
        const r2n = $('#referencia2_nombre').val().trim();
        const r2t = $('#referencia2_telefono').val().trim();
        const refs = [];
        if (r1n || r1t) refs.push(`Ref1: ${r1n || '—'} (${r1t || '—'})`);
        if (r2n || r2t) refs.push(`Ref2: ${r2n || '—'} (${r2t || '—'})`);
        $('#conf_refs').text(refs.length ? refs.join(' | ') : '—');

        const idf = $('#identificacion')[0]?.files?.[0]?.name || '—';
        const cto = $('#contrato')[0]?.files?.[0]?.name || '—';
        $('#conf_ident').text(idf);
        $('#conf_contrato').text(cto);
      }

      $('#form_venta').on('submit', function(e) {
        if (permitSubmit) return;
        e.preventDefault();
        const errores = validarFormulario();

        if (errores.length > 0) {
          $('#errores').removeClass('d-none')
            .html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>' + errores.join('</li><li>') + '</li></ul>');
          window.scrollTo({
            top: 0,
            behavior: 'smooth'
          });
          return;
        }

        $('#errores').addClass('d-none').empty();
        poblarModal();
        modalConfirm.show();
      });

      $('#btn_confirmar_envio').on('click', function() {
        $('#btn_submit').prop('disabled', true).text('Enviando...');
        permitSubmit = true;
        modalConfirm.hide();
        $('#form_venta')[0].submit();
      });

      // Inicial
      function initEquipos() {
        cargarEquipos($('#id_sucursal').val());
      }
      initEquipos();
      refreshEquipoLocks();
    });
  </script>

</body>

</html>