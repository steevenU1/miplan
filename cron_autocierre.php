<?php
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');
function horarioSucursalParaFecha(mysqli $c, int $sid, string $f){/* igual que arriba */}
// Selecciona abiertas
$rs = $conn->query("SELECT id,id_sucursal,fecha,hora_entrada FROM asistencias WHERE hora_salida IS NULL");
$today = date('Y-m-d'); $now = new DateTime();
while ($r = $rs->fetch_assoc()) {
  $hor = horarioSucursalParaFecha($conn, (int)$r['id_sucursal'], $r['fecha']);
  $sug = new DateTime($r['fecha'].' '.(($hor && (int)$hor['cerrado']!==1 && !empty($hor['cierra'])) ? $hor['cierra'] : '23:59:00'));
  $in  = new DateTime((strlen($r['hora_entrada'])<=8) ? ($r['fecha'].' '.$r['hora_entrada']) : $r['hora_entrada']);
  if ($sug <= $in) $sug = (clone $in)->modify('+1 minute');
  $elegible = ($r['fecha'] < $today) || ($r['fecha']===$today && $now >= $sug);
  if (!$elegible) continue;
  $salida = ($now < $sug) ? $now : $sug;
  $q = $conn->prepare("UPDATE asistencias SET hora_salida=?, duracion_minutos=TIMESTAMPDIFF(MINUTE,hora_entrada,?), ip='auto-close', metodo='autocierre' WHERE id=? AND hora_salida IS NULL");
  $s = $salida->format('Y-m-d H:i:s'); $q->bind_param('ssi',$s,$s,$r['id']); $q->execute(); $q->close();
}
