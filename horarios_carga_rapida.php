<?php
// horarios_carga_rapida.php  ·  Carga y copiado rápido de horarios por sucursal (solo tienda/propia)
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin'])) {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$daysMap = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
$msg = '';

// ===== Sucursales (solo tienda/propia) =====
$sucursales = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE tipo_sucursal='tienda' AND subtipo='propia'
  ORDER BY nombre
")->fetch_all(MYSQLI_ASSOC);

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;

// ===== Guardar horario de la sucursal =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='guardar') {
  $sucursal_id = (int)($_POST['sucursal_id'] ?? 0);
  if ($sucursal_id<=0) {
    $msg = "<div class='alert alert-danger mb-3'>Selecciona una sucursal.</div>";
  } else {
    // Validación básica
    $errores = [];
    for($d=1;$d<=7;$d++){
      $cerr = isset($_POST['cerrado'][$d]) ? 1 : 0;
      $ab = trim($_POST['abre'][$d] ?? '');
      $ci = trim($_POST['cierra'][$d] ?? '');
      if (!$cerr) {
        if ($ab==='' || $ci==='') $errores[] = $daysMap[$d].' sin horas';
        elseif ($ab >= $ci) $errores[] = $daysMap[$d].' apertura ≥ cierre';
      }
    }

    if ($errores) {
      $msg = "<div class='alert alert-warning mb-3'><b>Revisa:</b> ".h(implode(' · ', $errores))."</div>";
    } else {
      // Reemplazar todo el horario (DELETE + INSERT)
      $del = $conn->prepare("DELETE FROM sucursales_horario WHERE id_sucursal=?");
      $del->bind_param('i', $sucursal_id); $del->execute(); $del->close();

      $ins = $conn->prepare("INSERT INTO sucursales_horario (id_sucursal, dia_semana, abre, cierra, cerrado) VALUES (?,?,?,?,?)");
      for($d=1;$d<=7;$d++){
        $cerr = isset($_POST['cerrado'][$d]) ? 1 : 0;
        $ab = trim($_POST['abre'][$d] ?? '');
        $ci = trim($_POST['cierra'][$d] ?? '');
        $abre   = $cerr ? null : ($ab!=='' ? $ab.":00" : null);
        $cierra = $cerr ? null : ($ci!=='' ? $ci.":00" : null);
        $ins->bind_param('iissi', $sucursal_id, $d, $abre, $cierra, $cerr);
        $ins->execute();
      }
      $ins->close();
      $msg = "<div class='alert alert-success mb-3'>✅ Horarios de la sucursal guardados.</div>";
    }
  }
}

// ===== Copiar horario desde la sucursal actual a otras =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='clonar') {
  $srcId = (int)($_POST['src_id'] ?? 0);
  $targets = array_map('intval', $_POST['targets'] ?? []);

  if ($srcId<=0 || empty($targets)) {
    $msg = "<div class='alert alert-warning mb-3'>Selecciona origen y al menos una sucursal destino.</div>";
  } else {
    // Cargar horario fuente
    $src = [];
    $stmt = $conn->prepare("SELECT dia_semana, abre, cierra, cerrado FROM sucursales_horario WHERE id_sucursal=?");
    $stmt->bind_param('i', $srcId); $stmt->execute();
    $res = $stmt->get_result();
    while($r=$res->fetch_assoc()){
      $src[(int)$r['dia_semana']] = [
        'abre'   => $r['abre'] ?: null,
        'cierra' => $r['cierra'] ?: null,
        'cerrado'=> (int)$r['cerrado']
      ];
    }
    $stmt->close();

    // Si faltan días, completar como cerrado
    for($d=1;$d<=7;$d++){
      if (!isset($src[$d])) $src[$d] = ['abre'=>null,'cierra'=>null,'cerrado'=>1];
    }

    // Filtrar destinos a tienda/propia
    if ($targets) {
      $in = implode(',', array_fill(0,count($targets),'?'));
      $types = str_repeat('i', count($targets));
      $q = $conn->prepare("
        SELECT id FROM sucursales 
        WHERE id IN ($in) AND tipo_sucursal='tienda' AND subtipo='propia'
      ");
      $q->bind_param($types, ...$targets); $q->execute();
      $valid = array_map(fn($r)=> (int)$r['id'], $q->get_result()->fetch_all(MYSQLI_ASSOC));
      $q->close();
      $targets = $valid;
    }

    if (empty($targets)) {
      $msg = "<div class='alert alert-danger mb-3'>Ningún destino válido (solo tienda/propia).</div>";
    } else {
      $conn->begin_transaction();
      try {
        $del = $conn->prepare("DELETE FROM sucursales_horario WHERE id_sucursal=?");
        $ins = $conn->prepare("INSERT INTO sucursales_horario (id_sucursal, dia_semana, abre, cierra, cerrado) VALUES (?,?,?,?,?)");

        foreach($targets as $tid){
          $del->bind_param('i', $tid); $del->execute();
          for($d=1;$d<=7;$d++){
            $abre   = $src[$d]['abre'];
            $cierra = $src[$d]['cierra'];
            $cerr   = (int)$src[$d]['cerrado'];
            $ins->bind_param('iissi', $tid, $d, $abre, $cierra, $cerr);
            $ins->execute();
          }
        }

        $del->close(); $ins->close();
        $conn->commit();
        $msg = "<div class='alert alert-success mb-3'>✅ Horario clonado a <b>".count($targets)."</b> sucursal(es).</div>";
      } catch (Throwable $e) {
        $conn->rollback();
        $msg = "<div class='alert alert-danger mb-3'>Ocurrió un error al clonar: ".h($e->getMessage())."</div>";
      }
    }
  }
}

// ===== Cargar horario actual (para edición) =====
$horarios = [];
if ($sucursal_id>0){
  $stmt = $conn->prepare("SELECT dia_semana, abre, cierra, cerrado FROM sucursales_horario WHERE id_sucursal=?");
  $stmt->bind_param('i', $sucursal_id); $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){
    $d=(int)$r['dia_semana'];
    $horarios[$d] = [
      'abre'   => $r['abre']? substr($r['abre'],0,5):'',
      'cierra' => $r['cierra']? substr($r['cierra'],0,5):'',
      'cerrado'=> (int)$r['cerrado']
    ];
  }
  $stmt->close();
}
for($d=1;$d<=7;$d++){ $horarios[$d] = $horarios[$d] ?? ['abre'=>'','cierra'=>'','cerrado'=>0]; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Carga rápida · Horarios por sucursal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f8fafc; }
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:.5rem; border-top:1px solid #e5e7eb;}
    .tpl .form-control{ max-width:140px; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-clock-history me-2"></i>Carga rápida · Horarios</h3>
    <div class="text-muted">Solo sucursales <b>tienda / propia</b></div>
  </div>

  <?= $msg ?>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label mb-0">Sucursal</label>
          <select name="sucursal_id" class="form-select" onchange="this.form.submit()">
            <option value="0">— Selecciona sucursal —</option>
            <?php foreach($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Abrir</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($sucursal_id>0): ?>
  <!-- Plantillas rápidas -->
  <div class="card card-elev mb-3">
    <div class="card-header fw-bold"><i class="bi bi-lightning-charge me-1"></i>Plantillas rápidas</div>
    <div class="card-body tpl">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label mb-0">Lun–Vie abre</label>
          <input type="time" id="tpl_lv_abre" class="form-control" value="09:00">
        </div>
        <div class="col-md-3">
          <label class="form-label mb-0">Lun–Vie cierra</label>
          <input type="time" id="tpl_lv_cierra" class="form-control" value="19:00">
        </div>
        <div class="col-md-2">
          <button class="btn btn-outline-primary w-100" type="button" onclick="aplicarLV()">Aplicar L–V</button>
        </div>

        <div class="col-12 d-md-none"><hr></div>

        <div class="col-md-3">
          <label class="form-label mb-0">Sábado abre</label>
          <input type="time" id="tpl_sa_abre" class="form-control" value="10:00">
        </div>
        <div class="col-md-3">
          <label class="form-label mb-0">Sábado cierra</label>
          <input type="time" id="tpl_sa_cierra" class="form-control" value="18:00">
        </div>
        <div class="col-md-2">
          <button class="btn btn-outline-primary w-100" type="button" onclick="aplicarSabado()">Aplicar Sáb</button>
        </div>
        <div class="col-md-2">
          <button class="btn btn-outline-secondary w-100" type="button" onclick="cerrarDomingo()">Domingo cerrado</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Form principal -->
  <form method="post" id="frmHorario">
    <input type="hidden" name="action" value="guardar">
    <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal_id ?>">

    <div class="card card-elev mb-3">
      <div class="card-header fw-bold">Horario por día · <span class="text-muted"><?= h(array_values(array_filter($sucursales, fn($x)=>$x['id']==$sucursal_id))[0]['nombre'] ?? '') ?></span></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-xs align-middle mb-0">
            <thead class="table-dark">
              <tr><th>Día</th><th>Abre</th><th>Cierra</th><th class="text-center">Cerrado</th></tr>
            </thead>
            <tbody>
            <?php for($d=1;$d<=7;$d++):
              $row = $horarios[$d]; $cerrado = (int)$row['cerrado']===1;
            ?>
              <tr>
                <td class="fw-semibold"><?= $daysMap[$d] ?></td>
                <td><input type="time" class="form-control" name="abre[<?= $d ?>]" id="abre<?= $d ?>" value="<?= h($row['abre']) ?>" <?= $cerrado?'disabled':'' ?>></td>
                <td><input type="time" class="form-control" name="cierra[<?= $d ?>]" id="cierra<?= $d ?>" value="<?= h($row['cierra']) ?>" <?= $cerrado?'disabled':'' ?>></td>
                <td class="text-center">
                  <input type="checkbox" class="form-check-input" name="cerrado[<?= $d ?>]" id="cerrado<?= $d ?>" value="1" <?= $cerrado?'checked':'' ?> onclick="toggleCerrado(<?= $d ?>)">
                </td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="sticky-actions d-flex justify-content-between align-items-center">
        <div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Si marcas <b>cerrado</b>, las horas de ese día se ignoran.</div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-secondary" type="button" onclick="limpiarTodo()"><i class="bi bi-eraser me-1"></i>Limpiar</button>
          <button class="btn btn-success"><i class="bi bi-save me-1"></i>Guardar</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Copiar a otras sucursales -->
  <div class="card card-elev">
    <div class="card-header fw-bold"><i class="bi bi-files me-1"></i>Copiar horario de <span class="text-muted"><?= h(array_values(array_filter($sucursales, fn($x)=>$x['id']==$sucursal_id))[0]['nombre'] ?? '') ?></span> a…</div>
    <div class="card-body">
      <form method="post" onsubmit="return confirm('¿Copiar el horario de la sucursal actual a las seleccionadas? Se reemplazará por completo.');">
        <input type="hidden" name="action" value="clonar">
        <input type="hidden" name="src_id" value="<?= (int)$sucursal_id ?>">
        <div class="row g-2 align-items-end">
          <div class="col-md-8">
            <label class="form-label mb-0">Sucursales destino (puedes elegir varias)</label>
            <select class="form-select" name="targets[]" multiple size="8">
              <?php foreach($sucursales as $s):
                if ((int)$s['id'] === $sucursal_id) continue; ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Solo aparecen sucursales de tipo <b>tienda / propia</b>.</div>
          </div>
          <div class="col-md-4 d-grid">
            <button class="btn btn-outline-primary"><i class="bi bi-arrow-right-circle me-1"></i>Copiar a seleccionadas</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php endif; // sucursal seleccionada ?>

</div>

<script>
function toggleCerrado(d){
  const chk=document.getElementById('cerrado'+d);
  const a=document.getElementById('abre'+d);
  const c=document.getElementById('cierra'+d);
  if(chk.checked){ a.value=''; c.value=''; a.disabled=true; c.disabled=true; }
  else { a.disabled=false; c.disabled=false; }
}
function setDia(d,abre,cierra,cerrado=false){
  const a=document.getElementById('abre'+d);
  const c=document.getElementById('cierra'+d);
  const chk=document.getElementById('cerrado'+d);
  chk.checked=!!cerrado;
  if(cerrado){ a.value=''; c.value=''; a.disabled=true; c.disabled=true; }
  else { a.disabled=false; c.disabled=false; if(abre) a.value=abre; if(cierra) c.value=cierra; }
}
function aplicarLV(){
  const ab=document.getElementById('tpl_lv_abre').value||'';
  const ci=document.getElementById('tpl_lv_cierra').value||'';
  for(let d=1; d<=5; d++) setDia(d,ab,ci,false);
}
function aplicarSabado(){
  const ab=document.getElementById('tpl_sa_abre').value||'';
  const ci=document.getElementById('tpl_sa_cierra').value||'';
  setDia(6,ab,ci,false);
}
function cerrarDomingo(){ setDia(7,'','',true); }
function limpiarTodo(){
  for(let d=1; d<=7; d++){ setDia(d,'','',false); document.getElementById('cerrado'+d).checked=false; }
}
</script>
</body>
</html>
