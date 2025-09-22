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

/* ==========================================================
   ¿La tabla de esquemas de ejecutivos tiene fecha_fin?
   (Pospago referencia a ese esquema vía ecp.id_esquema)
========================================================== */
$hasFechaFin = false;
try {
  $chk = $conn->query("SHOW COLUMNS FROM esquemas_comisiones_ejecutivos LIKE 'fecha_fin'");
  $hasFechaFin = $chk && $chk->num_rows > 0;
} catch(Throwable $e){ $hasFechaFin = false; }

/* ==========================================================
   Determinar esquema vigente de ejecutivos (para marcar filas)
========================================================== */
$vigRow = null;
if ($hasFechaFin) {
  $q = $conn->query("
    SELECT id, fecha_inicio, fecha_fin
    FROM esquemas_comisiones_ejecutivos
    WHERE fecha_inicio <= CURDATE() AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q ? $q->fetch_assoc() : null;

  if (!$vigRow) {
    // Fallback: último anterior a hoy
    $q2 = $conn->query("
      SELECT id, fecha_inicio, fecha_fin
      FROM esquemas_comisiones_ejecutivos
      WHERE fecha_inicio <= CURDATE()
      ORDER BY fecha_inicio DESC, id DESC
      LIMIT 1
    ");
    $vigRow = $q2 ? $q2->fetch_assoc() : null;
  }
} else {
  // Sin fecha_fin: vigente = último con fecha_inicio <= hoy
  $q = $conn->query("
    SELECT id, fecha_inicio
    FROM esquemas_comisiones_ejecutivos
    WHERE fecha_inicio <= CURDATE()
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");
  $vigRow = $q ? $q->fetch_assoc() : null;
}
$vigenteId = $vigRow['id'] ?? null;

/* ==========================================================
   Listado de planes pospago + esquema al que pertenecen
========================================================== */
$selectFechaFin = $hasFechaFin ? ", ec.fecha_fin AS fecha_fin_esquema" : "";
$sql = "
  SELECT 
    ecp.*, 
    ec.id AS esquema_id,
    ec.fecha_inicio AS fecha_esquema
    $selectFechaFin
  FROM esquemas_comisiones_pospago ecp
  LEFT JOIN esquemas_comisiones_ejecutivos ec
    ON ec.id = ecp.id_esquema
  ORDER BY ecp.tipo, ec.fecha_inicio DESC, ecp.plan_monto DESC, ecp.id DESC
";
$res = $conn->query($sql);
$rows = [];
if ($res) while($r = $res->fetch_assoc()) $rows[] = $r;

/* ==========================================================
   Helper: estado por fila (según fechas del esquema asociado)
========================================================== */
function estadoFilaPos(array $row, $hasFechaFin, $hoy, $vigenteId){
  $ini = $row['fecha_esquema'] ?? null;
  $fin = $hasFechaFin ? ($row['fecha_fin_esquema'] ?? null) : null;
  $isVig = ($row['esquema_id'] ?? null) == $vigenteId;

  if ($ini && strtotime($ini) > strtotime($hoy))  return ['Próximo ⏳', 'table-info'];
  if ($hasFechaFin && !empty($fin) && strtotime($fin) < strtotime($hoy) && !$isVig) return ['Vencido', ''];
  return [$isVig ? 'Vigente ✅' : 'Histórico', $isVig ? 'table-success' : ''];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Comisiones Pospago por Plan</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">📋 Comisiones Pospago por Plan</h2>
    <a href="nuevo_pospago_plan.php" class="btn btn-primary">➕ Agregar Plan</a>
  </div>

  <?php if ($vigenteId):
    $infoVig = $conn->query("SELECT fecha_inicio ".($hasFechaFin?", fecha_fin":"")." FROM esquemas_comisiones_ejecutivos WHERE id=".(int)$vigenteId." LIMIT 1")->fetch_assoc();
    $iniTxt = isset($infoVig['fecha_inicio']) ? date('d/m/Y', strtotime($infoVig['fecha_inicio'])) : '—';
    $finTxt = ($hasFechaFin && !empty($infoVig['fecha_fin'])) ? date('d/m/Y', strtotime($infoVig['fecha_fin'])) : null;
  ?>
    <div class="alert alert-success py-2">
      <strong>Esquema vigente (ejecutivos):</strong>
      inicia el <b><?= h($iniTxt) ?></b><?= $finTxt ? ', termina el <b>'.h($finTxt).'</b>' : ', <b>sin fecha fin</b>' ?>
    </div>
  <?php else: ?>
    <div class="alert alert-warning py-2">
      No hay esquema vigente de ejecutivos para hoy (<?= h(date('d/m/Y')); ?>). Los planes pospago se marcan como Histórico/Próximo según su esquema.
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-bordered table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th>Tipo</th>
          <th>Esquema Inicio</th>
          <?php if ($hasFechaFin): ?><th>Esquema Fin</th><?php endif; ?>
          <th>Plan ($)</th>
          <th>Con Equipo ($)</th>
          <th>Sin Equipo ($)</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $hasFechaFin?7:6 ?>" class="text-center text-muted">Sin registros.</td></tr>
        <?php else: foreach($rows as $row):
          [$estado, $filaCls] = estadoFilaPos($row, $hasFechaFin, $hoy, $vigenteId);
          $tipo  = $row['tipo'] ?? '—';
          $ini   = $row['fecha_esquema'] ?? null;
          $fin   = $hasFechaFin ? ($row['fecha_fin_esquema'] ?? null) : null;

          $plan = $row['plan_monto'] ?? 0;
          $ce   = $row['comision_con_equipo'] ?? 0;
          $se   = $row['comision_sin_equipo'] ?? 0;
        ?>
        <tr class="<?= $filaCls ?>">
          <td><?= h($tipo) ?></td>
          <td><?= $ini ? h(date('d/m/Y', strtotime($ini))) : '—' ?></td>
          <?php if ($hasFechaFin): ?>
            <td><?= $fin ? h(date('d/m/Y', strtotime($fin))) : '—' ?></td>
          <?php endif; ?>
          <td>$<?= money($plan) ?></td>
          <td>$<?= money($ce) ?></td>
          <td>$<?= money($se) ?></td>
          <td><?= h($estado) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
