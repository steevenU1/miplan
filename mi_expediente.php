<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

/* ==== Includes con fallback ==== */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) {
  require_once __DIR__ . '/includes/docs_lib.php'; // incluye db.php
} else {
  require_once __DIR__ . '/docs_lib.php';
}
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* ==== Contexto ==== */
$mi_id      = (int)$_SESSION['id_usuario'];
$usuario_id = $mi_id; // vista del propio usuario

/* ==== Campos requeridos para el progreso (SIN baja/motivo) ==== */
$requiredFields = [
  'tel_contacto'         => 'Tel. de contacto',
  'fecha_nacimiento'     => 'Fecha de nacimiento',
  'fecha_ingreso'        => 'Fecha de ingreso',
  'curp'                 => 'CURP',
  'nss'                  => 'NSS (IMSS)',
  'rfc'                  => 'RFC',
  'genero'               => 'Género',
  'contacto_emergencia'  => 'Contacto de emergencia',
  'tel_emergencia'       => 'Tel. de emergencia',
  // Etiqueta visible actualizada
  'clabe'                => 'CLABE o Tarjeta (16/18)',
  // 'banco'             => 'Banco'
];

/* ==== Cargar expediente ==== */
$stmt = $conn->prepare("SELECT * FROM usuarios_expediente WHERE usuario_id=? LIMIT 1");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
$exp = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

/* ==== Documentos requeridos y estado ==== */
$tipos   = list_doc_types_with_status($conn, $usuario_id);

/* Lista para mostrar en UI (todos los requeridos) */
$reqDocs = array_values(array_filter($tipos, fn($t) => (int)$t['requerido'] === 1));

/* Lista filtrada solo para el conteo (excluye “Contrato”) */
$reqDocsForProgress = array_values(array_filter($reqDocs, function($t){
  $nombre = trim((string)($t['nombre'] ?? ''));
  return stripos($nombre, 'contrato') === false; // <- excluye cualquier doc que contenga “Contrato”
}));

/* ==== Progreso ==== */
$filledFields = 0; $missingFields = [];
foreach ($requiredFields as $key=>$label) {
  $val = $exp[$key] ?? '';
  $isFilled = in_array($key, ['fecha_nacimiento','fecha_ingreso'], true) ? !empty($val) : (trim((string)$val) !== '');
  if ($isFilled) $filledFields++; else $missingFields[] = $label;
}

$uploadedReqDocs = 0; $missingDocs = [];
foreach ($reqDocsForProgress as $d) {
  if (!empty($d['doc_id_vigente'])) $uploadedReqDocs++;
  else $missingDocs[] = $d['nombre'];
}

$totalItems = count($requiredFields) + count($reqDocsForProgress);
$doneItems  = $filledFields + $uploadedReqDocs;
$percent    = $totalItems > 0 ? floor(($doneItems / $totalItems) * 100) : 0;

/* ==== Derivados para mostrar ==== */
function fmt_antiguedad($meses) {
  if ($meses === null || $meses === '') return '';
  $y = intdiv((int)$meses, 12);
  $m = ((int)$meses) % 12;
  $parts = [];
  if ($y > 0) $parts[] = $y . ' año' . ($y>1?'s':'');
  if ($m > 0) $parts[] = $m . ' mes' . ($m>1?'es':'');
  return $parts ? implode(' y ', $parts) : '0 meses';
}
$edad_calc = '';
if (!empty($exp['fecha_nacimiento'])) {
  $n = new DateTime($exp['fecha_nacimiento']);
  $h = new DateTime();
  $edad_calc = $h->diff($n)->y;
}
$edad      = $exp['edad_years'] ?? $edad_calc ?? '';
$ant_texto = isset($exp['antiguedad_meses']) ? fmt_antiguedad($exp['antiguedad_meses']) : '';

$maxMB  = defined('DOCS_MAX_SIZE') ? (int)(DOCS_MAX_SIZE/1024/1024) : 10;
$ok     = !empty($_GET['ok']);
$err    = !empty($_GET['err']) ? $_GET['err'] : '';
$ok_doc = !empty($_GET['ok_doc']);
$err_doc= !empty($_GET['err_doc']) ? $_GET['err_doc'] : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi expediente</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:16px;background:#f7fafc}
    .container{max-width:1100px;margin:0 auto}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:14px}
    .title{margin:0 0 8px 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:600;font-size:12px}
    .badge-ok{background:#c6f6d5;color:#22543d;border:1px solid #9ae6b4}
    .progress{background:#edf2f7;border-radius:999px;height:16px;overflow:hidden}
    .bar{height:100%;background:#2f855a;color:#fff;text-align:center;font-size:12px;line-height:16px;white-space:nowrap}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    label{display:block;font-weight:600;margin-bottom:4px}
    input,select{width:100%;padding:10px;border:1px solid #cbd5e0;border-radius:8px;background:#fff}
    .readonly{background:#f1f5f9}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #cbd5e0;background:#fff;text-decoration:none}
    .btn-primary{background:#2b6cb0;border-color:#2b6cb0;color:#fff}
    .btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
    .btn-secondary{background:#4a5568;border-color:#4a5568;color:#fff}
    .btn-success:disabled,.btn-secondary:disabled{opacity:.5;cursor:not-allowed}
    .muted{opacity:.75;font-size:12px}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#ebf8ff;color:#2b6cb0;border:1px solid #bee3f8;font-size:12px;margin-right:6px}
    .list{margin:8px 0 0 0;padding-left:18px}
    .alert-ok{background:#e6fffa;border:1px solid #b2f5ea;color:#234e52;padding:10px;border-radius:10px;margin-bottom:10px}
    .alert-err{background:#fff5f5;border:1px solid #fed7d7;color:#742a2a;padding:10px;border-radius:10px;margin-bottom:10px}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}

    /* ---- Upload compacto y con estado ---- */
    .doc-row{padding:8px 12px;border:1px dashed #cbd5e0;border-radius:10px;background:#fbfdff;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .doc-info{min-width:240px}
    .upload{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .file input[type="file"]{display:none}
    .file .file-label{display:inline-block;padding:6px 10px;border:1px solid #cbd5e0;border-radius:8px;background:#fff;cursor:pointer}
    .file-name{font-size:12px;opacity:.9;padding:4px 8px;border-radius:999px;background:#edf2f7;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .doc-row.ready{border-color:#38a169;background:#f0fff4}
    .doc-row.ready .file-name{background:#c6f6d5}
  </style>
</head>
<body>
<div class="container">
  <h2 class="title">
    Mi expediente
    <?php if ($percent === 100): ?>
      <span class="badge badge-ok">✅ Expediente al 100%</span>
    <?php endif; ?>
  </h2>

  <?php if ($ok): ?><div class="alert-ok">Datos guardados. Puedes regresar y completar lo que falte cuando quieras.</div><?php endif; ?>
  <?php if ($err): ?><div class="alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($ok_doc): ?><div class="alert-ok">Documento subido correctamente.</div><?php endif; ?>
  <?php if ($err_doc): ?><div class="alert-err"><?= htmlspecialchars($err_doc) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <strong>Avance general: <?= $percent ?>%</strong>
      <span class="muted"><?= $doneItems ?>/<?= $totalItems ?> elementos completos</span>
    </div>
    <div class="progress" aria-label="Progreso">
      <div class="bar" style="width: <?= max(0,min(100,$percent)) ?>%"><?= $percent ?>%</div>
    </div>
    <?php if ($missingFields || $missingDocs): ?>
      <div style="margin-top:10px">
        <?php if ($missingFields): ?><div class="pill">Faltan datos: <?= count($missingFields) ?></div><?php endif; ?>
        <?php if ($missingDocs): ?><div class="pill">Faltan documentos: <?= count($missingDocs) ?></div><?php endif; ?>
        <ul class="list">
          <?php foreach ($missingFields as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
          <?php foreach ($missingDocs as $d): ?><li>Documento: <?= htmlspecialchars($d) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <form class="card" action="expediente_guardar.php" method="post" novalidate>
    <h3 class="title">Datos personales (puedes guardar aunque falten)</h3>
    <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">

    <div class="grid">
      <div>
        <label>Tel. de contacto</label>
        <input type="tel" name="tel_contacto" value="<?= htmlspecialchars($exp['tel_contacto'] ?? '') ?>">
      </div>
      <div>
        <label>Contacto de emergencia</label>
        <input type="text" name="contacto_emergencia" value="<?= htmlspecialchars($exp['contacto_emergencia'] ?? '') ?>">
      </div>
      <div>
        <label>Tel. de emergencia</label>
        <input type="tel" name="tel_emergencia" value="<?= htmlspecialchars($exp['tel_emergencia'] ?? '') ?>">
      </div>
      <div>
        <label>Género</label>
        <select name="genero">
          <?php $g = $exp['genero'] ?? ''; ?>
          <option value="">Seleccionar…</option>
          <option value="M" <?= $g==='M'?'selected':'' ?>>Masculino</option>
          <option value="F" <?= $g==='F'?'selected':'' ?>>Femenino</option>
          <option value="Otro" <?= $g==='Otro'?'selected':'' ?>>Otro</option>
        </select>
      </div>
      <div>
        <label>Fecha de nacimiento</label>
        <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($exp['fecha_nacimiento'] ?? '') ?>">
      </div>
      <div>
        <label>Edad (años)</label>
        <input type="text" value="<?= htmlspecialchars($edad) ?>" class="readonly" readonly>
      </div>
      <div>
        <label>Fecha de ingreso</label>
        <input type="date" name="fecha_ingreso" value="<?= htmlspecialchars($exp['fecha_ingreso'] ?? '') ?>">
      </div>
      <div>
        <label>Antigüedad</label>
        <input type="text" value="<?= htmlspecialchars($ant_texto) ?>" class="readonly" readonly>
      </div>
      <div>
        <label>CURP</label>
        <input type="text" name="curp" maxlength="18" style="text-transform:uppercase"
               value="<?= htmlspecialchars($exp['curp'] ?? '') ?>"
               pattern="^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM](AS|BC|BS|CC|CS|CH|CL|CM|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$"
               title="CURP en formato válido (ej. GAXX800101HDFRRN09)">
      </div>
      <div>
        <label>NSS (IMSS)</label>
        <input type="text" name="nss" maxlength="11" pattern="\d{11}" placeholder="11 dígitos"
               value="<?= htmlspecialchars($exp['nss'] ?? '') ?>" title="NSS de 11 dígitos">
      </div>
      <div>
        <label>RFC</label>
        <input type="text" name="rfc" maxlength="13" style="text-transform:uppercase"
               value="<?= htmlspecialchars($exp['rfc'] ?? '') ?>"
               pattern="^([A-ZÑ&]{3}|[A-ZÑ&]{4})\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[A-Z0-9]{3}$"
               title="RFC válido con fecha (ej. ABC800101XXX o ABCD800101XXX)">
      </div>

      <!-- Aceptar 16 o 18 dígitos (Tarjeta o CLABE) -->
      <div>
        <label>CLABE o Tarjeta (16/18)</label>
        <input
          type="text"
          name="clabe"
          maxlength="18"
          inputmode="numeric"
          pattern="^\d{16}(\d{2})?$"
          placeholder="Ingresa 16 (tarjeta) o 18 dígitos (CLABE)"
          value="<?= htmlspecialchars($exp['clabe'] ?? '') ?>"
          title="Ingresa exactamente 16 o 18 dígitos">
      </div>

      <div>
        <label>Banco (institución)</label>
        <input type="text" name="banco" maxlength="80" placeholder="Ej. BBVA, Santander, Banorte…"
               value="<?= htmlspecialchars($exp['banco'] ?? '') ?>">
      </div>
    </div>
    <div style="margin-top:12px">
      <button class="btn btn-primary" type="submit">Guardar (continuar después)</button>
      <span class="muted">Tu progreso se guardará aunque falten datos o documentos.</span>
    </div>
  </form>

  <div class="card" id="docs">
    <h3 class="title">Documentos requeridos</h3>
    <p class="muted">Formatos permitidos: PDF, JPG, PNG. Límite <?= $maxMB ?> MB.</p>

    <?php foreach ($reqDocs as $t):
      $docVigente = $t['doc_id_vigente'] ? (int)$t['doc_id_vigente'] : null;
    ?>
      <div class="doc-row" id="doc-<?= (int)$t['id'] ?>">
        <div class="doc-info">
          <strong><?= htmlspecialchars($t['nombre']) ?></strong><br>
          <span class="muted"><?= $docVigente ? 'Subido' : 'Pendiente' ?><?= $t['version'] ? ' (v'.$t['version'].')':'' ?></span>
        </div>

        <?php if ($docVigente): ?>
          <a class="btn" target="_blank" href="documento_descargar.php?id=<?= $docVigente ?>">Ver vigente</a>
        <?php endif; ?>

        <form class="upload js-upload" action="documento_subir.php" method="post" enctype="multipart/form-data">
          <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">
          <input type="hidden" name="doc_tipo_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="return_to" value="mi_expediente.php#doc-<?= (int)$t['id'] ?>">

          <span class="file">
            <span class="file-label">Elegir archivo</span>
            <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png">
          </span>

          <span class="file-name" data-placeholder="Ningún archivo elegido">Ningún archivo elegido</span>
          <button class="btn btn-secondary btn-clear" type="button" disabled>Quitar</button>
          <button class="btn btn-success" type="submit" disabled>Subir</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.querySelectorAll('.js-upload').forEach(function(form){
  const row    = form.closest('.doc-row');
  const input  = form.querySelector('input[type="file"]');
  const nameEl = form.querySelector('.file-name');
  const btnUp  = form.querySelector('button[type="submit"]');
  const btnClr = form.querySelector('.btn-clear');
  const label  = form.querySelector('.file-label');

  label.addEventListener('click', () => input.click());

  function resetState(){
    nameEl.textContent = nameEl.dataset.placeholder || 'Ningún archivo elegido';
    btnUp.disabled = true;
    btnClr.disabled = true;
    row.classList.remove('ready');
  }

  input.addEventListener('change', () => {
    if (input.files && input.files.length) {
      nameEl.textContent = input.files[0].name;
      btnUp.disabled = false;
      btnClr.disabled = false;
      row.classList.add('ready');
    } else {
      resetState();
    }
  });

  btnClr.addEventListener('click', () => {
    input.value = '';
    const ev = new Event('change');
    input.dispatchEvent(ev);
  });
});
</script>
</body>
</html>
