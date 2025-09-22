<?php
// historial_payjoy_tc.php — Historial de TC PayJoy (mobile-first, cards, export)
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

/* ===== Helpers de tiempo (Semana martes-lunes) ===== */
function obtenerSemanaPorIndice(int $offset = 0): array {
  $hoy = new DateTime();
  $dia = (int)$hoy->format('N'); // 1=lun..7=dom
  $dif = $dia - 2; if ($dif < 0) $dif += 7; // martes=2
  $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
  if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
  $fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);
  return [$inicio,$fin];
}
function rangoMensualDesde(DateTime $cualquierDiaDelMes): array {
  $mIni = (clone $cualquierDiaDelMes)->modify('first day of this month')->setTime(0,0,0);
  $mFin = (clone $cualquierDiaDelMes)->modify('last day of this month')->setTime(23,59,59);
  return [$mIni, $mFin];
}

/* ===== Contexto de sesión / roles ===== */
$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$isAdmin    = in_array($ROL, ['Admin','SuperAdmin','RH'], true);
$isGerente  = in_array($ROL, ['Gerente','Gerente General','GerenteZona','GerenteSucursal'], true);
$isEjecutivo= !$isAdmin && !$isGerente;

/* ===== Semana seleccionada ===== */
$semana = isset($_GET['semana']) ? max(0,(int)$_GET['semana']) : 0;
list($iniObj,$finObj) = obtenerSemanaPorIndice($semana);
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ===== Filtros UI (según rol) ===== */
$fil_suc  = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;
$fil_user = isset($_GET['id_usuario'])  ? (int)$_GET['id_usuario']  : 0;
$q        = trim($_GET['q'] ?? '');

/* Normalización por rol */
if ($isEjecutivo) {
  // Ejecutivo: bloqueado a su usuario y sucursal
  $fil_suc  = $idSucursal;
  $fil_user = $idUsuario;
} elseif ($isGerente) {
  // Gerente: bloqueado a su sucursal; usuario opcional para filtrar dentro de su tienda
  $fil_suc  = $idSucursal;
  // $fil_user puede venir 0 = todos, o un usuario específico de su sucursal
  // si alguien intenta inyectar otra sucursal por GET, lo ignoramos
}

/* ===== Data selects (solo donde aplica) ===== */
$sucursales = [];
$usuarios   = [];
if ($isAdmin) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
}
if ($isAdmin) {
  $ru = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre");
  while ($r = $ru->fetch_assoc()) $usuarios[] = $r;
} elseif ($isGerente) {
  // usuarios de la sucursal del gerente
  $stmtU = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id_sucursal=? ORDER BY nombre");
  $stmtU->bind_param("i", $idSucursal);
  $stmtU->execute();
  $ru = $stmtU->get_result();
  while ($r = $ru->fetch_assoc()) $usuarios[] = $r;
  $stmtU->close();
}

/* ===== Query principal (por semana) ===== */
$sql = "
  SELECT v.id, v.fecha_venta, v.nombre_cliente, v.tag, v.comision,
         u.id AS id_usuario, u.nombre AS usuario, s.id AS id_sucursal, s.nombre AS sucursal
  FROM ventas_payjoy_tc v
  INNER JOIN usuarios u   ON u.id = v.id_usuario
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.fecha_venta BETWEEN ? AND ?
";
$params = [$ini,$fin];
$types  = "ss";

/* filtros por rol */
if ($isEjecutivo) {
  $sql .= " AND v.id_usuario=? AND v.id_sucursal=? ";
  $params[] = $idUsuario; $params[] = $idSucursal; $types .= "ii";
} elseif ($isGerente) {
  $sql .= " AND v.id_sucursal=? ";
  $params[] = $idSucursal; $types .= "i";
  if ($fil_user > 0) { $sql .= " AND v.id_usuario=? "; $params[] = $fil_user; $types .= "i"; }
} else { // Admin
  if ($fil_suc > 0)  { $sql .= " AND v.id_sucursal=? "; $params[] = $fil_suc;  $types .= "i"; }
  if ($fil_user > 0) { $sql .= " AND v.id_usuario=? ";  $params[] = $fil_user; $types .= "i"; }
}

/* búsqueda */
if ($q !== '') {
  $sql .= " AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) ";
  $params[] = $q; $params[] = $q; $types .= "ss";
}
$sql .= " ORDER BY v.fecha_venta DESC, v.id DESC ";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalRegs = 0;
$totalCom  = 0.0;
while ($r = $res->fetch_assoc()) {
  $rows[] = $r;
  $totalRegs++;
  $totalCom += (float)$r['comision'];
}
$stmt->close();

/* ===== KPIs adicionales (promedio, por usuario, rango mensual) ===== */
$promedio = $totalRegs > 0 ? ($totalCom / $totalRegs) : 0.0;

/* Top usuarios (solo Admin/Gerente) */
$topUsuarios = [];
if (!$isEjecutivo) {
  $sqlTop = "
    SELECT u.nombre AS usuario, COUNT(*) AS ventas, SUM(v.comision) AS com_total
    FROM ventas_payjoy_tc v
    INNER JOIN usuarios u ON u.id = v.id_usuario
    WHERE v.fecha_venta BETWEEN ? AND ?
  ";
  $pTop = [$ini,$fin]; $tTop = "ss";
  if ($isGerente) { $sqlTop .= " AND v.id_sucursal=? "; $pTop[]=$idSucursal; $tTop.="i"; }
  if ($isAdmin && $fil_suc>0) { $sqlTop .= " AND v.id_sucursal=? "; $pTop[]=$fil_suc; $tTop.="i"; }
  if ($isAdmin && $fil_user>0) { $sqlTop .= " AND v.id_usuario=? "; $pTop[]=$fil_user; $tTop.="i"; }
  if ($q!=='') { $sqlTop.=" AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) "; $pTop[]=$q; $pTop[]=$q; $tTop.="ss"; }
  $sqlTop .= " GROUP BY u.id, u.nombre ORDER BY ventas DESC, com_total DESC LIMIT 5";
  $st = $conn->prepare($sqlTop);
  $st->bind_param($tTop, ...$pTop);
  $st->execute();
  $rt = $st->get_result();
  while ($row = $rt->fetch_assoc()) $topUsuarios[] = $row;
  $st->close();
}

/* Rango mensual para export/summary */
list($mIniObj,$mFinObj) = rangoMensualDesde($iniObj);
$mIni = $mIniObj->format('Y-m-d 00:00:00');
$mFin = $mFinObj->format('Y-m-d 23:59:59');

/* KPIs mensuales rápidos */
$sqlMonth = "
  SELECT COUNT(*) AS ventas, IFNULL(SUM(comision),0) AS com_total
  FROM ventas_payjoy_tc
  WHERE fecha_venta BETWEEN ? AND ?
";
$pM = [$mIni,$mFin]; $tM="ss";
if ($isEjecutivo) {
  $sqlMonth .= " AND id_usuario=? AND id_sucursal=? ";
  $pM[]=$idUsuario; $pM[]=$idSucursal; $tM.="ii";
} elseif ($isGerente) {
  $sqlMonth .= " AND id_sucursal=? "; $pM[]=$idSucursal; $tM.="i";
} else {
  if ($fil_suc>0) { $sqlMonth.=" AND id_sucursal=? "; $pM[]=$fil_suc; $tM.="i"; }
  if ($fil_user>0){ $sqlMonth.=" AND id_usuario=? ";  $pM[]=$fil_user; $tM.="i"; }
}
if ($q!=='') { $sqlMonth.=" AND (tag LIKE CONCAT('%',?,'%') OR nombre_cliente LIKE CONCAT('%',?,'%')) "; $pM[]=$q; $pM[]=$q; $tM.="ss"; }
$stmM = $conn->prepare($sqlMonth);
$stmM->bind_param($tM, ...$pM);
$stmM->execute();
$rm = $stmM->get_result()->fetch_assoc();
$stmM->close();
$mensualVentas = (int)($rm['ventas'] ?? 0);
$mensualCom    = (float)($rm['com_total'] ?? 0.0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial TC PayJoy</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  :root { --radius: 16px; }
  body { background:#f7f8fa; }
  .page { padding: 16px; }
  .card-soft {
    border:none; border-radius: var(--radius);
    box-shadow: 0 8px 20px rgba(0,0,0,.06);
    background:#fff;
  }
  .kpi {
    border-radius: 14px; padding:14px; background:#f9fbff; border:1px solid #e9f0ff;
  }
  .kpi h6 { margin:0; font-weight:700; color:#4777ff; font-size:.9rem; }
  .kpi .v { font-size:1.4rem; font-weight:800; }
  .sticky-actions{
    position: sticky; top:0; z-index: 1010; background:#f7f8fa; padding:10px 0 8px; 
  }
  .table-rounded {
    border-radius: var(--radius); overflow:hidden; border:1px solid #e9ecef; background:#fff;
  }
  .badge-role { font-weight:600; }
</style>
</head>
<body>
<div class="page container-fluid">
  <!-- Barra de filtros / acciones -->
  <div class="sticky-actions">
    <div class="d-flex flex-wrap align-items-end gap-2">
      <div class="d-flex align-items-center gap-2">
        <h3 class="m-0">💳 PayJoy · Historial</h3>
        <span class="badge bg-secondary badge-role">
          <?= $isAdmin ? 'Admin' : ($isGerente ? 'Gerente' : 'Ejecutivo'); ?>
        </span>
      </div>

      <form class="row gy-2 gx-2 align-items-end ms-auto" method="get">
        <input type="hidden" name="semana" value="<?= $semana ?>">
        <?php if ($isAdmin): ?>
          <div class="col-auto">
            <label class="form-label mb-0 small">Sucursal</label>
            <select class="form-select form-select-sm" name="id_sucursal">
              <option value="0">Todas</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ($fil_suc===(int)$s['id']?'selected':'') ?>>
                  <?= htmlspecialchars($s['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <label class="form-label mb-0 small">Usuario</label>
            <select class="form-select form-select-sm" name="id_usuario">
              <option value="0">Todos</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ($fil_user===(int)$u['id']?'selected':'') ?>>
                  <?= htmlspecialchars($u['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php elseif ($isGerente): ?>
          <div class="col-auto">
            <label class="form-label mb-0 small">Sucursal</label>
            <input class="form-control form-control-sm" value="Tu sucursal (ID: <?= $idSucursal ?>)" disabled>
          </div>
          <div class="col-auto">
            <label class="form-label mb-0 small">Usuario</label>
            <select class="form-select form-select-sm" name="id_usuario">
              <option value="0">Todos</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ($fil_user===(int)$u['id']?'selected':'') ?>>
                  <?= htmlspecialchars($u['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="id_sucursal" value="<?= $idSucursal ?>">
          <input type="hidden" name="id_usuario"  value="<?= $idUsuario ?>">
        <?php endif; ?>
        <div class="col-auto">
          <label class="form-label mb-0 small">Buscar</label>
          <input class="form-control form-control-sm" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="TAG o cliente">
        </div>
        <div class="col-auto">
          <button class="btn btn-sm btn-primary px-3">Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-3">
      <div class="kpi">
        <h6>Semana (mar→lun)</h6>
        <div class="v"><?= $iniObj->format('d/m/Y') ?> — <?= $finObj->format('d/m/Y') ?></div>
        <div class="text-muted small">Vista actual</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi">
        <h6>Ventas semana</h6>
        <div class="v"><?= $totalRegs ?></div>
        <div class="text-muted small">Registros</div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="kpi">
        <h6>Comisión semana</h6>
        <div class="v">$<?= number_format($totalCom,2) ?></div>
        <div class="text-muted small">$100 c/u</div>
      </div>
    </div>
    <div class="col-12 col-lg-3">
      <div class="kpi">
        <h6>Promedio por venta</h6>
        <div class="v">$<?= number_format($promedio,2) ?></div>
        <div class="text-muted small">Esperado ≈ $100</div>
      </div>
    </div>
  </div>

  <!-- KPIs mensuales y export -->
  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-4">
      <div class="kpi">
        <h6>Mes actual</h6>
        <div class="v"><?= $mIniObj->format('M Y') ?></div>
        <div class="text-muted small"><?= $mIniObj->format('d/m') ?> — <?= $mFinObj->format('d/m') ?></div>
      </div>
    </div>
    <div class="col-6 col-lg-4">
      <div class="kpi">
        <h6>Ventas mes</h6>
        <div class="v"><?= $mensualVentas ?></div>
        <div class="text-muted small">Acumulado</div>
      </div>
    </div>
    <div class="col-6 col-lg-4">
      <div class="kpi">
        <h6>Comisión mes</h6>
        <div class="v">$<?= number_format($mensualCom,2) ?></div>
        <div class="text-muted small">$100 c/u</div>
      </div>
    </div>
  </div>

  <!-- Acciones de rango / export -->
  <div class="d-flex flex-wrap gap-2 mt-3">
    <a class="btn btn-sm btn-outline-secondary <?= $semana===1?'active':'' ?>" href="?semana=1">← Semana anterior</a>
    <a class="btn btn-sm btn-secondary <?= $semana===0?'active':'' ?>" href="?semana=0">Semana actual</a>
    <a class="btn btn-sm btn-outline-success" href="payjoy_tc_nueva.php">+ Nueva venta</a>

    <!-- Exportar -->
    <?php
      // construir query role-aware para export
      $common = [
        'q' => $q,
        'id_sucursal' => $isAdmin ? $fil_suc : $idSucursal,
        'id_usuario'  => $isAdmin ? $fil_user : ($isGerente ? $fil_user : $idUsuario),
      ];
      $qs_week = http_build_query(array_merge($common, [
        'scope'=>'week',
        'ini'=>$ini,
        'fin'=>$fin
      ]));
      $qs_month = http_build_query(array_merge($common, [
        'scope'=>'month',
        'm_ini'=>$mIni,
        'm_fin'=>$mFin
      ]));
    ?>
    <a class="btn btn-sm btn-outline-primary" href="export_payjoy_tc.php?<?= $qs_week ?>">Exportar CSV (Semana)</a>
    <a class="btn btn-sm btn-outline-primary" href="export_payjoy_tc.php?<?= $qs_month ?>">Exportar CSV (Mes)</a>
  </div>

  <!-- Top usuarios (solo Admin/Gerente) -->
  <?php if (!$isEjecutivo): ?>
    <div class="card-soft p-3 mt-3">
      <h5 class="mb-2">🏅 Top ejecutivos (semana)</h5>
      <?php if (!$topUsuarios): ?>
        <div class="text-muted small">Sin datos.</div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($topUsuarios as $t): ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="kpi">
                <h6><?= htmlspecialchars($t['usuario']) ?></h6>
                <div class="v"><?= (int)$t['ventas'] ?> ventas</div>
                <div class="text-muted small">$<?= number_format((float)$t['com_total'],2) ?> en comisión</div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Tabla -->
  <div class="table-responsive mt-3 table-rounded">
    <table class="table table-hover align-middle m-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Fecha</th>
          <th>Sucursal</th>
          <th>Usuario</th>
          <th>Cliente</th>
          <th>TAG</th>
          <th class="text-end">Comisión</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars((new DateTime($r['fecha_venta']))->format('d/m/Y H:i')) ?></td>
          <td><?= htmlspecialchars($r['sucursal']) ?></td>
          <td><?= htmlspecialchars($r['usuario']) ?></td>
          <td><?= htmlspecialchars($r['nombre_cliente']) ?></td>
          <td><span class="badge bg-light text-dark"><?= htmlspecialchars($r['tag']) ?></span></td>
          <td class="text-end">$<?= number_format((float)$r['comision'],2) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Sin registros en el rango.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
