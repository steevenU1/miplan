<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php"); exit();
}
require_once 'db.php';

/* ===== Semana martes→lunes (exacta) ===== */
function obtenerSemanaPorIndice(int $offset = 0) {
    $tz = new DateTimeZone('America/Mexico_City');
    $hoy = new DateTime('now', $tz);
    $dow = (int)$hoy->format('N'); // 1=Lun..7=Dom
    $dif = $dow - 2; if ($dif < 0) $dif += 7;
    $ini = new DateTime('now', $tz); $ini->modify("-{$dif} days")->setTime(0,0,0);
    if ($offset > 0) $ini->modify('-'.(7*$offset).' days');
    $fin = clone $ini; $fin->modify('+6 days')->setTime(23,59,59);
    return [$ini,$fin];
}
$semIdx = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj,$finObj) = obtenerSemanaPorIndice($semIdx);
$ini = $iniObj->format('Y-m-d 00:00:00'); $fin = $finObj->format('Y-m-d 23:59:59');
$iniISO = $iniObj->format('Y-m-d');        $finISO = $finObj->format('Y-m-d');

/* ===== CSV ===== */
$filename = "nomina_semana_{$iniISO}_{$finISO}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$out = fopen('php://output','w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['REPORTE DE NÓMINA SEMANAL']);
fputcsv($out, ['Semana', $iniObj->format('d/m/Y').' - '.$finObj->format('d/m/Y')]);
fputcsv($out, []);

/* ===== Encabezados (igual que el reporte) ===== */
$hdr = [
  'Empleado','Rol','Sucursal',
  'Sueldo','Eq.','SIMs','Pos.','PosG.','DirG.','Esc.Eq.','PrepG.',
  'Desc.','Neto',
  'Override ID','Override Origen'
];
fputcsv($out, $hdr);

/* ===== Helpers ===== */
function fetch_override_exact(mysqli $conn, int $idUsuario, string $iniISO, string $finISO): ?array {
  $sql = "SELECT * FROM nomina_overrides_semana
          WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?
          ORDER BY FIELD(estado,'autorizado','por_autorizar','borrador'), id DESC
          LIMIT 1";
  $st=$conn->prepare($sql); $st->bind_param('iss',$idUsuario,$iniISO,$finISO);
  $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r ?: null;
}
function sum_descuentos(mysqli $conn,int $idUsuario,string $iniISO,string $finISO): float {
  $st=$conn->prepare("SELECT IFNULL(SUM(monto),0) tot FROM descuentos_nomina
                      WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?");
  $st->bind_param('iss',$idUsuario,$iniISO,$finISO); $st->execute();
  $tot=(float)($st->get_result()->fetch_assoc()['tot'] ?? 0); $st->close(); return $tot;
}

/* ===== Usuarios (sin almacén/subdistribuidor) ===== */
$subCol=null;
foreach(['subtipo_sucursal','subtipo','sub_tipo','tipo_subsucursal'] as $c){
  $r=$conn->query("SHOW COLUMNS FROM sucursales LIKE '$c'"); if($r&&$r->num_rows>0){$subCol=$c;break;}
}
$whereSuc="s.tipo_sucursal<>'Almacen'";
if($subCol) $whereSuc.=" AND (s.`$subCol` IS NULL OR LOWER(s.`$subCol`)<>'subdistribuidor')";
$sqlU="SELECT u.id,u.nombre,u.rol,u.sueldo,s.nombre sucursal,u.id_sucursal
       FROM usuarios u INNER JOIN sucursales s ON s.id=u.id_sucursal
       WHERE $whereSuc ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre";
$rsU=$conn->query($sqlU);

/* ===== Queries (sumas) ===== */
// Equipos (solo comisión)
$stEq = $conn->prepare("
  SELECT SUM(dv.comision_regular + dv.comision_especial) com_tot
  FROM detalle_venta dv
  INNER JOIN ventas v ON v.id=dv.id_venta
  INNER JOIN productos p ON p.id=dv.id_producto
  WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ?
    AND LOWER(p.tipo_producto) NOT IN ('sim','chip','pospago')
");
// SIMs (prepago) comisión
$stSims = $conn->prepare("
  SELECT SUM(vs.comision_ejecutivo) com_tot
  FROM ventas_sims vs
  WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta IN ('Nueva','Portabilidad')
");
// Pospago comisión
$stPos = $conn->prepare("
  SELECT SUM(vs.comision_ejecutivo) com_tot
  FROM ventas_sims vs
  WHERE vs.id_usuario=? AND vs.fecha_venta BETWEEN ? AND ?
    AND vs.tipo_venta='Pospago'
");
// Gerente – ventas (equipos/mifi/modem)
$stGerVentas = $conn->prepare("
  SELECT IFNULL(SUM(v.comision_gerente),0) tot
  FROM ventas v
  WHERE v.id_sucursal=? AND v.fecha_venta BETWEEN ? AND ?
");
// Gerente – SIMs (prepago/pospago)
$stGerSims = $conn->prepare("
  SELECT IFNULL(SUM(vs.comision_gerente),0) tot
  FROM ventas_sims vs
  WHERE vs.id_sucursal=? AND vs.fecha_venta BETWEEN ? AND ?
");

while($u=$rsU->fetch_assoc()){
  $idU=(int)$u['id']; $idSuc=(int)$u['id_sucursal']; $rol=$u['rol'];
  $ov = fetch_override_exact($conn,$idU,$iniISO,$finISO);

  // Sueldo
  $sueldo = (isset($ov['sueldo_override']) && $ov['sueldo_override']!==null)
              ? (float)$ov['sueldo_override'] : (float)$u['sueldo'];

  // Comisiones Ejecutivo
  $eq = 0.0; $sim = 0.0; $pos = 0.0;
  if($rol!=='Gerente'){
    $stEq->bind_param('iss',$idU,$ini,$fin);   $stEq->execute();
    $eq_real =(float)($stEq->get_result()->fetch_assoc()['com_tot'] ?? 0);
    $eq = (isset($ov['equipos_override']) && $ov['equipos_override']!==null) ? (float)$ov['equipos_override'] : $eq_real;

    $stSims->bind_param('iss',$idU,$ini,$fin); $stSims->execute();
    $sim_real=(float)($stSims->get_result()->fetch_assoc()['com_tot'] ?? 0);
    $sim = (isset($ov['sims_override']) && $ov['sims_override']!==null) ? (float)$ov['sims_override'] : $sim_real;

    $stPos->bind_param('iss',$idU,$ini,$fin);  $stPos->execute();
    $pos_real=(float)($stPos->get_result()->fetch_assoc()['com_tot'] ?? 0);
    $pos = (isset($ov['pospago_override']) && $ov['pospago_override']!==null) ? (float)$ov['pospago_override'] : $pos_real;
  }

  // Partes de Gerente
  $posg=0.0; $dirg=0.0; $esceq=0.0; $prepg=0.0; $baseg=0.0;
  if($rol==='Gerente'){
    // reales (sucursal)
    $stGerVentas->bind_param('iss',$idSuc,$ini,$fin); $stGerVentas->execute();
    $esceq_real=(float)($stGerVentas->get_result()->fetch_assoc()['tot'] ?? 0);
    $stGerSims->bind_param('iss',$idSuc,$ini,$fin);   $stGerSims->execute();
    $prepg_real=(float)($stGerSims->get_result()->fetch_assoc()['tot'] ?? 0);

    // overrides
    $dirg  = (isset($ov['ger_dir_override'])  && $ov['ger_dir_override']  !== null) ? (float)$ov['ger_dir_override']  : 0.0;
    $esceq = (isset($ov['ger_esc_override'])  && $ov['ger_esc_override']  !== null) ? (float)$ov['ger_esc_override']  : $esceq_real;
    $prepg = (isset($ov['ger_prep_override']) && $ov['ger_prep_override'] !== null) ? (float)$ov['ger_prep_override'] : $prepg_real;
    $posg  = (isset($ov['ger_pos_override'])  && $ov['ger_pos_override']  !== null) ? (float)$ov['ger_pos_override']  : 0.0;
    $baseg = (isset($ov['ger_base_override']) && $ov['ger_base_override'] !== null) ? (float)$ov['ger_base_override'] : 0.0;
  }

  // Descuentos y Neto (mantenemos la misma lógica del reporte)
  $desc = sum_descuentos($conn,$idU,$iniISO,$finISO);
  $neto = $sueldo + $eq + $sim + $pos + $posg + $dirg + $esceq + $prepg + $baseg - $desc;

  // Auditoría
  $ovId = $ov['id'] ?? '—';
  $ovSrc = ($ovId==='—') ? '—' : trim(($ov['fuente'] ?? '').' '.($ov['estado'] ?? ''));

  // Fila (sin columnas de unidades)
  fputcsv($out, [
    $u['nombre'], $rol, $u['sucursal'],
    number_format($sueldo,2,'.',''),
    number_format($eq,2,'.',''),
    number_format($sim,2,'.',''),
    number_format($pos,2,'.',''),
    number_format($posg,2,'.',''),
    number_format($dirg,2,'.',''),
    number_format($esceq,2,'.',''),
    number_format($prepg,2,'.',''),
    number_format($desc,2,'.',''),
    number_format($neto,2,'.',''),
    $ovId, $ovSrc
  ]);
}

fclose($out); exit;
