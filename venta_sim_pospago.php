<?php
/* venta_sim_pospago.php — Alta express de SIM en el mismo archivo (sin endpoints extra)
   Cambios:
   - DN obligatorio (backend/frontend)
   - Duplicado ICCID muestra nombre de sucursal (no ID)
*/
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser = trim($_SESSION['nombre'] ?? 'Usuario');
$mensaje    = '';
$selSimId   = isset($_GET['sel_sim']) ? (int)$_GET['sel_sim'] : 0; // para preseleccionar tras alta
$flash      = $_GET['msg'] ?? ''; // sim_ok, sim_dup, sim_err

// 🔹 Planes pospago visibles en el selector
$planesPospago = [
  "Plan Bait 199" => 199,
  "Plan Bait 249" => 249,
  "Plan Bait 289" => 289,
  "Plan Bait 339" => 339,
];

/* ===============================
   FUNCIONES AUXILIARES
================================ */
function tipos_mysqli(array $vals): string {
  $t = '';
  foreach ($vals as $v) {
    if (is_int($v))   { $t .= 'i'; continue; }
    if (is_float($v)) { $t .= 'd'; continue; }
    $t .= 's';
  }
  return $t;
}
function obtenerFilaPospagoVigente(mysqli $conn, float $planMonto): ?array {
  $sql = "SELECT comision_con_equipo, comision_sin_equipo
          FROM esquemas_comisiones_pospago
          WHERE tipo='Ejecutivo' AND plan_monto=?
          ORDER BY fecha_inicio DESC
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("d", $planMonto);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}
function calcularComisionPospago(mysqli $conn, float $planMonto, string $modalidad): float {
  $fila = obtenerFilaPospagoVigente($conn, $planMonto);
  if (!$fila) return 0.0;
  $conEquipo = (stripos($modalidad, 'con') !== false);
  return (float)($conEquipo ? $fila['comision_con_equipo'] : $fila['comision_sin_equipo']);
}
function redir(string $msg, array $extra = []) {
  $qs = array_merge(['msg'=>$msg], $extra);
  $url = basename($_SERVER['PHP_SELF']).'?'.http_build_query($qs);
  header("Location: $url");
  exit();
}

/* ===============================
   POST: Alta rápida de SIM (misma página)
   NO usa candado_captura (no es venta)
================================ */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'alta_sim')) {
  $iccid    = strtoupper(trim($_POST['iccid'] ?? ''));
  $operador = trim($_POST['operador'] ?? '');
  $dn       = trim($_POST['dn'] ?? '');
  $caja_id  = trim($_POST['caja_id'] ?? '');
  $tipoPlan = 'Pospago'; // esta vista es Pospago

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

  // Duplicado global (con nombre de sucursal)
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
    // Si ya existe y está disponible en ESTA sucursal, lo “seleccionamos”
    if ((int)$dup['id_sucursal'] === $idSucursal && $dup['estatus'] === 'Disponible') {
      redir('sim_dup', ['sel_sim'=>(int)$dup['id']]);
    }
    // Existe en otra sucursal o con otro estatus
    $msg = "El ICCID ya existe (ID {$dup['id']}) en la sucursal {$dup['sucursal_nombre']} con estatus {$dup['estatus']}.";
    redir('sim_err', ['e'=>$msg]);
  }

  // Insert en inventario_sims (Disponible en la sucursal del usuario)
  $sql = "INSERT INTO inventario_sims (iccid, dn, operador, caja_id, tipo_plan, estatus, id_sucursal)
          VALUES (?,?,?,?, 'Pospago', 'Disponible', ?)";
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

/* ===============================
   POST: Procesar Venta (usa candado)
================================ */
require_once __DIR__ . '/candado_captura.php'; // ya existe en tu proyecto
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'venta')) {
  abortar_si_captura_bloqueada(); // aplica SOLO a la venta

  $esEsim         = isset($_POST['es_esim']) ? 1 : 0;
  $idSim          = $_POST['id_sim'] ?? null;
  $plan           = $_POST['plan'] ?? '';
  $precioPlan     = $planesPospago[$plan] ?? 0;
  $modalidad      = $_POST['modalidad'] ?? 'Sin equipo';
  $idVentaEquipo  = ($_POST['id_venta_equipo'] ?? '') !== '' ? (int)$_POST['id_venta_equipo'] : null;
  $nombreCliente  = trim($_POST['nombre_cliente'] ?? '');
  $numeroCliente  = trim($_POST['numero_cliente'] ?? '');
  $comentarios    = trim($_POST['comentarios'] ?? '');

  if (!$plan || $precioPlan <= 0) {
    $mensaje = '<div class="alert alert-danger">Selecciona un plan válido.</div>';
  }
  if ($mensaje === '' && ($nombreCliente === '' || $numeroCliente === '')) {
    $mensaje = '<div class="alert alert-danger">El nombre y el número del cliente son obligatorios.</div>';
  }
  if ($mensaje === '' && !preg_match('/^\d{10}$/', $numeroCliente)) {
    $mensaje = '<div class="alert alert-danger">El número del cliente debe tener exactamente 10 dígitos.</div>';
  }
  if ($mensaje === '' && !$esEsim && $idSim) {
    $sql = "SELECT id FROM inventario_sims
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sim) {
      $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible en esta sucursal.</div>';
    }
  }

  if ($mensaje === '') {
    $comisionEjecutivo = calcularComisionPospago($conn, (float)$precioPlan, $modalidad);
    $comisionGerente   = 0.0;

    // Venta
    $sqlVenta = "INSERT INTO ventas_sims
      (tipo_venta, comentarios, precio_total, comision_ejecutivo, comision_gerente,
       id_usuario, id_sucursal, fecha_venta, es_esim, modalidad, id_venta_equipo, numero_cliente, nombre_cliente)
      VALUES ('Pospago', ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sqlVenta);
    $vals = [
      $comentarios, $precioPlan, $comisionEjecutivo, $comisionGerente,
      $idUsuario, $idSucursal, $esEsim, $modalidad, $idVentaEquipo, $numeroCliente, $nombreCliente
    ];
    $types = tipos_mysqli($vals);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $idVenta = (int)$stmt->insert_id;
    $stmt->close();

    // Detalle + mover inventario si SIM física
    if (!$esEsim && $idSim) {
      $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
      $stmt = $conn->prepare($sqlDetalle);
      $stmt->bind_param("iid", $idVenta, $idSim, $precioPlan);
      $stmt->execute();
      $stmt->close();

      $sqlUpdate = "UPDATE inventario_sims
                    SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                    WHERE id=?";
      $stmt = $conn->prepare($sqlUpdate);
      $stmt->bind_param("ii", $idUsuario, $idSim);
      $stmt->execute();
      $stmt->close();
    }

    $mensaje = '<div class="alert alert-success">✅ Venta pospago registrada correctamente. Comisión: $'.number_format($comisionEjecutivo,2).'</div>';
  }
}

/* ===============================
   OBTENER NOMBRE DE SUCURSAL
================================ */
$nomSucursal = '—';
$stmtNS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtNS->bind_param("i", $idSucursal);
$stmtNS->execute();
$rowNS = $stmtNS->get_result()->fetch_assoc();
if ($rowNS) { $nomSucursal = $rowNS['nombre']; }
$stmtNS->close();

/* ===============================
   LISTAR SIMs DISPONIBLES (Select2)
================================ */
$sql = "SELECT id, iccid, caja_id, fecha_ingreso
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponiblesRes = $stmt->get_result();
$disponibles = $disponiblesRes->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===============================
   LISTAR VENTAS DE EQUIPO (MISMO DÍA)
================================ */
$sqlEquipos = "
  SELECT v.id, v.fecha_venta, v.nombre_cliente,
         p.marca, p.modelo, p.color, dv.imei1
  FROM ventas v
  INNER JOIN (
      SELECT id_venta, MIN(id) AS min_detalle_id
      FROM detalle_venta GROUP BY id_venta
  ) dmin ON dmin.id_venta = v.id
  INNER JOIN detalle_venta dv ON dv.id = dmin.min_detalle_id
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal = ? AND v.id_usuario = ? AND DATE(v.fecha_venta) = CURDATE()
  ORDER BY v.fecha_venta DESC";
$stmt = $conn->prepare($sqlEquipos);
$stmt->bind_param("ii", $idSucursal, $idUsuario);
$stmt->execute();
$ventasEquipos = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Venta SIM Pospago</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
  :root{ --brand:#0d6efd; --brand-100:rgba(13,110,253,.08); }
  body.bg-light{
    background:
      radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
      radial-gradient(1200px 400px at -10% 120%, rgba(25,135,84,.06), transparent),
      #f8fafc;
  }
  .page-title{font-weight:700; letter-spacing:.3px;}
  .card-elev{border:0; box-shadow:0 10px 24px rgba(2,8,20,0.06), 0 2px 6px rgba(2,8,20,0.05); border-radius:1rem;}
  .section-title{font-size:.95rem; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.8px; margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem;}
  .help-text{font-size:.85rem; color:#64748b;}
  .select2-container .select2-selection--single { height: 38px; border-radius:.5rem; }
  .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
  .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
  .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
  .btn-gradient:disabled{opacity:.7;}
  .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
  .list-compact{margin:0; padding-left:1rem;} .list-compact li{margin-bottom:.25rem;}
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
      <h2 class="page-title mb-1"><i class="bi bi-sim me-2"></i>Venta de SIM Pospago</h2>
      <div class="help-text">Completa los datos y confirma en el modal antes de enviar.</div>
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

  <form method="POST" class="card card-elev p-3 mb-4" id="formPospago" novalidate>
    <input type="hidden" name="accion" value="venta">
    <div class="card-body">

      <div class="section-title"><i class="bi bi-collection"></i> Selección de SIM</div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="es_esim" name="es_esim" onchange="toggleSimSelect()">
        <label class="form-check-label">Es eSIM (no afecta inventario)</label>
      </div>

      <!-- SIM Física -->
      <div class="row g-3 mb-3" id="sim_fisica">
        <div class="col-md-8">
          <label class="form-label">SIM física disponible</label>
          <select name="id_sim" id="id_sim" class="form-select select2-sims">
            <option value="">-- Selecciona SIM --</option>
            <?php foreach($disponibles as $row): ?>
              <option value="<?= (int)$row['id'] ?>"
                      data-iccid="<?= htmlspecialchars($row['iccid']) ?>"
                      <?= ($selSimId && $selSimId==(int)$row['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['iccid']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ing: <?= htmlspecialchars($row['fecha_ingreso']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Escribe ICCID, caja o fecha para filtrar.</div>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAltaSim">
              <i class="bi bi-plus-circle me-1"></i> Agregar SIM (no está en inventario)
            </button>
          </div>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-receipt"></i> Datos de la venta</div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Plan pospago</label>
          <select name="plan" id="plan" class="form-select" onchange="setPrecio()" required>
            <option value="">-- Selecciona plan --</option>
            <?php foreach($planesPospago as $planNombre => $precioP): ?>
              <option value="<?= htmlspecialchars($planNombre) ?>"><?= htmlspecialchars($planNombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Precio/Plan</label>
          <input type="number" step="0.01" id="precio" name="precio" class="form-control" readonly>
        </div>
        <div class="col-md-3">
          <label class="form-label">Modalidad</label>
          <select name="modalidad" id="modalidad" class="form-select" onchange="toggleEquipo()" required>
            <option value="Sin equipo">Sin equipo</option>
            <option value="Con equipo">Con equipo</option>
          </select>
        </div>

        <!-- Relacionar venta de equipo (solo del mismo día) -->
        <div class="col-md-4" id="venta_equipo" style="display:none;">
          <label class="form-label">Relacionar venta de equipo (hoy)</label>
          <select name="id_venta_equipo" id="id_venta_equipo" class="form-select select2-ventas">
            <option value="">-- Selecciona venta --</option>
            <?php while($ve = $ventasEquipos->fetch_assoc()): ?>
              <option value="<?= (int)$ve['id'] ?>"
                      data-descrip="#<?= (int)$ve['id'] ?> | <?= htmlspecialchars(($ve['nombre_cliente'] ?? 'N/D').' • '.$ve['marca'].' '.$ve['modelo'].' '.$ve['color'].' • IMEI '.$ve['imei1']) ?>">
                #<?= (int)$ve['id'] ?> | Cliente: <?= htmlspecialchars($ve['nombre_cliente'] ?? 'N/D') ?>
                | Equipo: <?= htmlspecialchars($ve['marca'].' '.$ve['modelo'].' '.$ve['color']) ?>
                | IMEI: <?= htmlspecialchars($ve['imei1']) ?>
                | <?= date('H:i', strtotime($ve['fecha_venta'])) ?>
              </option>
            <?php endwhile; ?>
          </select>
          <div class="form-text">Solo ventas de <b>hoy</b> en tu sucursal.</div>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-person-vcard"></i> Datos del cliente</div>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label">Nombre del cliente</label>
          <input type="text" name="nombre_cliente" id="nombre_cliente" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Número telefónico</label>
          <input type="text" name="numero_cliente" id="numero_cliente" class="form-control" placeholder="10 dígitos" required>
        </div>
        <div class="col-md-5">
          <label class="form-label">Comentarios</label>
          <input type="text" name="comentarios" id="comentarios" class="form-control">
        </div>
      </div>

    </div>
    <div class="card-footer bg-white border-0 p-3">
      <button type="submit" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
        <i class="bi bi-check2-circle me-2"></i> Registrar Venta Pospago
      </button>
    </div>
  </form>
</div>

<!-- Modal: Alta rápida de SIM (mismo archivo, POST accion=alta_sim) -->
<div class="modal fade" id="modalAltaSim" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-sim me-2 text-primary"></i>Alta de SIM a inventario</h5>
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

<!-- Modal de Confirmación de venta -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma la venta pospago</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> confirma el <u>usuario</u> y la <u>sucursal</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombreUser) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal"><?= htmlspecialchars($nomSucursal) ?></span></li>
                  <li><strong>Tipo SIM:</strong> <span id="conf_tipo_sim">—</span></li>
                  <li class="d-none" id="li_iccid"><strong>ICCID:</strong> <span id="conf_iccid">—</span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-receipt"></i> Detalle de la venta</div>
                <ul class="list-compact">
                  <li><strong>Plan:</strong> <span id="conf_plan">—</span></li>
                  <li><strong>Precio/Plan:</strong> $<span id="conf_precio">0.00</span></li>
                  <li><strong>Modalidad:</strong> <span id="conf_modalidad">—</span></li>
                  <li class="d-none" id="li_equipo"><strong>Venta de equipo:</strong> <span id="conf_equipo">—</span></li>
                  <li><strong>Cliente:</strong> <span id="conf_cliente">—</span></li>
                  <li><strong>Número:</strong> <span id="conf_numero">—</span></li>
                  <li class="text-muted"><em>Comentarios:</em> <span id="conf_comentarios">—</span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <hr>
        <div class="help-text">
          Si algo está incorrecto, cierra el modal y corrige.
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
<!-- Si Bootstrap JS ya va en navbar.php, no lo dupliques. Si no, descomenta: -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
function toggleSimSelect() {
  const isEsim = document.getElementById('es_esim').checked;
  document.getElementById('sim_fisica').style.display = isEsim ? 'none' : 'block';
}
function toggleEquipo() {
  const modalidad = document.getElementById('modalidad').value;
  document.getElementById('venta_equipo').style.display = (modalidad === 'Con equipo') ? 'block' : 'none';
}
function setPrecio() {
  const plan = document.getElementById('plan').value;
  const precios = {"Plan Bait 199":199,"Plan Bait 249":249,"Plan Bait 289":289,"Plan Bait 339":339};
  document.getElementById('precio').value = precios[plan] || 0;
}

$(function(){
  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));

  const $form   = $('#formPospago');
  const $esim   = $('#es_esim');
  const $simSel = $('#id_sim');
  const $plan   = $('#plan');
  const $precio = $('#precio');
  const $modal  = $('#modalidad');
  const $venta  = $('#id_venta_equipo');
  const $nombre = $('#nombre_cliente');
  const $numero = $('#numero_cliente');
  const $coment = $('#comentarios');

  $('.select2-sims').select2({ placeholder:'-- Selecciona SIM --', width:'100%', language:{ noResults:()=> 'Sin resultados', searching:()=> 'Buscando…' }});
  $('.select2-ventas').select2({ placeholder:'-- Selecciona venta --', width:'100%', language:{ noResults:()=> 'Sin resultados', searching:()=> 'Buscando…' }});

  // Si venimos de alta (sel_sim en URL), Select2 respeta option[selected]; refrescamos UI:
  <?php if ($selSimId): ?> $('#id_sim').trigger('change'); <?php endif; ?>

  toggleSimSelect(); toggleEquipo(); setPrecio();

  function validar(){
    const errs = [];
    const plan = $plan.val();
    const precio = parseFloat($precio.val());
    if (!plan) errs.push('Selecciona un plan.');
    if (isNaN(precio) || precio <= 0) errs.push('El precio/plan es inválido o 0.');
    const nom = ($nombre.val() || '').trim();
    if (!nom) errs.push('El nombre del cliente es obligatorio.');
    const num = ($numero.val() || '').trim();
    if (!num) { errs.push('El número del cliente es obligatorio.'); }
    else if (!/^\d{10}$/.test(num)) { errs.push('El número del cliente debe tener 10 dígitos.'); }
    return errs;
  }

  function poblarModal(){
    const isEsim = $esim.is(':checked');
    $('#conf_tipo_sim').text(isEsim ? 'eSIM' : 'Física');
    if (!isEsim && $simSel.val()) {
      const iccid = $simSel.find(':selected').data('iccid') || '';
      $('#conf_iccid').text(iccid || '—');
      $('#li_iccid').removeClass('d-none');
    } else { $('#li_iccid').addClass('d-none'); }
    const planTxt = $plan.find(':selected').text() || '—';
    const precio  = parseFloat($precio.val()) || 0;
    $('#conf_plan').text(planTxt);
    $('#conf_precio').text(precio.toFixed(2));
    const modTxt = $modal.val() || '—';
    $('#conf_modalidad').text(modTxt);
    if (modTxt === 'Con equipo' && $venta.val()) {
      const descr = $venta.find(':selected').data('descrip') || ('#'+$venta.val());
      $('#conf_equipo').text(descr); $('#li_equipo').removeClass('d-none');
    } else { $('#li_equipo').addClass('d-none'); }
    $('#conf_cliente').text(($nombre.val() || '—'));
    $('#conf_numero').text(($numero.val() || '—'));
    $('#conf_comentarios').text(($coment.val() || '—'));
  }

  let allowSubmit = false;
  $form.on('submit', function(e){
    if (allowSubmit) return;
    e.preventDefault();
    const errs = validar();
    if (errs.length){ alert('Corrige lo siguiente:\n• ' + errs.join('\n• ')); return; }
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
