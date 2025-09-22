<?php
// admin_asistencias.php  ·  Panel Admin con KPIs + Matriz + Detalle (por día) + Permisos + Export CSV + Alta Vacaciones (modal)
ob_start(); // buffer para evitar "headers already sent"
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

/* ===== Debug opcional ===== */
$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

/* ===== Utils ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/',$iso,$m)) return null;
  $dt=new DateTime(); $dt->setISODate((int)$m[1],(int)$m[2]); $dt->modify('+1 day'); $dt->setTime(0,0,0); return $dt;
}
function currentOpWeekIso(): string {
  $t=new DateTime('today'); $dow=(int)$t->format('N'); $off=($dow>=2)?$dow-2:6+$dow; $tue=(clone $t)->modify("-{$off} days"); $mon=(clone $tue)->modify('-1 day'); return $mon->format('o-\WW');
}
function fmtBadgeRango(DateTime $tueStart): string {
  $dias=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun']; $ini=(clone $tueStart); $fin=(clone $tueStart)->modify('+6 day'); return $dias[0].' '.$ini->format('d/m').' → '.$dias[6].' '.$fin->format('d/m');
}
function diaCortoEs(DateTime $d): string {
  static $map=[1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
  return $map[(int)$d->format('N')] ?? $d->format('D');
}

/* ================== Compatibilidad de BD ================== */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $q = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $q && $q->num_rows > 0;
}
function pickDateCol(mysqli $conn, string $table, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  foreach ($candidates as $c) { if (column_exists($conn, $table, $c)) return $c; }
  return 'fecha';
}
function pickDateColWithAlias(mysqli $conn, string $table, string $alias, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  $raw = pickDateCol($conn, $table, $candidates);
  return "{$alias}.`{$raw}`";
}

/* ===== Helper: obtener todas las filas de un stmt SIN mysqlnd ===== */
function stmt_all_assoc(mysqli_stmt $stmt): array {
  $rows = [];
  $meta = $stmt->result_metadata();
  if (!$meta) return $rows;
  $fields = $meta->fetch_fields();
  $row = [];
  $bind = [];
  foreach ($fields as $f) { $row[$f->name] = null; $bind[] = &$row[$f->name]; }
  call_user_func_array([$stmt, 'bind_result'], $bind);
  while ($stmt->fetch()) {
    $rows[] = array_combine(array_keys($row), array_map(function($v){ return $v; }, array_values($row)));
  }
  return $rows;
}

/* ================== Filtros ================== */
$isExport = isset($_GET['export']);
$weekIso = $_GET['week'] ?? currentOpWeekIso();
$tuesdayStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');
$start = $tuesdayStart->format('Y-m-d');
$end   = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');
$today = (new DateTime('today'))->format('Y-m-d'); // para no contar faltas en días futuros

// Filtro por día (opcional) para el DETALLE
$diaSel = '';
if (!empty($_GET['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'])) {
  if ($_GET['dia'] >= $start && $_GET['dia'] <= $end) { $diaSel = $_GET['dia']; }
}

/* ===== Flash del alta de vacaciones ===== */
$msgVac=''; $clsVac='info';

/* ===== Handler: Alta de vacaciones (modal) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='alta_vacaciones') {
  $idUsuario = (int)($_POST['id_usuario'] ?? 0);
  $fini = trim($_POST['fecha_inicio'] ?? '');
  $ffin = trim($_POST['fecha_fin'] ?? '');
  $coment = trim($_POST['comentario'] ?? '');

  // Traer usuario y sucursal (válido para tiendas propias)
  $userRow = null;
  $stU = $conn->prepare("SELECT u.id, u.id_sucursal FROM usuarios u JOIN sucursales s ON s.id=u.id_sucursal WHERE u.id=? AND u.activo=1 AND u.rol IN ('Gerente','Ejecutivo') AND s.tipo_sucursal='tienda' AND s.subtipo='propia' LIMIT 1");
  $stU->bind_param('i',$idUsuario); $stU->execute();
  $resU=$stU->get_result(); $userRow=$resU->fetch_assoc(); $stU->close();

  if (!$userRow) { $msgVac="Usuario inválido o no elegible."; $clsVac="danger"; }
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ffin) || $ffin < $fini) {
    $msgVac="Rango de fechas inválido."; $clsVac="danger";
  } elseif (!table_exists($conn,'permisos_solicitudes')) {
    $msgVac="No existe la tabla permisos_solicitudes."; $clsVac="danger";
  } else {
    // Descubrir columnas presentes
    $dateCol       = pickDateCol($conn,'permisos_solicitudes',['fecha','fecha_permiso','dia','fecha_solicitada','creado_en']);
    $hasAprobPor   = column_exists($conn,'permisos_solicitudes','aprobado_por');
    $hasAprobEn    = column_exists($conn,'permisos_solicitudes','aprobado_en');
    $hasComApr     = column_exists($conn,'permisos_solicitudes','comentario_aprobador');
    $hasStatus     = column_exists($conn,'permisos_solicitudes','status');
    $hasMotivo     = column_exists($conn,'permisos_solicitudes','motivo');
    $hasComent     = column_exists($conn,'permisos_solicitudes','comentario');
    $hasSucursal   = column_exists($conn,'permisos_solicitudes','id_sucursal');
    $hasCreadoPor  = column_exists($conn,'permisos_solicitudes','creado_por');
    $hasCreadoEn   = column_exists($conn,'permisos_solicitudes','creado_en');

    // Columnas del insert
    $cols = ['id_usuario'];
    if ($hasSucursal)   $cols[] = 'id_sucursal';
    $cols[] = $dateCol;
    if ($hasMotivo)     $cols[] = 'motivo';
    if ($hasComent)     $cols[] = 'comentario';
    if ($hasStatus)     $cols[] = 'status';
    if ($hasCreadoPor)  $cols[] = 'creado_por';
    if ($hasCreadoEn)   $cols[] = 'creado_en';      // NOW()
    if ($hasAprobPor)   $cols[] = 'aprobado_por';
    if ($hasAprobEn)    $cols[] = 'aprobado_en';    // NOW()
    if ($hasComApr)     $cols[] = 'comentario_aprobador';

    $placeholders = [];
    foreach ($cols as $c) {
      $placeholders[] = ($c==='creado_en' || $c==='aprobado_en') ? 'NOW()' : '?';
    }
    $sqlIns = "INSERT INTO permisos_solicitudes (`".implode('`,`',$cols)."`) VALUES (".implode(',',$placeholders).")";
    $stmt = $conn->prepare($sqlIns);

    // Rango de fechas
    $dates=[]; $cursor=new DateTime($fini); $endR=new DateTime($ffin);
    while($cursor <= $endR){ $dates[]=$cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

    $conn->begin_transaction();
    try{
      $insertados=0; $omitidos=0;
      foreach($dates as $d){
        // Duplicados
        if ($hasMotivo) $sqlDup="SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? AND motivo='Vacaciones' LIMIT 1";
        else            $sqlDup="SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? LIMIT 1";
        $stDup=$conn->prepare($sqlDup); $stDup->bind_param('is',$idUsuario,$d); $stDup->execute();
        $dup=(bool)$stDup->get_result()->fetch_assoc(); $stDup->close();
        if ($dup){ $omitidos++; continue; }

        // Bind dinámico (omitimos *_en porque van con NOW())
        $vals=[]; $types='';
        foreach ($cols as $c) {
          if ($c==='creado_en' || $c==='aprobado_en') continue;
          switch ($c) {
            case 'id_usuario':            $vals[]=$idUsuario;                     $types.='i'; break;
            case 'id_sucursal':           $vals[]=(int)$userRow['id_sucursal'];   $types.='i'; break;
            case 'motivo':                $vals[]='Vacaciones';                   $types.='s'; break;
            case 'comentario':            $vals[]=$coment;                        $types.='s'; break;
            case 'status':                $vals[]='Aprobado';                     $types.='s'; break;
            case 'creado_por':            $vals[]=(int)$_SESSION['id_usuario'];   $types.='i'; break;
            case 'aprobado_por':          $vals[]=(int)$_SESSION['id_usuario'];   $types.='i'; break;
            case 'comentario_aprobador':  $vals[]='Alta vacaciones (admin)';      $types.='s'; break;
            default:
              if ($c===$dateCol){ $vals[]=$d; $types.='s'; }
          }
        }
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $insertados++;
      }
      $conn->commit();
      $msgVac="✅ Vacaciones registradas: {$insertados} día(s). Omitidos por duplicado: {$omitidos}.";
      $clsVac="success";
    } catch(Throwable $e){
      $conn->rollback();
      $msgVac="❌ Error al registrar: ".$e->getMessage();
      $clsVac="danger";
    }
  }
}

/* ===== Sucursales 'tienda' 'propia' ===== */
$sucursales = [];
$resSuc = $conn->query("SELECT id,nombre FROM sucursales WHERE tipo_sucursal='tienda' AND subtipo='propia' ORDER BY nombre");
if ($resSuc) { while ($r = $resSuc->fetch_assoc()) $sucursales[] = $r; }

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$qsExportArr = ['week'=>$weekIso,'sucursal_id'=>$sucursal_id];
if ($diaSel) $qsExportArr['dia'] = $diaSel;
$qsExport = http_build_query($qsExportArr);

/* ===== Usuarios activos — SOLO Gerente/Ejecutivo ===== */
$paramsU=[]; $typesU='';
$whereU=" WHERE u.activo=1 
          AND s.tipo_sucursal='tienda' 
          AND s.subtipo='propia'
          AND u.rol IN ('Gerente','Ejecutivo') ";
if ($sucursal_id>0){ $whereU.=' AND u.id_sucursal=? '; $typesU.='i'; $paramsU[]=$sucursal_id; }
$sqlUsers="SELECT u.id,u.nombre,u.id_sucursal,s.nombre AS sucursal 
           FROM usuarios u 
           JOIN sucursales s ON s.id=u.id_sucursal 
           $whereU 
           ORDER BY s.nombre,u.nombre";
$stmt=$conn->prepare($sqlUsers);
if($typesU) $stmt->bind_param($typesU, ...$paramsU);
$stmt->execute();
$usuarios = stmt_all_assoc($stmt);
$stmt->close();
$userIds=array_map(fn($u)=>(int)$u['id'],$usuarios); if(!$userIds)$userIds=[0];

/* ===== Horarios por sucursal ===== */
$horarios=[];
$horTable = table_exists($conn,'sucursales_horario') ? 'sucursales_horario' : (table_exists($conn,'horarios_sucursal') ? 'horarios_sucursal' : null);
if ($horTable === 'sucursales_horario') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,abre,cierra,cerrado FROM sucursales_horario");
  if ($resH) while($r=$resH->fetch_assoc()){
    $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = ['abre'=>$r['abre'],'cierra'=>$r['cierra'],'cerrado'=>(int)$r['cerrado']];
  }
} elseif ($horTable === 'horarios_sucursal') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,apertura AS abre,cierre AS cierra,IF(activo=1,0,1) AS cerrado FROM horarios_sucursal");
  if ($resH) while($r=$resH->fetch_assoc()){
    $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = ['abre'=>$r['abre'],'cierra'=>$r['cierra'],'cerrado'=>(int)$r['cerrado']];
  }
}

/* ===== Descansos semana ===== */
$descansos=[];
if (table_exists($conn,'descansos_programados')) {
  $inList=implode(',',array_fill(0,count($userIds),'?'));
  $typesD=str_repeat('i',count($userIds)).'ss';
  $descansoDateCol = pickDateCol($conn, 'descansos_programados', ['fecha','dia','fecha_programada']);
  $sqlD = "SELECT id_usuario, `{$descansoDateCol}` AS fecha FROM descansos_programados WHERE id_usuario IN ($inList) AND `{$descansoDateCol}` BETWEEN ? AND ?";
  $stmt=$conn->prepare($sqlD);
  $stmt->bind_param($typesD, ...array_merge($userIds,[$start,$end]));
  $stmt->execute();
  $rows=stmt_all_assoc($stmt);
  foreach($rows as $r){ $descansos[(int)$r['id_usuario']][$r['fecha']] = true; }
  $stmt->close();
}

/* ===== Permisos aprobados (guardar motivo por día) ===== */
$permAprob=[];
if (table_exists($conn,'permisos_solicitudes')) {
  $permDateCol = pickDateCol($conn, 'permisos_solicitudes', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $hasMotivo = column_exists($conn,'permisos_solicitudes','motivo');
  $inList = implode(',',array_fill(0,count($userIds),'?'));
  $typesPA = str_repeat('i',count($userIds)).'ss';
  $selMot = $hasMotivo ? ", p.motivo AS motivo" : "";
  $sqlPA = "
    SELECT p.id_usuario, `{$permDateCol}` AS fecha {$selMot}
    FROM permisos_solicitudes p
    WHERE p.id_usuario IN ($inList) AND p.status='Aprobado' AND `{$permDateCol}` BETWEEN ? AND ?
  ";
  $stmt=$conn->prepare($sqlPA);
  $stmt->bind_param($typesPA, ...array_merge($userIds,[$start,$end]));
  $stmt->execute();
  $rows=stmt_all_assoc($stmt);
  foreach($rows as $r){
    $permAprob[(int)$r['id_usuario']][$r['fecha']] = $hasMotivo ? (string)($r['motivo'] ?? '') : 'PERMISO';
  }
  $stmt->close();
}

/* ===== Asistencias DETALLE (toda la semana) — base para matriz y detalle ===== */
$typesA=str_repeat('i',count($userIds)).'ss';
$asisDateRaw = pickDateColWithAlias($conn, 'asistencias', 'a', ['fecha','creado_en','fecha_evento','dia','timestamp']);
$asistDetWeek=[];
$sqlA="
  SELECT a.*, {$asisDateRaw} AS fecha, s.nombre AS sucursal, u.nombre AS usuario
  FROM asistencias a
  JOIN sucursales s ON s.id=a.id_sucursal
  JOIN usuarios u   ON u.id=a.id_usuario
  WHERE a.id_usuario IN (%s) AND DATE({$asisDateRaw}) BETWEEN ? AND ?
  ORDER BY {$asisDateRaw} ASC, a.hora_entrada ASC, a.id ASC
";
$inList=implode(',',array_fill(0,count($userIds),'?'));
$sqlA = sprintf($sqlA, $inList);
$stmt=$conn->prepare($sqlA);
$stmt->bind_param($typesA, ...array_merge($userIds,[$start,$end]));
$stmt->execute();
$asistDetWeek = stmt_all_assoc($stmt);
$stmt->close();

// Vista del detalle (filtrada por día si aplica)
$asistDetView = $asistDetWeek;
if ($diaSel) {
  $asistDetView = array_values(array_filter($asistDetWeek, fn($a) => ($a['fecha'] ?? '') === $diaSel));
}

/* Index asistencia por usuario/día (para matriz) */
$asistByUserDay=[];
foreach($asistDetWeek as $a){
  $uid=(int)$a['id_usuario']; $f=$a['fecha'];
  if(!isset($asistByUserDay[$uid][$f])) $asistByUserDay[$uid][$f]=$a;
}

/* ===== Permisos de la semana (tabla informativa) ===== */
$permisosSemana=[];
if (table_exists($conn,'permisos_solicitudes')) {
  $permDateRaw = pickDateColWithAlias($conn, 'permisos_solicitudes', 'p', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $typesPS='ss'; $paramsPS=[$start,$end];
  $wherePS = " AND s.tipo_sucursal='tienda' AND s.subtipo='propia' ";
  $hasAprobPor = column_exists($conn,'permisos_solicitudes','aprobado_por');
  if ($sucursal_id>0){ $typesPS.='i'; $paramsPS[]=$sucursal_id; $wherePS.=' AND s.id=? '; }
  $selAprob = $hasAprobPor ? 'p.aprobado_por' : 'NULL AS aprobado_por';
  $sqlPS="
    SELECT p.*, {$permDateRaw} AS fecha, u.nombre AS usuario, s.nombre AS sucursal, {$selAprob}
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    JOIN sucursales s ON s.id=p.id_sucursal
    WHERE DATE({$permDateRaw}) BETWEEN ? AND ? $wherePS
    ORDER BY s.nombre,u.nombre, {$permDateRaw} DESC
  ";
  $stmt=$conn->prepare($sqlPS);
  $stmt->bind_param($typesPS, ...$paramsPS);
  $stmt->execute();
  $permisosSemana = stmt_all_assoc($stmt);
  $stmt->close();
}

/* ====== Construcción de matriz + KPIs ====== */
$days=[]; for($i=0;$i<7;$i++){ $d=clone $tuesdayStart; $d->modify("+$i day"); $days[]=$d; }
$weekNames=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

$matriz=[];
$totAsis=0;$totRet=0;$totFal=0;$totPerm=0;$totDesc=0;$totMin=0;$faltasPorRetardos=0;$laborables=0;$presentes=0;

foreach($usuarios as $u){
  $uid=(int)$u['id']; $sid=(int)$u['id_sucursal'];
  $fila=['usuario'=>$u['nombre'],'sucursal'=>$u['sucursal'],'dias'=>[],'asis'=>0,'ret'=>0,'fal'=>0,'perm'=>0,'desc'=>0,'min'=>0];
  $retSemanaUsuario=0;

  foreach($days as $d){
    $f=$d->format('Y-m-d');
    $isFuture = ($f > $today); // no contar faltas en futuro
    $dow=(int)$d->format('N');
    $hor=$horarios[$sid][$dow]??null; $cerrado=$hor?((int)$hor['cerrado']===1):false;
    $isDesc=!empty($descansos[$uid][$f]);
    $isPerm = isset($permAprob[$uid][$f]); // ahora contiene motivo o 'PERMISO'
    $a=$asistByUserDay[$uid][$f]??null;
    $esLaborable = !$cerrado && !$isDesc && !$isPerm;

    if ($isFuture) {
      $fila['dias'][]=['fecha'=>$f,'estado'=>'PENDIENTE','entrada'=>null,'salida'=>null,'retardo_min'=>0,'dur'=>0];
      continue;
    }

    if($a){
      $ret=(int)($a['retardo']??0); $retMin=(int)($a['retardo_minutos']??0); $dur=(int)($a['duracion_minutos']??0);
      $fila['min'] += $dur; $totMin += $dur;
      if($ret===1){ $estado='RETARDO'; $fila['ret']++; $retSemanaUsuario++; $totRet++; }
      else { $estado='ASISTIÓ'; $fila['asis']++; $totAsis++; }
      $presentes++; if($esLaborable) $laborables++;
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>$a['hora_entrada'],'salida'=>$a['hora_salida'],'retardo_min'=>$retMin,'dur'=>$dur];
    } else {
      if($isDesc){ $estado='DESCANSO'; $fila['desc']++; $totDesc++; }
      elseif($cerrado){ $estado='CERRADA'; }
      elseif($isPerm){
        $mot = strtoupper(trim((string)($permAprob[$uid][$f] ?? '')));
        $estado = (strpos($mot,'VACACION') === 0) ? 'VACACIONES' : 'PERMISO';
        $fila['perm']++; $totPerm++;
      }
      else { $estado='FALTA'; $fila['fal']++; if($esLaborable) $laborables++; $totFal++; }
      $fila['dias'][]=['fecha'=>$f,'estado'=>$estado,'entrada'=>null,'salida'=>null,'retardo_min'=>0,'dur'=>0];
    }
  }
  if ($retSemanaUsuario >= 3) { $faltasPorRetardos++; }
  $matriz[]=$fila;
}

/* ====== KPIs ====== */
$empleadosActivos = ($userIds===[0]) ? 0 : count($usuarios);
$puntualidad = ($totAsis+$totRet)>0 ? round(($totAsis/($totAsis+$totRet))*100,1) : 0.0;
$cumplimiento = ($laborables>0) ? round(($presentes / $laborables)*100,1) : 0.0;
$horasTot = $totMin>0 ? round($totMin/60,2) : 0.0;

/* ====== EXPORTACIONES (antes de imprimir cualquier HTML/ navbar) ====== */
// NOTA: exports usan la semana completa (asistDetWeek).
if ($isExport) {
  ini_set('display_errors','0'); // evita que warnings contaminen el CSV
  while (ob_get_level()) { ob_end_clean(); }

  header("Content-Type: text/csv; charset=UTF-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  echo "\xEF\xBB\xBF"; // BOM UTF-8

  $type = $_GET['export'];
  $labels=[]; foreach($days as $d){ $labels[] = diaCortoEs($d).' '.$d->format('d/m'); }

  if ($type==='matrix') {
    header("Content-Disposition: attachment; filename=asistencias_matriz_{$weekIso}.csv");
    $out=fopen('php://output','w');
    $head=['Sucursal','Colaborador']; foreach($labels as $l)$head[]=$l;
    $head=array_merge($head,['Asistencias','Retardos','Faltas','Permisos','Descansos','Minutos','Horas','Falta_por_retardos']);
    fputcsv($out,$head);
    foreach($matriz as $fila){
      $row=[$fila['sucursal'],$fila['usuario']];
      foreach($fila['dias'] as $dCell){
        $estado=$dCell['estado'];
        if ($estado==='PENDIENTE'){ $row[]='—'; }
        elseif($estado==='RETARDO'){ $row[]='RETARDO +'.($dCell['retardo_min']??0).'m'; }
        else { $row[]=$estado; }
      }
      $hrs=$fila['min']>0?round($fila['min']/60,2):0;
      $faltaRet = ($fila['ret']>=3)?1:0;
      fputcsv($out, array_merge($row,[$fila['asis'],$fila['ret'],$fila['fal'],$fila['perm'],$fila['desc'],$fila['min'],$hrs,$faltaRet]));
    }
    fclose($out); exit;
  }

  if ($type==='detalles') {
    header("Content-Disposition: attachment; filename=asistencias_detalle_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out, ['Sucursal','Usuario','Fecha','Entrada','Salida','Duración(min)','Estado','Retardo(min)','Lat','Lng','IP']);
    foreach($asistDetWeek as $a){
      $estado=((int)($a['retardo']??0)===1)?'RETARDO':'OK';
      fputcsv($out,[
        $a['sucursal'],
        $a['usuario'],
        $a['fecha'],
        $a['hora_entrada'],
        $a['hora_salida'],
        (int)($a['duracion_minutos']??0),
        $estado,
        (int)($a['retardo_minutos']??0),
        $a['latitud']??'',
        $a['longitud']??'',
        $a['ip']??''
      ]);
    }
    fclose($out); exit;
  }

  if ($type==='permisos' && $permisosSemana) {
    header("Content-Disposition: attachment; filename=permisos_semana_{$weekIso}.csv");
    $out=fopen('php://output','w');
    fputcsv($out,['Sucursal','Colaborador','Fecha','Motivo','Comentario','Status','Aprobado por','Aprobado en','Obs.aprobador']);
    foreach($permisosSemana as $p){
      fputcsv($out,[
        $p['sucursal'],$p['usuario'],$p['fecha'],$p['motivo'],$p['comentario']??'',$p['status'],
        $p['aprobado_por']??'',$p['aprobado_en']??'',$p['comentario_aprobador']??''
      ]);
    }
    fclose($out); exit;
  }

  // fallback
  header("Content-Disposition: attachment; filename=export_{$weekIso}.csv");
  $out=fopen('php://output','w'); fputcsv($out,['Sin datos']); fclose($out); exit;
}

/* ============ UI ============ */
require_once __DIR__.'/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin · Asistencias (Mar→Lun)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    /* Pills compactos */
    .pill{ display:inline-block; border-radius:999px; font-weight:700; line-height:1; }
    .pill-compact{ padding:.12rem .38rem; font-size:.72rem; min-width:1.35rem; text-align:center; border:1px solid transparent; }
    /* Colores */
    .pill-A{ background:#e6fcf5; color:#0f5132; border-color:#c3fae8; }       /* Asistió (verde) */
    .pill-R{ background:#fff3cd; color:#8a6d3b; border-color:#ffeeba; }       /* Retardo (ámbar) */
    .pill-F{ background:#ffe3e3; color:#842029; border-color:#f5c2c7; }       /* Falta (rojo) */
    .pill-P{ background:#e2f0d9; color:#2b6a2b; border-color:#c7e3be; }       /* Permiso (verde suave) */
    .pill-V{ background:#fff0f6; color:#9c36b5; border-color:#f3d9fa; }       /* Vacaciones (morado suave) */
    .pill-D{ background:#f3f4f6; color:#374151; border-color:#e5e7eb; }       /* Descanso (gris) */
    .pill-X{ background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }       /* Cerrada (lila) */
    .pill-PN{ background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }      /* Pendiente (azul) */

    .thead-sticky th{ position:sticky; top:0; background:#111827; color:#fff; z-index:2; }
    .kpi{ border:0; border-radius:1rem; padding:1rem 1.25rem; display:flex; gap:.9rem; align-items:center; }
    .kpi i{ font-size:1.3rem; opacity:.9; }
    .kpi .big{ font-weight:800; font-size:1.35rem; line-height:1; }
    .bg-soft-blue{ background:#e7f5ff; }
    .bg-soft-green{ background:#e6fcf5; }
    .bg-soft-yellow{ background:#fff9db; }
    .bg-soft-red{ background:#ffe3e3; }
    .bg-soft-purple{ background:#f3f0ff; }
    .bg-soft-slate{ background:#f1f5f9; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-building-fill-gear me-2"></i>Panel Admin · Asistencias</h3>
    <span class="badge text-bg-secondary"><?= h(fmtBadgeRango($tuesdayStart)) ?></span>
  </div>

  <?php if($msgVac): ?>
    <div id="alert-vac" class="alert alert-<?= h($clsVac) ?>"><?= h($msgVac) ?></div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-people"></i><div><div class="text-muted small">Colaboradores</div><div class="big"><?= (int)$empleadosActivos ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-person-check"></i><div><div class="text-muted small">Presentes</div><div class="big"><?= (int)($totAsis+$totRet) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-yellow"><i class="bi bi-alarm"></i><div><div class="text-muted small">Retardos</div><div class="big"><?= (int)$totRet ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-red"><i class="bi bi-person-x"></i><div><div class="text-muted small">Faltas</div><div class="big"><?= (int)$totFal ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-purple"><i class="bi bi-clipboard-check"></i><div><div class="text-muted small">Permisos</div><div class="big"><?= (int)$totPerm ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-moon-stars"></i><div><div class="text-muted small">Descansos</div><div class="big"><?= (int)$totDesc ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-blue"><i class="bi bi-clock-history"></i><div><div class="text-muted small">Horas</div><div class="big"><?= number_format($horasTot,2) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-graph-up"></i><div><div class="text-muted small">Puntualidad</div><div class="big"><?= $puntualidad ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-blue"><i class="bi bi-bullseye"></i><div><div class="text-muted small">Cumplimiento</div><div class="big"><?= $cumplimiento ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-yellow"><i class="bi bi-exclamation-diamond"></i><div><div class="text-muted small">Falta por 3+ retardos</div><div class="big"><?= (int)$faltasPorRetardos ?></div></div></div>
    </div>
  </div>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Semana (Mar→Lun)</label>
          <input type="week" name="week" value="<?= h($weekIso) ?>" class="form-control">
        </div>
        <div class="col-sm-5 col-md-4">
          <label class="form-label mb-0">Sucursal</label>
          <select name="sucursal_id" class="form-select">
            <option value="0">Todas</option>
            <?php foreach($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3 col-md-2">
          <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Export + Acción vacaciones -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-success btn-sm" href="?export=matrix&<?= $qsExport ?>"><i class="bi bi-grid-3x3-gap me-1"></i> Exportar matriz</a>
    <a class="btn btn-outline-primary btn-sm" href="?export=detalles&<?= $qsExport ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar detalles</a>
    <a class="btn btn-outline-secondary btn-sm" href="?export=permisos&<?= $qsExport ?>"><i class="bi bi-clipboard-check me-1"></i> Exportar permisos</a>
    <button class="btn btn-warning btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalVacaciones">
      <i class="bi bi-sunglasses me-1"></i> Agregar vacaciones
    </button>
  </div>

  <!-- MATRIZ -->
  <div class="card card-elev mb-4">
    <div class="card-header fw-bold d-flex align-items-center justify-content-between">
      <span>Matriz semanal (Mar→Lun) por persona</span>
      <!-- Leyenda de abreviaturas -->
      <div class="small text-muted">
        <span class="pill pill-compact pill-A" title="Asistió">A</span>
        <span class="pill pill-compact pill-R" title="Retardo">R+min</span>
        <span class="pill pill-compact pill-F" title="Falta">F</span>
        <span class="pill pill-compact pill-P" title="Permiso">P</span>
        <span class="pill pill-compact pill-V" title="Vacaciones">V</span>
        <span class="pill pill-compact pill-D" title="Descanso">D</span>
        <span class="pill pill-compact pill-X" title="Sucursal cerrada">X</span>
        <span class="pill pill-compact pill-PN" title="Pendiente">—</span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark thead-sticky">
            <tr>
              <th>Sucursal</th><th>Colaborador</th>
              <?php $weekNames=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun']; foreach($days as $idx=>$d): ?>
                <th class="text-center"><?= $weekNames[$idx] ?><br><small><?= $d->format('d/m') ?></small></th>
              <?php endforeach; ?>
              <th class="text-end">Asis.</th><th class="text-end">Ret.</th><th class="text-end">Faltas</th><th class="text-end">Perm.</th><th class="text-end">Desc.</th><th class="text-end">Min</th><th class="text-end">Horas</th><th class="text-center">Falta por retardos</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$matriz): ?>
            <tr><td colspan="<?= 2 + count($days) + 8 ?>" class="text-muted">Sin datos.</td></tr>
          <?php else: foreach($matriz as $fila):
            $hrs=$fila['min']>0? number_format($fila['min']/60,2):'0.00';
            $faltaRet = ($fila['ret']>=3) ? '<span class="badge text-bg-danger">1</span>' : '<span class="badge text-bg-secondary">0</span>';
          ?>
            <tr>
              <td><?= h($fila['sucursal']) ?></td>
              <td class="fw-semibold"><?= h($fila['usuario']) ?></td>
              <?php foreach($fila['dias'] as $d):
                $estado=$d['estado'];
                // Abreviatura y clase
                $abbr=''; $cls=''; $title='';
                switch ($estado) {
                  case 'ASISTIÓ':   $abbr='A'; $cls='pill-A'; $title='Asistió'; break;
                  case 'RETARDO':   $abbr='R'.($d['retardo_min']>0?'+'.$d['retardo_min'].'m':''); $cls='pill-R'; $title='Retardo'.($d['retardo_min']>0?' +'.$d['retardo_min'].'m':''); break;
                  case 'FALTA':     $abbr='F'; $cls='pill-F'; $title='Falta'; break;
                  case 'PERMISO':   $abbr='P'; $cls='pill-P'; $title='Permiso'; break;
                  case 'VACACIONES':$abbr='V'; $cls='pill-V'; $title='Vacaciones'; break;
                  case 'DESCANSO':  $abbr='D'; $cls='pill-D'; $title='Descanso'; break;
                  case 'CERRADA':   $abbr='X'; $cls='pill-X'; $title='Sucursal cerrada'; break;
                  case 'PENDIENTE': $abbr='—'; $cls='pill-PN'; $title='Pendiente'; break;
                  default:          $abbr='?'; $cls='pill-PN'; $title=$estado; break;
                }
                $tooltip = 'Entrada: '.($d['entrada']??'—').' | Salida: '.($d['salida']??'—').' | Dur: '.$d['dur'].'m';
                $titleFull = trim($title.' · '.$tooltip);
              ?>
                <td class="text-center">
                  <span class="pill pill-compact <?= $cls ?>" title="<?= h($titleFull) ?>"><?= h($abbr) ?></span>
                </td>
              <?php endforeach; ?>
              <td class="text-end"><?= (int)$fila['asis'] ?></td>
              <td class="text-end"><?= (int)$fila['ret'] ?></td>
              <td class="text-end"><?= (int)$fila['fal'] ?></td>
              <td class="text-end"><?= (int)$fila['perm'] ?></td>
              <td class="text-end"><?= (int)$fila['desc'] ?></td>
              <td class="text-end"><?= (int)$fila['min'] ?></td>
              <td class="text-end"><?= $hrs ?></td>
              <td class="text-center"><?= $faltaRet ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- === PESTAÑAS: Detalle (con filtro por día) / Permisos === -->
  <div class="card card-elev">
    <div class="card-header p-0">
      <ul class="nav nav-tabs card-header-tabs" id="asistTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="tab-detalle-tab" data-bs-toggle="tab" data-bs-target="#tab-detalle" type="button" role="tab" aria-controls="tab-detalle" aria-selected="true">
            Detalle de asistencias
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-permisos-tab" data-bs-toggle="tab" data-bs-target="#tab-permisos" type="button" role="tab" aria-controls="tab-permisos" aria-selected="false">
            Permisos de la semana
          </button>
        </li>
      </ul>
    </div>

    <div class="card-body">
      <div class="tab-content" id="asistTabsContent">

        <!-- TAB: DETALLE (filtro por día) -->
        <div class="tab-pane fade show active" id="tab-detalle" role="tabpanel" aria-labelledby="tab-detalle-tab">
          <!-- Filtro por día -->
          <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="week" value="<?= h($weekIso) ?>">
            <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal_id ?>">
            <div class="col-sm-6 col-md-4">
              <label class="form-label mb-0">Día dentro de la semana</label>
              <select name="dia" class="form-select">
                <option value="">Todos los días (semana)</option>
                <?php foreach($days as $d): $v=$d->format('Y-m-d'); ?>
                  <option value="<?= $v ?>" <?= $diaSel===$v?'selected':'' ?>>
                    <?= diaCortoEs($d).' '.$d->format('d/m/Y') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3 col-md-2">
              <button class="btn btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Aplicar</button>
            </div>
            <?php if($diaSel): ?>
              <div class="col-sm-3 col-md-2">
                <a class="btn btn-outline-secondary w-100" href="?week=<?= h($weekIso) ?>&sucursal_id=<?= (int)$sucursal_id ?>">
                  Limpiar día
                </a>
              </div>
            <?php endif; ?>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr><th>Sucursal</th><th>Usuario</th><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="text-end">Duración (min)</th><th>Estado</th><th>Retardo(min)</th><th>Mapa</th><th>IP</th></tr>
              </thead>
              <tbody>
              <?php if(!$asistDetView): ?>
                <tr><td colspan="10" class="text-muted">Sin registros.</td></tr>
              <?php else: foreach($asistDetView as $a):
                $esRet = ((int)($a['retardo']??0)===1);
                $retMin = (int)($a['retardo_minutos']??0);
                $abbr = $esRet ? ('R'.($retMin>0?'+'.$retMin.'m':'')) : 'A';
                $cls  = $esRet ? 'pill-R' : 'pill-A';
                $title= $esRet ? ('Retardo'.($retMin>0?' +'.$retMin.'m':'')) : 'Asistió';
              ?>
                <tr class="<?= $a['hora_salida'] ? '' : 'table-warning' ?>">
                  <td><?= h($a['sucursal']) ?></td>
                  <td><?= h($a['usuario']) ?></td>
                  <td><?= h($a['fecha']) ?></td>
                  <td><?= h($a['hora_entrada']) ?></td>
                  <td><?= $a['hora_salida']?h($a['hora_salida']):'<span class="text-muted">—</span>' ?></td>
                  <td class="text-end"><?= (int)($a['duracion_minutos']??0) ?></td>
                  <td><span class="pill pill-compact <?= $cls ?>" title="<?= h($title) ?>"><?= h($abbr) ?></span></td>
                  <td><?= $retMin ?></td>
                  <td><?php if($a['latitud']!==null && $a['longitud']!==null): $url='https://maps.google.com/?q='.urlencode($a['latitud'].','.$a['longitud']); ?>
                    <a href="<?= h($url) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Mapa</a>
                  <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                  <td><code><?= h($a['ip']??'—') ?></code></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- TAB: PERMISOS SEMANA -->
        <div class="tab-pane fade" id="tab-permisos" role="tabpanel" aria-labelledby="tab-permisos-tab">
          <div class="table-responsive">
            <table class="table table-striped table-xs align-middle mb-0">
              <thead class="table-dark"><tr><th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th>Status</th><th>Resuelto por</th><th>Obs.</th></tr></thead>
              <tbody>
              <?php if(!$permisosSemana): ?>
                <tr><td colspan="8" class="text-muted">Sin permisos en esta semana.</td></tr>
              <?php else: foreach($permisosSemana as $p): ?>
                <tr>
                  <td><?= h($p['sucursal']) ?></td><td><?= h($p['usuario']) ?></td><td><?= h($p['fecha']) ?></td>
                  <td><?= h($p['motivo']) ?></td><td><?= h($p['comentario']??'—') ?></td>
                  <td><span class="badge <?= $p['status']==='Aprobado'?'bg-success':($p['status']==='Rechazado'?'bg-danger':'bg-warning text-dark') ?>"><?= h($p['status']) ?></span></td>
                  <td><?= isset($p['aprobado_por']) && $p['aprobado_por']!==null ? (int)$p['aprobado_por'] : '—' ?></td>
                  <td><?= h($p['comentario_aprobador']??'—') ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>

</div>

<!-- MODAL: Alta de Vacaciones -->
<div class="modal fade" id="modalVacaciones" tabindex="-1" aria-labelledby="modalVacacionesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="accion" value="alta_vacaciones">
        <div class="modal-header">
          <h5 class="modal-title" id="modalVacacionesLabel">Agregar vacaciones</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Colaborador *</label>
            <select name="id_usuario" class="form-select" required>
              <option value="">Seleccione…</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['sucursal'].' · '.$u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Fecha inicio *</label>
              <input type="date" name="fecha_inicio" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha fin *</label>
              <input type="date" name="fecha_fin" class="form-control" required>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Comentario (opcional)</label>
            <textarea name="comentario" rows="2" class="form-control" placeholder="Notas internas"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary">Guardar vacaciones</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bootstrap JS para tabs y modal -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<?php if($msgVac && $clsVac==='danger'): ?>
<script>
// Si hubo error en el alta, reabrimos el modal para corregir
const vacModal = new bootstrap.Modal(document.getElementById('modalVacaciones'));
vacModal.show();
</script>
<?php endif; ?>
</body>
</html>
