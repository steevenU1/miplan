<?php
// cargar_cuotas_semanales.php — LUGA (UI organizada + martes obligatorio)
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ymd($d){ return $d ? date('Y-m-d', strtotime($d)) : null; }
function fmtDMY($d){ return $d ? date('d/m/Y', strtotime($d)) : '—'; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $semanaInicio = ymd($_POST['semana_inicio'] ?? '');
    $semanaFin    = ymd($_POST['semana_fin'] ?? '');
    $cuotaUnidades= (int)($_POST['cuota_unidades'] ?? 0);

    if ($semanaInicio && $semanaFin && $cuotaUnidades > 0) {
        // Validar que inicio sea MARTES
        $dowInicio = (int)date('N', strtotime($semanaInicio)); // 1=Lun, 2=Mar, ... 7=Dom
        if ($dowInicio !== 2) {
            $msg = "⚠ La fecha de 'Semana inicio' debe ser martes.";
        } else {
            // Validar rango martes→lunes (7 días)
            $okRango = (strtotime($semanaFin) === strtotime($semanaInicio . ' +6 day'));
            if (!$okRango) {
                $msg = "⚠ El rango debe ser de 7 días exactos (martes→lunes).";
            } else {
                $resSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
                $insertadas = 0;

                $stmtCheck = $conn->prepare("
                    SELECT COUNT(*) AS total 
                    FROM cuotas_semanales_sucursal 
                    WHERE id_sucursal=? AND semana_inicio=? AND semana_fin=?
                ");
                $stmtIns = $conn->prepare("
                    INSERT INTO cuotas_semanales_sucursal
                        (id_sucursal, semana_inicio, semana_fin, cuota_unidades, creado_en)
                    VALUES (?,?,?,?,NOW())
                ");

                while ($suc = $resSuc->fetch_assoc()) {
                    $idSucursal = (int)$suc['id'];
                    $stmtCheck->bind_param("iss", $idSucursal, $semanaInicio, $semanaFin);
                    $stmtCheck->execute();
                    $existe = (int)($stmtCheck->get_result()->fetch_assoc()['total'] ?? 0);
                    if ($existe === 0) {
                        $stmtIns->bind_param("issi", $idSucursal, $semanaInicio, $semanaFin, $cuotaUnidades);
                        $stmtIns->execute();
                        $insertadas++;
                    }
                }
                $msg = "✅ Se generaron {$insertadas} cuotas semanales para la semana ".fmtDMY($semanaInicio)." → ".fmtDMY($semanaFin).".";
            }
        }
    } else {
        $msg = "⚠ Completa todos los campos correctamente.";
    }
}

// ===== Filtros historial =====
$q         = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(10, min(100, (int)($_GET['per_page'] ?? 20)));

$weeks = [];
$rw = $conn->query("
    SELECT semana_inicio, semana_fin
    FROM cuotas_semanales_sucursal
    GROUP BY semana_inicio, semana_fin
    ORDER BY semana_inicio DESC
    LIMIT 16
");
while ($r = $rw->fetch_assoc()) $weeks[] = $r;

if (!empty($weeks)) {
    $weekStartSel = ymd($_GET['week_start'] ?? $weeks[0]['semana_inicio']);
    $weekFinSel = null;
    foreach ($weeks as $w) {
        if (ymd($w['semana_inicio']) === $weekStartSel) { $weekFinSel = ymd($w['semana_fin']); break; }
    }
} else {
    // Semana operativa de referencia (martes→lunes) respecto a hoy
    $today = new DateTime();
    $dow = (int)$today->format('w'); // 0=Dom,1=Lun,2=Mar...
    $deltaToTue = ($dow <= 2) ? (2 - $dow) : (9 - $dow);
    $tuesday = clone $today; $tuesday->modify("+$deltaToTue day")->setTime(0,0,0);
    $monday  = clone $tuesday; $monday->modify('+6 day');
    $weekStartSel = $tuesday->format('Y-m-d');
    $weekFinSel   = $monday->format('Y-m-d');
}

// ===== Histórico paginado (semana seleccionada)
$where = "WHERE c.semana_inicio=? ";
$params = [$weekStartSel]; $types = "s";
if ($q !== '') { $where .= "AND s.nombre LIKE ? "; $params[]="%$q%"; $types.="s"; }

$sqlCount = "SELECT COUNT(*) AS total
             FROM cuotas_semanales_sucursal c
             JOIN sucursales s ON s.id=c.id_sucursal
             $where";
$stmt = $conn->prepare($sqlCount);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$pages = max(1, (int)ceil($totalRows / $perPage));
$page  = min($page, $pages);
$offset= ($page - 1) * $perPage;

$sqlData = "SELECT c.*, s.nombre AS sucursal
            FROM cuotas_semanales_sucursal c
            JOIN sucursales s ON s.id=c.id_sucursal
            $where
            ORDER BY s.nombre
            LIMIT ? OFFSET ?";
$params2 = $params; $types2 = $types . "ii";
$params2[] = $perPage; $params2[] = $offset;
$stmt = $conn->prepare($sqlData);
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$resLista = $stmt->get_result();

function buildUrl($params = []){
    $curr = $_GET;
    foreach ($params as $k=>$v) { if ($v === null) unset($curr[$k]); else $curr[$k] = $v; }
    $qstr = http_build_query($curr);
    return 'cargar_cuotas_semanales.php' . ($qstr ? ('?'.$qstr) : '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cargar Cuotas Semanales</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background:#f6f7fb; }
  .toolbar { position: sticky; top: 0; z-index: 100; backdrop-filter: blur(4px); background: rgba(248,249,250,.9); border-bottom:1px solid #eee; }
  .card-elev { border:1px solid #e9ecf2; box-shadow:0 6px 18px rgba(20,32,66,.06); }
  .badge-soft { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
  .chip { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:.5rem; padding:.3rem .5rem; display:inline-flex; gap:.35rem; align-items:center; }
</style>
</head>
<body>

<div class="toolbar py-3">
  <div class="container d-flex align-items-center gap-2">
    <h3 class="m-0 me-auto">📅 Cargar Cuotas Semanales (Solo Admin)</h3>
    <a href="panel.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver al panel</a>
  </div>
</div>

<div class="container my-4">
  <?php if ($msg): ?>
    <div class="alert alert-info"><?= h($msg) ?></div>
  <?php endif; ?>

  <!-- ====== Formulario ====== -->
  <div class="card card-elev mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Generar cuotas para todas las sucursales</div>
      <span class="badge badge-soft"><i class="bi bi-gear me-1"></i>Acción masiva</span>
    </div>
    <form id="formGen" method="POST" class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-sm-6 col-md-3">
          <label class="form-label">Semana inicio (martes)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            <input type="date" class="form-control" name="semana_inicio" id="semana_inicio" required>
          </div>
        </div>
        <div class="col-sm-6 col-md-3">
          <label class="form-label">Semana fin (lunes)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
            <input type="date" class="form-control" name="semana_fin" id="semana_fin" required>
          </div>
        </div>
        <div class="col-sm-6 col-md-3">
          <label class="form-label">Cuota de unidades</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-123"></i></span>
            <input type="number" class="form-control" name="cuota_unidades" id="cuota_unidades" min="1" step="1" placeholder="Ej. 6" required>
          </div>
        </div>
        <div class="col-sm-6 col-md-3 d-flex gap-2">
          <button type="button" class="btn btn-outline-primary flex-fill" id="btnThisWeek">Esta semana</button>
          <button type="button" class="btn btn-outline-primary flex-fill" id="btnNextWeek">Próxima semana</button>
        </div>
      </div>

      <div class="mt-3 d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-calendar-check me-1"></i> Inicio debe ser <strong>martes</strong>.</span>
        <span class="chip"><i class="bi bi-info-circle me-1"></i> Rango fijo: 7 días (mar→lun).</span>
        <span class="chip"><i class="bi bi-lightning-charge me-1"></i> No duplica registros.</span>
      </div>

      <div id="alertForm" class="alert mt-3 d-none"></div>

      <div class="d-flex justify-content-end mt-3">
        <button type="button" id="btnPreview" class="btn btn-success">
          <span class="btn-text"><i class="bi bi-check2-circle me-1"></i> Generar cuotas</span>
          <span class="btn-wait d-none"><span class="spinner-border spinner-border-sm me-1"></span> Procesando...</span>
        </button>
      </div>
    </form>
  </div>

  <!-- ====== Histórico por semana ====== -->
  <div class="card card-elev">
    <div class="card-header bg-white">
      <div class="d-flex flex-wrap gap-2 align-items-end">
        <div class="me-auto">
          <div class="fw-semibold">Histórico organizado por semana</div>
          <div class="small text-muted">Selecciona una semana para ver las cuotas registradas por sucursal.</div>
        </div>

        <form class="row g-2" method="get" action="cargar_cuotas_semanales.php">
          <div class="col-auto">
            <label class="form-label small">Semana</label>
            <select name="week_start" class="form-select">
              <?php if (!empty($weeks)): ?>
                <?php foreach ($weeks as $w): 
                  $ws = ymd($w['semana_inicio']); $wf = ymd($w['semana_fin']);
                  $sel = ($ws === $weekStartSel) ? 'selected' : '';
                ?>
                  <option value="<?= h($ws) ?>" <?= $sel ?>>
                    <?= fmtDMY($ws) ?> → <?= fmtDMY($wf) ?>
                  </option>
                <?php endforeach; ?>
              <?php else: ?>
                <option value="<?= h($weekStartSel) ?>" selected><?= fmtDMY($weekStartSel) ?> → <?= fmtDMY($weekFinSel) ?></option>
              <?php endif; ?>
            </select>
          </div>
          <div class="col-auto">
            <label class="form-label small">Buscar sucursal</label>
            <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Ej. Centro, Reforma...">
          </div>
          <div class="col-auto">
            <label class="form-label small">Por página</label>
            <select name="per_page" class="form-select">
              <?php foreach ([10,20,30,50,100] as $pp): ?>
                <option value="<?= $pp ?>" <?= $pp===$perPage?'selected':'' ?>><?= $pp ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <label class="form-label small">&nbsp;</label>
            <button class="btn btn-dark w-100">Aplicar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 mb-2">
        <span class="chip"><i class="bi bi-calendar3 me-1"></i> Semana: <strong><?= fmtDMY($weekStartSel) ?> → <?= fmtDMY($weekFinSel) ?></strong></span>
        <span class="chip"><i class="bi bi-list-ol me-1"></i> Registros: <strong><?= $totalRows ?></strong></span>
        <span class="chip"><i class="bi bi-file-spreadsheet me-1"></i> Página <?= $page ?> / <?= $pages ?></span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle bg-white">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Sucursal</th>
              <th>Semana inicio</th>
              <th>Semana fin</th>
              <th>Cuota (unidades)</th>
              <th>Creado en</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($totalRows === 0): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Sin registros para la semana seleccionada.</td></tr>
            <?php else: 
              $i = $offset + 1;
              while ($row = $resLista->fetch_assoc()): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($row['sucursal']) ?></td>
                <td><?= fmtDMY($row['semana_inicio']) ?></td>
                <td><?= fmtDMY($row['semana_fin']) ?></td>
                <td><span class="badge text-bg-primary"><?= (int)$row['cuota_unidades'] ?></span></td>
                <td><?= fmtDMY($row['creado_en']) ?></td>
              </tr>
            <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>max(1,$page-1)])) ?>">Anterior</a>
          </li>
          <?php
            $win=2; $start=max(1,$page-$win); $end=min($pages,$page+$win);
            if ($start>1){
              echo '<li class="page-item"><a class="page-link" href="'.h(buildUrl(['page'=>1])).'">1</a></li>';
              if ($start>2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            for($k=$start;$k<=$end;$k++){
              $act = $k===$page ? 'active' : '';
              echo '<li class="page-item '.$act.'"><a class="page-link" href="'.h(buildUrl(['page'=>$k])).'">'.$k.'</a></li>';
            }
            if ($end<$pages){
              if ($end<$pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              echo '<li class="page-item"><a class="page-link" href="'.h(buildUrl(['page'=>$pages])).'">'.$pages.'</a></li>';
            }
          ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>min($pages,$page+1)])) ?>">Siguiente</a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-shield-check me-1"></i> Confirmar generación masiva</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Se generarán cuotas de <strong id="m_unidades">—</strong> unidades para <strong>todas</strong> las sucursales en la semana:</p>
        <ul class="list-group list-group-flush">
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Inicio (martes)</span><strong id="m_inicio">—</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Fin (lunes)</span><strong id="m_fin">—</strong></li>
        </ul>
        <div id="m_warn" class="alert alert-warning mt-3 d-none">
          <i class="bi bi-exclamation-triangle me-1"></i>
          El rango no es de 7 días (martes→lunes). Corrige antes de continuar.
        </div>
        <div id="m_warn2" class="alert alert-warning mt-2 d-none">
          <i class="bi bi-exclamation-octagon me-1"></i>
          La fecha de inicio no es martes. Corrígela antes de continuar.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Revisar</button>
        <button id="btnConfirmar" class="btn btn-primary">
          <span class="btn-text">Confirmar y generar</span>
          <span class="btn-wait d-none"><span class="spinner-border spinner-border-sm me-1"></span> Generando...</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
const $ = (id)=>document.getElementById(id);
const setLoading=(btn,loading)=>{const t=btn.querySelector('.btn-text'),w=btn.querySelector('.btn-wait'); if(loading){t?.classList.add('d-none');w?.classList.remove('d-none');btn.disabled=true;}else{t?.classList.remove('d-none');w?.classList.add('d-none');btn.disabled=false;}};
const fmt = (iso)=> new Date(iso).toLocaleDateString('es-MX');

function iso(d){ return d.toISOString().slice(0,10); }
function addDays(isoStr, n){ const d=new Date(isoStr); d.setDate(d.getDate()+n); return iso(d); }
function isTuesday(isoStr){ return new Date(isoStr).getDay() === 2; } // 0=Dom,1=Lun,2=Mar
function snapToNextTuesday(isoStr){
  let d = new Date(isoStr); let day = d.getDay();
  if (day === 2) return iso(d);
  const delta = (9 - day) % 7 || 7; // a próximo martes
  d.setDate(d.getDate()+delta);
  return iso(d);
}

const iStart = $('semana_inicio'), iEnd = $('semana_fin'), iUnits = $('cuota_unidades');
const alertForm = $('alertForm');

function showAlert(type,msg){ alertForm.className='alert alert-'+type; alertForm.textContent=msg; alertForm.classList.remove('d-none'); }
function clearAlert(){ alertForm.classList.add('d-none'); }

// Presets
function thisWeek(){ 
  const now=new Date(); const dow=now.getDay(); const deltaToTue=(dow<=2)?(2-dow):(9-dow);
  const tue=new Date(now.getFullYear(),now.getMonth(),now.getDate()+deltaToTue);
  const mon=new Date(tue.getFullYear(),tue.getMonth(),tue.getDate()+6);
  iStart.value=iso(tue); iEnd.value=iso(mon);
  clearAlert();
}
function nextWeek(){ thisWeek(); iStart.value=addDays(iStart.value,7); iEnd.value=addDays(iEnd.value,7); clearAlert(); }

$('btnThisWeek').addEventListener('click', thisWeek);
$('btnNextWeek').addEventListener('click', nextWeek);

// Ajuste automático: si el usuario elige un día que NO es martes, lo movemos al próximo martes y avisamos
iStart.addEventListener('change', ()=>{
  if (!iStart.value) return;
  if (!isTuesday(iStart.value)) {
    const original = iStart.value;
    iStart.value = snapToNextTuesday(original);
    showAlert('warning', 'La fecha de inicio debe ser martes. Ajusté automáticamente al próximo martes: ' + fmt(iStart.value));
  } else {
    clearAlert();
  }
  // forzar fin = inicio + 6
  iEnd.value = addDays(iStart.value, 6);
});

// Si el usuario toca fin, forzamos coherencia
iEnd.addEventListener('change', ()=>{
  if (!iStart.value) return;
  iEnd.value = addDays(iStart.value, 6);
  showAlert('info', 'La fecha de fin siempre se ajusta a lunes (inicio + 6 días).');
});

// Preview / validación y modal
const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
$('btnPreview').addEventListener('click', ()=>{
  clearAlert();
  if(!iStart.value || !iEnd.value || !iUnits.value){ showAlert('danger','Completa todos los campos.'); return; }
  if(Number(iUnits.value)<=0){ showAlert('warning','La cuota de unidades debe ser mayor a 0.'); return; }

  const spanOk = (new Date(iEnd.value).getTime() === new Date(iStart.value).getTime() + 6*24*3600*1000);
  const tueOk  = isTuesday(iStart.value);

  $('m_unidades').textContent = iUnits.value;
  $('m_inicio').textContent   = fmt(iStart.value);
  $('m_fin').textContent      = fmt(iEnd.value);
  $('m_warn').classList.toggle('d-none', spanOk);
  $('m_warn2').classList.toggle('d-none', tueOk);

  if (!tueOk) showAlert('warning','La fecha de inicio no es martes. Corrígela o usa el preset “Esta semana / Próxima semana”.');
  modal.show();
});

$('btnConfirmar').addEventListener('click', ()=>{
  setLoading($('btnConfirmar'), true);
  setLoading($('btnPreview'), true);
  document.getElementById('formGen').submit();
});

// Inicial sugerido: preset de “Esta semana”
thisWeek();
</script>
</body>
</html>
