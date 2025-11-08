<?php
// ticket_verificar.php — público
require_once __DIR__.'/db.php';
require_once __DIR__.'/config.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid   = $_GET['uid']   ?? '';
$total = $_GET['total'] ?? null;
$ts    = isset($_GET['ts']) ? (int)$_GET['ts'] : null;
$sig   = $_GET['sig']   ?? null;

// Validación de firma (opcional, si definiste TICKET_SECRET)
$firma_valida = null;
if ($uid && $total !== null && $ts && $sig) {
  $calc = hash_hmac('sha256', $uid.'|'.$total.'|'.$ts, TICKET_SECRET);
  $firma_valida = hash_equals($calc, $sig) && (time() - $ts) <= 7*24*3600;
}

$datos = null;
$estado = 'no_encontrado';

if ($uid) {
  $stmt = $conn->prepare("
    SELECT c.id, c.ticket_uid, c.estado, c.motivo, c.monto_total, c.fecha_cobro,
           s.nombre AS sucursal
    FROM cobros c
    LEFT JOIN sucursales s ON s.id = c.id_sucursal
    WHERE c.ticket_uid = ?
    LIMIT 1
  ");
  $stmt->bind_param('s', $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    $datos = $row;
    $estado = ($row['estado'] === 'anulado') ? 'anulado' : 'valido';
  }
  $stmt->close();
}

if (isset($_GET['format']) && $_GET['format']==='json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'uid' => $uid,
    'estado' => $estado,
    'firma_valida' => $firma_valida,
    'data' => $datos
  ]);
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Verificación de ticket</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h4 mb-3">Verificación de ticket</h1>

      <?php if ($estado === 'no_encontrado'): ?>
        <div class="alert alert-danger">❌ Ticket no encontrado.</div>
      <?php elseif ($estado === 'anulado'): ?>
        <div class="alert alert-warning">⚠ Ticket ANULADO.</div>
      <?php else: ?>
        <div class="alert alert-success">✅ Ticket válido.</div>
      <?php endif; ?>

      <?php if ($firma_valida !== null): ?>
        <?php if ($firma_valida): ?>
          <div class="alert alert-success py-2">🔐 Firma válida.</div>
        <?php else: ?>
          <div class="alert alert-secondary py-2">🔐 Firma inválida o expirada.</div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($datos): ?>
      <ul class="list-group">
        <li class="list-group-item"><strong>UID:</strong> <?= h($datos['ticket_uid']) ?></li>
        <li class="list-group-item"><strong>Sucursal:</strong> <?= h($datos['sucursal'] ?? '—') ?></li>
        <li class="list-group-item"><strong>Fecha:</strong> <?= h($datos['fecha_cobro']) ?></li>
        <li class="list-group-item"><strong>Motivo:</strong> <?= h($datos['motivo']) ?></li>
        <li class="list-group-item"><strong>Monto:</strong> $<?= number_format((float)$datos['monto_total'],2) ?></li>
        <li class="list-group-item"><strong>Estado:</strong> <?= h($datos['estado']) ?></li>
      </ul>
      <?php endif; ?>

      <!-- <div class="mt-4 text-muted small">
        Si necesitas reimpresión, usa la consola interna (tickets_ui.php).
      </div> -->
    </div>
  </div>
</div>
</body>
</html>
