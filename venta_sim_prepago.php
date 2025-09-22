<?php
/* venta_sim_prepago.php — Alta express de SIM (misma página) + venta normal
   - DN obligatorio en alta
   - Mensaje de duplicado muestra nombre de sucursal
   - Si ICCID ya existe Disponible en tu sucursal, lo preselecciona
*/
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');
$mensaje     = '';

/* ===== Flags para alta rápida ===== */
$selSimId = isset($_GET['sel_sim']) ? (int)$_GET['sel_sim'] : 0; // para preseleccionar tras alta
$flash    = $_GET['msg'] ?? ''; // sim_ok, sim_dup, sim_err

/* =========================
   FUNCIONES AUXILIARES
========================= */
function obtenerEsquemaVigente($conn, $fechaVenta) {
    $sql = "SELECT * FROM esquemas_comisiones
            WHERE fecha_inicio <= ?
              AND (fecha_fin IS NULL OR fecha_fin >= ?)
              AND activo = 1
            ORDER BY fecha_inicio DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fechaVenta, $fechaVenta);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta) {
    $sql = "SELECT cuota_monto
            FROM cuotas_sucursales
            WHERE id_sucursal=? AND fecha_inicio <= ?
            ORDER BY fecha_inicio DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $idSucursal, $fechaVenta);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cuota = $row['cuota_monto'] ?? 0;

    // Semana martes-lunes
    $ini = new DateTime($fechaVenta);
    $dif = $ini->format('N') - 2;
    if ($dif < 0) $dif += 7;
    $ini->modify("-$dif days")->setTime(0,0,0);
    $fin = clone $ini;
    $fin->modify("+6 days")->setTime(23,59,59);

    $q = "SELECT SUM(precio_total) AS monto
          FROM ventas_sims
          WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?";
    $stmt2 = $conn->prepare($q);
    $inicio = $ini->format('Y-m-d H:i:s');
    $final  = $fin->format('Y-m-d H:i:s');
    $stmt2->bind_param("iss", $idSucursal, $inicio, $final);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc();
    $monto = $row2['monto'] ?? 0;

    return $monto >= $cuota;
}
function calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota) {
    $tipoSim   = strtolower($tipoSim);
    $tipoVenta = strtolower($tipoVenta);
    $col = null;

    if ($tipoSim == 'bait') {
        $col = ($tipoVenta == 'portabilidad')
            ? ($cumpleCuota ? 'comision_sim_bait_port_con' : 'comision_sim_bait_port_sin')
            : ($cumpleCuota ? 'comision_sim_bait_nueva_con' : 'comision_sim_bait_nueva_sin');
    } elseif ($tipoSim == 'att') {
        $col = ($tipoVenta == 'portabilidad')
            ? ($cumpleCuota ? 'comision_sim_att_port_con' : 'comision_sim_att_port_sin')
            : ($cumpleCuota ? 'comision_sim_att_nueva_con' : 'comision_sim_att_nueva_sin');
    }

    return (float)($esquema[$col] ?? 0);
}
function redir($msg, $extra = []) {
    $qs = array_merge(['msg'=>$msg], $extra);
    $url = basename($_SERVER['PHP_SELF']).'?'.http_build_query($qs);
    header("Location: $url"); exit();
}

/* =========================
   ALTA RÁPIDA DE SIM (Prepago)
   (antes del candado; no es venta)
========================= */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'alta_sim')) {
    $iccid    = strtoupper(trim($_POST['iccid'] ?? ''));
    $operador = trim($_POST['operador'] ?? '');
    $dn       = trim($_POST['dn'] ?? '');
    $caja_id  = trim($_POST['caja_id'] ?? '');

    // Validaciones
    if (!preg_match('/^\d{19}[A-Z]$/', $iccid)) {
        redir('sim_err', ['e'=>'ICCID inválido. Debe ser 19 dígitos + 1 letra mayúscula (ej. ...1909F).']);
    }
    if (!in_array($operador, ['Bait','AT&T'], true)) {
        redir('sim_err', ['e'=>'Operador inválido. Elige Bait o AT&T.']);
    }
    // DN OBLIGATORIO
    if ($dn === '' || !preg_match('/^\d{10}$/', $dn)) {
        redir('sim_err', ['e'=>'El DN es obligatorio y debe tener 10 dígitos.']);
    }

    // Duplicado global con nombre de sucursal
    $stmt = $conn->prepare("
        SELECT i.id, i.id_sucursal, i.estatus, s.nombre AS sucursal_nombre
        FROM inventario_sims i
        JOIN sucursales s ON s.id = i.id_sucursal
        WHERE i.iccid=? LIMIT 1
    ");
    $stmt->bind_param('s', $iccid);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
        if ((int)$dup['id_sucursal'] === $idSucursal && $dup['estatus'] === 'Disponible') {
            redir('sim_dup', ['sel_sim'=>(int)$dup['id']]);
        }
        $msg = "El ICCID ya existe (ID {$dup['id']}) en la sucursal {$dup['sucursal_nombre']} con estatus {$dup['estatus']}.";
        redir('sim_err', ['e'=>$msg]);
    }

    // Insert como PREPAGO Disponible en esta sucursal
    $sql = "INSERT INTO inventario_sims (iccid, dn, operador, caja_id, tipo_plan, estatus, id_sucursal)
            VALUES (?,?,?,?, 'Prepago', 'Disponible', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssi', $iccid, $dn, $operador, $caja_id, $idSucursal);

    try {
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        redir('sim_ok', ['sel_sim'=>$newId]);
    } catch (mysqli_sql_exception $e) {
        redir('sim_err', ['e'=>'No se pudo guardar: '.$e->getMessage()]);
    }
}

/* =========================
   PROCESAR VENTA SIM (tu lógica intacta)
========================= */
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['accion'] ?? '') !== 'alta_sim')) {
    $idSim       = (int)$_POST['id_sim'];
    $tipoVenta   = $_POST['tipo_venta'];
    $precio      = (float)$_POST['precio'];
    $comentarios = trim($_POST['comentarios']);
    $fechaVenta  = date('Y-m-d');

    // 1) Verificar SIM y OBTENER operador DESDE INVENTARIO (ignorar POST)
    $sql = "SELECT id, iccid, operador
            FROM inventario_sims
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sim) {
        $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible.</div>';
    } else {
        // Normalizar operador -> tipoSim que usa el esquema
        $tipoSim = (strtoupper($sim['operador']) === 'ATT') ? 'ATT' : 'Bait';

        // 2) Comisiones
        $esquema     = obtenerEsquemaVigente($conn, $fechaVenta);
        $cumpleCuota = cumpleCuotaSucursal($conn, $idSucursal, $fechaVenta);

        $comisionEjecutivo = calcularComisionesSIM($esquema, $tipoSim, $tipoVenta, $cumpleCuota);
        $comisionGerente   = $comisionEjecutivo > 0
            ? ($cumpleCuota ? $esquema['comision_gerente_sim_con'] : $esquema['comision_gerente_sim_sin'])
            : 0;

        // 3) Insertar venta
        $sqlVenta = "INSERT INTO ventas_sims
            (tipo_venta, tipo_sim, comentarios, precio_total, comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, fecha_venta)
            VALUES (?,?,?,?,?,?,?,?,NOW())";
        $stmt = $conn->prepare($sqlVenta);
        $stmt->bind_param("sssddiii", $tipoVenta, $tipoSim, $comentarios, $precio, $comisionEjecutivo, $comisionGerente, $idUsuario, $idSucursal);
        $stmt->execute();
        $idVenta = $stmt->insert_id;
        $stmt->close();

        // 4) Detalle
        $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
        $stmt = $conn->prepare($sqlDetalle);
        $stmt->bind_param("iid", $idVenta, $idSim, $precio);
        $stmt->execute();
        $stmt->close();

        // 5) Actualizar inventario
        $sqlUpdate = "UPDATE inventario_sims
                      SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                      WHERE id=?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param("ii", $idUsuario, $idSim);
        $stmt->execute();
        $stmt->close();

        $mensaje = '<div class="alert alert-success">✅ Venta de SIM registrada correctamente.</div>';
    }
}

/* ===== Nombre de sucursal del usuario ===== */
$nomSucursal = '—';
$stmtNS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtNS->bind_param("i", $idSucursal);
$stmtNS->execute();
$rowNS = $stmtNS->get_result()->fetch_assoc();
if ($rowNS) { $nomSucursal = $rowNS['nombre']; }
$stmtNS->close();

/* ===== Listar SIMs disponibles (incluye operador) ===== */
$sql = "SELECT id, iccid, caja_id, fecha_ingreso, operador
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponiblesRes = $stmt->get_result();
$disponibles = $disponiblesRes->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Venta SIM Prepago</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <style>
    :root{ --brand:#0d6efd; --brand-100: rgba(13,110,253,.08); }
    body.bg-light{
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-title{font-weight:700; letter-spacing:.3px;}
    .card-elev{border:0; box-shadow:0 10px 24px rgba(2,8,20,0.06), 0 2px 6px rgba(2,8,20,0.05); border-radius:1rem;}
    .section-title{font-size:.95rem; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.8px; margin-bottom:.75rem; display:flex; gap:.5rem; align-items:center;}
    .help-text{font-size:.85rem; color:#64748b;}
    .select2-container .select2-selection--single { height: 38px; border-radius:.5rem; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
    .btn-gradient:disabled{opacity:.7;}
    .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
    .list-compact{margin:0; padding-left:1rem;} .list-compact li{margin-bottom:.25rem;}
    .readonly-hint{background:#f1f5f9;}
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <?php if ($flash === 'sim_ok'): ?>
    <div class="alert alert-success">✅ SIM agregada a tu inventario y preseleccionada.</div>
  <?php elseif ($flash === 'sim_dup'): ?>
    <div class="alert alert-info">ℹ️ Ese ICCID ya existía en tu inventario y quedó seleccionado.</div>
  <?php elseif ($flash === 'sim_err'): ?>
    <div class="alert alert-danger">❌ No se pudo agregar la SIM. <?= htmlspecialchars($_GET['e'] ?? '') ?></div>
  <?php endif; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-sim me-2"></i>Venta de SIM Prepago</h2>
      <div class="help-text">Selecciona la SIM y confirma los datos en el modal antes de enviar.</div>
    </div>
  </div>

  <!-- Contexto de sesión -->
  <div class="mb-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombreUser) ?></span>
        <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($nomSucursal) ?></span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <form method="POST" class="card card-elev p-3 mb-4" id="formVentaSim" novalidate>
    <input type="hidden" name="accion" value="venta">
    <div class="card-body">

      <div class="section-title"><i class="bi bi-collection"></i> Selección de SIM</div>
      <div class="row g-3 mb-3">
        <!-- SIM con buscador -->
        <div class="col-md-7">
          <label class="form-label">SIM disponible</label>
          <select name="id_sim" id="selectSim" class="form-select select2-sims" required>
            <option value="">-- Selecciona SIM --</option>
            <?php foreach($disponibles as $row): ?>
              <option
                value="<?= (int)$row['id'] ?>"
                data-operador="<?= htmlspecialchars($row['operador']) ?>"
                data-iccid="<?= htmlspecialchars($row['iccid']) ?>"
                <?= ($selSimId && $selSimId==(int)$row['id']) ? 'selected' : '' ?>
              >
                <?= htmlspecialchars($row['iccid']) ?> | <?= htmlspecialchars($row['operador']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ingreso: <?= htmlspecialchars($row['fecha_ingreso']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Escribe ICCID, operador o caja para filtrar.</div>

          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAltaSim">
              <i class="bi bi-plus-circle me-1"></i> Agregar SIM (no está en inventario)
            </button>
          </div>
        </div>

        <!-- Operador solo lectura -->
        <div class="col-md-5">
          <label class="form-label">Operador (solo lectura)</label>
          <input type="text" id="tipoSimView" class="form-control" value="" readonly>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-receipt"></i> Datos de la venta</div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Tipo de venta</label>
          <select name="tipo_venta" id="tipo_venta" class="form-select" required>
            <option value="Nueva">Nueva</option>
            <option value="Portabilidad">Portabilidad</option>
            <option value="Regalo">Regalo (costo 0)</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Precio</label>
          <input type="number" step="0.01" name="precio" id="precio" class="form-control" value="0" required>
          <div class="form-text" id="precio_help">Para “Regalo”, el precio debe ser 0.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Comentarios</label>
          <input type="text" name="comentarios" id="comentarios" class="form-control" placeholder="Notas (opcional)">
        </div>
      </div>

    </div>
    <div class="card-footer bg-white border-0 p-3">
      <button type="submit" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
        <i class="bi bi-check2-circle me-2"></i> Registrar Venta
      </button>
    </div>
  </form>
</div>

<!-- Modal: Alta rápida de SIM (Prepago) -->
<div class="modal fade" id="modalAltaSim" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-sim me-2 text-primary"></i>Alta de SIM a inventario (Prepago)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form method="POST" id="formAltaSim">
        <input type="hidden" name="accion" value="alta_sim">
        <div class="modal-body">
          <div class="alert alert-secondary py-2">
            Se agregará a tu inventario de <b><?= htmlspecialchars($nomSucursal) ?></b> como <b>Disponible</b>.
          </div>

          <div class="mb-3">
            <label class="form-label">ICCID</label>
            <input type="text" name="iccid" id="alta_iccid" class="form-control" placeholder="8952140063250341909F" maxlength="20" required>
            <div class="form-text">Formato: 19 dígitos + 1 letra mayúscula.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Operador</label>
            <select name="operador" id="alta_operador" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <option value="Bait">Bait</option>
              <option value="AT&T">AT&T</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">DN (10 dígitos)</label>
            <input type="text" name="dn" id="alta_dn" class="form-control" placeholder="5512345678" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Caja ID (opcional)</label>
            <input type="text" name="caja_id" id="alta_caja" class="form-control" placeholder="Etiqueta/caja">
          </div>

          <?php if ($flash==='sim_err' && !empty($_GET['e'])): ?>
            <div class="text-danger small mt-2"><?= htmlspecialchars($_GET['e']) ?></div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar y usar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal de Confirmación -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma la venta de SIM</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> verifica que se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombreUser) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal"><?= htmlspecialchars($nomSucursal) ?></span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-sim"></i> Detalle de venta</div>
                <ul class="list-compact">
                  <li><strong>ICCID:</strong> <span id="conf_iccid">—</span></li>
                  <li><strong>Operador:</strong> <span id="conf_operador">—</span></li>
                  <li><strong>Tipo de venta:</strong> <span id="conf_tipo">—</span></li>
                  <li><strong>Precio:</strong> $<span id="conf_precio">0.00</span></li>
                  <li class="text-muted"><em>Comentarios:</em> <span id="conf_comentarios">—</span></li>
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

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(function(){
  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
  const $form   = $('#formVentaSim');
  const $simSel = $('#selectSim');
  const $precio = $('#precio');
  const $tipo   = $('#tipo_venta');
  const $coment = $('#comentarios');
  const $tipoSimView = $('#tipoSimView');

  // Select2
  $simSel.select2({
    placeholder: '-- Selecciona SIM --',
    width: '100%',
    language: { noResults: () => 'Sin resultados', searching: () => 'Buscando…' }
  });

  // Preselección si venimos de alta
  <?php if ($selSimId): ?> $('#selectSim').trigger('change'); <?php endif; ?>

  function actualizarTipo() {
    const $opt = $simSel.find(':selected');
    const operador = ($opt.data('operador') || '').toString().trim();
    $tipoSimView.val(operador || '');
  }
  actualizarTipo();
  $simSel.on('change', actualizarTipo);

  // Reglas de precio
  function ajustarAyudaPrecio(){
    if ($tipo.val() === 'Regalo') {
      $precio.val('0.00').prop('readonly', true).addClass('readonly-hint');
      $('#precio_help').text('Para “Regalo”, el precio es 0 y no se puede editar.');
    } else {
      $precio.prop('readonly', false).removeClass('readonly-hint');
      if ($tipo.val() === 'Nueva' || $tipo.val() === 'Portabilidad') {
        $('#precio_help').text('Para “Nueva” o “Portabilidad”, el precio debe ser mayor a 0.');
      } else { $('#precio_help').text('Define el precio de la SIM.'); }
    }
  }
  ajustarAyudaPrecio();
  $('#tipo_venta').on('change', ajustarAyudaPrecio);

  // Validación + Modal
  let allowSubmit = false;

  function validar() {
    const errores = [];
    const idSim = $simSel.val();
    const tipo  = $tipo.val();
    const precio = parseFloat($precio.val());

    if (!idSim) errores.push('Selecciona una SIM disponible.');
    if (!tipo) errores.push('Selecciona el tipo de venta.');

    if (tipo === 'Regalo') {
      if (isNaN(precio) || Number(precio.toFixed(2)) !== 0) errores.push('En “Regalo”, el precio debe ser exactamente 0.');
    } else {
      if (isNaN(precio) || precio <= 0) errores.push('El precio debe ser mayor a 0 para Nueva/Portabilidad.');
    }
    return errores;
  }

  function poblarModal(){
    const $opt = $simSel.find(':selected');
    const iccid = ($opt.data('iccid') || '').toString();
    const operador = ($opt.data('operador') || '').toString();
    const tipo = $tipo.val() || '—';
    const precio = parseFloat($precio.val()) || 0;
    const comentarios = ($coment.val() || '').trim();

    $('#conf_iccid').text(iccid || '—');
    $('#conf_operador').text(operador || '—');
    $('#conf_tipo').text(tipo);
    $('#conf_precio').text(precio.toFixed(2));
    $('#conf_comentarios').text(comentarios || '—');
  }

  $form.on('submit', function(e){
    if (allowSubmit) return; // ya confirmado
    e.preventDefault();
    const errs = validar();
    if (errs.length) { alert('Corrige lo siguiente:\n• ' + errs.join('\n• ')); return; }
    poblarModal(); modalConfirm.show();
  });

  $('#btn_confirmar_envio').on('click', function(){
    $('#btn_submit').prop('disabled', true).text('Enviando...');
    allowSubmit = true; modalConfirm.hide(); $form[0].submit();
  });
});
</script>

</body>
</html>
