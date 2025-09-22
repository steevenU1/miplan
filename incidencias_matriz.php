<?php
// incidencias_matriz.php — Central 2.0 (UI moderna + mobile-first)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

/* Normaliza collation/charset de la conexión */
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

date_default_timezone_set('America/Mexico_City');

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function semana_mar_lun($offset=0){
  $hoy = new DateTime(); $n=(int)$hoy->format('N'); $dif=$n-2; if($dif<0)$dif+=7;
  $ini=(new DateTime())->modify("-$dif days")->setTime(0,0,0);
  if ($offset>0) $ini->modify('-'.(7*$offset).' days');
  $fin=(clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
}
function canViewAll($rol){ return in_array($rol, ['Admin','RH','GerenteZona'], true); }
function isGerente($rol){ return in_array($rol, ['Gerente','GerenteSucursal'], true); }

/* ===== Contexto sesión ===== */
$idUser  = (int)($_SESSION['id_usuario'] ?? 0);
$rol     = $_SESSION['rol'] ?? 'Ejecutivo';
$idSuc   = (int)($_SESSION['id_sucursal'] ?? 0);

/* ===== Filtros ===== */
$semana = isset($_GET['semana']) ? max(0,(int)$_GET['semana']) : 0;
list($fini,$ffin) = semana_mar_lun($semana);

$usuario   = isset($_GET['usuario'])   ? (int)$_GET['usuario']   : 0;
$sucursal  = isset($_GET['sucursal'])  ? (int)$_GET['sucursal']  : 0;
$estado    = isset($_GET['estado'])    ? trim($_GET['estado'])    : '';
$modulo    = isset($_GET['modulo'])    ? trim($_GET['modulo'])    : '';
$tipo      = isset($_GET['tipo'])      ? trim($_GET['tipo'])      : '';
$sev       = isset($_GET['sev'])       ? trim($_GET['sev'])       : '';
$buscar    = isset($_GET['buscar'])    ? trim($_GET['buscar'])    : '';
$export    = isset($_GET['export']);

$where = " WHERE i.fecha_creacion BETWEEN ? AND ? ";
$params = [$fini,$ffin];
$types  = "ss";

/* Alcance por rol */
if (canViewAll($rol)) {
  // ven todo
} elseif (isGerente($rol)) {
  $where .= " AND i.id_sucursal = ? ";
  $params[] = $idSuc; $types .= "i";
} else {
  $where .= " AND (i.creada_por = ? OR i.id_usuario_involucrado = ?) ";
  $params[] = $idUser; $params[] = $idUser; $types .= "ii";
}

/* Filtros extra */
if ($usuario>0){ $where.=" AND i.id_usuario_involucrado = ? "; $params[]=$usuario; $types.="i"; }
if ($sucursal>0){ $where.=" AND i.id_sucursal = ? "; $params[]=$sucursal; $types.="i"; }
if ($estado!==''){ $where.=" AND i.estado = ? "; $params[]=$estado; $types.="s"; }
if ($modulo!==''){ $where.=" AND i.modulo = ? "; $params[]=$modulo; $types.="s"; }
if ($tipo!==''){ $where.=" AND i.tipo = ? "; $params[]=$tipo; $types.="s"; }
if ($sev!==''){ $where.=" AND i.severidad = ? "; $params[]=$sev; $types.="s"; }
if ($buscar!==''){
  $q = "%$buscar%";
  $where .= " AND ( i.descripcion LIKE ?
                OR i.referencia_tag LIKE ?
                OR i.referencia_imei1 LIKE ?
                OR i.referencia_imei2 LIKE ?
                OR i.referencia_iccid LIKE ? ) ";
  array_push($params,$q,$q,$q,$q,$q);
  $types .= "sssss";
}

/* ===== Catálogos ligeros ===== */
$usuarios = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$sucs     = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$cats     = $conn->query("SELECT modulo, tipo, severidad FROM cat_incidencias ORDER BY modulo, tipo")->fetch_all(MYSQLI_ASSOC);

/* ===== Consulta principal ===== */
$sql = "
SELECT
  i.*,
  u1.nombre AS creador,
  u2.nombre AS involucrado,
  s.nombre  AS sucursal_nombre,
  DATEDIFF(COALESCE(i.fecha_cierre, NOW()), i.fecha_creacion) AS dias_abierta,
  (
    SELECT COUNT(1)
    FROM incidencias x
    WHERE x.id <> i.id
      -- Usuario (NULL/0 tratado igual y comparado por ID)
      AND COALESCE(x.id_usuario_involucrado,0) = COALESCE(i.id_usuario_involucrado,0)
      -- Módulo y tipo: TRIM + collation insensible a mayúsculas/acentos
      AND TRIM(CONVERT(x.modulo USING utf8mb4)) COLLATE utf8mb4_general_ci
          = TRIM(CONVERT(i.modulo USING utf8mb4)) COLLATE utf8mb4_general_ci
      AND TRIM(CONVERT(x.tipo USING utf8mb4)) COLLATE utf8mb4_general_ci
          = TRIM(CONVERT(i.tipo USING utf8mb4)) COLLATE utf8mb4_general_ci
      -- Ventana 30 días previos (incluye mismo día)
      AND x.fecha_creacion >= DATE_SUB(i.fecha_creacion, INTERVAL 30 DAY)
      AND x.fecha_creacion <= i.fecha_creacion
  ) AS reinc_30d
FROM incidencias i
LEFT JOIN usuarios u1 ON u1.id = i.creada_por
LEFT JOIN usuarios u2 ON u2.id = i.id_usuario_involucrado
LEFT JOIN sucursales s ON s.id = i.id_sucursal
{$where}
ORDER BY i.fecha_creacion DESC, i.id DESC
";
$stmt = $conn->prepare($sql);
if ($params){ $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===== Stats para KPIs ===== */
$total = count($rows);
$statusCounts = ['Abierta'=>0,'En proceso'=>0,'Resuelta'=>0,'Cerrada'=>0,'Rechazada'=>0];
$crit=0; $reinc3=0; $reinc5=0; $sumDias=0;
foreach($rows as $r){
  $st = $r['estado'] ?? '';
  if (isset($statusCounts[$st])) $statusCounts[$st]++;
  if (($r['severidad'] ?? '') === 'Crítica') $crit++;
  $rc = (int)($r['reinc_30d'] ?? 0);
  if ($rc>=3) $reinc3++;
  if ($rc>=5) $reinc5++;
  $sumDias += (int)($r['dias_abierta'] ?? 0);
}
$cerradas = $statusCounts['Resuelta'] + $statusCounts['Cerrada'];
$pctCerr  = $total ? round($cerradas*100/$total) : 0;
$promDias = $total ? round($sumDias / $total, 1) : 0;

/* ===== Export ===== */
if ($export) {
  while (ob_get_level()) ob_end_clean();
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=incidencias_{$fini}_{$ffin}.xls");
  echo "\xEF\xBB\xBF";
  echo "<table border='1'><thead><tr style='background:#f2f2f2'>";
  $headers = ['ID','Fecha','Creador','Involucrado','Sucursal','Módulo','Tipo','Severidad','Estado',
              'Responsable','Compromiso','Días abiertos','Reinc.30d',
              'TAG','IMEI1','IMEI2','ICCID','VentaID','Descripción'];
  foreach($headers as $h){ echo "<th>".h($h)."</th>"; }
  echo "</tr></thead><tbody>";
  foreach($rows as $r){
    echo "<tr>";
    echo "<td>".h($r['id'])."</td>";
    echo "<td>".h($r['fecha_creacion'])."</td>";
    echo "<td>".h($r['creador'])."</td>";
    echo "<td>".h($r['involucrado'])."</td>";
    echo "<td>".h($r['sucursal_nombre'])."</td>";
    echo "<td>".h($r['modulo'])."</td>";
    echo "<td>".h($r['tipo'])."</td>";
    echo "<td>".h($r['severidad'])."</td>";
    echo "<td>".h($r['estado'])."</td>";
    echo "<td>".h($r['responsable_id'])."</td>";
    echo "<td>".h($r['fecha_compromiso'])."</td>";
    echo "<td>".h($r['dias_abierta'])."</td>";
    echo "<td>".h($r['reinc_30d'])."</td>";
    echo "<td>".h($r['referencia_tag'])."</td>";
    echo "<td>=\"".h($r['referencia_imei1'])."\"</td>";
    echo "<td>=\"".h($r['referencia_imei2'])."\"</td>";
    echo "<td>=\"".h($r['referencia_iccid'])."\"</td>";
    echo "<td>".h($r['id_venta'])."</td>";
    echo "<td>".h($r['descripcion'])."</td>";
    echo "</tr>";
  }
  echo "</tbody></table>";
  exit;
}

/* ===== Alta rápida ===== */
if (isset($_POST['__alta'])){
  $i_invol = (int)($_POST['id_usuario_involucrado'] ?? 0);
  $i_suc   = (int)($_POST['id_sucursal'] ?? 0);
  $i_mod   = trim($_POST['modulo'] ?? '');
  $i_tipo  = trim($_POST['tipo'] ?? '');
  $i_sev   = trim($_POST['severidad'] ?? 'Baja');
  $i_desc  = trim($_POST['descripcion'] ?? '');
  $tag     = trim($_POST['referencia_tag'] ?? '');
  $im1     = trim($_POST['referencia_imei1'] ?? '');
  $im2     = trim($_POST['referencia_imei2'] ?? '');
  $iccid   = trim($_POST['referencia_iccid'] ?? '');
  $ventaId = (int)($_POST['id_venta'] ?? 0);
  $respId  = (int)($_POST['responsable_id'] ?? 0);
  $fcomp   = trim($_POST['fecha_compromiso'] ?? '');

  if ($i_mod==='' || $i_tipo==='' || $i_desc===''){
    $msg = "Faltan campos obligatorios (módulo, tipo, descripción).";
  } else {
    $sqlIns = "INSERT INTO incidencias
      (creada_por,id_usuario_involucrado,id_sucursal,modulo,tipo,subtipo,severidad,descripcion,
       referencia_tag,referencia_imei1,referencia_imei2,referencia_iccid,id_venta,
       estado,responsable_id,fecha_compromiso)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $subtipo = trim($_POST['subtipo'] ?? '');
    $estado0 = 'Abierta';
    $fechaComp = $fcomp !== '' ? $fcomp : null;
    $stmt = $conn->prepare($sqlIns);
    $stmt->bind_param(
      "iiisssssssssssis",
      $idUser,$i_invol,$i_suc,$i_mod,$i_tipo,$subtipo,$i_sev,$i_desc,
      $tag,$im1,$im2,$iccid,$ventaId,
      $estado0,$respId,$fechaComp
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    $stmtH = $conn->prepare("INSERT INTO incidencias_historial (id_incidencia,id_usuario,estado_anterior,estado_nuevo,comentario)
                             VALUES (?,?,?,?,?)");
    $cmt = "Creada";
    $stmtH->bind_param("iisss",$newId,$idUser,$estado0,$estado0,$cmt);
    $stmtH->execute(); $stmtH->close();

    header("Location: incidencias_matriz.php?ok=1");
    exit;
  }
}

/* ===== Cambio de estado ===== */
if (isset($_POST['__estado'])){
  $idInc = (int)($_POST['id_incidencia'] ?? 0);
  $nuevo = trim($_POST['estado_nuevo'] ?? 'En proceso');
  $coment= trim($_POST['comentario'] ?? '');

  $can = canViewAll($rol) || isGerente($rol);
  if (!$can) {
    $q = $conn->prepare("SELECT creada_por,id_usuario_involucrado FROM incidencias WHERE id=?");
    $q->bind_param("i",$idInc); $q->execute();
    $q->bind_result($c,$inv); $q->fetch(); $q->close();
    $can = ($c==$idUser || $inv==$idUser);
  }
  if ($can) {
    $q = $conn->prepare("SELECT estado FROM incidencias WHERE id=?");
    $q->bind_param("i",$idInc); $q->execute(); $q->bind_result($prev); $q->fetch(); $q->close();

    $fechaCierre = in_array($nuevo, ['Resuelta','Cerrada','Rechazada'], true) ? date('Y-m-d H:i:s') : null;
    if ($fechaCierre){
      $u = $conn->prepare("UPDATE incidencias SET estado=?, fecha_cierre=? WHERE id=?");
      $u->bind_param("ssi",$nuevo,$fechaCierre,$idInc);
    } else {
      $u = $conn->prepare("UPDATE incidencias SET estado=? WHERE id=?");
      $u->bind_param("si",$nuevo,$idInc);
    }
    $u->execute(); $u->close();

    $h = $conn->prepare("INSERT INTO incidencias_historial (id_incidencia,id_usuario,estado_anterior,estado_nuevo,comentario)
                         VALUES (?,?,?,?,?)");
    $h->bind_param("iisss",$idInc,$idUser,$prev,$nuevo,$coment);
    $h->execute(); $h->close();
    header("Location: incidencias_matriz.php?cambio=1");
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Matriz de incidencias</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --card-grad: linear-gradient(135deg, #e8f0ff, #ffffff);
  --soft: #f7f9fc;
}
body{ background:#fafbfe; }
.kpi-card{
  background: var(--card-grad);
  border: 1px solid #e6ecf5;
  border-radius: 1rem;
  box-shadow: 0 4px 14px rgba(15,23,42,0.06);
  padding: 1rem 1.25rem;
}
.kpi-title{ font-size:.85rem; color:#64748b; margin-bottom:.25rem }
.kpi-value{ font-size:1.6rem; font-weight:700; line-height:1; }
.kpi-trend{ font-size:.8rem; color:#64748b; }
.toolbar{ position: sticky; top: .5rem; z-index: 10; background: transparent; }
.filter-card{
  background:#fff; border:1px solid #e6ecf5; border-radius: .75rem;
  box-shadow: 0 6px 18px rgba(15,23,42,0.05);
}
/* Pills de reincidencias – alto contraste */
.badge-rec{
  font-weight:700;
  border-radius:.6rem;
  padding:.25rem .5rem;
  font-size:.75rem;
  line-height:1;
}
.badge-rec.rec1{                      /* ≥1 en 30d */
  background:#d1fae5;
  color:#065f46;
  border:1px solid #10b98133;
}
.badge-rec.rec3{                      /* ≥3 en 30d */
  background:#fff3cd;
  color:#7a5b00;
  border:1px solid #f59e0b33;
}
.badge-rec.rec5{                      /* ≥5 en 30d */
  background:#fee2e2;
  color:#991b1b;
  border:1px solid #ef444433;
}

/* Que no se “pierdan” sobre filas rayadas */
.table .badge-rec{
  box-shadow:0 0 0 1px rgba(0,0,0,.05) inset;
}

.badge-sev.Baja{ background:#e2e8f0; color:#0f172a; }
.badge-sev.Media{ background:#fff3cd; color:#7a5b00; }
.badge-sev.Alta{  background:#fecaca; color:#7f1d1d; }
.badge-sev.Crítica{ background:#111827; color:#fff; }

.badge-est.Abierta{ background:#fee2e2; color:#7f1d1d; }
.badge-est.En\ proceso{ background:#fff3cd; color:#7a5b00; }
.badge-est.Resuelta{ background:#dcfce7; color:#14532d; }
.badge-est.Cerrada{ background:#e5e7eb; color:#111827; }
.badge-est.Rechazada{ background:#e2e8f0; color:#334155; }

.table thead th{ font-size:.78rem; letter-spacing:.02em; }
.table td, .table th{ vertical-align: middle; }
.card-inc{
  background:#fff; border:1px solid #e6ecf5; border-radius: .9rem; padding: .9rem;
  box-shadow: 0 8px 20px rgba(15,23,42,0.05);
}
.small-label{ font-size:.75rem; color:#64748b; }
.truncate-2{
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container-fluid py-3"><!-- wrapper del contenido para alinear con navbar -->

  <!-- Toolbar -->
  <div class="toolbar mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div>
      <h3 class="m-0">Matriz de incidencias <small class="text-muted">(mar→lun)</small></h3>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="?export=1<?= '&semana='.$semana .
        ($usuario? '&usuario='.$usuario:'') . ($sucursal?'&sucursal='.$sucursal:'') .
        ($estado? '&estado='.urlencode($estado):'') . ($modulo?'&modulo='.urlencode($modulo):'') .
        ($tipo? '&tipo='.urlencode($tipo):'') . ($sev? '&sev='.urlencode($sev):'') .
        ($buscar? '&buscar='.urlencode($buscar):'') ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Exportar</a>
      <button class="btn btn-outline-primary d-md-none" data-bs-toggle="collapse" data-bs-target="#filtros"><i class="bi bi-funnel"></i> Filtros</button>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAlta"><i class="bi bi-plus-lg"></i> Nueva incidencia</button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="kpi-card">
        <div class="kpi-title"><i class="bi bi-collection"></i> Total</div>
        <div class="kpi-value"><?= (int)$total ?></div>
        <div class="kpi-trend">Prom. días abiertos: <b><?= h($promDias) ?></b></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card">
        <div class="kpi-title"><i class="bi bi-exclamation-triangle"></i> Abiertas</div>
        <div class="kpi-value"><?= (int)$statusCounts['Abierta'] ?></div>
        <div class="kpi-trend">En proceso: <b><?= (int)$statusCounts['En proceso'] ?></b></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card">
        <div class="kpi-title"><i class="bi bi-gear"></i> En proceso</div>
        <div class="kpi-value"><?= (int)$statusCounts['En proceso'] ?></div>
        <div class="kpi-trend">Críticas: <b><?= (int)$crit ?></b></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card">
        <div class="kpi-title"><i class="bi bi-check2-circle"></i> Cerradas/Resueltas</div>
        <div class="kpi-value"><?= (int)($statusCounts['Resuelta'] + $statusCounts['Cerrada']) ?></div>
        <div class="kpi-trend">Cierre en semana: <b><?= $pctCerr ?>%</b></div>
      </div>
    </div>
  </div>

  <!-- Chips de alerta -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge rounded-pill text-bg-dark"><i class="bi bi-lightning-charge"></i> Críticas: <?= (int)$crit ?></span>
    <span class="badge rounded-pill bg-warning text-dark"><i class="bi bi-repeat"></i> Reincidentes ≥3 (30d): <?= (int)$reinc3 ?></span>
    <span class="badge rounded-pill bg-danger"><i class="bi bi-repeat-1"></i> Reincidentes ≥5 (30d): <?= (int)$reinc5 ?></span>
  </div>

  <!-- Filtros -->
  <div id="filtros" class="collapse d-md-block">
    <div class="filter-card p-3 mb-3">
      <form class="row g-2">
        <input type="hidden" name="semana" value="<?= h($semana) ?>">
        <div class="col-12 col-md-2">
          <label class="form-label small-label">Usuario</label>
          <select class="form-select form-select-sm" name="usuario">
            <option value="0">Todos</option>
            <?php foreach($usuarios as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $usuario==$u['id']?'selected':'' ?>><?= h($u['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label small-label">Sucursal</label>
          <select class="form-select form-select-sm" name="sucursal">
            <option value="0">Todas</option>
            <?php foreach($sucs as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal==$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small-label">Estado</label>
          <select class="form-select form-select-sm" name="estado">
            <?php foreach(['','Abierta','En proceso','Resuelta','Cerrada','Rechazada'] as $op): ?>
              <option value="<?= h($op) ?>" <?= $estado===$op?'selected':'' ?>><?= $op===''?'Todos':$op ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small-label">Severidad</label>
          <select class="form-select form-select-sm" name="sev">
            <?php foreach(['','Baja','Media','Alta','Crítica'] as $op): ?>
              <option value="<?= h($op) ?>" <?= $sev===$op?'selected':'' ?>><?= $op===''?'Todas':$op ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small-label">Módulo</label>
          <input class="form-control form-control-sm" name="modulo" value="<?= h($modulo) ?>" placeholder="Ventas, Inventario...">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small-label">Tipo</label>
          <input class="form-control form-control-sm" name="tipo" value="<?= h($tipo) ?>" placeholder="Captura, Duplicado...">
        </div>
        <div class="col-12 col-md-10">
          <label class="form-label small-label">Buscar</label>
          <input class="form-control form-control-sm" name="buscar" value="<?= h($buscar) ?>" placeholder="Descripción, TAG, IMEI, ICCID...">
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end gap-2">
          <button class="btn btn-outline-secondary btn-sm w-50" name="semana" value="<?= max(0,$semana+1) ?>"><i class="bi bi-chevron-left"></i> Sem-1</button>
          <?php if ($semana>0): ?>
          <button class="btn btn-outline-secondary btn-sm w-50" name="semana" value="<?= max(0,$semana-1) ?>">Sem+1 <i class="bi bi-chevron-right"></i></button>
          <?php else: ?>
          <button class="btn btn-outline-secondary btn-sm w-50" name="semana" value="0">Actual</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabla (desktop) -->
  <div class="table-responsive d-none d-md-block">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-light">
      <tr>
        <th>ID</th><th>Fecha</th><th>Creada por</th><th>Involucrado</th><th>Sucursal</th>
        <th>Módulo</th><th>Tipo</th><th>Sev.</th><th>Estado</th>
        <th>Resp.</th><th>Compromiso</th><th>Días</th><th>Reinc.30d</th>
        <th>TAG</th><th>IMEI1</th><th>IMEI2</th><th>ICCID</th>
        <th>Acciones</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r):
        $sevClass = 'badge-sev '.str_replace(' ','_',$r['severidad'] ?? '');
        $estClass = 'badge-est '.str_replace(' ','\ ',$r['estado'] ?? '');
        $badge = '';
        if ((int)$r['reinc_30d'] >= 5) $badge = 'rec5';
        elseif ((int)$r['reinc_30d'] >= 3) $badge = 'rec3';
      ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h($r['fecha_creacion']) ?></td>
          <td><?= h($r['creador']) ?></td>
          <td><?= h($r['involucrado']) ?></td>
          <td><?= h($r['sucursal_nombre']) ?></td>
          <td><?= h($r['modulo']) ?></td>
          <td><?= h($r['tipo']) ?></td>
          <td><span class="badge <?= $sevClass ?>"><?= h($r['severidad']) ?></span></td>
          <td><span class="badge <?= $estClass ?>"><?= h($r['estado']) ?></span></td>
          <td><?= h($r['responsable_id']) ?></td>
          <td><?= h($r['fecha_compromiso']) ?></td>
          <td><?= (int)$r['dias_abierta'] ?></td>
          <td><span class="badge badge-rec <?= $badge ?>"><?= (int)$r['reinc_30d'] ?></span></td>
          <td><?= h($r['referencia_tag']) ?></td>
          <td><?= $r['referencia_imei1'] ? '="'.h($r['referencia_imei1']).'"' : '' ?></td>
          <td><?= $r['referencia_imei2'] ? '="'.h($r['referencia_imei2']).'"' : '' ?></td>
          <td><?= $r['referencia_iccid'] ? '="'.h($r['referencia_iccid']).'"' : '' ?></td>
          <td>
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="id_incidencia" value="<?= (int)$r['id'] ?>">
              <select name="estado_nuevo" class="form-select form-select-sm" style="width:140px">
                <?php foreach(['Abierta','En proceso','Resuelta','Cerrada','Rechazada'] as $es): ?>
                  <option value="<?= $es ?>" <?= ($r['estado']??'')===$es?'selected':'' ?>><?= $es ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="comentario" class="form-control form-control-sm" placeholder="Comentario">
              <button class="btn btn-sm btn-outline-primary" name="__estado" value="1"><i class="bi bi-save"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Lista de cards (móvil) -->
  <div class="d-md-none">
    <div class="d-flex flex-column gap-2">
      <?php foreach($rows as $r):
        $sevClass = 'badge-sev '.str_replace(' ','_',$r['severidad'] ?? '');
        $estClass = 'badge-est '.str_replace(' ','\ ',$r['estado'] ?? '');
        $badge = '';
        if ((int)$r['reinc_30d'] >= 5) $badge = 'rec5';
        elseif ((int)$r['reinc_30d'] >= 3) $badge = 'rec3';
      ?>
      <div class="card-inc">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <div>
            <div class="fw-semibold"><?= h($r['modulo']) ?> • <?= h($r['tipo']) ?></div>
            <div class="text-muted small"><?= h($r['fecha_creacion']) ?> • <?= h($r['sucursal_nombre']) ?></div>
          </div>
          <div class="text-end">
            <span class="badge <?= $sevClass ?>"><?= h($r['severidad']) ?></span>
            <span class="badge <?= $estClass ?>"><?= h($r['estado']) ?></span>
          </div>
        </div>
        <div class="small text-muted mb-1">
          Involucrado: <b><?= h($r['involucrado']) ?></b> • Creador: <b><?= h($r['creador']) ?></b>
        </div>
        <div class="row g-1 small text-muted mb-2">
          <div class="col-6">TAG: <span class="text-dark"><?= h($r['referencia_tag']) ?></span></div>
          <div class="col-6">Reinc.30d: <span class="badge badge-rec <?= $badge ?>"><?= (int)$r['reinc_30d'] ?></span></div>
          <div class="col-6">IMEI1: <span class="text-dark"><?= $r['referencia_imei1'] ? '="'.h($r['referencia_imei1']).'"' : '' ?></span></div>
          <div class="col-6">IMEI2: <span class="text-dark"><?= $r['referencia_imei2'] ? '="'.h($r['referencia_imei2']).'"' : '' ?></span></div>
          <div class="col-12">ICCID: <span class="text-dark"><?= $r['referencia_iccid'] ? '="'.h($r['referencia_iccid']).'"' : '' ?></span></div>
        </div>
        <?php if (!empty($r['descripcion'])): ?>
          <div class="truncate-2 mb-2"><?= h($r['descripcion']) ?></div>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="id_incidencia" value="<?= (int)$r['id'] ?>">
          <select name="estado_nuevo" class="form-select form-select-sm">
            <?php foreach(['Abierta','En proceso','Resuelta','Cerrada','Rechazada'] as $es): ?>
              <option value="<?= $es ?>" <?= ($r['estado']??'')===$es?'selected':'' ?>><?= $es ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="comentario" class="form-control form-control-sm" placeholder="Comentario">
          <button class="btn btn-sm btn-primary" name="__estado" value="1"><i class="bi bi-save"></i></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Modal alta -->
  <div class="modal fade" id="modalAlta" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <form method="post" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Nueva incidencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-2">
          <input type="hidden" name="__alta" value="1">
          <div class="col-sm-6">
            <label class="form-label">Usuario involucrado</label>
            <select class="form-select" name="id_usuario_involucrado">
              <option value="0">No aplica</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label">Sucursal</label>
            <select class="form-select" name="id_sucursal">
              <option value="0">No aplica</option>
              <?php foreach($sucs as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-4">
            <label class="form-label">Módulo</label>
            <input class="form-control" name="modulo" list="lstModulo" placeholder="Ventas, Inventario...">
            <datalist id="lstModulo">
              <?php
                $mods = array_unique(array_map(fn($x)=>$x['modulo'],$cats));
                foreach($mods as $m) echo "<option value=\"".h($m)."\"></option>";
              ?>
            </datalist>
          </div>
          <div class="col-sm-4">
            <label class="form-label">Tipo</label>
            <input class="form-control" name="tipo" list="lstTipo" placeholder="Captura incorrecta...">
            <datalist id="lstTipo">
              <?php
                $tipos = array_unique(array_map(fn($x)=>$x['tipo'],$cats));
                foreach($tipos as $t) echo "<option value=\"".h($t)."\"></option>";
              ?>
            </datalist>
          </div>
          <div class="col-sm-4">
            <label class="form-label">Severidad</label>
            <select class="form-select" name="severidad">
              <?php foreach(['Baja','Media','Alta','Crítica'] as $s) echo "<option>$s</option>"; ?>
            </select>
          </div>

          <div class="col-sm-12">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" name="descripcion" rows="3" required></textarea>
          </div>

          <div class="col-sm-3">
            <label class="form-label">TAG</label>
            <input class="form-control" name="referencia_tag">
          </div>
          <div class="col-sm-3">
            <label class="form-label">IMEI1</label>
            <input class="form-control" name="referencia_imei1">
          </div>
          <div class="col-sm-3">
            <label class="form-label">IMEI2</label>
            <input class="form-control" name="referencia_imei2">
          </div>
          <div class="col-sm-3">
            <label class="form-label">ICCID</label>
            <input class="form-control" name="referencia_iccid">
          </div>

          <div class="col-sm-4">
            <label class="form-label">Venta ID</label>
            <input class="form-control" type="number" name="id_venta" min="0">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Responsable (user id)</label>
            <input class="form-control" type="number" name="responsable_id" min="0" placeholder="ID de usuario">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Fecha compromiso</label>
            <input class="form-control" type="datetime-local" name="fecha_compromiso">
          </div>

          <div class="col-sm-12">
            <label class="form-label">Subtipo (opcional)</label>
            <input class="form-control" name="subtipo" placeholder="Detalle/causa específica">
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>

</div><!-- /container-fluid -->

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
