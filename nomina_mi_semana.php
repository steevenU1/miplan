<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once 'db.php';
require_once 'navbar.php';

date_default_timezone_set('America/Mexico_City');

$idUsuario = (int)$_SESSION['id_usuario'];
$rolSesion = $_SESSION['rol'] ?? '';

/* ========================
   Semanas mar→lun
======================== */
function semanaPorIndice($offset = 0){
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $dif = ((int)$hoy->format('N')) - 2; if ($dif < 0) $dif += 7; // martes=2
  $ini = new DateTime('now', $tz); $ini->modify('-'.$dif.' days')->setTime(0,0,0);
  if ($offset > 0) $ini->modify('-'.(7*$offset).' days');
  $fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini, $fin];
}
function applyOverride($ov, $calc){ return ($ov === null || $ov === '') ? (float)$calc : (float)$ov; }

$semanaOffset = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj, $finObj) = semanaPorIndice($semanaOffset);
$iniISO = $iniObj->format('Y-m-d');
$finISO = $finObj->format('Y-m-d');
$inicioSemana = $iniObj->format('Y-m-d 00:00:00');
$finSemana    = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Datos del usuario
======================== */
$stmtU = $conn->prepare("
  SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
  FROM usuarios u
  INNER JOIN sucursales s ON s.id=u.id_sucursal
  WHERE u.id=? LIMIT 1
");
$stmtU->bind_param("i", $idUsuario);
$stmtU->execute();
$u = $stmtU->get_result()->fetch_assoc();
if (!$u) { echo "Usuario no encontrado."; exit; }
$isGerente   = ($u['rol'] === 'Gerente');
$idSucursal  = (int)$u['id_sucursal'];
$sueldo_calc = (float)$u['sueldo'];

/* ========================
   Acciones: Confirmar nómina
======================== */
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $accion = $_POST['action'] ?? '';
  if ($accion === 'confirmar') {
    // seguridad: solo a sí mismo
    $uidPost = (int)($_POST['id_usuario'] ?? 0);
    if ($uidPost === $idUsuario) {
      // ventana a partir del martes
      $abreConfirm = (clone $finObj)->modify('+1 day')->setTime(0,0,0);
      $ahora = new DateTime('now', new DateTimeZone('America/Mexico_City'));
      if ($ahora < $abreConfirm) {
        $msg = "La confirmación se habilita el martes posterior al cierre de la semana.";
      } else {
        $comentario = trim($_POST['comentario'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conn->prepare("
          INSERT INTO nomina_confirmaciones
            (id_usuario, semana_inicio, semana_fin, confirmado, comentario, confirmado_en, ip_confirmacion)
          VALUES (?,?,?,?,?,NOW(),?)
          ON DUPLICATE KEY UPDATE
            confirmado=VALUES(confirmado),
            comentario=VALUES(comentario),
            confirmado_en=VALUES(confirmado_en),
            ip_confirmacion=VALUES(ip_confirmacion)
        ");
        $uno = 1;
        $stmt->bind_param("ississ", $idUsuario, $iniISO, $finISO, $uno, $comentario, $ip);
        if ($stmt->execute()) { $msg = "✅ Confirmación registrada."; }
        else { $msg = "Error al confirmar."; }
      }
    }
  }
}

/* ========================
   Cálculos base (sin override)
======================== */
// Equipos (ventas propias)
$stmt = $conn->prepare("SELECT IFNULL(SUM(v.comision),0) AS t FROM ventas v WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?");
$stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
$stmt->execute();
$com_equipos_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

// SIMs prepago (ejec)
$com_sims_calc = 0.0;
if (!$isGerente) {
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS t
    FROM ventas_sims vs
    WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
      AND vs.tipo_venta IN ('Nueva','Portabilidad')
  ");
  $stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_sims_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
}

// Pospago (ejec)
$com_pos_calc = 0.0;
if (!$isGerente) {
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(vs.comision_ejecutivo),0) AS t
    FROM ventas_sims vs
    WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
      AND vs.tipo_venta='Pospago'
  ");
  $stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $com_pos_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
}

// Gerente desglose
$dirg_calc = $esceq_calc = $prepg_calc = $posg_calc = 0.0;
if ($isGerente) {
  // Ventas directas del gerente
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(v.comision_gerente),0) AS t
    FROM ventas v
    WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?
  ");
  $stmt->bind_param("iss", $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $dirg_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

  // Escalonado de equipos (vendedor != gerente)
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(v.comision_gerente),0) AS t
    FROM ventas v
    WHERE v.id_sucursal=? AND v.id_usuario<>? AND v.fecha_venta BETWEEN ? AND ?
  ");
  $stmt->bind_param("iiss", $idSucursal, $idUsuario, $inicioSemana, $finSemana);
  $stmt->execute();
  $esceq_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

  // Prepago gerente en ventas_sims (Nueva/Porta)
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(vs.comision_gerente),0) AS t
    FROM ventas_sims vs
    WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?
      AND vs.tipo_venta IN ('Nueva','Portabilidad')
  ");
  $stmt->bind_param("iss", $idSucursal, $inicioSemana, $finSemana);
  $stmt->execute();
  $prepg_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

  // Pospago gerente
  $stmt = $conn->prepare("
    SELECT IFNULL(SUM(vs.comision_gerente),0) AS t
    FROM ventas_sims vs
    WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?
      AND vs.tipo_venta='Pospago'
  ");
  $stmt->bind_param("iss", $idSucursal, $inicioSemana, $finSemana);
  $stmt->execute();
  $posg_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);
}

// Descuentos (semana)
$stmt = $conn->prepare("SELECT IFNULL(SUM(monto),0) AS t FROM descuentos_nomina WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
$stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
$stmt->execute();
$desc_calc = (float)($stmt->get_result()->fetch_assoc()['t'] ?? 0);

/* ========================
   Overrides (si existen)
======================== */
$stmt = $conn->prepare("
  SELECT *
  FROM nomina_overrides_semana
  WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?
  LIMIT 1
");
$stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
$stmt->execute();
$ov = $stmt->get_result()->fetch_assoc() ?: [];

$sueldo   = applyOverride($ov['sueldo_override']   ?? null, $sueldo_calc);
$equipos  = applyOverride($ov['equipos_override']  ?? null, $com_equipos_calc);
$sims     = applyOverride($ov['sims_override']     ?? null, $com_sims_calc);
$pos      = applyOverride($ov['pospago_override']  ?? null, $com_pos_calc);

$dirg     = $isGerente ? applyOverride($ov['ger_dir_override']  ?? null, $dirg_calc)  : 0.0;
$esceq    = $isGerente ? applyOverride($ov['ger_esc_override']  ?? null, $esceq_calc) : 0.0;
$prepg    = $isGerente ? applyOverride($ov['ger_prep_override'] ?? null, $prepg_calc) : 0.0;
$posg     = $isGerente ? applyOverride($ov['ger_pos_override']  ?? null, $posg_calc)  : 0.0;

$desc     = applyOverride($ov['descuentos_override'] ?? null, $desc_calc);
$ajuste   = (float)($ov['ajuste_neto_extra'] ?? 0.0);
$ovEstado = $ov['estado'] ?? null;
$ovNota   = $ov['nota']   ?? null;

/* ========================
   Totales (con override)
======================== */
$com_ger_total = $dirg + $esceq + $prepg + $posg;
$bruto = $sueldo + $equipos + $sims + $pos + $com_ger_total;
$neto  = $bruto - $desc + $ajuste;

/* ========================
   Confirmación
======================== */
$stmt = $conn->prepare("SELECT confirmado, comentario, confirmado_en FROM nomina_confirmaciones WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
$stmt->bind_param("iss", $idUsuario, $iniISO, $finISO);
$stmt->execute();
$conf = $stmt->get_result()->fetch_assoc();
$confirmado   = (int)($conf['confirmado'] ?? 0) === 1;
$confirmadoEn = $conf['confirmado_en'] ?? null;
$comentPrev   = $conf['comentario'] ?? '';

$abreConfirm = (clone $finObj)->modify('+1 day')->setTime(0,0,0);
$ahora = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$puedeConfirmar = ($ahora >= $abreConfirm);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mi Nómina</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{ --card:#fff; --muted:#6b7280; }
  body{ background:#f7f7fb; }
  .card-soft{ background:var(--card); border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
  .text-muted-2{ color:#6b7280; font-size:.8rem; }
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.4rem">🧾</span>
      <div>
        <h4 class="mb-0">Mi Nómina</h4>
        <div class="text-muted small">Semana del <strong><?= $iniObj->format('d/m/Y') ?></strong> al <strong><?= $finObj->format('d/m/Y') ?></strong></div>
      </div>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="form-label mb-0 small text-muted">Semana</label>
      <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php for ($i=0;$i<8;$i++): list($a,$b)=semanaPorIndice($i); ?>
          <option value="<?= $i ?>" <?= $i==$semanaOffset?'selected':'' ?>>Del <?= $a->format('d/m/Y') ?> al <?= $b->format('d/m/Y') ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-3 mb-3">
    <div class="card-soft p-3"><div class="text-muted small mb-1">Colaborador</div><div class="h6 mb-0"><?= htmlspecialchars($u['nombre']) ?> <span class="badge bg-secondary ms-2"><?= htmlspecialchars($u['rol']) ?></span></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Sucursal</div><div class="h6 mb-0"><?= htmlspecialchars($u['sucursal']) ?></div></div>
    <div class="card-soft p-3"><div class="text-muted small mb-1">Estado ajustes</div>
      <div class="h6 mb-0">
        <?= $ovEstado ? 'OV: '.htmlspecialchars($ovEstado, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Sin overrides</span>' ?>
      </div>
      <?php if ($ovNota): ?><div class="text-muted small mt-1">“<?= htmlspecialchars($ovNota) ?>”</div><?php endif; ?>
    </div>
    <div class="card-soft p-3">
      <div class="text-muted small mb-1">Total a Pagar (Neto)</div>
      <div class="h5 mb-0">$<?= number_format($neto,2) ?></div>
    </div>
  </div>

  <div class="card-soft p-0 mb-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Concepto</th><th class="text-end">Importe</th></tr></thead>
        <tbody>
          <tr>
            <td>Sueldo Base</td>
            <td class="text-end">$<?= number_format($sueldo,2) ?></td>
          </tr>

          <tr>
            <td>Comisiones Equipos</td>
            <td class="text-end">$<?= number_format($equipos,2) ?></td>
          </tr>

          <tr>
            <td>Comisiones SIMs</td>
            <td class="text-end">$<?= number_format($sims,2) ?></td>
          </tr>

          <tr>
            <td>Comisiones Pospago (Ejec.)</td>
            <td class="text-end">$<?= number_format($pos,2) ?></td>
          </tr>

          <?php if ($isGerente): ?>
            <tr>
              <td>Comisión Gerente (DirG.)</td>
              <td class="text-end">$<?= number_format($dirg,2) ?></td>
            </tr>
            <tr>
              <td>Comisión Gerente (Esc.Eq.)</td>
              <td class="text-end">$<?= number_format($esceq,2) ?></td>
            </tr>
            <tr>
              <td>Comisión Gerente (PrepG.)</td>
              <td class="text-end">$<?= number_format($prepg,2) ?></td>
            </tr>
            <tr>
              <td>Comisión Gerente (PosG.)</td>
              <td class="text-end">$<?= number_format($posg,2) ?></td>
            </tr>
          <?php endif; ?>

          <tr class="table-danger">
            <td>Descuentos</td>
            <td class="text-end">-$<?= number_format($desc,2) ?></td>
          </tr>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="text-end"><strong>Total a Pagar (Neto)</strong></td>
            <td class="text-end"><strong>$<?= number_format($neto,2) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Solo Confirmación -->
  <div class="card-soft p-3">
    <div class="mb-2"><strong>Confirmar mi nómina</strong></div>
    <?php if ($confirmado): ?>
      <div class="d-flex align-items-center gap-2">
        <span class="text-success">✔</span>
        <div>
          Confirmado el <?= $confirmadoEn ? date('d/m/Y H:i', strtotime($confirmadoEn)) : '' ?><br>
          <?php if ($comentPrev): ?><span class="text-muted small">Comentario: <?= htmlspecialchars($comentPrev, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="confirmar">
        <input type="hidden" name="id_usuario" value="<?= $idUsuario ?>">
        <div class="col-12 col-md-8">
          <input name="comentario" maxlength="255" class="form-control form-control-sm" placeholder="Comentario (opcional)">
        </div>
        <div class="col-12 col-md-4 d-flex justify-content-end">
          <button class="btn btn-primary btn-sm" <?= $puedeConfirmar ? '' : 'disabled' ?>>
            <i class="bi bi-check2-circle me-1"></i> Confirmar nómina
          </button>
        </div>
        <?php if (!$puedeConfirmar): ?>
          <div class="col-12 text-muted small">Se habilita el <strong>martes</strong> posterior al cierre (mar→lun). Apertura para esta nómina: <?= $abreConfirm->format('d/m/Y H:i') ?></div>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
