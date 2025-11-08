<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Ejecutivo','Gerente'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/candado_captura.php';

abortar_si_captura_bloqueada(); // bloquea mutaciones si MODO_CAPTURA=false

$id_usuario  = (int)($_SESSION['id_usuario']  ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);

/* ===========================
   Token anti doble-submit
   =========================== */
if (empty($_SESSION['cobro_token'])) {
    $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
}

/* ===========================
   Helpers
   =========================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $rs && $rs->num_rows > 0;
}

/* ===========================
   Nombre de la sucursal
   =========================== */
$nombre_sucursal = "Sucursal #$id_sucursal";
try {
    $stmtSuc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSuc->bind_param("i", $id_sucursal);
    $stmtSuc->execute();
    $stmtSuc->bind_result($tmpNombre);
    if ($stmtSuc->fetch() && !empty($tmpNombre)) {
        $nombre_sucursal = $tmpNombre;
    }
    $stmtSuc->close();
} catch (Throwable $e) {
    // Fallback ya definido
}

/* ===========================
   Procesar cobro (POST)
   =========================== */
$msg = '';
$lock = (defined('MODO_CAPTURA') && MODO_CAPTURA === false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Valida token idempotencia
    $posted_token = $_POST['cobro_token'] ?? '';
    if (!hash_equals($_SESSION['cobro_token'] ?? '', $posted_token)) {
        $msg = "<div class='alert alert-warning mb-3'>⚠ Sesión expirada o envío duplicado. Recarga la página e intenta de nuevo.</div>";
    } else {
        $motivo         = trim($_POST['motivo'] ?? '');
        $tipo_pago      = $_POST['tipo_pago'] ?? '';
        $monto_total    = (float)($_POST['monto_total'] ?? 0);
        $monto_efectivo = (float)($_POST['monto_efectivo'] ?? 0);
        $monto_tarjeta  = (float)($_POST['monto_tarjeta'] ?? 0);

        // Datos de cliente (solo Innovación Móvil; vienen del modal)
        $nombre_cliente   = trim($_POST['nombre_cliente']   ?? '');
        $telefono_cliente = trim($_POST['telefono_cliente'] ?? '');

        // Redondeo seguro
        $monto_total    = round($monto_total, 2);
        $monto_efectivo = round($monto_efectivo, 2);
        $monto_tarjeta  = round($monto_tarjeta, 2);

        // Normaliza por tipo de pago (evita arrastre de valores ocultos)
        switch ($tipo_pago) {
            case 'Efectivo':
                $monto_efectivo = $monto_total;
                $monto_tarjeta  = 0.00;
                break;
            case 'Tarjeta':
                $monto_tarjeta  = $monto_total;
                $monto_efectivo = 0.00;
                break;
            case 'Mixto':
                // se queda como viene (ya redondeado)
                break;
            default:
                $monto_efectivo = 0.00;
                $monto_tarjeta  = 0.00;
        }

        // Comisión especial alineada a LUGA:
        // SOLO para Abono PayJoy/Krediya y NO aplica si el pago es Tarjeta.
        $esAbono = in_array($motivo, ['Abono PayJoy','Abono Krediya'], true);
        $comision_especial = ($esAbono && $tipo_pago !== 'Tarjeta') ? 10.00 : 0.00;

        // Reglas de Innovación Móvil
        $motivosInnovacion = ['Enganche Innovacion Movil','Pago Innovacion Movil'];
        $esInnovacion = in_array($motivo, $motivosInnovacion, true);

        if ($motivo === '' || $tipo_pago === '' || $monto_total <= 0) {
            $msg = "<div class='alert alert-warning mb-3'>⚠ Debes llenar todos los campos obligatorios.</div>";
        } else {
            // Si es Innovación Móvil, exige datos de cliente
            if ($esInnovacion && ($nombre_cliente === '' || $telefono_cliente === '')) {
                $msg = "<div class='alert alert-warning mb-3'>⚠ Para $motivo debes capturar nombre y teléfono del cliente.</div>";
            } else {
                // Valida coherencia tras normalizar
                $valido = false;
                if ($tipo_pago === 'Efectivo' && abs($monto_efectivo - $monto_total) < 0.01) $valido = true;
                if ($tipo_pago === 'Tarjeta'  && abs($monto_tarjeta  - $monto_total) < 0.01) $valido = true;
                if ($tipo_pago === 'Mixto'    && abs(($monto_efectivo + $monto_tarjeta) - $monto_total) < 0.01) $valido = true;

                if (!$valido) {
                    $msg = "<div class='alert alert-danger mb-3'>⚠ Los montos no cuadran con el tipo de pago seleccionado.</div>";
                } else {
                    // Inserción robusta: incluye nombre/telefono si esas columnas existen
                    $hasNombre = column_exists($conn, 'cobros', 'nombre_cliente');
                    $hasTel    = column_exists($conn, 'cobros', 'telefono_cliente');

                    $cols = "id_usuario, id_sucursal, motivo, tipo_pago, monto_total, monto_efectivo, monto_tarjeta, comision_especial";
                    $vals = "?, ?, ?, ?, ?, ?, ?, ?";
                    $types = "iissdddd";
                    $bind  = [$id_usuario, $id_sucursal, $motivo, $tipo_pago, $monto_total, $monto_efectivo, $monto_tarjeta, $comision_especial];

                    if ($hasNombre) { $cols .= ", nombre_cliente"; $vals .= ", ?"; $types .= "s"; $bind[] = $nombre_cliente; }
                    if ($hasTel)    { $cols .= ", telefono_cliente"; $vals .= ", ?"; $types .= "s"; $bind[] = $telefono_cliente; }

                    $sql = "INSERT INTO cobros ($cols, fecha_cobro, id_corte, corte_generado) VALUES ($vals, NOW(), NULL, 0)";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $msg = "<div class='alert alert-danger mb-3'>❌ Error al preparar INSERT.</div>";
                    } else {
                        // bind dinámico
                        $stmt->bind_param($types, ...$bind);
                        if ($stmt->execute()) {
                            $msg = "<div class='alert alert-success mb-3'>✅ Cobro registrado correctamente.</div>";
                            // Regenera token para impedir re-envío del mismo POST
                            $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
                            // Limpia POST para no repoblar inputs
                            $_POST = [];
                        } else {
                            $msg = "<div class='alert alert-danger mb-3'>❌ Error al registrar cobro.</div>";
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}

/* ===========================
   Cobros de HOY por sucursal
   =========================== */
$tz = new DateTimeZone('America/Mexico_City');
$inicio = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');
$fin    = (new DateTime('tomorrow', $tz))->format('Y-m-d 00:00:00');

$cobros_hoy = [];
$tot_total = $tot_efectivo = $tot_tarjeta = $tot_comision = 0.0;

try {
    $sql = "
        SELECT c.id, c.fecha_cobro, c.motivo, c.tipo_pago,
               c.monto_total, c.monto_efectivo, c.monto_tarjeta, c.comision_especial,
               u.nombre AS usuario
        FROM cobros c
        JOIN usuarios u ON u.id = c.id_usuario
        WHERE c.id_sucursal = ?
          AND c.fecha_cobro >= ? AND c.fecha_cobro < ?
        ORDER BY c.fecha_cobro DESC
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $id_sucursal, $inicio, $fin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $cobros_hoy[] = $row;
        $tot_total    += (float)$row['monto_total'];
        $tot_efectivo += (float)$row['monto_efectivo'];
        $tot_tarjeta  += (float)$row['monto_tarjeta'];
        $tot_comision += (float)$row['comision_especial'];
    }
    $stmt->close();
} catch (Throwable $e) {
    // Silencioso para vista
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Cobro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .page-hero{background:linear-gradient(135deg,#0ea5e9 0%,#22c55e 100%);color:#fff;border-radius:16px;padding:20px;box-shadow:0 8px 24px rgba(2,6,23,.15)}
    .card-soft{border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 8px 24px rgba(2,6,23,.06)}
    .label-req::after{content:" *";color:#ef4444;font-weight:700}
    .form-help{font-size:.9rem;color:#64748b}
    .summary-row{display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px dashed #e2e8f0;font-size:.95rem}
    .summary-row:last-child{border-bottom:0}
    .currency-prefix{min-width:44px}
    .sticky-actions{position:sticky;bottom:0;background:#fff;padding-top:.5rem;margin-top:1rem;border-top:1px solid #e2e8f0}
    .table thead th{white-space:nowrap}
    .badge-soft{background:#eef2ff;color:#3730a3}
  </style>
</head>
<body class="bg-light">
<div class="container py-4">

  <?php if ($lock): ?>
    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
      <i class="bi bi-lock-fill me-2"></i>
      <div><strong>Captura deshabilitada temporalmente.</strong> Podrás registrar cobros cuando el admin lo habilite.</div>
    </div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="page-hero mb-4">
    <div class="d-flex align-items-center">
      <div class="me-3" style="font-size:2rem"><i class="bi bi-cash-coin"></i></div>
      <div>
        <h2 class="h3 mb-0">Registrar Cobro</h2>
        <div class="opacity-75">Captura rápida y validada • <?= h($nombre_sucursal) ?></div>
      </div>
    </div>
  </div>

  <?= $msg ?>

  <div class="row g-4">
    <!-- Columna izquierda: formulario -->
    <div class="col-12 col-lg-7">
      <form method="POST" class="card card-soft p-3 p-md-4" id="formCobro" novalidate>
        <input type="hidden" name="cobro_token" value="<?= h($_SESSION['cobro_token']) ?>">

        <!-- hidden para datos de cliente (los llena el modal si aplica) -->
        <input type="hidden" name="nombre_cliente" id="nombre_cliente_hidden">
        <input type="hidden" name="telefono_cliente" id="telefono_cliente_hidden">

        <!-- Motivo -->
        <div class="mb-3">
          <label class="form-label label-req"><i class="bi bi-clipboard2-check me-1"></i>Motivo del cobro</label>
          <select name="motivo" id="motivo" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php
              $motivoSel = $_POST['motivo'] ?? '';
              $motivos = [
                'Enganche',
                'Equipo de contado',
                'Venta SIM',
                'Recarga Tiempo Aire',
                'Abono PayJoy',
                'Abono Krediya',
                // Ajustes de Innovación Móvil + pospago
                'Pago inicial Pospago',
                'Enganche Innovacion Movil',
                'Pago Innovacion Movil',
              ];
              foreach ($motivos as $m) {
                  $sel = ($motivoSel === $m) ? 'selected' : '';
                  echo "<option $sel>".h($m)."</option>";
              }
            ?>
          </select>
          <div class="form-help">
            Para <strong>Abono PayJoy/Krediya</strong> la comisión especial se agrega
            <em>solo si el pago no es con tarjeta</em>.
          </div>
        </div>

        <!-- Tipo de pago + total -->
        <div class="mb-3">
          <label class="form-label label-req"><i class="bi bi-credit-card-2-front me-1"></i>Tipo de pago</label>
          <div class="row g-2">
            <div class="col-12 col-sm-6">
              <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                <?php
                  $tipoSel = $_POST['tipo_pago'] ?? '';
                  $opts = [''=>'-- Selecciona --','Efectivo'=>'Efectivo','Tarjeta'=>'Tarjeta','Mixto'=>'Mixto'];
                  foreach ($opts as $val=>$txt){
                    $sel = ($tipoSel === $val) ? 'selected' : '';
                    echo "<option value='".h($val)."' $sel>".h($txt)."</option>";
                  }
                ?>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_total" id="monto_total"
                       class="form-control" placeholder="0.00" required
                       value="<?= h((string)($_POST['monto_total'] ?? '')) ?>">
              </div>
              <div class="form-help">Monto total del cobro.</div>
            </div>
          </div>
        </div>

        <!-- Campos condicionales -->
        <div class="row g-3">
          <div class="col-12 col-md-6 pago-efectivo d-none">
            <label class="form-label"><i class="bi bi-cash me-1"></i>Monto en efectivo</label>
            <div class="input-group">
              <span class="input-group-text currency-prefix">$</span>
              <input type="number" step="0.01" min="0" name="monto_efectivo" id="monto_efectivo"
                     class="form-control" placeholder="0.00"
                     value="<?= h((string)($_POST['monto_efectivo'] ?? '')) ?>">
            </div>
          </div>

          <div class="col-12 col-md-6 pago-tarjeta d-none">
            <label class="form-label"><i class="bi bi-credit-card me-1"></i>Monto con tarjeta</label>
            <div class="input-group">
              <span class="input-group-text currency-prefix">$</span>
              <input type="number" step="0.01" min="0" name="monto_tarjeta" id="monto_tarjeta"
                     class="form-control" placeholder="0.00"
                     value="<?= h((string)($_POST['monto_tarjeta'] ?? '')) ?>">
            </div>
          </div>
        </div>

        <div class="mt-3 small text-muted">
          Los importes deben cuadrar con el tipo de pago: efectivo = total, tarjeta = total, mixto = efectivo + tarjeta = total.
        </div>

        <div class="sticky-actions">
          <div class="d-grid mt-3">
            <button type="submit" class="btn btn-success btn-lg" id="btnGuardar"
                    <?= $lock ? 'disabled' : '' ?>><i class="bi bi-save me-2"></i>Guardar Cobro</button>
          </div>
          <?php if ($lock): ?>
            <div class="text-center text-muted mt-2">
              <i class="bi bi-info-circle me-1"></i>El administrador habilitará la captura pronto.
            </div>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Columna derecha: resumen dinámico -->
    <div class="col-12 col-lg-5">
      <div class="card card-soft p-3 p-md-4">
        <div class="d-flex align-items-center mb-2">
          <i class="bi bi-receipt-cutoff me-2 fs-4"></i>
          <h5 class="mb-0">Resumen del cobro</h5>
        </div>
        <div class="summary-row"><span class="text-muted">Motivo</span><strong id="r_motivo">—</strong></div>
        <div class="summary-row"><span class="text-muted">Tipo de pago</span><strong id="r_tipo">—</strong></div>
        <div class="summary-row"><span class="text-muted">Total</span><strong id="r_total">$0.00</strong></div>
        <div class="summary-row"><span class="text-muted">Efectivo</span><strong id="r_efectivo">$0.00</strong></div>
        <div class="summary-row"><span class="text-muted">Tarjeta</span><strong id="r_tarjeta">$0.00</strong></div>
        <div class="summary-row"><span class="text-muted">Comisión especial</span><strong id="r_comision">$0.00</strong></div>
        <div id="r_status" class="mt-3"></div>
        <div class="mt-3 small text-muted"><i class="bi bi-shield-check me-1"></i>Validación en tiempo real.</div>
      </div>
    </div>
  </div>

  <!-- ===================== -->
  <!-- Cobros de hoy (tabla) -->
  <!-- ===================== -->
  <div class="card card-soft p-3 p-md-4 mt-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
      <h5 class="mb-2 mb-sm-0">
        Cobros de hoy — <span class="badge badge-soft"><?= h($nombre_sucursal) ?></span>
      </h5>
      <div class="d-flex gap-2">
        <input type="text" id="filtroTabla" class="form-control" placeholder="Buscar en tabla (motivo, usuario, tipo)" />
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle" id="tablaCobros">
        <thead class="table-light">
          <tr>
            <th>Hora</th>
            <th>Usuario</th>
            <th>Motivo</th>
            <th>Tipo de pago</th>
            <th class="text-end">Total</th>
            <th class="text-end">Efectivo</th>
            <th class="text-end">Tarjeta</th>
            <th class="text-end">Comisión</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($cobros_hoy) === 0): ?>
            <tr><td colspan="8" class="text-center text-muted">Sin cobros registrados hoy en esta sucursal.</td></tr>
          <?php else: ?>
            <?php foreach ($cobros_hoy as $r): ?>
              <tr>
                <td><?= h((new DateTime($r['fecha_cobro']))->format('H:i')) ?></td>
                <td><?= h($r['usuario'] ?? '') ?></td>
                <td><?= h($r['motivo'] ?? '') ?></td>
                <td><?= h($r['tipo_pago'] ?? '') ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_total'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_efectivo'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_tarjeta'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['comision_especial'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (count($cobros_hoy) > 0): ?>
        <tfoot>
          <tr class="fw-semibold">
            <td colspan="4" class="text-end">Totales</td>
            <td class="text-end"><?= number_format($tot_total, 2) ?></td>
            <td class="text-end"><?= number_format($tot_efectivo, 2) ?></td>
            <td class="text-end"><?= number_format($tot_tarjeta, 2) ?></td>
            <td class="text-end"><?= number_format($tot_comision, 2) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
    <div class="small text-muted">Ventana: hoy <?= h((new DateTime('today', $tz))->format('d/m/Y')) ?> — registros más recientes primero (máx. 100).</div>
  </div>

</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap bundle solo para el modal de datos de cliente -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const $motivo=$("#motivo"),
        $tipo=$("#tipo_pago"),
        $total=$("#monto_total"),
        $efectivo=$("#monto_efectivo"),
        $tarjeta=$("#monto_tarjeta");

  const fmt=n=>"$"+(isFinite(n)?Number(n):0).toFixed(2);
  const motivosInnovacion = new Set(['Enganche Innovacion Movil','Pago Innovacion Movil']);

  function toggleCampos(){
    const t=$tipo.val();

    // Mostrar/ocultar secciones
    $(".pago-efectivo, .pago-tarjeta").addClass("d-none");
    if(t==="Efectivo")$(".pago-efectivo").removeClass("d-none");
    if(t==="Tarjeta") $(".pago-tarjeta").removeClass("d-none");
    if(t==="Mixto")   $(".pago-efectivo, .pago-tarjeta").removeClass("d-none");

    // Deshabilitar y limpiar inputs que no aplican (no se envían si están disabled)
    if (t==="Efectivo"){
      $tarjeta.prop("disabled", true).val("");
      $efectivo.prop("disabled", false);
    } else if (t==="Tarjeta"){
      $efectivo.prop("disabled", true).val("");
      $tarjeta.prop("disabled", false);
    } else if (t==="Mixto"){
      $efectivo.prop("disabled", false);
      $tarjeta.prop("disabled", false);
    } else {
      $efectivo.prop("disabled", true).val("");
      $tarjeta.prop("disabled", true).val("");
    }

    validar();
  }

  // Comisión especial alineada a LUGA (no aplica si pago con tarjeta)
  function comisionEspecial(m,t){ return ((m==="Abono PayJoy"||m==="Abono Krediya") && t!=="Tarjeta") ? 10 : 0; }

  function validar(){
    const m=($motivo.val()||"").trim(),
          t=$tipo.val()||"",
          tot=parseFloat($total.val()||0)||0,
          ef=parseFloat($efectivo.val()||0)||0,
          tj=parseFloat($tarjeta.val()||0)||0,
          com=comisionEspecial(m,t);

    $("#r_motivo").text(m||"—");
    $("#r_tipo").text(t||"—");
    $("#r_total").text(fmt(tot));
    $("#r_efectivo").text(fmt(ef));
    $("#r_tarjeta").text(fmt(tj));
    $("#r_comision").text(fmt(com));

    let ok=false;
    if(t==="Efectivo") ok=Math.abs(ef-tot)<0.01;
    if(t==="Tarjeta")  ok=Math.abs(tj-tot)<0.01;
    if(t==="Mixto")    ok=Math.abs((ef+tj)-tot)<0.01;

    const $s=$("#r_status");
    if(!t||tot<=0){
      $s.html(`<div class="alert alert-secondary py-2 mb-0"><i class="bi bi-info-circle me-1"></i>Completa el tipo de pago y el total.</div>`);
      return;
    }
    $s.html(ok
      ? `<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>Montos correctos.</div>`
      : `<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Los montos no cuadran.</div>`
    );
  }

  $("#tipo_pago").on("change", toggleCampos);
  $("#motivo, #monto_total, #monto_efectivo, #monto_tarjeta").on("input change", validar);
  $("#motivo").trigger("focus"); toggleCampos(); validar();

  // === Filtro rápido en tabla de cobros de hoy ===
  $("#filtroTabla").on("input", function(){
    const q = $(this).val().toLowerCase();
    $("#tablaCobros tbody tr").each(function(){
      const t = $(this).text().toLowerCase();
      $(this).toggle(t.indexOf(q) !== -1);
    });
  });

  // ==== Modal de datos de cliente para Innovación Móvil ====
  const btnGuardar = document.getElementById('btnGuardar');
  btnGuardar.addEventListener('click', function(ev){
    const motivo = ($motivo.val() || '').trim();
    if (motivosInnovacion.has(motivo)) {
      const tieneDatos = (document.getElementById('nombre_cliente_hidden').value.trim() !== '' &&
                          document.getElementById('telefono_cliente_hidden').value.trim() !== '');
      if (!tieneDatos) {
        ev.preventDefault();
        ev.stopPropagation();
        new bootstrap.Modal(document.getElementById('clienteModal')).show();
      }
    }
  });

  document.getElementById('btnGuardarCliente').addEventListener('click', function(){
    const n = document.getElementById('nombre_cliente_modal').value.trim();
    const t = document.getElementById('telefono_cliente_modal').value.trim();
    if (n.length < 3) { alert('Nombre del cliente inválido.'); return; }
    if (t.length < 8) { alert('Teléfono del cliente inválido.'); return; }
    document.getElementById('nombre_cliente_hidden').value = n;
    document.getElementById('telefono_cliente_hidden').value = t;
    bootstrap.Modal.getInstance(document.getElementById('clienteModal')).hide();
    document.getElementById('formCobro').submit();
  });

})();
</script>

<!-- Modal Datos del Cliente (solo Innovación Móvil) -->
<div class="modal fade" id="clienteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>Datos del cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Nombre del cliente</label>
          <input type="text" class="form-control" id="nombre_cliente_modal" maxlength="120" placeholder="Nombre y apellidos">
        </div>
        <div class="mb-1">
          <label class="form-label">Teléfono del cliente</label>
          <input type="tel" class="form-control" id="telefono_cliente_modal" maxlength="25" placeholder="10 dígitos">
        </div>
        <div class="small text-muted">Estos datos quedarán ligados al cobro para Innovación Móvil.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btnGuardarCliente"><i class="bi bi-check2-circle me-1"></i>Continuar</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
