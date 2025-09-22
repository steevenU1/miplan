<?php
// vacaciones_alta.php — Alta masiva de vacaciones (crea permisos "Vacaciones" Aprobados por día)
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  $q = $c->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}
function column_exists(mysqli $c, string $t, string $col): bool {
  $t = $c->real_escape_string($t); $col = $c->real_escape_string($col);
  $q = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
  return $q && $q->num_rows > 0;
}
function pickDateCol(mysqli $conn, string $table, array $candidates=['fecha','fecha_permiso','dia','fecha_solicitada','creado_en']): string {
  foreach ($candidates as $c) if (column_exists($conn,$table,$c)) return $c;
  return 'fecha';
}

// ===== Usuarios activos (Gerente/Ejecutivo) en tiendas propias =====
$usuarios = [];
$sqlU = "
  SELECT u.id, u.nombre, u.id_sucursal, s.nombre AS sucursal
  FROM usuarios u
  JOIN sucursales s ON s.id=u.id_sucursal
  WHERE u.activo=1 AND u.rol IN ('Gerente','Ejecutivo')
    AND s.tipo_sucursal='tienda' AND s.subtipo='propia'
  ORDER BY s.nombre, u.nombre
";
$resU = $conn->query($sqlU);
while($r = $resU->fetch_assoc()) $usuarios[] = $r;

$MSG = ''; $CLS = 'info';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $idUsuario = (int)($_POST['id_usuario'] ?? 0);
  $fini = trim($_POST['fecha_inicio'] ?? '');
  $ffin = trim($_POST['fecha_fin'] ?? '');
  $coment = trim($_POST['comentario'] ?? '');

  // Validar usuario y rango
  $userRow = null;
  foreach($usuarios as $u){ if ((int)$u['id'] === $idUsuario) { $userRow=$u; break; } }
  if (!$userRow) { $MSG="Usuario inválido."; $CLS="danger"; }
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ffin) || $ffin < $fini) {
    $MSG="Rango de fechas inválido."; $CLS="danger";
  } elseif (!table_exists($conn,'permisos_solicitudes')) {
    $MSG="No existe la tabla permisos_solicitudes."; $CLS="danger";
  } else {
    // ===== Descubrir columnas existentes =====
    $dateCol       = pickDateCol($conn,'permisos_solicitudes');
    $hasAprobPor   = column_exists($conn,'permisos_solicitudes','aprobado_por');
    $hasAprobEn    = column_exists($conn,'permisos_solicitudes','aprobado_en');
    $hasComApr     = column_exists($conn,'permisos_solicitudes','comentario_aprobador');
    $hasStatus     = column_exists($conn,'permisos_solicitudes','status');
    $hasMotivo     = column_exists($conn,'permisos_solicitudes','motivo');
    $hasComent     = column_exists($conn,'permisos_solicitudes','comentario');
    $hasSucursal   = column_exists($conn,'permisos_solicitudes','id_sucursal');
    $hasCreadoPor  = column_exists($conn,'permisos_solicitudes','creado_por');
    $hasCreadoEn   = column_exists($conn,'permisos_solicitudes','creado_en'); // timestamp/datetime

    // Construir lista de columnas para INSERT
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

    // Placeholders (para *_en usamos NOW())
    $placeholders = [];
    foreach ($cols as $c) {
      if ($c==='creado_en' || $c==='aprobado_en') $placeholders[] = 'NOW()';
      else $placeholders[] = '?';
    }
    $sqlIns = "INSERT INTO permisos_solicitudes (`".implode('`,`',$cols)."`) VALUES (".implode(',',$placeholders).")";
    $stmt = $conn->prepare($sqlIns);

    // Rango de fechas
    $dates=[]; $cursor=new DateTime($fini); $end=new DateTime($ffin);
    while($cursor <= $end){ $dates[]=$cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

    $conn->begin_transaction();
    try {
      $insertados=0; $omitidos=0;

      foreach($dates as $d){
        // Evitar duplicado del mismo día/usuario (si hay 'motivo', solo "Vacaciones")
        if ($hasMotivo) {
          $sqlDup = "SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? AND motivo='Vacaciones' LIMIT 1";
        } else {
          $sqlDup = "SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? LIMIT 1";
        }
        $stDup = $conn->prepare($sqlDup);
        $stDup->bind_param('is',$idUsuario,$d);
        $stDup->execute();
        $dup = (bool)$stDup->get_result()->fetch_assoc();
        $stDup->close();
        if ($dup) { $omitidos++; continue; }

        // Armar valores y tipos PARA ESTA ITERACIÓN (omitimos columnas con NOW())
        $valsIter = []; $typesIter = '';
        foreach ($cols as $c) {
          if ($c==='creado_en' || $c==='aprobado_en') continue; // se ponen con NOW()
          switch ($c) {
            case 'id_usuario':            $valsIter[] = $idUsuario;                     $typesIter.='i'; break;
            case 'id_sucursal':           $valsIter[] = (int)$userRow['id_sucursal'];   $typesIter.='i'; break;
            case 'motivo':                $valsIter[] = 'Vacaciones';                   $typesIter.='s'; break;
            case 'comentario':            $valsIter[] = $coment;                        $typesIter.='s'; break;
            case 'status':                $valsIter[] = 'Aprobado';                     $typesIter.='s'; break;
            case 'creado_por':            $valsIter[] = (int)$_SESSION['id_usuario'];   $typesIter.='i'; break;
            case 'aprobado_por':          $valsIter[] = (int)$_SESSION['id_usuario'];   $typesIter.='i'; break;
            case 'comentario_aprobador':  $valsIter[] = 'Alta vacaciones (admin)';      $typesIter.='s'; break;
            default:
              // la columna de fecha dinámica
              if ($c === $dateCol) { $valsIter[] = $d; $typesIter.='s'; }
              break;
          }
        }

        $stmt->bind_param($typesIter, ...$valsIter);
        $stmt->execute();
        $insertados++;
      }

      $conn->commit();
      $MSG = "✅ Vacaciones registradas: {$insertados} día(s). Omitidos por duplicado: {$omitidos}.";
      $CLS = "success";
    } catch (Throwable $e) {
      $conn->rollback();
      $MSG = "❌ Error al registrar: ".$e->getMessage();
      $CLS = "danger";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Alta de Vacaciones</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
  </style>
</head>
<body>
<div class="container my-4" style="max-width:760px;">
  <h3 class="mb-3">🏝️ Alta de Vacaciones</h3>
  <p class="text-muted">Genera permisos “Vacaciones (Aprobado)” por cada día del rango seleccionado. Se verán como <b>PERMISO</b> en el panel semanal.</p>

  <?php if($MSG): ?>
    <div class="alert alert-<?= h($CLS) ?>"><?= h($MSG) ?></div>
  <?php endif; ?>

  <div class="card card-elev">
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-12">
          <label class="form-label">Colaborador *</label>
          <select name="id_usuario" class="form-select" required>
            <option value="">Seleccione…</option>
            <?php foreach($usuarios as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= h($u['sucursal'].' · '.$u['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Fecha inicio *</label>
          <input type="date" name="fecha_inicio" class="form-control" required value="<?= h($_POST['fecha_inicio'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha fin *</label>
          <input type="date" name="fecha_fin" class="form-control" required value="<?= h($_POST['fecha_fin'] ?? '') ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Comentario (opcional)</label>
          <textarea name="comentario" rows="2" class="form-control" placeholder="Notas internas"><?= h($_POST['comentario'] ?? '') ?></textarea>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2">
          <a href="admin_asistencias.php" class="btn btn-outline-secondary">Volver</a>
          <button class="btn btn-primary">Guardar vacaciones</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
