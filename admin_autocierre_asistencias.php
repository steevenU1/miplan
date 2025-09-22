<?php
// admin_autocierre_asistencias.php — Cierre masivo de asistencias abiertas
// Requisitos: Admin o GerenteZona
ob_start();
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['Admin','GerenteZona'], true)) { header("Location: 403.php"); exit(); }

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

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $rs  = $conn->query($sql);
  return $rs && $rs->num_rows > 0;
}
function horarioSucursalParaFecha(mysqli $conn, int $idSucursal, string $fechaYmd): ?array {
  // intenta en sucursales_horario; si no existe, intenta en horarios_sucursal (compat)
  $dow = (int)date('N', strtotime($fechaYmd)); // 1..7
  $tbl = 'sucursales_horario';
  $exists = $conn->query("SHOW TABLES LIKE 'sucursales_horario'"); 
  if (!$exists || $exists->num_rows === 0) $tbl = 'horarios_sucursal';
  if ($tbl === 'sucursales_horario') {
    $st  = $conn->prepare("SELECT abre,cierra,cerrado FROM sucursales_horario WHERE id_sucursal=? AND dia_semana=? LIMIT 1");
  } else {
    // map a nombres compatibles
    $st  = $conn->prepare("SELECT apertura AS abre,cierre AS cierra,IF(activo=1,0,1) AS cerrado FROM horarios_sucursal WHERE id_sucursal=? AND dia_semana=? LIMIT 1");
  }
  $st->bind_param('ii',$idSucursal,$dow); $st->execute();
  $res = $st->get_result()->fetch_assoc(); $st->close();
  return $res ?: null;
}
function salidaSugerida(string $fecha, string $horaEntrada, ?array $horario): DateTime {
  // Base: cierre de sucursal; fallback: 23:59
  if ($horario && (int)$horario['cerrado'] !== 1 && !empty($horario['cierra'])) {
    $dt = new DateTime($fecha.' '.$horario['cierra']);
  } else {
    $dt = new DateTime($fecha.' 23:59:00');
  }
  // Si por alguna razón el cierre es <= a la entrada, empuja 1 minuto
  $inDT = new DateTime((strlen($horaEntrada)<=8) ? ($fecha.' '.$horaEntrada) : $horaEntrada);
  if ($dt <= $inDT) { $dt = (clone $inDT)->modify('+1 minute'); }
  // No proponer un futuro si estamos revisando pasado: la vista mostrará min(sugerida, NOW()) solo al cerrar
  return $dt;
}

/* ===== Acciones ===== */
$today = date('Y-m-d');
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  $ipNow  = 'auto-close';
  $metodo = 'autocierre';

  // Trae todas las abiertas para decidir cierre en PHP (por horario por sucursal)
  $rs = $conn->query("
    SELECT a.id, a.id_usuario, a.id_sucursal, a.fecha, a.hora_entrada, u.nombre AS usuario
    FROM asistencias a
    JOIN usuarios u   ON u.id=a.id_usuario
    WHERE a.hora_salida IS NULL
    ORDER BY a.fecha ASC, a.hora_entrada ASC, a.id ASC
  ");
  $abiertas = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];

  $cerradas = 0; $saltadas = 0;
  foreach ($abiertas as $row) {
    $fecha = $row['fecha'];
    $hor   = horarioSucursalParaFecha($conn, (int)$row['id_sucursal'], $fecha);
    $sugDT = salidaSugerida($fecha, $row['hora_entrada'], $hor);
    $nowDT = new DateTime();

    $elegible = false;
    if ($action === 'cerrar_sel') {
      // Cerrar solo los marcados
      $idsSel = array_map('intval', $_POST['ids'] ?? []);
      if (!in_array((int)$row['id'], $idsSel, true)) { continue; }
      // Evita cerrar hoy si aún no pasó la hora de cierre
      $elegible = ($fecha < $today) || ($fecha === $today && $nowDT >= $sugDT);
    } elseif ($action === 'cerrar_ayer') {
      // Cerrar únicamente los de fechas anteriores a hoy
      $elegible = ($fecha < $today);
    } elseif ($action === 'cerrar_elegibles') {
      // Cerrar los de fechas anteriores y también hoy si ya pasó el cierre
      $elegible = ($fecha < $today) || ($fecha === $today && $nowDT >= $sugDT);
    }

    if (!$elegible) { $saltadas++; continue; }

    // Hora de salida real = el menor entre sugerida y ahora (por si se ejecuta antes del cierre)
    $salidaReal = ($nowDT < $sugDT) ? $nowDT : $sugDT;
    $salidaStr  = $salidaReal->format('Y-m-d H:i:s');

    // Update: sin tocar lat/lng de entrada; si existen columnas *_salida y quieres registrar nulls, podrías setearlos.
    $sql = "UPDATE asistencias
            SET hora_salida=?,
                duracion_minutos=TIMESTAMPDIFF(MINUTE, hora_entrada, ?),
                ip=?, metodo=?
            WHERE id=? AND hora_salida IS NULL";
    $st  = $conn->prepare($sql);
    $st->bind_param('ssssi', $salidaStr, $salidaStr, $ipNow, $metodo, $row['id']);
    if ($st->execute() && $st->affected_rows>0) { $cerradas++; } else { $saltadas++; }
    $st->close();
  }

  $msg = "<div class='alert alert-info mb-3'>Cerradas: <b>{$cerradas}</b> &nbsp; | &nbsp; Omitidas: <b>{$saltadas}</b></div>";
}

/* ===== Carga de abiertas para mostrar ===== */
$sql = "
  SELECT a.*, u.nombre AS usuario, s.nombre AS sucursal,
         TIMESTAMPDIFF(MINUTE, a.hora_entrada, NOW()) AS mins_trans
  FROM asistencias a
  JOIN usuarios u ON u.id=a.id_usuario
  JOIN sucursales s ON s.id=a.id_sucursal
  WHERE a.hora_salida IS NULL
  ORDER BY a.fecha ASC, a.hora_entrada ASC, a.id ASC
";
$res = $conn->query($sql);
$abiertas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

/* ===== UI ===== */
require_once __DIR__.'/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin · Autocierre de asistencias abiertas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .pill-warn{ background:#fde2e1; color:#a61e4d; border:1px solid #f8b4b4; padding:.1rem .5rem; border-radius:999px; font-weight:600; font-size:.78rem; }
    .pill-ok{ background:#e7f5ff; color:#0b7285; border:1px solid #c5e3f6; padding:.1rem .5rem; border-radius:999px; font-weight:600; font-size:.78rem; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-door-open me-2"></i>Autocierre de asistencias abiertas</h3>
    <span class="badge text-bg-secondary"><?= h(date('Y-m-d H:i')) ?></span>
  </div>

  <?= $msg ?>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="post" class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-secondary" name="action" value="cerrar_ayer">
          <i class="bi bi-sunset me-1"></i> Cerrar todo <b>hasta ayer</b>
        </button>
        <button class="btn btn-primary" name="action" value="cerrar_elegibles">
          <i class="bi bi-magic me-1"></i> Cerrar <b>todas las elegibles</b> (ayer + hoy si ya pasó el cierre)
        </button>
      </form>
    </div>
  </div>

  <div class="card card-elev">
    <div class="card-header fw-bold">Entradas abiertas</div>
    <div class="card-body p-0">
      <form method="post" id="formSel">
        <input type="hidden" name="action" value="cerrar_sel">
        <div class="table-responsive">
          <table class="table table-hover table-xs align-middle mb-0">
            <thead class="table-dark">
              <tr>
                <th style="width:32px;"><input type="checkbox" class="form-check-input" id="chkAll"></th>
                <th>Sucursal</th>
                <th>Colaborador</th>
                <th>Fecha</th>
                <th>Entrada</th>
                <th class="text-end">Min. transcurridos</th>
                <th>Sugerida salida</th>
                <th>Elegible</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$abiertas): ?>
              <tr><td colspan="8" class="text-muted">No hay entradas abiertas 🎉</td></tr>
            <?php else: foreach($abiertas as $a):
              $fecha = $a['fecha'];
              $hor   = horarioSucursalParaFecha($conn, (int)$a['id_sucursal'], $fecha);
              $sugDT = salidaSugerida($fecha, $a['hora_entrada'], $hor);
              $nowDT = new DateTime();
              $elegible = ($fecha < $today) || ($fecha === $today && $nowDT >= $sugDT);
            ?>
              <tr class="<?= $elegible ? '' : 'table-warning' ?>">
                <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int)$a['id'] ?>"></td>
                <td><?= h($a['sucursal']) ?></td>
                <td><?= h($a['usuario']) ?></td>
                <td><?= h($a['fecha']) ?></td>
                <td><?= h($a['hora_entrada']) ?></td>
                <td class="text-end"><?= (int)($a['mins_trans'] ?? 0) ?></td>
                <td><?= h($sugDT->format('Y-m-d H:i')) ?></td>
                <td><?= $elegible ? '<span class="pill-ok">Sí</span>' : '<span class="pill-warn">Aún no</span>' ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="p-2 border-top bg-white d-flex justify-content-between align-items-center">
          <div class="text-muted small">Selecciona filas y cierra manualmente si lo necesitas.</div>
          <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i> Cerrar seleccionados</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  const chkAll = document.getElementById('chkAll');
  if (chkAll) {
    chkAll.addEventListener('change', (e)=>{
      document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = e.target.checked);
    });
  }
</script>
</body>
</html>
