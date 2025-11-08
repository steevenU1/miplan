<?php
// ticket_cobro.php — Reimpresión interna por UID o ID (80mm)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

$uid = trim($_GET['uid'] ?? '');
$id  = (int)($_GET['id']  ?? 0);

$row = null;
if ($uid !== '') {
  $stmt = $conn->prepare("SELECT c.*, u.nombre AS usuario, s.nombre AS sucursal
                          FROM cobros c
                          JOIN usuarios u ON u.id=c.id_usuario
                          JOIN sucursales s ON s.id=c.id_sucursal
                          WHERE c.ticket_uid=? LIMIT 1");
  $stmt->bind_param('s',$uid);
} else {
  $stmt = $conn->prepare("SELECT c.*, u.nombre AS usuario, s.nombre AS sucursal
                          FROM cobros c
                          JOIN usuarios u ON u.id=c.id_usuario
                          JOIN sucursales s ON s.id=c.id_sucursal
                          WHERE c.id=? LIMIT 1");
  $stmt->bind_param('i',$id);
}
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); echo "Ticket no encontrado"; exit; }

// Datos empresa (ajusta a tu razón social)
$empresa = [
  'nombre'   => 'NanoRed / LUGA',
  'direccion'=> 'Calle Empresa 123, CDMX',
  'telefono' => '55-0000-0000',
];

$fecha = (new DateTime($row['fecha_cobro']))->setTimezone(new DateTimeZone('America/Mexico_City'));
$folio = (int)$row['id'];
$payloadQR = json_encode([
  't'=>'COBRO','id'=>$folio,'uid'=>$row['ticket_uid'],
  'suc'=>$row['sucursal'],'total'=>number_format((float)$row['monto_total'],2,'.',''),
  'f'=>$fecha->format('Y-m-d H:i')
], JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ticket #<?= htmlspecialchars((string)$folio) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @page { size: 80mm auto; margin:0; }
    body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    #ticket { width:72mm; margin:0 auto; font-size:12px; }
    h6 { margin:0; font-size:14px; text-align:center; }
    .line { border-top:1px dashed #999; margin:6px 0; }
    table { width:100%; border-collapse:collapse; }
    td { padding:2px 0; vertical-align:top; }
    .actions { padding:10px; text-align:center; }
    .badge { display:inline-block; padding:2px 6px; border-radius:6px; background:#eef2ff; }
    @media print {.actions{display:none}}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body>
<div class="actions">
  <button onclick="window.print()">Imprimir / PDF</button>
  <a href="tickets_ui.php">Volver</a>
</div>

<div id="ticket">
  <h6><?= htmlspecialchars($empresa['nombre']) ?></h6>
  <div style="text-align:center;font-size:11px">
    <?= htmlspecialchars($empresa['direccion']) ?> • Tel: <?= htmlspecialchars($empresa['telefono']) ?><br>
    <?= htmlspecialchars($row['sucursal']) ?>
  </div>
  <div class="line"></div>
  <table>
    <tr><td>Folio:</td><td style="text-align:right">#<?= $folio ?></td></tr>
    <tr><td>Fecha:</td><td style="text-align:right"><?= $fecha->format('d/m/Y H:i') ?></td></tr>
    <tr><td>Atendió:</td><td style="text-align:right"><?= htmlspecialchars($row['usuario']) ?></td></tr>
    <tr><td>Motivo:</td><td style="text-align:right"><?= htmlspecialchars($row['motivo']) ?></td></tr>
    <tr><td>Tipo de pago:</td><td style="text-align:right"><?= htmlspecialchars($row['tipo_pago']) ?></td></tr>
  </table>
  <?php if ($row['nombre_cliente']): ?>
  <div class="line"></div>
  <div><strong>Cliente:</strong> <?= htmlspecialchars($row['nombre_cliente']) ?></div>
  <div><strong>Teléfono:</strong> <?= htmlspecialchars($row['telefono_cliente']) ?></div>
  <?php endif; ?>
  <div class="line"></div>
  <table>
    <tr><td>Total</td><td style="text-align:right">$<?= number_format((float)$row['monto_total'],2) ?></td></tr>
    <tr><td>Efectivo</td><td style="text-align:right">$<?= number_format((float)$row['monto_efectivo'],2) ?></td></tr>
    <tr><td>Tarjeta</td><td style="text-align:right">$<?= number_format((float)$row['monto_tarjeta'],2) ?></td></tr>
  </table>
  <div class="line"></div>
  <div id="qrcode" style="display:flex;justify-content:center"></div>
  <div style="text-align:center;font-size:11px;margin-top:6px">
    <?= htmlspecialchars($row['ticket_uid'] ?? '') ?>
    <?php if (($row['estado'] ?? 'valido') !== 'valido'): ?>
      <span class="badge">ANULADO</span>
    <?php endif; ?>
  </div>
  <div style="text-align:center;margin-top:8px">¡Gracias por su preferencia!</div>
</div>
<script>
new QRCode(document.getElementById("qrcode"), { text: <?= json_encode($payloadQR) ?>, width: 120, height: 120 });
</script>
</body>
</html>
