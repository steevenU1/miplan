<?php
// inventario_ciclico.php — Captura de conteo cíclico (Presente/Ausente/No Verificable, Sobrantes y Cerrar)
// Acceso: Gerente, GerenteZona, Admin
// Flujo: llega desde iniciar_conteo.php con ?id_conteo=...

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = ['Gerente','GerenteZona','Admin'];
if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
  header("Location: 403.php"); exit();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = $conn ?? new mysqli(); // asume que db.php inicializa $conn
$conn->set_charset('utf8mb4');

// === Resolver id_conteo (redirige ANTES de imprimir cualquier salida)
$idConteo = (int)($_GET['id_conteo'] ?? 0);
if ($idConteo <= 0) { header("Location: iniciar_conteo.php"); exit(); }

// Cabecera
$stmt = $conn->prepare("
  SELECT c.id, c.id_sucursal, c.semana_inicio, c.semana_fin, c.estado, s.nombre AS sucursal
  FROM conteos_ciclicos c
  JOIN sucursales s ON s.id = c.id_sucursal
  WHERE c.id=? LIMIT 1
");
$stmt->bind_param('i', $idConteo);
$stmt->execute();
$cab = $stmt->get_result()->fetch_assoc();
if (!$cab) { http_response_code(404); die("Conteo no encontrado."); }

// Permiso por sucursal: Gerente solo su sucursal
if ($ROL === 'Gerente' && (int)$cab['id_sucursal'] !== $ID_SUCURSAL) {
  header("Location: 403.php"); exit();
}

$ESTADO = (string)$cab['estado'];
$DISABLED_ALL = $ESTADO === 'Cerrado' ? 'disabled' : '';

// Motivos activos
$motivos = [];
$rs = $conn->query("SELECT id, descripcion FROM cat_motivos_conteo WHERE activo=1 ORDER BY descripcion");
while ($m = $rs->fetch_assoc()) { $motivos[] = $m; }

// Detalle
$stmt = $conn->prepare("
  SELECT id, id_inventario, imei1, imei2, marca, modelo, color, estatus_snapshot, resultado, id_motivo, comentario
  FROM conteos_ciclicos_det
  WHERE id_conteo=?
  ORDER BY marca, modelo, color, imei1
");
$stmt->bind_param('i', $idConteo);
$stmt->execute();
$det = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// KPIs
$tot = count($det);
$presentes = 0; $ausentes = 0; $sobrantes = 0; $nover = 0;
foreach ($det as $r) {
  if ($r['resultado'] === 'Presente') $presentes++;
  elseif ($r['resultado'] === 'Ausente') $ausentes++;
  elseif ($r['resultado'] === 'Sobrante') $sobrantes++;
  else $nover++;
}
$rev = $presentes + $ausentes + $sobrantes;
$avance = $tot > 0 ? round(($rev / $tot) * 100, 1) : 0;

$msg = isset($_GET['msg']) ? h($_GET['msg']) : '';

// === Rutas de API (soporta endpoints en la misma carpeta o en /api)
$API_SUBDIR_URL  = '';   // si moviste los endpoints a /api, pon '/api'
$API_SUBDIR_FS   = '';   // idem para filesystem
// $API_SUBDIR_URL  = '/api'; $API_SUBDIR_FS = '/api'; // <- DESCOMENTA si tus endpoints están en /api

$BASE_URL = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/'); // p.ej. /luga_php
if ($BASE_URL === '/') $BASE_URL = ''; // normaliza raíz
$BASE_FS  = rtrim(str_replace('\\','/', __DIR__), '/');                          // p.ej. C:/laragon/www/luga_php

// Paths FS para validar existencia
$apiGuardarFs  = $BASE_FS . $API_SUBDIR_FS . '/api_conteo_guardar.php';
$apiSobranteFs = $BASE_FS . $API_SUBDIR_FS . '/api_conteo_sobrante.php';
$apiCerrarFs   = $BASE_FS . $API_SUBDIR_FS . '/cerrar_conteo.php';

// URLs que usará fetch()
$API_GUARDAR  = $BASE_URL . $API_SUBDIR_URL . '/api_conteo_guardar.php';
$API_SOBRANTE = $BASE_URL . $API_SUBDIR_URL . '/api_conteo_sobrante.php';
$API_CERRAR   = $BASE_URL . $API_SUBDIR_URL . '/cerrar_conteo.php';

// Debug opcional en UI si falta algún endpoint
$DEBUG_MISSING = [];
if (!file_exists($apiGuardarFs))  { $DEBUG_MISSING[] = $apiGuardarFs; }
if (!file_exists($apiSobranteFs)) { $DEBUG_MISSING[] = $apiSobranteFs; }
if (!file_exists($apiCerrarFs))   { $DEBUG_MISSING[] = $apiCerrarFs; }
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Conteo cíclico · <?php echo h($cab['sucursal']); ?></title>
  <style>
    :root{--bg:#f8fafc;--fg:#0f172a;--muted:#64748b;--ok:#16a34a;--warn:#d97706;--bad:#dc2626;--card:#ffffff;--line:#e5e7eb}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;background:var(--bg);color:var(--fg);margin:0}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
    h1{font-size:1.25rem;margin:0 0 8px}
    .sub{color:var(--muted);margin-bottom:16px}
    .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:16px 0}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
    .k{font-size:.8rem;color:var(--muted)}
    .v{font-weight:700;margin-top:4px}
    .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 16px}
    .btn{appearance:none;border:1px solid var(--line);background:#fff;padding:10px 14px;border-radius:10px;cursor:pointer}
    .btn.primary{background:#111;color:#fff;border-color:#111}
    .btn.danger{background:var(--bad);color:#fff;border-color:var(--bad)}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .pill{display:inline-block;padding:.25rem .5rem;border-radius:999px;border:1px solid var(--line);font-size:.8rem;color:var(--muted)}
    .ok{color:var(--ok)} .bad{color:var(--bad)} .warn{color:var(--warn)}
    .table{width:100%;border-collapse:separate;border-spacing:0;margin-top:8px}
    .table th,.table td{border-bottom:1px solid var(--line);padding:8px;vertical-align:top}
    .table th{background:#f3f4f6;text-align:left;font-size:.9rem}
    .table tbody tr:hover{background:#fcfcfd}
    select, input[type="text"], textarea{width:100%;padding:8px;border:1px solid var(--line);border-radius:8px;background:#fff}
    textarea{min-height:42px;resize:vertical}
    .row-actions{display:flex;gap:8px;align-items:center}
    .msg{margin:8px 0;padding:10px 12px;border-radius:10px;background:#eef2ff;border:1px solid #c7d2fe}
    .msg-warn{background:#fff3cd;border-color:#ffeeba}
    .muted{color:var(--muted)}
    .sticky-head{position:sticky;top:0;z-index:2}
    .badge{font-size:.75rem;padding:.15rem .45rem;border-radius:6px;border:1px solid var(--line);background:#fff}
    .right{margin-left:auto}
    .invalid{ border-color:#dc2626 !important; box-shadow:0 0 0 2px rgba(220,38,38,.15) }
    @media (max-width:960px){
      .kpis{grid-template-columns:repeat(2,1fr)}
      .hide-sm{display:none}
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/navbar.php'; ?>
  <div class="wrap">
    <h1>Conteo cíclico — <?php echo h($cab['sucursal']); ?></h1>
    <div class="sub">
      Semana: <strong><?php echo h($cab['semana_inicio']); ?></strong> → <strong><?php echo h($cab['semana_fin']); ?></strong>
      · Estado: <span class="pill"><?php echo h($ESTADO); ?></span>
      <?php if ($msg): ?> · <span class="pill"><?php echo $msg; ?></span><?php endif; ?>
    </div>

    <?php if (!empty($DEBUG_MISSING)): ?>
      <div class="msg msg-warn">
        <strong>Advertencia:</strong> No encuentro estos endpoints en el servidor (filesystem):
        <pre style="white-space:pre-wrap;margin:8px 0 0;"><?php echo h(implode("\n", $DEBUG_MISSING)); ?></pre>
        <div style="margin-top:6px;font-size:.9rem;">
          Si moviste los endpoints a <code>/api</code>, edita arriba <code>$API_SUBDIR_URL</code> y <code>$API_SUBDIR_FS</code> a <code>'/api'</code>.
        </div>
      </div>
    <?php endif; ?>

    <?php
      $presentes = (int)$presentes; $ausentes=(int)$ausentes; $sobrantes=(int)$sobrantes; $nover=(int)$nover;
      $rev = $presentes + $ausentes + $sobrantes; $tot = (int)$tot;
    ?>
    <div class="kpis">
      <div class="card"><div class="k">Total</div><div class="v" id="k-total"><?php echo $tot; ?></div></div>
      <div class="card"><div class="k">Revisados</div><div class="v" id="k-revisados"><?php echo $rev; ?></div></div>
      <div class="card"><div class="k ok">Presentes</div><div class="v" id="k-pres"><?php echo $presentes; ?></div></div>
      <div class="card"><div class="k bad">Ausentes</div><div class="v" id="k-aus"><?php echo $ausentes; ?></div></div>
      <div class="card"><div class="k warn">Sobrantes</div><div class="v" id="k-sob"><?php echo $sobrantes; ?></div></div>
    </div>

    <div class="toolbar">
      <form id="form-sobrante" class="row-actions" onsubmit="return addSobrante(event)">
        <input type="hidden" name="id_conteo" value="<?php echo (int)$idConteo; ?>">
        <input type="text" name="imei" placeholder="Agregar IMEI sobrante" required <?php echo $DISABLED_ALL; ?>>
        <button class="btn" <?php echo $DISABLED_ALL; ?>>Agregar sobrante</button>
      </form>
      <span class="badge">No verificables: <span id="k-nover"><?php echo $nover; ?></span></span>
      <span class="badge">Avance: <span id="k-avance"><?php echo $avance; ?>%</span></span>
      <div class="right"></div>
      <button class="btn primary" onclick="guardarTodo()" title="Guardar cambios pendientes" <?php echo $DISABLED_ALL; ?>>Guardar</button>
      <button class="btn danger" onclick="cerrarConteo()" <?php echo $ESTADO==='Cerrado'?'disabled':''; ?>>Cerrar conteo</button>
    </div>

    <table class="table" id="tabla">
      <thead class="sticky-head">
        <tr>
          <th class="hide-sm">#</th>
          <th>Marca</th>
          <th>Modelo</th>
          <th class="hide-sm">Color</th>
          <th>IMEI</th>
          <th>Estatus (snapshot)</th>
          <th>Resultado</th>
          <th>Motivo (si Ausente)</th>
          <th class="hide-sm">Comentario</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $i=1;
      foreach ($det as $r):
        $idDet = (int)$r['id'];
        $res = $r['resultado'] ?: 'No Verificable';
        $motSel = (int)($r['id_motivo'] ?? 0);
        $disabledMot = ($res === 'Ausente') ? '' : 'disabled';
      ?>
        <tr data-id="<?php echo $idDet; ?>" data-estatus="<?php echo h($r['estatus_snapshot']); ?>">
          <td class="hide-sm"><?php echo $i++; ?></td>
          <td><?php echo h($r['marca']); ?></td>
          <td><?php echo h($r['modelo']); ?></td>
          <td class="hide-sm"><?php echo h($r['color']); ?></td>
          <td><span class="muted"><?php echo h($r['imei1'] ?: ''); ?></span></td>
          <td><span class="pill"><?php echo h($r['estatus_snapshot']); ?></span></td>
          <td>
            <select class="inp-resultado" onchange="onResultadoChange(this)" <?php echo $DISABLED_ALL; ?>>
              <?php
              $opts = ['No Verificable','Presente','Ausente','Sobrante'];
              foreach ($opts as $opt) {
                $sel = $res===$opt ? 'selected' : '';
                echo "<option value=\"".h($opt)."\" $sel>$opt</option>";
              }
              ?>
            </select>
          </td>
          <td>
            <select class="inp-motivo" <?php echo $disabledMot . ' ' . $DISABLED_ALL; ?> onchange="queueSave(this)">
              <option value="">— Selecciona motivo —</option>
              <?php foreach ($motivos as $m): ?>
                <option value="<?php echo (int)$m['id']; ?>" <?php echo $motSel===(int)$m['id']?'selected':''; ?>>
                  <?php echo h($m['descripcion']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="hide-sm">
            <textarea class="inp-coment" placeholder="Comentario..." onblur="queueSave(this)" <?php echo $DISABLED_ALL; ?>><?php echo h($r['comentario'] ?? ''); ?></textarea>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p class="muted">Tip: 1=Presente, 2=Ausente, 3=No verificable (con el foco en “Resultado”). Guardado en vivo.</p>
    <?php if ($ESTADO==='Cerrado'): ?>
      <div class="msg">Este conteo está <strong>cerrado</strong>. Solo lectura.</div>
    <?php endif; ?>
  </div>

<script>
const ID_CONTEO = <?php echo (int)$idConteo; ?>;
const ESTADO = <?php echo json_encode($ESTADO); ?>;
// Rutas API desde PHP (evita 404 en subcarpetas)
const API_GUARDAR  = <?php echo json_encode($API_GUARDAR); ?>;
const API_SOBRANTE = <?php echo json_encode($API_SOBRANTE); ?>;
const API_CERRAR   = <?php echo json_encode($API_CERRAR); ?>;

let saveQueue = new Map(); // id_det -> payload
let saving = false;

function onResultadoChange(sel){
  const tr = sel.closest('tr');
  const motivo = tr.querySelector('.inp-motivo');
  const coment = tr.querySelector('.inp-coment');
  const val = sel.value;

  if (val === 'Ausente'){
    motivo.disabled = false;
    // valida motivo/comentario antes de guardar
    if (!motivo.value){
      motivo.classList.add('invalid');
      motivo.focus();
      return;
    } else {
      motivo.classList.remove('invalid');
    }
    if (!coment.value || coment.value.trim().length < 5){
      coment.classList.add('invalid');
      coment.focus();
      return;
    } else {
      coment.classList.remove('invalid');
    }
  } else {
    // limpiar cuando no es Ausente
    motivo.value = '';
    motivo.disabled = true;
    motivo.classList.remove('invalid');
    coment.classList.remove('invalid');
  }
  queueSave(sel);
  recomputeKpis();
}

function queueSave(el){
  if (ESTADO === 'Cerrado') return;
  const tr = el.closest('tr');
  const id = parseInt(tr.dataset.id);
  const resultado = tr.querySelector('.inp-resultado')?.value || 'No Verificable';
  const motivoSel = tr.querySelector('.inp-motivo');
  const id_motivo = motivoSel?.value || '';
  const comentEl = tr.querySelector('.inp-coment');
  const comentario = comentEl?.value || '';

  // Validación front: si Ausente, exige motivo y comentario >=5
  if (resultado === 'Ausente'){
    let ok = true;
    if (!id_motivo){ motivoSel.classList.add('invalid'); ok = false; }
    else { motivoSel.classList.remove('invalid'); }
    if (!comentario || comentario.trim().length < 5){ comentEl.classList.add('invalid'); ok = false; }
    else { comentEl.classList.remove('invalid'); }
    if (!ok) return; // no encolar si falta algo
  }

  const payload = { id_det: id, resultado, id_motivo, comentario };
  saveQueue.set(id, payload);
  debounce(doSaves, 300)();
}

async function doSaves(){
  if (saving || saveQueue.size===0) return;
  saving = true;
  try{
    const batch = Array.from(saveQueue.values());
    saveQueue.clear();

    for(const payload of batch){
      const res = await fetch(API_GUARDAR, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id_conteo: ID_CONTEO, ...payload })
      });

      if(!res.ok){
        // intenta parsear JSON de error para mostrar mensaje limpio
        let txt = await res.text();
        try {
          const j = JSON.parse(txt);
          alert(j.error || txt);
        } catch {
          alert(txt);
        }
      }
    }
  }catch(err){
    console.error(err);
    alert('Falla de red al guardar.');
  }finally{
    saving = false;
  }
}

function guardarTodo(){ doSaves(); }

async function addSobrante(ev){
  ev.preventDefault();
  if (ESTADO === 'Cerrado') return false;
  const form = ev.target;
  const fd = new FormData(form);
  try{
    const res = await fetch(API_SOBRANTE, { method:'POST', body: fd });
    const data = await res.json();
    if(!data.ok){ alert(data.error || 'No se pudo agregar sobrante'); return false; }
    appendRow(data.row);
    form.reset();
    recomputeKpis();
  }catch(e){
    console.error(e);
    alert('Error al agregar sobrante');
  }
  return false;
}

function appendRow(row){
  const tbody = document.querySelector('#tabla tbody');
  const idx = tbody.children.length + 1;
  const tr = document.createElement('tr');
  tr.dataset.id = row.id;
  tr.dataset.estatus = row.estatus_snapshot || '';
  tr.innerHTML = `
    <td class="hide-sm">${idx}</td>
    <td>${escapeHtml(row.marca||'')}</td>
    <td>${escapeHtml(row.modelo||'')}</td>
    <td class="hide-sm">${escapeHtml(row.color||'')}</td>
    <td><span class="muted">${escapeHtml(row.imei1||'')}</span></td>
    <td><span class="pill">${escapeHtml(row.estatus_snapshot||'')}</span></td>
    <td>
      <select class="inp-resultado" onchange="onResultadoChange(this)">
        <option value="No Verificable">No Verificable</option>
        <option value="Presente">Presente</option>
        <option value="Ausente">Ausente</option>
        <option value="Sobrante" selected>Sobrante</option>
      </select>
    </td>
    <td>
      <select class="inp-motivo" disabled onchange="queueSave(this)">
        <option value="">— Selecciona motivo —</option>
        <?php foreach ($motivos as $m): ?>
          <option value="<?php echo (int)$m['id']; ?>"><?php echo h($m['descripcion']); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="hide-sm">
      <textarea class="inp-coment" placeholder="Comentario..." onblur="queueSave(this)"></textarea>
    </td>
  `;
  tbody.appendChild(tr);
}

async function cerrarConteo(){
  const nover = parseInt(document.getElementById('k-nover').innerText || '0');
  if (nover > 0){
    const go = confirm('Hay elementos en "No verificable". ¿Cerrar de todos modos?');
    if(!go) return;
  }
  await doSaves();
  try{
    const res = await fetch(API_CERRAR, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id_conteo: ID_CONTEO })
    });
    const data = await res.json();
    if(!data.ok){ alert(data.error || 'No se pudo cerrar'); return; }
    alert('Conteo cerrado.');
    location.href = 'inventario_ciclico.php?id_conteo='+ID_CONTEO+'&msg=' + encodeURIComponent('Cerrado correctamente');
  }catch(e){
    console.error(e);
    alert('Error al cerrar conteo');
  }
}

function recomputeKpis(){
  const rows = Array.from(document.querySelectorAll('#tabla tbody tr'));
  let tot=rows.length, pres=0, aus=0, sob=0, nover=0;
  for(const tr of rows){
    const val = tr.querySelector('.inp-resultado')?.value || 'No Verificable';
    if (val==='Presente') pres++;
    else if (val==='Ausente') aus++;
    else if (val==='Sobrante') sob++;
    else nover++;
  }
  const rev = pres+aus+sob;
  const avance = tot ? Math.round((rev/tot)*1000)/10 : 0;
  document.getElementById('k-total').innerText = tot;
  document.getElementById('k-revisados').innerText = rev;
  document.getElementById('k-pres').innerText = pres;
  document.getElementById('k-aus').innerText = aus;
  document.getElementById('k-sob').innerText = sob;
  document.getElementById('k-nover').innerText = nover;
  document.getElementById('k-avance').innerText = avance + '%';
}

// Utils
function escapeHtml(s){
  return (s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function debounce(fn,ms){ let t; return ()=>{ clearTimeout(t); t=setTimeout(fn,ms); }; }

// Atajos 1/2/3 con el foco en "Resultado"
document.addEventListener('keydown', (e)=>{
  const sel = document.activeElement;
  if (!sel || sel.tagName!=='SELECT' || !sel.classList.contains('inp-resultado')) return;
  if (e.key==='1'){ sel.value='Presente'; onResultadoChange(sel); }
  if (e.key==='2'){ sel.value='Ausente'; onResultadoChange(sel); }
  if (e.key==='3'){ sel.value='No Verificable'; onResultadoChange(sel); }
});
</script>
</body>
</html>
