<?php
// asistencia.php — compatible con diferencias de esquema (estatus / latitud_salida / longitud_salida)
// Candado: salida mínima después de X minutos desde entrada
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

// DEBUG opcional: https://tu-sitio/asistencia.php?debug=1
if (isset($_GET['debug'])) {
  ini_set('display_errors','1');
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

/* ========= Configuración de reglas ========= */
const MIN_SALIDA_MIN = 60; // ← Candado: minutos mínimos desde entrada para permitir salida

/* ========= Helpers ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(): string {
  foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
  }
  return '0.0.0.0';
}
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/',$iso,$m)) return null;
  $dt = new DateTime(); $dt->setISODate((int)$m[1], (int)$m[2]); // ISO week: Monday
  $dt->modify('+1 day'); // Operativa: Martes → Lunes
  $dt->setTime(0,0,0); return $dt;
}
function currentOpWeekIso(): string {
  $today = new DateTime('today'); $dow = (int)$today->format('N'); // 1..7 (Mon..Sun)
  $offset = ($dow >= 2) ? $dow - 2 : 6 + $dow; // retroceder hasta martes
  $tue = (clone $today)->modify("-{$offset} days");
  $mon = (clone $tue)->modify('-1 day'); // lunes ISO para <input type=week>
  return $mon->format('o-\WW');
}
function fmtBadgeRango(DateTime $tueStart): string {
  $dias = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];
  $ini = (clone $tueStart); $fin = (clone $tueStart)->modify('+6 day');
  return $dias[0].' '.$ini->format('d/m').' → '.$dias[6].' '.$fin->format('d/m');
}
function horarioSucursalParaFecha(mysqli $conn, int $idSucursal, string $fechaYmd): ?array {
  $dow = (int)date('N', strtotime($fechaYmd)); // 1..7
  $st  = $conn->prepare("SELECT abre,cierra,cerrado FROM sucursales_horario WHERE id_sucursal=? AND dia_semana=? LIMIT 1");
  $st->bind_param('ii',$idSucursal,$dow); $st->execute();
  $res = $st->get_result()->fetch_assoc(); $st->close();
  return $res ?: null;
}
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $rs  = $conn->query($sql);
  return $rs && $rs->num_rows > 0;
}

/* ========= Session data ========= */
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser = trim($_SESSION['nombre'] ?? 'Usuario');
$rolUser    = $_SESSION['rol'] ?? '';
$isManager  = in_array($rolUser, ['Gerente','Admin','GerenteZona'], true);

$msg = '';

/* ========= Acceso por SUBTIPO de sucursal ========= */
/* Garantiza tener id_sucursal en sesión (fallback a usuarios.id_sucursal) */
if ($idSucursal <= 0) {
  $st = $conn->prepare("SELECT id_sucursal FROM usuarios WHERE id=? LIMIT 1");
  $st->bind_param('i', $idUsuario);
  $st->execute();
  if ($u = $st->get_result()->fetch_assoc()) {
    $idSucursal = (int)$u['id_sucursal'];
    $_SESSION['id_sucursal'] = $idSucursal;
  }
  $st->close();
}

/* Lee nombre y subtipo de la sucursal */
$nombreSucursalActual = '';
$subtipoSucursal      = '';
$esSucursalPropia     = false;

if ($idSucursal > 0) {
  $st = $conn->prepare("SELECT nombre, subtipo FROM sucursales WHERE id=? LIMIT 1");
  $st->bind_param('i', $idSucursal);
  $st->execute();
  if ($s = $st->get_result()->fetch_assoc()) {
    $nombreSucursalActual = trim($s['nombre'] ?? '');
    $subtipoSucursal      = trim($s['subtipo'] ?? '');
    $esSucursalPropia     = (strcasecmp($subtipoSucursal, 'Propia') === 0);
  }
  $st->close();
}

if (isset($_GET['debug'])) {
  error_log("asistencia.php DEBUG => id_usuario={$idUsuario}, id_sucursal={$idSucursal}, sucursal='{$nombreSucursalActual}', subtipo='{$subtipoSucursal}', esPropia=" . ($esSucursalPropia ? '1' : '0'));
}

/* Si NO es Propia ⇒ mostrar aviso y terminar (sin UI de marcaje) */
if (!$esSucursalPropia) {
  require_once __DIR__.'/navbar.php';
  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="UTF-8">
    <title>Asistencia</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  </head>
  <body>
    <div class="container my-5">
      <div class="alert alert-info shadow-sm">
        <i class="bi bi-info-circle me-2"></i>
        Para tu sucursal no es necesario registrar asistencia.
        <div class="small text-muted mt-1">
          Sucursal: <b><?= h($nombreSucursalActual ?: '—') ?></b>
          <?php if ($subtipoSucursal !== ''): ?> · Subtipo: <b><?= h($subtipoSucursal) ?></b><?php endif; ?>
        </div>
      </div>
      <?php if (isset($_GET['debug'])): ?>
        <pre class="small text-muted border rounded p-2 bg-light">DEBUG
id_usuario: <?= (int)$idUsuario . "\n" ?>
id_sucursal: <?= (int)$idSucursal . "\n" ?>
subtipo: <?= h($subtipoSucursal ?: '—') . "\n" ?>
permitido: NO (solo 'Propia')</pre>
      <?php endif; ?>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ========= Datos de hoy y de semana operativa ========= */
$hoyYmd       = date('Y-m-d');
$horarioHoy   = horarioSucursalParaFecha($conn, $idSucursal, $hoyYmd);
$sucCerrada   = $horarioHoy ? ((int)$horarioHoy['cerrado'] === 1 || empty($horarioHoy['abre'])) : false;
$horaApertura = $horarioHoy && !$sucCerrada ? $horarioHoy['abre'] : null;
$horaCierre   = $horarioHoy && !$sucCerrada ? ($horarioHoy['cierra'] ?? null) : null;
$toleranciaStr = $horaApertura ? DateTime::createFromFormat('H:i:s',$horaApertura)->modify('+10 minutes')->format('H:i:s') : null;

// Semana seleccionada (para panel de descansos / permisos)
$weekSelected  = $_GET['semana_g'] ?? $_POST['semana_g'] ?? currentOpWeekIso();
$tuesdayStart  = opWeekStartFromWeekInput($weekSelected) ?: new DateTime('tuesday this week');
$startWeekYmd  = $tuesdayStart->format('Y-m-d');
$endWeekYmd    = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');
$days = []; for($i=0;$i<7;$i++){ $d=clone $tuesdayStart; $d->modify("+$i day"); $days[]=$d; }
$weekNames = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

/* ========= Flags de día: descanso / permiso ========= */
// Descanso programado hoy
$st = $conn->prepare("SELECT 1 FROM descansos_programados WHERE id_usuario=? AND fecha=? AND es_descanso=1 LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd); $st->execute();
$esDescansoHoy = (bool)$st->get_result()->fetch_column(); $st->close();

// Permiso (informativo)
$st = $conn->prepare("SELECT status FROM permisos_solicitudes WHERE id_usuario=? AND fecha=? LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd); $st->execute();
$permHoy = $st->get_result()->fetch_assoc(); $st->close();

$bloqueadoParaCheckIn = $esDescansoHoy || $sucCerrada;

/* ========= Detección de columnas (compatibilidad entre entornos) ========= */
$colEstatus      = hasColumn($conn,'asistencias','estatus');
$colLatOut       = hasColumn($conn,'asistencias','latitud_salida');
$colLngOut       = hasColumn($conn,'asistencias','longitud_salida');
$tieneColsSalida = $colLatOut && $colLngOut;

/* ========= Acciones de marcaje ========= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'],['checkin','checkout'],true)) {
  $action = $_POST['action'];
  $ip     = client_ip();
  $metodo = 'web';

  if ($action === 'checkin') {
    if ($bloqueadoParaCheckIn) {
      $razon = $esDescansoHoy ? 'día de descanso' : 'sucursal cerrada';
      $msg = "<div class='alert alert-warning mb-3'>Hoy es <b>$razon</b>. No es necesario registrar entrada.</div>";
    } else {
      // ¿ya hay registro?
      $st = $conn->prepare("SELECT id, hora_salida FROM asistencias WHERE id_usuario=? AND fecha=? ORDER BY id DESC LIMIT 1");
      $st->bind_param('is',$idUsuario,$hoyYmd); $st->execute();
      $exist = $st->get_result()->fetch_assoc(); $st->close();

      if ($exist) {
        $msg = $exist['hora_salida']===null
          ? "<div class='alert alert-warning mb-3'>Ya tienes una <b>entrada abierta</b> hoy. Marca salida primero.</div>"
          : "<div class='alert alert-info mb-3'>Ya registraste asistencia hoy. No es necesario otro check-in.</div>";
      } else {
        $lat = ($_POST['lat']!=='') ? (float)$_POST['lat'] : null;
        $lng = ($_POST['lng']!=='') ? (float)$_POST['lng'] : null;
        if ($lat===null || $lng===null) {
          $msg = "<div class='alert alert-danger mb-3'>Debes permitir tu <b>ubicación</b> para registrar la entrada.</div>";
        } else {
          // cálculo de retardo
          $retardo=0; $retMin=0;
          if ($horaApertura) {
            $now   = new DateTime();
            $tolDT = DateTime::createFromFormat('H:i:s',$horaApertura)->setDate((int)date('Y'),(int)date('m'),(int)date('d'))->modify('+10 minutes');
            if ($now > $tolDT) { $retardo=1; $retMin=(int)round(($now->getTimestamp()-$tolDT->getTimestamp())/60); }
          }

          if ($colEstatus) {
            // Inserta con 'estatus'
            $estatus='Asistencia';
            $sql = "INSERT INTO asistencias
                    (id_usuario,id_sucursal,fecha,hora_entrada,estatus,retardo,retardo_minutos,latitud,longitud,ip,metodo)
                    VALUES (?,?,?,NOW(),?,?,?,?,?,?,?)";
            $st  = $conn->prepare($sql);
            $st->bind_param('iissiiddss',
              $idUsuario, $idSucursal, $hoyYmd, $estatus, $retardo, $retMin, $lat, $lng, $ip, $metodo
            );
          } else {
            // Inserta sin 'estatus'
            $sql = "INSERT INTO asistencias
                    (id_usuario,id_sucursal,fecha,hora_entrada,retardo,retardo_minutos,latitud,longitud,ip,metodo)
                    VALUES (?,?,?,NOW(),?,?,?,?,?,?)";
            $st  = $conn->prepare($sql);
            $st->bind_param('iisiiddss',
              $idUsuario, $idSucursal, $hoyYmd, $retardo, $retMin, $lat, $lng, $ip, $metodo
            );
          }

          if ($st->execute()) {
            $msg = "<div class='alert alert-success mb-3'>✅ Entrada registrado"
                 . ($retardo ? " <span class='badge bg-warning text-dark'>Retardo +{$retMin}m</span>" : '')
                 . "</div>";
          } else {
            $msg = "<div class='alert alert-danger mb-3'>Error al registrar la entrada.</div>";
          }
          $st->close();
        }
      }
    }
  }

  if ($action === 'checkout') {
    // Traer entrada abierta y minutos transcurridos desde hora_entrada
    $st = $conn->prepare("
      SELECT id, TIMESTAMPDIFF(MINUTE, hora_entrada, NOW()) AS mins_desde_entrada
      FROM asistencias
      WHERE id_usuario=? AND fecha=? AND hora_salida IS NULL
      ORDER BY id ASC LIMIT 1
    ");
    $st->bind_param('is',$idUsuario,$hoyYmd); $st->execute();
    $abierta = $st->get_result()->fetch_assoc(); $st->close();

    if (!$abierta) {
      $msg = "<div class='alert alert-warning mb-3'>No hay una entrada abierta hoy para cerrar.</div>";
    } else {
      $minsDesdeEntrada = (int)($abierta['mins_desde_entrada'] ?? 0);
      if ($minsDesdeEntrada < MIN_SALIDA_MIN) {
        $faltan = MAX(0, MIN_SALIDA_MIN - $minsDesdeEntrada);
        $msg = "<div class='alert alert-warning mb-3'>
                  Para registrar la salida se requiere un mínimo de <b>".MIN_SALIDA_MIN." min</b> desde tu entrada.
                  Te faltan <b>{$faltan} min</b>.
                </div>";
      } else {
        $latOut = ($_POST['lat_out']!=='') ? (float)$_POST['lat_out'] : null;
        $lngOut = ($_POST['lng_out']!=='') ? (float)$_POST['lng_out'] : null;
        if ($latOut===null || $lngOut===null) {
          $msg = "<div class='alert alert-danger mb-3'>Debes permitir tu <b>ubicación</b> para registrar la salida.</div>";
        } else {
          $idAsist = (int)$abierta['id'];

          if ($tieneColsSalida) {
            // Guardar coordenadas de salida en columnas dedicadas
            $sql = "UPDATE asistencias
                    SET hora_salida=NOW(),
                        duracion_minutos=TIMESTAMPDIFF(MINUTE,hora_entrada,NOW()),
                        latitud_salida=?, longitud_salida=?, ip=?, metodo=?
                    WHERE id=? AND hora_salida IS NULL";
            $st  = $conn->prepare($sql);
            $ipNow = client_ip();
            $st->bind_param('ddssi',$latOut,$lngOut,$ipNow,$metodo,$idAsist);
          } else {
            // Entorno sin columnas *_salida: solo actualiza hora/ip/metodo (no pisa lat/long de entrada)
            $sql = "UPDATE asistencias
                    SET hora_salida=NOW(),
                        duracion_minutos=TIMESTAMPDIFF(MINUTE,hora_entrada,NOW()),
                        ip=?, metodo=?
                    WHERE id=? AND hora_salida IS NULL";
            $st  = $conn->prepare($sql);
            $ipNow = client_ip();
            $st->bind_param('ssi',$ipNow,$metodo,$idAsist);
          }

          if ($st->execute() && $st->affected_rows > 0) {
            $msg = "<div class='alert alert-success mb-3'>✅ Salida registrada.</div>";
          } else {
            $msg = "<div class='alert alert-danger mb-3'>No se pudo registrar la salida.</div>";
          }
          $st->close();
        }
      }
    }
  }
}

/* ========= Datos de hoy ========= */
$st=$conn->prepare("SELECT * FROM asistencias WHERE id_usuario=? AND fecha=? ORDER BY id DESC LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd); $st->execute(); $asistHoy=$st->get_result()->fetch_assoc(); $st->close();

$st=$conn->prepare("SELECT id, hora_entrada FROM asistencias WHERE id_usuario=? AND fecha=? AND hora_salida IS NULL LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd); $st->execute(); $abiertaHoy=$st->get_result()->fetch_assoc(); $st->close();

$puedeCheckIn  = !$asistHoy && !$bloqueadoParaCheckIn;
$puedeCheckOut = $abiertaHoy !== null;

$entradaHoy       = $asistHoy['hora_entrada'] ?? null;
$salidaHoy        = $asistHoy['hora_salida']  ?? null;
$duracionHoy      = $asistHoy['duracion_minutos'] ?? null;
$retardoHoy       = (int)($asistHoy['retardo'] ?? 0);
$retardoMinHoy    = (int)($asistHoy['retardo_minutos'] ?? 0);

/* ==== Cálculo para UI: bloqueo de salida anticipada ==== */
$minsDesdeEntradaUI = null; $bloqueoAnticipadoUI = false; $faltanUI = 0;
if ($puedeCheckOut && $entradaHoy && !$salidaHoy) {
  $entradaStr = (string)$entradaHoy;
  $entradaDT  = (strlen($entradaStr) <= 8) ? strtotime($hoyYmd.' '.$entradaStr) : strtotime($entradaStr);
  if ($entradaDT) {
    $minsDesdeEntradaUI = (int)floor((time() - $entradaDT) / 60);
    $bloqueoAnticipadoUI = ($minsDesdeEntradaUI < MIN_SALIDA_MIN);
    if ($bloqueoAnticipadoUI) $faltanUI = max(0, MIN_SALIDA_MIN - $minsDesdeEntradaUI);
  }
}

/* ========= Historial (últimos 20) ========= */
$st=$conn->prepare("
  SELECT a.*, s.nombre AS sucursal
  FROM asistencias a
  LEFT JOIN sucursales s ON s.id=a.id_sucursal
  WHERE a.id_usuario=?
  ORDER BY a.fecha DESC, a.id DESC
  LIMIT 20
");
$st->bind_param('i',$idUsuario); $st->execute(); $hist=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

/* ========= Paneles (descansos / permisos) ========= */
$staff=[]; $staffSuc=[];
if ($isManager) {
  $st=$conn->prepare("SELECT id,nombre,rol FROM usuarios WHERE id_sucursal=? AND activo=1 ORDER BY (rol='Gerente') DESC, nombre");
  $st->bind_param('i',$idSucursal); $st->execute(); $staff=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

  $st=$conn->prepare("SELECT id,nombre FROM usuarios WHERE id_sucursal=? AND activo=1 ORDER BY nombre");
  $st->bind_param('i',$idSucursal); $st->execute(); $staffSuc=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* Acciones descansos */
if ($_SERVER['REQUEST_METHOD']==='POST' && $isManager && isset($_POST['action']) && in_array($_POST['action'],['guardar_descansos','limpiar_semana'],true)) {
  $semana=trim($_POST['semana_g']??''); $tue=opWeekStartFromWeekInput($semana);
  if ($tue) {
    $start=$tue->format('Y-m-d'); $end=(clone $tue)->modify('+6 day')->format('Y-m-d');
    $st=$conn->prepare("DELETE dp FROM descansos_programados dp WHERE dp.fecha BETWEEN ? AND ? AND dp.id_usuario IN (SELECT id FROM usuarios WHERE id_sucursal=? AND activo=1)");
    $st->bind_param('ssi',$start,$end,$idSucursal); $st->execute(); $st->close();

    if ($_POST['action']==='guardar_descansos' && !empty($_POST['descanso']) && is_array($_POST['descanso'])) {
      $ins=$conn->prepare("INSERT INTO descansos_programados (id_usuario,fecha,es_descanso,creado_por) VALUES (?,?,1,?)");
      foreach($_POST['descanso'] as $uidStr=>$byDate){
        $uid=(int)$uidStr; if(!$uid || !is_array($byDate)) continue;
        foreach($byDate as $f=>$v){
          if($v!=='1') continue;
          if($f>=$start && $f<=$end){ $ins->bind_param('isi',$uid,$f,$idUsuario); $ins->execute(); }
        }
      }
      $ins->close();
      $msg="<div class='alert alert-success mb-3'>✅ Descansos guardados para semana ".h($semana).".</div>";
    } else {
      $msg="<div class='alert alert-warning mb-3'>🧹 Semana limpiada.</div>";
    }
  } else { $msg="<div class='alert alert-danger mb-3'>Semana inválida.</div>"; }
}

/* Permisos: crear (Gerente/Admin) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='crear_permiso' && in_array($rolUser,['Gerente','Admin'],true)) {
  $uid=(int)($_POST['perm_uid']??0); $fecha=trim($_POST['perm_fecha']??''); $motivo=trim($_POST['perm_motivo']??''); $com=trim($_POST['perm_com']??'');
  if(!$uid || !$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || $motivo===''){
    $msg="<div class='alert alert-danger mb-3'>Datos incompletos para permiso.</div>";
  } else {
    $st=$conn->prepare("SELECT id_sucursal FROM usuarios WHERE id=? AND activo=1 LIMIT 1");
    $st->bind_param('i',$uid); $st->execute(); $ux=$st->get_result()->fetch_assoc(); $st->close();
    if(!$ux || (int)$ux['id_sucursal']!==$idSucursal){
      $msg="<div class='alert alert-danger mb-3'>El colaborador no pertenece a tu sucursal.</div>";
    } else {
      $st=$conn->prepare("INSERT INTO permisos_solicitudes (id_usuario,id_sucursal,fecha,motivo,comentario,status,creado_por) VALUES (?,?,?,?,?,'Pendiente',?)");
      $st->bind_param('iisssi',$uid,$idSucursal,$fecha,$motivo,$com,$idUsuario);
      if($st->execute()){
        $msg="<div class='alert alert-success mb-3'>✅ Permiso registrado y enviado a Gerente de Zona.</div>";
      } else {
        $msg="<div class='alert alert-warning mb-3'>Ya existe una solicitud de permiso para ese colaborador y fecha.</div>";
      }
      $st->close();
    }
  }
}

/* Permisos: aprobar/rechazar (GZ/Admin) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'],['aprobar_permiso','rechazar_permiso'],true) && in_array($rolUser,['GerenteZona','Admin'],true)) {
  $pid=(int)($_POST['perm_id']??0); $obs=trim($_POST['perm_obs']??'');
  $stLabel = $_POST['action']==='aprobar_permiso' ? 'Aprobado' : 'Rechazado';
  if($pid){
    $st=$conn->prepare("UPDATE permisos_solicitudes SET status=?, aprobado_por=?, aprobado_en=NOW(), comentario_aprobador=? WHERE id=? AND status='Pendiente'");
    $st->bind_param('sisi',$stLabel,$idUsuario,$obs,$pid); $st->execute(); $st->close();
    $msg="<div class='alert alert-success mb-3'>✅ Permiso $stLabel.</div>";
  }
}

/* Listas permisos de la semana */
$permisosSemana=[];
if ($isManager) {
  $st=$conn->prepare("
    SELECT p.*, u.nombre AS usuario
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    WHERE p.id_sucursal=? AND p.fecha BETWEEN ? AND ?
    ORDER BY p.fecha DESC, p.id DESC
  ");
  $st->bind_param('iss',$idSucursal,$startWeekYmd,$endWeekYmd); $st->execute();
  $permisosSemana=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

$pendientesGZ=[];
if (in_array($rolUser,['GerenteZona','Admin'],true)) {
  $st=$conn->prepare("
    SELECT p.*, u.nombre AS usuario, s.nombre AS sucursal
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    JOIN sucursales s ON s.id=p.id_sucursal
    WHERE p.status='Pendiente' AND p.fecha BETWEEN ? AND ?
    ORDER BY s.nombre, u.nombre, p.fecha
  ");
  $st->bind_param('ss',$startWeekYmd,$endWeekYmd); $st->execute();
  $pendientesGZ=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

// Navbar (al final para aprovechar $msg si lo deseas arriba del contenido)
require_once __DIR__.'/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Asistencia (Permisos + Horario)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --brand:#0d6efd; --bg:#f8fafc; --ink:#0f172a; --muted:#64748b; }
    body{ background: radial-gradient(1200px 400px at 120% -50%, rgba(13,110,253,.07), transparent),
                      radial-gradient(1000px 380px at -10% 120%, rgba(25,135,84,.06), transparent), var(--bg); }
    .page-title{font-weight:800; letter-spacing:.2px; color:var(--ink);}
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .section-title{font-size:.95rem; font-weight:700; color:#334155; letter-spacing:.6px; text-transform:uppercase; display:flex; align-items:center; gap:.5rem;}
    .help-text{color:var(--muted); font-size:.9rem;}
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .badge-retardo{ background:#fff3cd; color:#8a6d3b; border:1px solid #ffeeba; }
  </style>
</head>
<body>

<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-person-check me-2"></i>Asistencia</h2>
      <div class="help-text">Hola, <b><?= h($nombreUser) ?></b>. Registra tu entrada/salida y revisa tus marcajes.</div>
    </div>
    <div class="text-end">
      <div class="help-text mb-1">Semana (Mar→Lun): <b><?= h(fmtBadgeRango($tuesdayStart)) ?></b></div>
    </div>
  </div>

  <?= $msg ?>

  <?php if ($sucCerrada): ?>
    <div class="alert alert-secondary"><i class="bi bi-door-closed me-1"></i>Sucursal <b>cerrada</b> hoy.</div>
  <?php endif; ?>
  <?php if ($esDescansoHoy): ?>
    <div class="alert alert-light border"><i class="bi bi-moon-stars me-1"></i>Hoy es tu <b>descanso</b>.</div>
  <?php endif; ?>
  <?php if ($permHoy && ($permHoy['status'] ?? '')==='Aprobado'): ?>
    <div class="alert alert-info"><i class="bi bi-clipboard-check me-1"></i>Tienes un <b>permiso aprobado</b> para hoy.</div>
  <?php endif; ?>

  <!-- Estado de hoy -->
  <div class="card card-elev mb-4">
    <div class="card-body">
      <div class="row g-3 align-items-center">
        <div class="col-md-6">
          <div class="section-title mb-2"><i class="bi bi-calendar-day"></i> Tu día de hoy</div>
          <ul class="list-unstyled mb-2">
            <li><b>Entrada:</b> <?= $entradaHoy ? h($entradaHoy) : '<span class="text-muted">—</span>' ?>
              <?php if ($entradaHoy && $retardoHoy): ?><span class="badge badge-retardo ms-2">Retardo +<?= (int)$retardoMinHoy ?> min</span><?php endif; ?>
            </li>
            <li><b>Salida:</b> <?= $salidaHoy ? h($salidaHoy) : '<span class="text-muted">—</span>' ?></li>
            <li><b>Duración:</b> <?= $duracionHoy!==null ? (int)$duracionHoy.' min' : '<span class="text-muted">—</span>' ?></li>
            <?php if ($bloqueoAnticipadoUI): ?>
              <li class="text-danger"><i class="bi bi-hourglass-split me-1"></i>
                Te faltan <b><?= (int)$faltanUI ?></b> min para poder registrar la salida (mínimo <?= MIN_SALIDA_MIN ?> min).
              </li>
            <?php endif; ?>
          </ul>
          <div class="help-text">
            <?php if ($sucCerrada): ?>
              <i class="bi bi-info-circle me-1"></i>Hoy no hay horario laboral (cerrada).
            <?php elseif ($horaApertura): ?>
              <i class="bi bi-clock me-1"></i>Horario de hoy: <b><?= h(substr($horaApertura,0,5)) ?></b><?= $horaCierre?'– <b>'.h(substr($horaCierre,0,5)).'</b>':'' ?>.
              Tolerancia: <b>10 min</b> (retardo después de <b><?= h(substr($toleranciaStr,0,5)) ?></b>).
            <?php else: ?>
              <i class="bi bi-clock me-1"></i>Sin horario configurado para hoy.
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="d-flex flex-wrap gap-2 justify-content-md-end">
            <!-- CHECK-IN -->
            <form method="post" class="d-inline" onsubmit="return prepGeoIn(this)">
              <input type="hidden" name="action" value="checkin">
              <input type="hidden" name="lat" id="lat_in">
              <input type="hidden" name="lng" id="lng_in">
              <button class="btn btn-success btn-lg" id="btnIn" <?= $puedeCheckIn?'':'disabled' ?>>
                <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
              </button>
            </form>
            <!-- CHECK-OUT -->
            <form method="post" class="d-inline" onsubmit="return prepGeoOut(this)">
              <input type="hidden" name="action" value="checkout">
              <input type="hidden" name="lat_out" id="lat_out">
              <input type="hidden" name="lng_out" id="lng_out">
              <?php
                $btnOutDisabled = !$puedeCheckOut || $bloqueoAnticipadoUI;
                $btnOutTitle = $bloqueoAnticipadoUI ? "Debes esperar {$faltanUI} min (mínimo ".MIN_SALIDA_MIN." min desde tu entrada)" : "";
              ?>
              <button class="btn btn-danger btn-lg" id="btnOut" <?= $btnOutDisabled?'disabled':'' ?> title="<?= h($btnOutTitle) ?>">
                <i class="bi bi-box-arrow-right me-1"></i> Salir
              </button>
            </form>
          </div>
          <div class="help-text mt-2"><i class="bi bi-shield-check me-1"></i>Tu ubicación solo se usa para validar el marcaje.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Historial -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-clock-history"></i> Tus últimos marcajes</div>
      <span class="badge bg-light text-dark">Últimos <?= count($hist) ?> registros</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-xs align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th class="text-end">Duración (min)</th><th>Sucursal</th><th>Mapa</th><th>IP</th></tr>
          </thead>
          <tbody>
          <?php if(!$hist): ?>
            <tr><td colspan="7" class="text-muted">Sin registros.</td></tr>
          <?php else: foreach($hist as $r): ?>
            <tr class="<?= $r['hora_salida'] ? '' : 'table-warning' ?>">
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['hora_entrada']) ?></td>
              <td><?= $r['hora_salida']?h($r['hora_salida']):'<span class="text-muted">—</span>' ?></td>
              <td class="text-end"><?= $r['duracion_minutos']!==null ? (int)$r['duracion_minutos'] : '—' ?></td>
              <td><?= h($r['sucursal'] ?? 'N/D') ?></td>
              <td>
                <?php if ($r['latitud']!==null && $r['longitud']!==null):
                  $url='https://maps.google.com/?q='.urlencode($r['latitud'].','.$r['longitud']); ?>
                  <a href="<?= h($url) ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-geo"></i> Ver mapa</a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td><code><?= h($r['ip'] ?? '—') ?></code></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($isManager): ?>
  <!-- Descansos (Mar→Lun) -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="section-title mb-0"><i class="bi bi-moon-stars"></i> Descansos por semana (Mar→Lun)</div>
      <form method="get" class="d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary"><?= h(fmtBadgeRango($tuesdayStart)) ?></span>
        <label class="form-label mb-0">Semana</label>
        <input type="week" name="semana_g" value="<?= h($weekSelected) ?>" class="form-control form-control-sm">
        <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Ver</button>
      </form>
    </div>
    <div class="card-body p-0">
      <?php
        $pre=[]; // precarga de checks
        $st=$conn->prepare("SELECT id_usuario,fecha FROM descansos_programados WHERE fecha BETWEEN ? AND ? AND id_usuario IN (SELECT id FROM usuarios WHERE id_sucursal=? AND activo=1)");
        $st->bind_param('ssi',$startWeekYmd,$endWeekYmd,$idSucursal); $st->execute();
        $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        foreach($rows as $r){ $pre[(int)$r['id_usuario']][$r['fecha']]=true; }
      ?>
      <?php if (!$staff): ?>
        <div class="p-3 text-muted">No hay personal activo en tu sucursal.</div>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="semana_g" value="<?= h($weekSelected) ?>">
          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr><th>Colaborador</th><th>Rol</th>
                <?php foreach ($days as $idx=>$d): ?><th class="text-center"><?= $weekNames[$idx] ?><br><small class="text-muted"><?= $d->format('d/m') ?></small></th><?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach($staff as $u): ?>
                  <tr>
                    <td class="fw-semibold"><?= h($u['nombre']) ?></td>
                    <td><span class="badge bg-light text-dark"><?= h($u['rol']) ?></span></td>
                    <?php foreach ($days as $d): $f=$d->format('Y-m-d'); $checked=!empty($pre[(int)$u['id']][$f]); ?>
                      <td class="text-center">
                        <input type="checkbox" class="form-check-input" name="descanso[<?= (int)$u['id'] ?>][<?= $f ?>]" value="1" <?= $checked?'checked':'' ?>>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-center p-2 border-top bg-white">
            <button class="btn btn-outline-secondary" name="action" value="limpiar_semana"><i class="bi bi-eraser me-1"></i> Limpiar semana</button>
            <button class="btn btn-success" name="action" value="guardar_descansos"><i class="bi bi-save me-1"></i> Guardar descansos</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (in_array($rolUser,['Gerente','Admin'],true)): ?>
  <!-- Solicitud de permisos -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-clipboard-plus"></i> Solicitud de permisos</div>
      <span class="help-text">Si lo aprueba el Gerente de Zona, no contará como falta.</span>
    </div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="action" value="crear_permiso">
        <div class="col-md-3">
          <label class="form-label mb-0">Colaborador</label>
          <select name="perm_uid" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php foreach($staffSuc as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-0">Fecha</label>
          <input type="date" name="perm_fecha" class="form-control" required value="<?= $tuesdayStart->format('Y-m-d') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label mb-0">Motivo</label>
          <input type="text" name="perm_motivo" class="form-control" required placeholder="Ej. cita médica">
        </div>
        <div class="col-md-3">
          <label class="form-label mb-0">Comentario (opcional)</label>
          <input type="text" name="perm_com" class="form-control" placeholder="Detalle adicional">
        </div>
        <div class="col-12 text-end">
          <button class="btn btn-warning"><i class="bi bi-send-plus me-1"></i> Enviar</button>
        </div>
      </form>

      <hr>
      <div class="section-title mb-2"><i class="bi bi-list-check"></i> Permisos de la semana (tu sucursal)</div>
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark"><tr>
            <th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th>Status</th><th>Resuelto por</th><th>Obs.</th>
          </tr></thead>
          <tbody>
            <?php if(!$permisosSemana): ?>
              <tr><td colspan="7" class="text-muted">Sin solicitudes esta semana.</td></tr>
            <?php else: foreach($permisosSemana as $p): ?>
              <tr>
                <td><?= h($p['usuario']) ?></td>
                <td><?= h($p['fecha']) ?></td>
                <td><?= h($p['motivo']) ?></td>
                <td><?= h($p['comentario'] ?? '—') ?></td>
                <td><span class="badge <?= $p['status']==='Aprobado'?'bg-success':($p['status']==='Rechazado'?'bg-danger':'bg-warning text-dark') ?>"><?= h($p['status']) ?></span></td>
                <td><?= $p['aprobado_por'] ? (int)$p['aprobado_por'] : '—' ?></td>
                <td><?= h($p['comentario_aprobador'] ?? '—') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; // Gerente/Admin ?>

  <!-- Pendientes GZ -->
  <?php if (in_array($rolUser,['GerenteZona','Admin'],true)): ?>
  <div class="card card-elev">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-inboxes"></i> Permisos pendientes (Mar→Lun)</div>
      <span class="badge bg-danger"><?= count($pendientesGZ) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-xs align-middle mb-0">
          <thead class="table-dark"><tr>
            <th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th class="text-end">Acciones</th>
          </tr></thead>
          <tbody>
            <?php if(!$pendientesGZ): ?>
              <tr><td colspan="6" class="text-muted">Sin pendientes.</td></tr>
            <?php else: foreach($pendientesGZ as $p): ?>
              <tr>
                <td><?= h($p['sucursal']) ?></td>
                <td><?= h($p['usuario']) ?></td>
                <td><?= h($p['fecha']) ?></td>
                <td><?= h($p['motivo']) ?></td>
                <td><?= h($p['comentario'] ?? '—') ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="aprobar_permiso">
                    <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="perm_obs" value="">
                    <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Aprobar</button>
                  </form>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="rechazar_permiso">
                    <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="perm_obs" value="">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Rechazar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; // GZ/Admin ?>

  <?php endif; // isManager ?>
</div>

<script>
  function prepGeoIn(form){
    const btn=document.getElementById('btnIn'); if(btn) btn.disabled=true;
    if(!navigator.geolocation){ alert('Tu navegador no soporta geolocalización.'); if(btn) btn.disabled=false; return false; }
    navigator.geolocation.getCurrentPosition(
      (pos)=>{ document.getElementById('lat_in').value=pos.coords.latitude; document.getElementById('lng_in').value=pos.coords.longitude; form.submit(); },
      (_)=>{ alert('Activa GPS y permite la ubicación para ENTRADA.'); if(btn) btn.disabled=false; },
      { enableHighAccuracy:true, timeout:6000, maximumAge:0 }
    ); return false;
  }
  function prepGeoOut(form){
    const btn=document.getElementById('btnOut'); if(btn) btn.disabled=true;
    if(!navigator.geolocation){ alert('Tu navegador no soporta geolocalización.'); if(btn) btn.disabled=false; return false; }
    navigator.geolocation.getCurrentPosition(
      (pos)=>{ document.getElementById('lat_out').value=pos.coords.latitude; document.getElementById('lng_out').value=pos.coords.longitude; form.submit(); },
      (_)=>{ alert('Activa GPS y permite la ubicación para SALIDA.'); if(btn) btn.disabled=false; },
      { enableHighAccuracy:true, timeout:6000, maximumAge:0 }
    ); return false;
  }
</script>
</body>
</html>
