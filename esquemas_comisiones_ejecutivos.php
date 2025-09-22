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

/* --- ¿existe fecha_fin? --- */
$hasFechaFin = false;
try {
  $chk = $conn->query("SHOW COLUMNS FROM esquemas_comisiones_ejecutivos LIKE 'fecha_fin'");
  $hasFechaFin = $chk && $chk->num_rows > 0;
} catch(Throwable $e){ $hasFechaFin = false; }

/* --- obtener vigente hoy (o último anterior a hoy como fallback) --- */
$vigRow = null;
if ($hasFechaFin) {
  $q = $conn->query("
    SELECT id, fecha_inicio, fecha_fin
    FROM esquemas_comisiones_ejecutivos
    WHERE fecha_inicio <= CURDATE() AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q? $q->fetch_assoc() : null;

  if (!$vigRow) {
    $q2 = $conn->query("
      SELECT id, fecha_inicio, fecha_fin
      FROM esquemas_comisiones_ejecutivos
      WHERE fecha_inicio <= CURDATE()
      ORDER BY fecha_inicio DESC, id DESC
      LIMIT 1
    ");
    $vigRow = $q2? $q2->fetch_assoc() : null;
  }
} else {
  $q = $conn->query("
    SELECT id, fecha_inicio
    FROM esquemas_comisiones_ejecutivos
    WHERE fecha_inicio <= CURDATE()
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q? $q->fetch_assoc() : null;
}
$vigenteId = $vigRow['id'] ?? null;

/* --- listado completo --- */
$listRes = $conn->query("SELECT * FROM esquemas_comisiones_ejecutivos ORDER BY fecha_inicio DESC, id DESC");
$rows = [];
if ($listRes) {
  while ($r = $listRes->fetch_assoc()) $rows[] = $r;
}

/* fallback duro: si por cualquier razón no vino nada, trae al menos el vigente */
if (empty($rows) && $vigenteId) {
  $one = $conn->query("SELECT * FROM esquemas_comisiones_ejecutivos WHERE id=".(int)$vigenteId." LIMIT 1");
  if ($one && $one->num_rows) $rows[] = $one->fetch_assoc();
}

/* helper de estado por fila */
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
  <title>Esquemas de Comisiones - Ejecutivos</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">📋 Esquemas de Comisiones - Ejecutivos</h2>
    <a href="nuevo_esquema_ejecutivos.php" class="btn btn-primary">➕ Nuevo Esquema</a>
  </div>

  <?php if ($vigenteId):
    $infoVig = $conn->query("SELECT fecha_inicio ".($hasFechaFin?", fecha_fin":"")." FROM esquemas_comisiones_ejecutivos WHERE id=".(int)$vigenteId." LIMIT 1")->fetch_assoc();
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
        <th>C sin / con</th>
        <th>B sin / con</th>
        <th>A sin / con</th>
        <th>MiFi sin / con</th>
        <th>Combo</th>
        <th>SIM Bait N/P<br><span class="text-muted small">sin &nbsp;/ con</span></th>
        <th>SIM ATT N/P<br><span class="text-muted small">sin &nbsp;/ con</span></th>
        <th>Pospago c/s eq</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $hasFechaFin?11:10 ?>" class="text-center text-muted">Sin registros.</td></tr>
      <?php else: foreach ($rows as $row):
        [$estado, $filaCls] = estadoFila($row, $hasFechaFin, $hoy, $vigenteId);
        $inicio = $row['fecha_inicio'] ?? null;
        $fin    = $hasFechaFin ? ($row['fecha_fin'] ?? null) : null;

        /* valores defensivos por si alguna columna no existe en tu esquema actual */
        $c_sin = $row['comision_c_sin'] ?? 0;  $c_con = $row['comision_c_con'] ?? 0;
        $b_sin = $row['comision_b_sin'] ?? 0;  $b_con = $row['comision_b_con'] ?? 0;
        $a_sin = $row['comision_a_sin'] ?? 0;  $a_con = $row['comision_a_con'] ?? 0;
        $m_sin = $row['comision_mifi_sin'] ?? 0; $m_con = $row['comision_mifi_con'] ?? 0;
        $combo = $row['comision_combo'] ?? 0;

        $bait_n_sin = $row['comision_sim_bait_nueva_sin'] ?? 0;
        $bait_n_con = $row['comision_sim_bait_nueva_con'] ?? 0;
        $bait_p_sin = $row['comision_sim_bait_port_sin']  ?? 0;
        $bait_p_con = $row['comision_sim_bait_port_con']  ?? 0;

        $att_n_sin = $row['comision_sim_att_nueva_sin'] ?? 0;
        $att_n_con = $row['comision_sim_att_nueva_con'] ?? 0;
        $att_p_sin = $row['comision_sim_att_port_sin']  ?? 0;
        $att_p_con = $row['comision_sim_att_port_con']  ?? 0;

        $pos_con   = $row['comision_pos_con_equipo'] ?? 0;
        $pos_sin   = $row['comision_pos_sin_equipo'] ?? 0;
      ?>
      <tr class="<?= $filaCls ?>">
        <td><?= $inicio ? h(date('d/m/Y', strtotime($inicio))) : '—' ?></td>
        <?php if ($hasFechaFin): ?>
          <td><?= $fin ? h(date('d/m/Y', strtotime($fin))) : '—' ?></td>
        <?php endif; ?>
        <td>$<?= money($c_sin) ?> / $<?= money($c_con) ?></td>
        <td>$<?= money($b_sin) ?> / $<?= money($b_con) ?></td>
        <td>$<?= money($a_sin) ?> / $<?= money($a_con) ?></td>
        <td>$<?= money($m_sin) ?> / $<?= money($m_con) ?></td>
        <td>$<?= money($combo) ?></td>
        <td>
          $<?= money($bait_n_sin) ?> / $<?= money($bait_n_con) ?><br>
          $<?= money($bait_p_sin) ?> / $<?= money($bait_p_con) ?>
        </td>
        <td>
          $<?= money($att_n_sin) ?> / $<?= money($att_n_con) ?><br>
          $<?= money($att_p_sin) ?> / $<?= money($att_p_con) ?>
        </td>
        <td>$<?= money($pos_con) ?> / $<?= money($pos_sin) ?></td>
        <td><?= h($estado) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
