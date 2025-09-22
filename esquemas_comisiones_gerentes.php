<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','Gerente General'])) {
  header("Location: index.php");
  exit();
}
require_once __DIR__ . '/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

$hoy = date('Y-m-d');

/* ===== Detectar si existe fecha_fin ===== */
$hasFechaFin = false;
try {
  $chk = $conn->query("SHOW COLUMNS FROM esquemas_comisiones_gerentes LIKE 'fecha_fin'");
  $hasFechaFin = $chk && $chk->num_rows > 0;
} catch (Throwable $e) {
  $hasFechaFin = false;
}

/* ===== Esquema vigente (según exista o no fecha_fin) ===== */
$vigRow = null;
if ($hasFechaFin) {
  // Vigente hoy
  $q = $conn->query("
    SELECT id, fecha_inicio, fecha_fin
    FROM esquemas_comisiones_gerentes
    WHERE fecha_inicio <= CURDATE() AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q? $q->fetch_assoc() : null;

  if (!$vigRow) {
    // Fallback: último anterior a hoy
    $q2 = $conn->query("
      SELECT id, fecha_inicio, fecha_fin
      FROM esquemas_comisiones_gerentes
      WHERE fecha_inicio <= CURDATE()
      ORDER BY fecha_inicio DESC, id DESC
      LIMIT 1
    ");
    $vigRow = $q2? $q2->fetch_assoc() : null;
  }
} else {
  // Sin fecha_fin: vigente = último con fecha_inicio <= hoy
  $q = $conn->query("
    SELECT id, fecha_inicio
    FROM esquemas_comisiones_gerentes
    WHERE fecha_inicio <= CURDATE()
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q? $q->fetch_assoc() : null;
}
$vigenteId = $vigRow['id'] ?? null;

/* ===== Listado completo ===== */
$listRes = $conn->query("SELECT * FROM esquemas_comisiones_gerentes ORDER BY fecha_inicio DESC, id DESC");
$rows = [];
if ($listRes) while ($r = $listRes->fetch_assoc()) $rows[] = $r;

/* Fallback: si nada vino, al menos trae el vigente */
if (empty($rows) && $vigenteId) {
  $one = $conn->query("SELECT * FROM esquemas_comisiones_gerentes WHERE id=".(int)$vigenteId." LIMIT 1");
  if ($one && $one->num_rows) $rows[] = $one->fetch_assoc();
}

/* ===== Helper estado por fila ===== */
function estadoFila(array $row, $hasFechaFin, $hoy, $vigenteId){
  $inicio = $row['fecha_inicio'] ?? null;
  $fin    = $hasFechaFin ? ($row['fecha_fin'] ?? null) : null;
  $isVig  = ($row['id'] ?? null) == $vigenteId;

  if ($inicio && strtotime($inicio) > strtotime($hoy))  return ['Próximo ⏳', 'table-info'];
  if ($hasFechaFin && !empty($fin) && strtotime($fin) < strtotime($hoy) && !$isVig) return ['Vencido', ''];
  return [$isVig ? 'Vigente ✅' : 'Histórico', $isVig ? 'table-success' : ''];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Esquemas de Comisiones - Gerentes</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">📋 Esquemas de Comisiones - Gerentes</h2>
    <a href="nuevo_esquema_gerentes.php" class="btn btn-primary">➕ Nuevo Esquema</a>
  </div>

  <?php if ($vigenteId):
    $infoVig = $conn->query("SELECT fecha_inicio ".($hasFechaFin?", fecha_fin":"")." FROM esquemas_comisiones_gerentes WHERE id=".(int)$vigenteId." LIMIT 1")->fetch_assoc();
    $iniTxt = isset($infoVig['fecha_inicio']) ? date('d/m/Y', strtotime($infoVig['fecha_inicio'])) : '—';
    $finTxt = ($hasFechaFin && !empty($infoVig['fecha_fin'])) ? date('d/m/Y', strtotime($infoVig['fecha_fin'])) : null;
  ?>
    <div class="alert alert-success py-2">
      <strong>Esquema vigente:</strong>
      inicia el <b><?= h($iniTxt) ?></b><?= $finTxt ? ', termina el <b>'.h($finTxt).'</b>' : ', <b>sin fecha fin</b>' ?>
    </div>
  <?php else: ?>
    <div class="alert alert-warning py-2">
      No hay esquema vigente para hoy (<?= h(date('d/m/Y')); ?>).
    </div>
  <?php endif; ?>

  <table class="table table-bordered table-striped table-hover align-middle">
    <thead class="table-dark">
      <tr>
        <th>Fecha Inicio</th>
        <?php if ($hasFechaFin): ?><th>Fecha Fin</th><?php endif; ?>
        <th>Venta Directa sin/con</th>
        <th>Suc 1-10 sin/con</th>
        <th>Suc 11-20 sin/con</th>
        <th>Suc 21+ sin/con</th>
        <th>Modem sin/con</th>
        <th>SIM sin/con</th>
        <th>Pospago c/s eq</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $hasFechaFin?10:9 ?>" class="text-center text-muted">Sin registros.</td></tr>
      <?php else: foreach ($rows as $row):
        [$estado, $filaCls] = estadoFila($row, $hasFechaFin, $hoy, $vigenteId);
        $inicio = $row['fecha_inicio'] ?? null;
        $fin    = $hasFechaFin ? ($row['fecha_fin'] ?? null) : null;

        // Valores defensivos por si alguna columna no existe
        $vd_sin = $row['venta_directa_sin'] ?? 0;  $vd_con = $row['venta_directa_con'] ?? 0;
        $s1_sin = $row['sucursal_1_10_sin'] ?? 0;  $s1_con = $row['sucursal_1_10_con'] ?? 0;
        $s2_sin = $row['sucursal_11_20_sin'] ?? 0; $s2_con = $row['sucursal_11_20_con'] ?? 0;
        $s3_sin = $row['sucursal_21_mas_sin'] ?? 0; $s3_con = $row['sucursal_21_mas_con'] ?? 0;
        $m_sin  = $row['comision_modem_sin'] ?? 0;  $m_con  = $row['comision_modem_con'] ?? 0;
        $sim_sin= $row['comision_sim_sin'] ?? 0;    $sim_con= $row['comision_sim_con'] ?? 0;
        $pos_con= $row['comision_pos_con_equipo'] ?? 0;
        $pos_sin= $row['comision_pos_sin_equipo'] ?? 0;
      ?>
      <tr class="<?= $filaCls ?>">
        <td><?= $inicio ? h(date('d/m/Y', strtotime($inicio))) : '—' ?></td>
        <?php if ($hasFechaFin): ?>
          <td><?= $fin ? h(date('d/m/Y', strtotime($fin))) : '—' ?></td>
        <?php endif; ?>
        <td>$<?= money($vd_sin) ?> / $<?= money($vd_con) ?></td>
        <td>$<?= money($s1_sin) ?> / $<?= money($s1_con) ?></td>
        <td>$<?= money($s2_sin) ?> / $<?= money($s2_con) ?></td>
        <td>$<?= money($s3_sin) ?> / $<?= money($s3_con) ?></td>
        <td>$<?= money($m_sin) ?> / $<?= money($m_con) ?></td>
        <td>$<?= money($sim_sin) ?> / $<?= money($sim_con) ?></td>
        <td>$<?= money($pos_con) ?> / $<?= money($pos_sin) ?></td>
        <td><?= h($estado) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
