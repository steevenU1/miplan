<?php
// nueva_cuota_sucursal.php — LUGA (UI mejorada + "Próximo lunes" + SIN fetch externo)
// Precarga cuotas vigentes por sucursal y las expone como data-* en el <select>

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Admin','Gerente General'])) {
    header("Location: index.php"); exit();
}

require_once __DIR__ . '/db.php';

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_sucursal = $_POST['id_sucursal'] ?? '';
    $cuota       = $_POST['cuota_monto'] ?? '';
    $fecha       = $_POST['fecha_inicio'] ?? '';

    if ($id_sucursal && $cuota && $fecha) {
        // Validar tipo Tienda
        $validacion = $conn->prepare("SELECT tipo_sucursal FROM sucursales WHERE id=? LIMIT 1");
        $validacion->bind_param("i", $id_sucursal);
        $validacion->execute();
        $tipo = $validacion->get_result()->fetch_assoc()['tipo_sucursal'] ?? '';

        if ($tipo !== 'Tienda') {
            $mensaje = "❌ No se pueden asignar cuotas a Almacenes.";
        } else {
            // Cuota vigente actual (última por fecha_inicio)
            $stmtV = $conn->prepare("
                SELECT cuota_monto 
                FROM cuotas_sucursales 
                WHERE id_sucursal=? 
                ORDER BY fecha_inicio DESC 
                LIMIT 1
            ");
            $stmtV->bind_param("i", $id_sucursal);
            $stmtV->execute();
            $cuotaVigente = $stmtV->get_result()->fetch_assoc()['cuota_monto'] ?? null;

            if ($cuotaVigente !== null && (float)$cuota == (float)$cuotaVigente) {
                $mensaje = "⚠️ La cuota ingresada es igual a la vigente. No es necesario registrar una nueva.";
            } else {
                $stmt = $conn->prepare("INSERT INTO cuotas_sucursales (id_sucursal, cuota_monto, fecha_inicio) VALUES (?,?,?)");
                $stmt->bind_param("ids", $id_sucursal, $cuota, $fecha);
                if ($stmt->execute()) {
                    header("Location: cuotas_sucursales.php");
                    exit();
                } else {
                    $mensaje = "❌ Error al guardar la cuota: " . $conn->error;
                }
            }
        }
    } else {
        $mensaje = "❌ Todos los campos son obligatorios.";
    }
}

// 1) Sucursales tipo Tienda
$resSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");
$sucursales = $resSuc ? $resSuc->fetch_all(MYSQLI_ASSOC) : [];

// 2) Precargar cuota vigente por sucursal (una sola consulta)
$vigentes = []; // id_sucursal => ['monto'=>float, 'fecha'=>'YYYY-MM-DD']
if (!empty($sucursales)) {
    $ids = array_map(fn($r) => (int)$r['id'], $sucursales);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $typ = str_repeat('i', count($ids));

    // última cuota por sucursal (join con subconsulta MAX(fecha_inicio))
    $sql = "
      SELECT cs.id_sucursal, cs.cuota_monto, cs.fecha_inicio
      FROM cuotas_sucursales cs
      JOIN (
         SELECT id_sucursal, MAX(fecha_inicio) AS maxf
         FROM cuotas_sucursales
         GROUP BY id_sucursal
      ) m ON m.id_sucursal = cs.id_sucursal AND m.maxf = cs.fecha_inicio
      WHERE cs.id_sucursal IN ($ph)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typ, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $vigentes[(int)$row['id_sucursal']] = [
            'monto' => (float)$row['cuota_monto'],
            'fecha' => $row['fecha_inicio']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nueva Cuota de Sucursal</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background: #f6f7fb; }
  .page-wrap { min-height: 100vh; display: flex; flex-direction: column; }
  .card-elev { border: 1px solid #e9ecf2; box-shadow: 0 6px 18px rgba(20, 32, 66, .06); }
  .form-legend { font-weight: 700; letter-spacing:.2px; }
  .muted { color: #6b7280; }
  .preview-card { background: #fff; border: 1px dashed #d8dde6; border-radius: .75rem; }
  .currency-hint { font-size: .875rem; color: #6b7280; }
  .badge-soft { background: #eef2ff; color: #3730a3; border: 1px solid #e0e7ff; }
  .info-chip { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:.5rem; padding:.35rem .5rem; display:inline-flex; gap:.35rem; align-items:center; }
  .required::after { content:" *"; color:#dc2626; }
</style>
</head>
<body class="page-wrap">

<?php include 'navbar.php'; ?>

<div class="container my-4 my-md-5">
  <div class="row justify-content-center">
    <div class="col-12 col-xl-10">
      <div class="card card-elev">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <div>
            <h4 class="mb-0">➕ Nueva Cuota de Sucursal</h4>
            <div class="small text-muted">Define la nueva cuota para una sucursal tipo <strong>Tienda</strong>.</div>
          </div>
          <a href="cuotas_sucursales.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
          </a>
        </div>

        <?php if ($mensaje): ?>
          <div class="alert alert-warning m-3 mb-0">
            <?= htmlspecialchars($mensaje) ?>
          </div>
        <?php endif; ?>

        <form id="formCuota" method="POST" class="card-body">
          <div class="row g-4">
            <!-- Columna izquierda -->
            <div class="col-12 col-lg-7">
              <fieldset>
                <legend class="form-legend h6 text-uppercase text-muted">Datos de la cuota</legend>

                <!-- Sucursal -->
                <div class="mb-3">
                  <label class="form-label required">Sucursal</label>
                  <select name="id_sucursal" class="form-select" required id="id_sucursal">
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($sucursales as $s):
                      $sid = (int)$s['id'];
                      $vig = $vigentes[$sid] ?? null;
                      $dataCuota = $vig ? ' data-cuota="'.htmlspecialchars($vig['monto']).'"' : '';
                      $dataFecha = $vig ? ' data-fecha="'.htmlspecialchars($vig['fecha']).'"' : '';
                    ?>
                      <option value="<?= $sid ?>"<?= $dataCuota.$dataFecha ?>>
                        <?= htmlspecialchars($s['nombre']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div id="cuotaVigenteInfo" class="mt-2"></div>
                </div>

                <!-- Cuota -->
                <div class="mb-3">
                  <label class="form-label required">Cuota ($)</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-cash-coin"></i></span>
                    <input type="number" step="0.01" min="0.01" name="cuota_monto" id="cuota_monto" class="form-control" placeholder="Ej. 150000" required>
                  </div>
                  <div class="currency-hint mt-1">
                    Vista previa: <strong id="cuotaPreview">$0.00</strong>
                  </div>
                </div>

                <!-- Fecha de inicio -->
                <div class="mb-3">
                  <label class="form-label required">Fecha de inicio</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" required>
                    <button type="button" id="btnNextMonday" class="btn btn-outline-primary">Próximo lunes</button>
                  </div>
                  <div class="form-text">La nueva cuota aplicará desde esta fecha. Sugerencia rápida: <em>Próximo lunes</em>.</div>
                </div>

                <div id="alertCliente" class="d-none alert mt-2"></div>
              </fieldset>
            </div>

            <!-- Columna derecha: Previsualización -->
            <div class="col-12 col-lg-5">
              <legend class="form-legend h6 text-uppercase text-muted">Previsualización</legend>
              <div class="preview-card p-3">
                <div class="d-flex align-items-center justify-content-between">
                  <div class="fw-semibold">Nueva cuota propuesta</div>
                  <span class="badge badge-soft"><i class="bi bi-eye me-1"></i>Vista previa</span>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                  <div class="muted">Cuota</div>
                  <div id="prevCuota" class="fw-bold">$0.00</div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                  <div class="muted">Fecha de inicio</div>
                  <div id="prevFecha" class="fw-semibold">—</div>
                </div>
                <div class="mt-3 small">
                  <span class="info-chip"><i class="bi bi-info-circle"></i> Si la cuota es igual a la vigente, el sistema te avisará.</span>
                </div>
              </div>
            </div>
          </div>
        </form>

        <div class="card-footer bg-white d-flex gap-2 justify-content-end">
          <button type="button" id="btnGuardar" class="btn btn-success">
            <span class="btn-text"><i class="bi bi-save2 me-1"></i> Guardar Cuota</span>
            <span class="btn-wait d-none"><span class="spinner-border spinner-border-sm me-1"></span> Guardando...</span>
          </button>
          <a href="cuotas_sucursales.php" class="btn btn-outline-secondary">Cancelar</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-shield-check me-1"></i> Confirmar registro de cuota</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Estás por registrar la siguiente cuota:</p>
        <ul class="list-group list-group-flush">
          <li class="list-group-item d-flex justify-content-between"><span class="muted">Sucursal</span><strong id="confSucursal">—</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="muted">Cuota</span><strong id="confCuota">$0.00</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="muted">Fecha de inicio</span><strong id="confFecha">—</strong></li>
        </ul>
        <div id="confWarning" class="alert alert-warning mt-3 d-none">
          <i class="bi bi-exclamation-triangle me-1"></i>
          La cuota ingresada coincide con la vigente. No es necesario registrar una nueva.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Revisar</button>
        <button id="btnConfirmar" class="btn btn-primary">
          <span class="btn-text">Confirmar y guardar</span>
          <span class="btn-wait d-none"><span class="spinner-border spinner-border-sm me-1"></span> Procesando...</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
const fmtMoney = (n) => Number(n||0).toLocaleString('es-MX',{style:'currency',currency:'MXN',minimumFractionDigits:2});
const byId = (id) => document.getElementById(id);

const form = byId('formCuota');
const btnGuardar = byId('btnGuardar');
const btnConfirmar = byId('btnConfirmar');
const cuotaInput = byId('cuota_monto');
const fechaInput = byId('fecha_inicio');
const selSuc = byId('id_sucursal');
const btnNextMonday = byId('btnNextMonday');
const alertCli = byId('alertCliente');

const prevCuota = byId('prevCuota');
const prevFecha = byId('prevFecha');
const cuotaPreview = byId('cuotaPreview');

const confSucursal = byId('confSucursal');
const confCuota = byId('confCuota');
const confFecha = byId('confFecha');
const confWarning = byId('confWarning');

let cuotaVigenteCache = null; // {monto,fecha} de la sucursal seleccionada

function refreshPreview(){
  prevCuota.textContent = fmtMoney(cuotaInput.value);
  if (cuotaPreview) cuotaPreview.textContent = fmtMoney(cuotaInput.value);
  prevFecha.textContent = fechaInput.value ? new Date(fechaInput.value).toLocaleDateString('es-MX') : '—';
}
cuotaInput.addEventListener('input', refreshPreview);
fechaInput.addEventListener('change', refreshPreview);

// Leer cuota vigente desde data-* (sin fetch)
function mostrarVigenteActual(){
  const opt = selSuc.options[selSuc.selectedIndex];
  const monto = opt?.dataset?.cuota ?? '';
  const fecha = opt?.dataset?.fecha ?? '';

  const infoDiv = byId('cuotaVigenteInfo');
  cuotaVigenteCache = null;

  if (monto !== '' && fecha){
    cuotaVigenteCache = {monto:Number(monto), fecha:fecha};
    infoDiv.innerHTML = `
      <div class="alert alert-info d-flex align-items-center gap-2 mb-0">
        <i class="bi bi-activity"></i>
        <div>
          <div><strong>Cuota vigente:</strong> ${fmtMoney(monto)}</div>
          <div class="small">Desde el ${new Date(fecha).toLocaleDateString('es-MX')}</div>
        </div>
      </div>`;
  } else {
    infoDiv.innerHTML = `
      <div class="alert alert-secondary d-flex align-items-center gap-2 mb-0">
        <i class="bi bi-clock-history"></i>
        <div>Esta sucursal no tiene cuota vigente registrada.</div>
      </div>`;
  }
}
selSuc.addEventListener('change', mostrarVigenteActual);

// Próximo lunes
function getNextMondayISO(baseDate = new Date()){
  const d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
  const day = d.getDay(); // 0=Dom,1=Lun
  const delta = (8 - day) % 7 || 7; // si hoy es lunes -> +7
  d.setDate(d.getDate() + delta);
  const yyyy = d.getFullYear(), mm = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
  return `${yyyy}-${mm}-${dd}`;
}
btnNextMonday.addEventListener('click', () => {
  fechaInput.value = getNextMondayISO();
  refreshPreview();
});

// Validación cliente
function showClientAlert(type, msg){
  alertCli.className = `alert alert-${type} mt-2`;
  alertCli.textContent = msg;
  alertCli.classList.remove('d-none');
}
function clearClientAlert(){ alertCli.classList.add('d-none'); }

function validarCliente(){
  clearClientAlert();
  const suc = selSuc.value;
  const monto = Number(cuotaInput.value);
  const fecha = fechaInput.value;

  if (!suc || !monto || !fecha){ showClientAlert('danger','Todos los campos son obligatorios.'); return false; }
  if (!(monto > 0)){ showClientAlert('warning','La cuota debe ser mayor a $0.00'); return false; }
  if (cuotaVigenteCache && Number(monto) === Number(cuotaVigenteCache.monto)){
    showClientAlert('warning','La cuota ingresada es igual a la vigente. Verifica si realmente necesitas registrarla.');
  }
  return true;
}

// Modal confirmación
const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
btnGuardar.addEventListener('click', () => {
  if (!validarCliente()) return;
  confSucursal.textContent = selSuc.options[selSuc.selectedIndex]?.text || '—';
  confCuota.textContent = fmtMoney(cuotaInput.value);
  confFecha.textContent = fechaInput.value ? new Date(fechaInput.value).toLocaleDateString('es-MX') : '—';
  if (cuotaVigenteCache && Number(cuotaInput.value) === Number(cuotaVigenteCache.monto)) confWarning.classList.remove('d-none');
  else confWarning.classList.add('d-none');
  confirmModal.show();
});

// Envío
function setLoading(btn, loading){
  const text = btn.querySelector('.btn-text');
  const wait = btn.querySelector('.btn-wait');
  if (loading){ text?.classList.add('d-none'); wait?.classList.remove('d-none'); btn.disabled = true; }
  else { text?.classList.remove('d-none'); wait?.classList.add('d-none'); btn.disabled = false; }
}
btnConfirmar.addEventListener('click', () => {
  setLoading(btnConfirmar, true); setLoading(btnGuardar, true); form.submit();
});

// Inicial
// (si el usuario ya tenía una sucursal seleccionada por autofill del navegador)
if (selSuc.value) { mostrarVigenteActual(); }
</script>
</body>
</html>
