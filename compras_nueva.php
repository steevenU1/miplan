<?php
// compras_nueva.php — Central 2.0
// Captura de factura de compra por renglones de MODELO (catálogo formal) + Otros cargos

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

// Permisos
if (!in_array($ROL, ['Admin','Logistica'])) {
  header("Location: 403.php"); exit();
}

// Helper seguro
if (!function_exists('h')) { function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); } }

// Proveedores
$proveedores = [];
$res = $conn->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
while ($row = $res->fetch_assoc()) { $proveedores[] = $row; }

// Sucursales
$sucursales = [];
$res2 = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
while ($row = $res2->fetch_assoc()) { $sucursales[] = $row; }

// Catálogo de modelos
$modelos = [];
$res3 = $conn->query("
  SELECT id, marca, modelo, codigo_producto, color, ram, capacidad
  FROM catalogo_modelos
  WHERE activo=1
  ORDER BY marca, modelo, color, ram, capacidad
");
while ($row = $res3->fetch_assoc()) { $modelos[] = $row; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Compras · Nueva factura — Central 2.0</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <style>
    body{ background:#f6f7fb; }
    .page-head{ display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .role-chip{ font-size:.8rem; padding:.2rem .55rem; border-radius:999px; background:#eef2ff; color:#3743a5; border:1px solid #d9e0ff; }
    .toolbar{ display:flex; gap:8px; align-items:center; }
    .card-soft{ border:1px solid #e9ecf1; border-radius:16px; box-shadow:0 2px 12px rgba(16,24,40,.06); }
    .kicker{ color:#6b7280; font-size:.9rem; }

    /* Tabla renglones (modelos) */
    #tablaDetalle th, #tablaDetalle td{ white-space:nowrap; }
    #tablaDetalle .col-codigo{ min-width: 320px; }
    #tablaDetalle .col-color{ width: 120px; }
    #tablaDetalle .col-ram{ width: 110px; }
    #tablaDetalle .col-cap{ width: 130px; }
    #tablaDetalle .col-qty{ width: 90px; }
    #tablaDetalle .col-pu{ width: 130px; }
    #tablaDetalle .col-ivp{ width: 110px; }
    #tablaDetalle .col-sub{ width: 130px; }
    #tablaDetalle .col-iva{ width: 120px; }
    #tablaDetalle .col-tot{ width: 140px; }
    #tablaDetalle .col-req{ width: 120px; text-align:center; }
    #tablaDetalle .col-acc{ width: 64px; }
    #tablaDetalle .form-control{ padding:.35rem .55rem; }
    #tablaDetalle input.num{ text-align:right; }
    #tablaDetalle input[readonly]{ background:#f8fafc; cursor:not-allowed; }

    /* Tabla otros cargos */
    #tablaCargos th, #tablaCargos td{ white-space:nowrap; }
    #tablaCargos .form-control{ padding:.35rem .55rem; }
    #tablaCargos input.num{ text-align:right; }

    /* Resumen */
    .summary{ position:sticky; top:12px; border-radius:16px; border:1px solid #e9ecf1; background:#fff; box-shadow:0 2px 10px rgba(16,24,40,.06);}
    .summary .rowline{ display:flex; align-items:center; justify-content:space-between; margin-bottom:.35rem; }
    .summary .total{ font-size:1.35rem; font-weight:800; }

    .hint{ font-size:.8rem; color:#6b7280; }
  </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4">

  <!-- Encabezado -->
  <div class="page-head">
    <div>
      <h2 class="page-title">🧾 Nueva factura de compra</h2>
      <div class="mt-1"><span class="role-chip"><?= h($ROL) ?></span></div>
    </div>
    <div class="toolbar">
      <a href="proveedores.php" target="_blank" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-person-plus me-1"></i>Alta proveedor</a>
      <a href="modelos.php" target="_blank" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-phone me-1"></i>Nuevo modelo</a>
      <a href="compras_resumen.php" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-list-ul me-1"></i>Ver compras</a>
    </div>
  </div>

  <form action="compras_guardar.php" method="POST" id="formCompra">
    <!-- Datos de la factura -->
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Proveedor <span class="text-danger">*</span></label>
            <select name="id_proveedor" class="form-select" required>
              <option value="">— Selecciona —</option>
              <?php foreach ($proveedores as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label"># Factura <span class="text-danger">*</span></label>
            <input type="text" name="num_factura" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Sucursal destino <span class="text-danger">*</span></label>
            <select name="id_sucursal" class="form-select" required>
              <?php foreach ($sucursales as $s): ?>
                <?php $sid = (int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= ($sid === $ID_SUCURSAL ? 'selected' : '') ?>><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">IVA % (default)</label>
            <input type="number" step="0.01" value="16" id="ivaDefault" class="form-control">
          </div>

          <div class="col-md-3">
            <label class="form-label">Fecha factura <span class="text-danger">*</span></label>
            <input type="date" name="fecha_factura" class="form-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Fecha vencimiento</label>
            <input type="date" name="fecha_vencimiento" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Condición de pago <span class="text-danger">*</span></label>
            <select name="condicion_pago" id="condicionPago" class="form-select" required>
              <option value="Contado">Contado</option>
              <option value="Crédito">Crédito</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Días de vencimiento</label>
            <input type="number" min="0" step="1" id="diasVencimiento" name="dias_vencimiento" class="form-control" placeholder="ej. 30" list="plazosSugeridos">
            <datalist id="plazosSugeridos">
              <option value="7"><option value="14"><option value="15"><option value="21">
              <option value="30"><option value="45"><option value="60"><option value="90">
            </datalist>
            <div class="form-text">Crédito: escribe días y te calculo la fecha.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Notas</label>
            <input type="text" name="notas" class="form-control" maxlength="250" placeholder="Opcional">
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-9">
        <!-- Detalle (modelos) -->
        <div class="card card-soft">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <h5 class="mb-0">Detalle por modelo</h5>
                <div class="kicker">Captura por <b>código o marca/modelo</b>. El IVA por renglón parte del valor default.</div>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary rounded-pill" href="modelos.php" target="_blank"><i class="bi bi-plus-lg me-1"></i>Nuevo modelo</a>
                <button type="button" class="btn btn-sm btn-primary rounded-pill" id="btnAgregar"><i class="bi bi-plus-circle me-1"></i>Agregar renglón</button>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-striped align-middle" id="tablaDetalle">
                <thead class="table-light">
                  <tr>
                    <th class="col-codigo">Código · Marca/Modelo (buscador)</th>
                    <th class="col-color">Color</th>
                    <th class="col-ram">RAM</th>
                    <th class="col-cap">Capacidad</th>
                    <th class="col-qty">Cantidad</th>
                    <th class="col-pu">P. Unitario</th>
                    <th class="col-ivp">IVA %</th>
                    <th class="col-sub">Subtotal</th>
                    <th class="col-iva">IVA</th>
                    <th class="col-tot">Total</th>
                    <th class="col-req">Requiere IMEI</th>
                    <th class="col-acc"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>

            <div class="hint mt-2">
              Tip: marca/desmarca “Requiere IMEI” si el modelo lo amerita (p. ej. accesorios).
            </div>
          </div>
        </div>

        <!-- Otros cargos (opcional) -->
        <div class="card card-soft mt-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <h6 class="mb-0">Otros cargos (opcional)</h6>
                <div class="kicker">Ej.: seguro de protección, flete, cargo por morosidad, etc.</div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" id="btnAddCargo">
                <i class="bi bi-plus-circle me-1"></i>Agregar cargo
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-sm align-middle" id="tablaCargos">
                <thead class="table-light">
                  <tr>
                    <th style="min-width:280px">Descripción</th>
                    <th style="width:150px">Importe</th>
                    <th style="width:110px">IVA %</th>
                    <th style="width:130px">Subtotal</th>
                    <th style="width:120px">IVA</th>
                    <th style="width:140px">Total</th>
                    <th style="width:64px"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
            <small class="text-muted">Se envían como <code>extra_desc[]</code>, <code>extra_monto[]</code> y <code>extra_iva_porcentaje[]</code>.</small>
          </div>
        </div>
      </div>

      <!-- Resumen -->
      <div class="col-lg-3">
        <div class="summary p-3">
          <div class="rowline"><strong>Subtotal</strong><strong id="lblSubtotal">$0.00</strong></div>
          <div class="rowline"><strong>IVA</strong><strong id="lblIVA">$0.00</strong></div>
          <hr class="my-2">
          <div class="rowline total"><span>Total</span><span id="lblTotal">$0.00</span></div>

          <input type="hidden" name="subtotal" id="inpSubtotal">
          <input type="hidden" name="iva" id="inpIVA">
          <input type="hidden" name="total" id="inpTotal">

          <!-- Hidden para pago contado -->
          <input type="hidden" name="registrar_pago" id="registrarPago" value="0">
          <input type="hidden" name="pago_monto" id="pagoMonto">
          <input type="hidden" name="pago_metodo" id="pagoMetodo">
          <input type="hidden" name="pago_referencia" id="pagoReferencia">
          <input type="hidden" name="pago_fecha" id="pagoFecha">
          <input type="hidden" name="pago_nota" id="pagoNota">

          <div class="d-grid mt-3">
            <button type="submit" class="btn btn-success rounded-pill">
              <i class="bi bi-save2 me-1"></i>Guardar factura
            </button>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- Datalist global -->
<datalist id="dlModelos">
  <?php foreach ($modelos as $m):
    $desc = trim(($m['marca'] ?? '').' '.($m['modelo'] ?? '').' · '.($m['color']??'').' · '.($m['ram']??'').' · '.($m['capacidad']??'')); 
    $val  = $m['codigo_producto'] ?: (($m['marca']??'').' '.($m['modelo']??''));
  ?>
    <option value="<?= h($val) ?>" label="<?= h($desc) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- Modal de confirmación previo a guardar -->
<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirma datos de la factura</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Comprueba que los datos capturados <b>(Modelos, Colores y costos)</b> sean correctos.</p>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Código / Modelo</th>
                <th>Color</th>
                <th>RAM</th>
                <th>Capacidad</th>
                <th class="text-end">Cantidad</th>
                <th class="text-end">P. Unitario</th>
              </tr>
            </thead>
            <tbody id="cfBody"></tbody>
          </table>
        </div>
        <small class="text-muted">Si ves algún dato incorrecto, cierra este cuadro y corrígelo antes de guardar.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Revisar de nuevo</button>
        <button type="button" class="btn btn-primary" id="btnConfirmarDatos">Sí, todo está correcto</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de pago (Contado) -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Registrar pago (Contado)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2">Importe de la factura: <strong id="mpTotal">$0.00</strong></div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Fecha de pago</label>
            <input type="date" class="form-control" id="mpFecha" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Método</label>
            <select id="mpMetodo" class="form-select">
              <option value="Efectivo">Efectivo</option>
              <option value="Transferencia">Transferencia</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Depósito">Depósito</option>
              <option value="Otro">Otro</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Referencia</label>
            <input type="text" id="mpRef" class="form-control" maxlength="80" placeholder="Folio, banco, últimos 4, etc. (opcional)">
          </div>
          <div class="col-6">
            <label class="form-label">Importe pagado</label>
            <input type="number" id="mpMonto" class="form-control" step="0.01" min="0">
          </div>
          <div class="col-6">
            <label class="form-label">Notas</label>
            <input type="text" id="mpNota" class="form-control" maxlength="120" placeholder="Opcional">
          </div>
        </div>
        <small class="text-muted d-block mt-2">El pago se guardará en <strong>compras_pagos</strong> junto con la factura.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnConfirmarPago">Guardar factura + pago</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script> -->

<script>
  // Datos PHP -> JS
  const modelos = <?= json_encode($modelos, JSON_UNESCAPED_UNICODE) ?>;

  // Elementos base
  const tbody = document.querySelector('#tablaDetalle tbody');
  const cargosBody = document.querySelector('#tablaCargos tbody');
  const ivaDefault = document.getElementById('ivaDefault');

  // vencimiento / condición
  const fechaFacturaEl = document.querySelector('input[name="fecha_factura"]');
  const fechaVencEl    = document.querySelector('input[name="fecha_vencimiento"]');
  const diasVencEl     = document.getElementById('diasVencimiento');
  const condicionPagoEl= document.getElementById('condicionPago');

  // modales
  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirm'));
  const cfBody       = document.getElementById('cfBody');

  const modalPago   = new bootstrap.Modal(document.getElementById('modalPago'));
  const mpTotalEl   = document.getElementById('mpTotal');
  const mpFechaEl   = document.getElementById('mpFecha');
  const mpMetodoEl  = document.getElementById('mpMetodo');
  const mpRefEl     = document.getElementById('mpRef');
  const mpMontoEl   = document.getElementById('mpMonto');
  const mpNotaEl    = document.getElementById('mpNota');

  const regPagoEl   = document.getElementById('registrarPago');
  const pagoMontoEl = document.getElementById('pagoMonto');
  const pagoMetodoEl= document.getElementById('pagoMetodo');
  const pagoRefEl   = document.getElementById('pagoReferencia');
  const pagoFechaEl = document.getElementById('pagoFecha');
  const pagoNotaEl  = document.getElementById('pagoNota');

  let rowIdx = 0;
  let cargoIdx = 0;
  let forceSubmit = false; // evita loop al reenviar

  function formato(n){ return new Intl.NumberFormat('es-MX',{minimumFractionDigits:2, maximumFractionDigits:2}).format(n||0); }

  function calcTotales(){
    let sub=0, iva=0, tot=0;

    // Modelos
    document.querySelectorAll('#tablaDetalle tbody tr.renglon').forEach(tr=>{
      const qty = parseFloat(tr.querySelector('.qty').value) || 0;
      const pu  = parseFloat(tr.querySelector('.pu').value)  || 0;
      const ivp = parseFloat(tr.querySelector('.ivp').value) || 0;
      const rsub = qty * pu;
      const riva = rsub * (ivp/100.0);
      const rtot = rsub + riva;
      tr.querySelector('.rsub').textContent = '$'+formato(rsub);
      tr.querySelector('.riva').textContent = '$'+formato(riva);
      tr.querySelector('.rtot').textContent = '$'+formato(rtot);
      sub+=rsub; iva+=riva; tot+=rtot;
    });

    // Otros cargos
    document.querySelectorAll('#tablaCargos tbody tr.cargo').forEach(tr=>{
      const imp = parseFloat(tr.querySelector('.importe').value) || 0;
      const ivp = parseFloat(tr.querySelector('.ivp').value)     || 0;
      const rsub = imp;
      const riva = rsub * (ivp/100.0);
      const rtot = rsub + riva;
      tr.querySelector('.rsub').textContent = '$'+formato(rsub);
      tr.querySelector('.riva').textContent = '$'+formato(riva);
      tr.querySelector('.rtot').textContent = '$'+formato(rtot);
      sub+=rsub; iva+=riva; tot+=rtot;
    });

    document.getElementById('lblSubtotal').textContent = '$'+formato(sub);
    document.getElementById('lblIVA').textContent      = '$'+formato(iva);
    document.getElementById('lblTotal').textContent    = '$'+formato(tot);
    document.getElementById('inpSubtotal').value = sub.toFixed(2);
    document.getElementById('inpIVA').value      = iva.toFixed(2);
    document.getElementById('inpTotal').value    = tot.toFixed(2);
  }

  // Mapas para localizar por código o etiqueta
  function etiqueta(m){ return (m.marca + ' ' + m.modelo + (m.codigo_producto ? (' · ' + m.codigo_producto) : '')).trim(); }
  const byCodigo = {}; const byEtiqueta = {};
  modelos.forEach(m => {
    if (m.codigo_producto) byCodigo[m.codigo_producto] = m;
    byEtiqueta[etiqueta(m).toLowerCase()] = m;
  });

  function aplicarModeloEnRenglon(m, tr){
    tr.querySelector('.mm-id').value     = m.id;
    tr.querySelector('.color').value     = m.color || '';
    tr.querySelector('.ram').value       = m.ram || '';
    tr.querySelector('.capacidad').value = m.capacidad || '';
    const mm = tr.querySelector('.mm-buscar');
    mm.classList.remove('is-invalid');
    mm.setCustomValidity('');
  }

  // Validación de PU: deja capturar 0 pero NO deja enviar si queda en 0 o negativo.
  function wirePrecioUnitarioValidation(inputPU){
    inputPU.min = '0';        // permite 0 mientras capturas
    inputPU.step = '0.01';

    const validatePU = () => {
      const v = parseFloat(inputPU.value);
      if (!isFinite(v) || v <= 0) {
        inputPU.classList.add('is-invalid');
        inputPU.setCustomValidity('El precio unitario debe ser mayor a 0.');
      } else {
        inputPU.classList.remove('is-invalid');
        inputPU.setCustomValidity('');
      }
    };

    // Validamos cuando terminas de editar
    inputPU.addEventListener('blur', validatePU);
    inputPU.addEventListener('change', validatePU);
  }

  function agregarRenglon(){
    const idx = rowIdx++;
    const tr = document.createElement('tr');
    tr.className = 'renglon';
    tr.innerHTML = `
      <td class="col-codigo">
        <div class="position-relative">
          <input type="text" class="form-control mm-buscar" list="dlModelos"
                 placeholder="Escribe código o marca/modelo" autocomplete="off" required>
          <input type="hidden" name="id_modelo[${idx}]" class="mm-id">
          <div class="invalid-feedback">Elige un código válido del catálogo.</div>
        </div>
      </td>
      <td class="col-color"><input type="text" class="form-control color" name="color[${idx}]" readonly required></td>
      <td class="col-ram"><input type="text" class="form-control ram" name="ram[${idx}]" readonly></td>
      <td class="col-cap"><input type="text" class="form-control capacidad" name="capacidad[${idx}]" readonly required></td>
      <td class="col-qty"><input type="number" min="1" value="1" class="form-control num qty" name="cantidad[${idx}]" required></td>
      <td class="col-pu">
        <input type="number" step="0.01" min="0" value="0" class="form-control num pu" name="precio_unitario[${idx}]" required>
        <div class="invalid-feedback">El precio unitario debe ser mayor a 0.</div>
      </td>
      <td class="col-ivp"><input type="number" step="0.01" min="0" class="form-control num ivp" name="iva_porcentaje[${idx}]" value="${ivaDefault.value || 16}"></td>
      <td class="col-sub rsub">$0.00</td>
      <td class="col-iva riva">$0.00</td>
      <td class="col-tot rtot">$0.00</td>
      <td class="col-req"><input type="hidden" name="requiere_imei[${idx}]" value="0"><input type="checkbox" class="form-check-input reqi" name="requiere_imei[${idx}]" value="1" checked></td>
      <td class="col-acc"><button type="button" class="btn btn-sm btn-outline-danger rounded-pill btnQuitar" title="Quitar">&times;</button></td>
    `;
    tbody.appendChild(tr);

    tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', calcTotales));
    tr.querySelector('.btnQuitar').addEventListener('click', () => { tr.remove(); calcTotales(); });

    const input  = tr.querySelector('.mm-buscar');
    const hidden = tr.querySelector('.mm-id');
    const pu     = tr.querySelector('.pu');

    // candado PU (pero dejando default 0)
    wirePrecioUnitarioValidation(pu);

    function handleTryApply() {
      const raw = (input.value || '').trim();
      const m = byCodigo[raw] || byEtiqueta[raw.toLowerCase()];
      if (m) {
        aplicarModeloEnRenglon(m, tr);
      } else {
        hidden.value = '';
        input.classList.remove('is-invalid');
        input.setCustomValidity('');
      }
    }

    // Robustez para datalist/pegado + guardar
    input.addEventListener('input', handleTryApply);
    input.addEventListener('change', handleTryApply);
    input.addEventListener('blur', handleTryApply);

    calcTotales();
  }

  function agregarCargo(){
    const idx = cargoIdx++;
    const tr = document.createElement('tr');
    tr.className = 'cargo';
    tr.innerHTML = `
      <td>
        <input type="text" class="form-control desc" name="extra_desc[${idx}]"
               placeholder="Descripción del cargo (p. ej. Seguro)" maxlength="120" required>
      </td>
      <td>
        <input type="number" class="form-control num importe" name="extra_monto[${idx}]"
               step="0.01" min="0" value="0" required>
      </td>
        <td>
        <input type="number" class="form-control num ivp" name="extra_iva_porcentaje[${idx}]"
               step="0.01" min="0" value="${ivaDefault.value || 16}">
      </td>
      <td class="rsub">$0.00</td>
      <td class="riva">$0.00</td>
      <td class="rtot">$0.00</td>
      <td><button type="button" class="btn btn-sm btn-outline-danger rounded-pill btnQuitar">&times;</button></td>
    `;
    cargosBody.appendChild(tr);

    tr.querySelectorAll('input').forEach(el => el.addEventListener('input', calcTotales));
    tr.querySelector('.btnQuitar').addEventListener('click', () => { tr.remove(); calcTotales(); });

    calcTotales();
  }

  document.getElementById('btnAgregar').addEventListener('click', agregarRenglon);
  document.getElementById('btnAddCargo').addEventListener('click', agregarCargo);

  ivaDefault.addEventListener('input', () => {
    document.querySelectorAll('.ivp').forEach(i => i.value = ivaDefault.value || 16);
    calcTotales();
  });

  // arranca con 1 renglón de modelo
  agregarRenglon();

  // ===== SUBMIT: validación, confirmación y (si aplica) pago contado =====
  document.getElementById('formCompra').addEventListener('submit', function(e){
    if (forceSubmit) return;

    if (!tbody.querySelector('tr')) {
      e.preventDefault(); alert('Agrega al menos un renglón'); return;
    }

    // Pre-aplicar por seguridad (por si no hubo blur/change)
    document.querySelectorAll('#tablaDetalle tbody tr.renglon .mm-buscar')
      .forEach(inp => {
        const tr = inp.closest('tr');
        const hidden = tr.querySelector('.mm-id');
        const raw = (inp.value || '').trim();
        const m = byCodigo[raw] || byEtiqueta[raw.toLowerCase()];
        if (m) aplicarModeloEnRenglon(m, tr);
      });

    // Validaciones por renglón
    let ok = true; let msg = '';
    const rows = document.querySelectorAll('#tablaDetalle tbody tr.renglon');
    rows.forEach(tr => {
      const hidden = tr.querySelector('.mm-id');
      const qty    = parseFloat(tr.querySelector('.qty').value);
      const puEl   = tr.querySelector('.pu');
      const pu     = parseFloat(puEl.value);

      if (!hidden.value) { ok = false; msg = 'Verifica que todos los renglones tengan un código válido.'; }
      if (!isFinite(qty) || qty < 1) { ok = false; msg = 'Hay cantidades inválidas (deben ser ≥ 1).'; }
      if (!isFinite(pu) || pu <= 0) {
        ok = false; msg = 'Hay precios unitarios en 0 o inválidos.';
        puEl.classList.add('is-invalid'); puEl.setCustomValidity('El precio unitario debe ser mayor a 0.');
      }
    });

    if (!ok) { e.preventDefault(); alert(msg); return; }

    // Construir resumen para el modal de confirmación
    cfBody.innerHTML = '';
    rows.forEach((tr, idx) => {
      const codTxt = tr.querySelector('.mm-buscar').value || '';
      const color  = tr.querySelector('.color').value || '';
      const ram    = tr.querySelector('.ram').value || '';
      const cap    = tr.querySelector('.capacidad').value || '';
      const qty    = parseFloat(tr.querySelector('.qty').value) || 0;
      const pu     = parseFloat(tr.querySelector('.pu').value) || 0;
      const trHtml = `
        <tr>
          <td>${idx+1}</td>
          <td>${codTxt ? htmlesc(codTxt) : '-'}</td>
          <td>${color ? htmlesc(color) : '-'}</td>
          <td>${ram ? htmlesc(ram) : '-'}</td>
          <td>${cap ? htmlesc(cap) : '-'}</td>
          <td class="text-end">${qty}</td>
          <td class="text-end">$${formato(pu)}</td>
        </tr>
      `;
      cfBody.insertAdjacentHTML('beforeend', trHtml);
    });

    // Mostrar modal de confirmación
    e.preventDefault();
    modalConfirm.show();
  });

  // Confirmación final del usuario
  document.getElementById('btnConfirmarDatos').addEventListener('click', () => {
    modalConfirm.hide();

    // Si es contado, abrimos modal de pago; si no, enviamos directo
    if (condicionPagoEl.value === 'Contado') {
      calcTotales();
      const total = parseFloat(document.getElementById('inpTotal').value || '0') || 0;
      mpTotalEl.textContent = '$' + formato(total);
      mpMontoEl.value = total.toFixed(2);
      mpFechaEl.value = fechaFacturaEl.value || new Date().toISOString().slice(0,10);
      modalPago.show();
    } else {
      forceSubmit = true;
      document.getElementById('formCompra').submit();
    }
  });

  // Confirmar modal contado
  document.getElementById('btnConfirmarPago').addEventListener('click', () => {
    const monto = parseFloat(mpMontoEl.value || '0');
    if (isNaN(monto) || monto <= 0) { alert('Importe de pago inválido'); return; }
    document.getElementById('registrarPago').value = '1';
    document.getElementById('pagoMonto').value  = monto.toFixed(2);
    document.getElementById('pagoMetodo').value = mpMetodoEl.value || 'Efectivo';
    document.getElementById('pagoReferencia').value = mpRefEl.value.trim();
    document.getElementById('pagoFecha').value  = mpFechaEl.value || new Date().toISOString().slice(0,10);
    document.getElementById('pagoNota').value   = mpNotaEl.value.trim();

    forceSubmit = true;
    modalPago.hide();
    document.getElementById('formCompra').submit();
  });

  // ===== Vencimiento (Crédito) =====
  function ymd(dateObj){
    const tzOffset = dateObj.getTimezoneOffset();
    const local = new Date(dateObj.getTime() - tzOffset*60000);
    return local.toISOString().slice(0,10);
  }
  function sumarDias(baseStr, dias){
    const d = new Date(baseStr + 'T00:00:00'); if (isNaN(d.getTime())) return '';
    const n = parseInt(dias, 10); if (isNaN(n)) return '';
    d.setDate(d.getDate() + n); return ymd(d);
  }
  function setContadoUI(){
    if (fechaFacturaEl.value) fechaVencEl.value = fechaFacturaEl.value;
    diasVencEl.value = 0;
    diasVencEl.readOnly = true; fechaVencEl.readOnly = true;
    diasVencEl.classList.add('bg-light'); fechaVencEl.classList.add('bg-light');
  }
  function setCreditoUI(){
    diasVencEl.readOnly = false; fechaVencEl.readOnly = false;
    diasVencEl.classList.remove('bg-light'); fechaVencEl.classList.remove('bg-light');
    recalcularFechaVenc();
  }
  function recalcularFechaVenc(){
    if (condicionPagoEl.value !== 'Crédito') return;
    const f = fechaFacturaEl.value; const dias = diasVencEl.value;
    if (f && dias !== '') {
      const fv = sumarDias(f, dias); if (fv) fechaVencEl.value = fv;
    }
  }
  function recalcularDias(){
    if (condicionPagoEl.value !== 'Crédito') return;
    const f = fechaFacturaEl.value; const fv = fechaVencEl.value;
    if (f && fv) {
      const df = new Date(f + 'T00:00:00'); const dv = new Date(fv + 'T00:00:00');
      if (!isNaN(df.getTime()) && !isNaN(dv.getTime())) {
        const diffDays = Math.round((dv - df) / (1000*60*60*24));
        if (diffDays >= 0) diasVencEl.value = diffDays;
      }
    }
  }
  fechaFacturaEl.addEventListener('change', () => {
    if (condicionPagoEl.value === 'Contado') setContadoUI(); else recalcularFechaVenc();
  });
  diasVencEl.addEventListener('input', recalcularFechaVenc);
  fechaVencEl.addEventListener('change', recalcularDias);
  condicionPagoEl.addEventListener('change', () => {
    if (condicionPagoEl.value === 'Contado') setContadoUI(); else setCreditoUI();
  });
  setContadoUI();

  // Utilidad para escapar HTML en el resumen
  function htmlesc(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }
</script>
</body>
</html>
