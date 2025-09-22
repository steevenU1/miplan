<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

/* ==== Includes con fallback ==== */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) require_once __DIR__ . '/includes/docs_lib.php';
else require_once __DIR__ . '/docs_lib.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* ==== Contexto ==== */
$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';

$usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : $mi_id;
if ($usuario_id <= 0) { $usuario_id = $mi_id; }

/* ==== Datos ==== */
$tipos = list_doc_types_with_status($conn, $usuario_id);
$maxMB = defined('DOCS_MAX_SIZE') ? (int)(DOCS_MAX_SIZE/1024/1024) : 10;

/* Nombre del usuario (opcional para cabecera) */
$usuario_nombre = '';
$stmt = $conn->prepare("SELECT nombre FROM usuarios WHERE id=?");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
if ($rs = $stmt->get_result()) { if ($row = $rs->fetch_assoc()) $usuario_nombre = $row['nombre']; }
$stmt->close();

/* Foto actual (desde usuarios_expediente) */
$foto_actual = null;
$stmt = $conn->prepare("SELECT foto FROM usuarios_expediente WHERE usuario_id=? LIMIT 1");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
if ($rs = $stmt->get_result()) { if ($row = $rs->fetch_assoc()) $foto_actual = $row['foto']; }
$stmt->close();

/* Progreso requeridos */
$totalReq = 0; $uploadedReq = 0;
foreach ($tipos as $t) {
  if ((int)$t['requerido'] === 1) {
    $totalReq++;
    if (!empty($t['doc_id_vigente'])) $uploadedReq++;
  }
}
$pct = $totalReq ? floor(($uploadedReq/$totalReq)*100) : 0;

/* Permisos */
function puede_subir(string $rol, int $mi_id, int $usuario_id): bool {
  return in_array($rol, ['Admin','Gerente'], true) || ($mi_id === $usuario_id);
}
$esAdmin = in_array($mi_rol, ['Admin','Super'], true);

/* Mensajes */
$ok       = !empty($_GET['ok']) || !empty($_GET['ok_doc']);
$err      = !empty($_GET['err']) ? $_GET['err'] : '';
$errDoc   = !empty($_GET['err_doc']) ? $_GET['err_doc'] : '';
$ok_foto  = !empty($_GET['ok_foto']);
$err_foto = !empty($_GET['err_foto']) ? $_GET['err_foto'] : '';

/* Parámetros foto */
$maxFotoMB = 5;
$placeholder = 'https://ui-avatars.com/api/?name='.urlencode($usuario_nombre ?: 'Usuario').'&background=random&bold=true';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Expediente: Documentos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--bg:#f8fafc;
      --ok:#16a34a;--pri:#2563eb;
    }
    /* FIX navbar: sin margen en body; margen/padding en el container */
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
    .container{max-width:1100px;margin:16px auto;padding:0 12px}

    .title{margin:10px 0 4px;font-weight:800;font-size:28px}
    .sub{color:var(--muted);margin:0 0 14px}
    .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:14px}

    /* bloque foto */
    .pfp{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
    .pfp img{width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid var(--line);background:#fff}
    .pfp .actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .pfp .file input[type="file"]{display:none}
    .pfp .file .file-label{display:inline-block;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
    .pfp .file-name{font-size:12px;color:#111;padding:4px 10px;border-radius:999px;background:#edf2f7;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

    /* progreso */
    .progress{background:#eef2f7;height:12px;border-radius:999px;overflow:hidden}
    .bar{height:100%;background:var(--ok)}

    /* lista documentos en tarjetas */
    .doc-list{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
    .doc{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line)}
    .doc:last-child{border-bottom:none}
    .doc-left{min-width:260px}
    .doc-title{font-weight:600}
    .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
    .chip{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid}
    .req{background:#eef2ff;border-color:#c7d2fe;color:#1e3a8a}
    .opt{background:#fdf2f8;border-color:#fbcfe8;color:#9d174d}
    .ok{background:#dcfce7;border-color:#bbf7d0;color:#14532d}
    .pend{background:#f1f5f9;border-color:#e2e8f0;color:#334155}
    .ver{background:#f0f9ff;border-color:#bae6fd;color:#075985}

    .doc-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;min-width:420px}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;text-decoration:none;color:#111;cursor:pointer}
    .btn:hover{background:#f5f7fb}
    .btn-primary{background:var(--pri);border-color:var(--pri);color:#fff}
    .btn-success{background:var(--ok);border-color:var(--ok);color:#fff}
    .btn-secondary{background:#475569;border-color:#475569;color:#fff}
    .btn-danger{background:#dc2626;border-color:#dc2626;color:#fff}
    .btn-danger:hover{filter:brightness(0.95)}
    .btn:disabled{opacity:.6;cursor:not-allowed}

    /* uploader */
    .uploader{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .file input[type="file"]{display:none}
    .file .file-label{display:inline-block;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
    .file-name{font-size:12px;color:#111;padding:4px 10px;border-radius:999px;background:#edf2f7;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .doc.ready{background:#f0fff4}
    .doc.ready .file-name{background:#c6f6d5}

    /* alerts */
    .alert{padding:10px;border-radius:10px;margin-bottom:10px}
    .alert-ok{background:#ecfeff;border:1px solid #a5f3fc;color:#155e75}
    .alert-err{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}

    @media (max-width:980px){
      .doc-right{min-width:0;justify-content:flex-start}
      .container{padding:0 8px}
    }
  </style>
</head>
<body>
<div class="container">
  <h1 class="title">Expediente: Documentos</h1>
  <p class="sub">
    <?= $usuario_nombre ? htmlspecialchars($usuario_nombre) . " · " : "" ?>
    Usuario #<?= (int)$usuario_id ?> · Requeridos: <strong><?= $uploadedReq ?>/<?= $totalReq ?></strong>
  </p>

  <?php if ($ok): ?><div class="alert alert-ok">Documento subido/borrado correctamente.</div><?php endif; ?>
  <?php if ($err || $errDoc): ?><div class="alert alert-err"><?= htmlspecialchars($err ?: $errDoc) ?></div><?php endif; ?>
  <?php if ($ok_foto): ?><div class="alert alert-ok">Foto actualizada correctamente.</div><?php endif; ?>
  <?php if ($err_foto): ?><div class="alert alert-err"><?= htmlspecialchars($err_foto) ?></div><?php endif; ?>

  <!-- Bloque FOTO -->
  <div class="card">
    <div class="pfp">
      <img id="pfp-img" src="<?= htmlspecialchars($foto_actual ?: $placeholder) ?>" alt="Foto del usuario">
      <div>
        <div style="font-weight:700;margin-bottom:6px">Foto del usuario</div>
        <div class="sub" style="margin:0 0 10px">Formatos: JPG/PNG/WebP · Límite <?= (int)$maxFotoMB ?>MB</div>

        <?php if (puede_subir($mi_rol, $mi_id, $usuario_id)): ?>
          <form class="actions" action="expediente_subir_foto.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?usuario_id='.$usuario_id) ?>">
            <span class="file">
              <label class="file-label" for="foto">Elegir foto…</label>
              <input id="foto" type="file" name="foto" accept="image/*">
            </span>
            <span class="file-name" id="foto-name">No se ha seleccionado archivo</span>
            <button class="btn btn-success" id="btn-foto" type="submit" disabled>Guardar</button>
            <button class="btn btn-secondary" id="btn-foto-clear" type="button" disabled>Quitar</button>
          </form>
        <?php else: ?>
          <div class="sub" style="margin:0">No tienes permisos para actualizar la foto.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Progreso -->
  <div class="card">
    <div class="progress" aria-label="Progreso documentos requeridos">
      <div class="bar" style="width: <?= $pct ?>%"></div>
    </div>
    <div class="sub" style="margin-top:6px"><?= $pct ?>% completo de documentos requeridos</div>
  </div>

  <!-- Lista de documentos -->
  <div class="doc-list">
    <?php foreach ($tipos as $t):
      $docTipoId  = (int)$t['id'];
      $docVigente = $t['doc_id_vigente'] ? (int)$t['doc_id_vigente'] : null;
      $isReq      = (int)$t['requerido'] === 1;
      $puedeVer   = user_can_view_doc_type($conn, $mi_rol, $docTipoId) || ($usuario_id === $mi_id);
      $puedeSubir = puede_subir($mi_rol, $mi_id, $usuario_id);
      $return_to  = "documentos_historial.php?usuario_id={$usuario_id}#doc-{$docTipoId}";
    ?>
      <div class="doc" id="doc-<?= $docTipoId ?>">
        <div class="doc-left">
          <div class="doc-title"><?= htmlspecialchars($t['nombre']) ?></div>
          <div class="chips">
            <?= $isReq ? '<span class="chip req">Requerido</span>' : '<span class="chip opt">Opcional</span>' ?>
            <?= $docVigente ? '<span class="chip ok">Subido</span>' : '<span class="chip pend">Pendiente</span>' ?>
            <span class="chip ver"><?= $t['version'] ? ('v'.(int)$t['version']) : 'v—' ?></span>
          </div>
        </div>

        <div class="doc-right">
          <?php if ($docVigente && $puedeVer): ?>
            <a class="btn btn-primary" target="_blank"
               href="documento_descargar.php?id=<?= $docVigente ?>">Ver</a>
          <?php endif; ?>

          <?php if ($docVigente && $esAdmin): ?>
            <form action="documento_borrar.php" method="post"
                  onsubmit="return confirm('¿Seguro que deseas borrar este documento?');"
                  style="display:inline">
              <input type="hidden" name="doc_id" value="<?= $docVigente ?>">
              <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">
              <button type="submit" class="btn btn-danger">Borrar</button>
            </form>
          <?php endif; ?>

          <?php if ($puedeSubir): ?>
            <form class="uploader js-upload" action="documento_subir.php" method="post" enctype="multipart/form-data">
              <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">
              <input type="hidden" name="doc_tipo_id" value="<?= $docTipoId ?>">
              <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">

              <span class="file">
                <span class="file-label">Elegir archivo</span>
                <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png">
              </span>

              <span class="file-name" data-placeholder="No se ha seleccionado archivo">No se ha seleccionado archivo</span>
              <button class="btn btn-secondary btn-clear" type="button" disabled>Quitar</button>
              <button class="btn btn-success" type="submit" disabled>Subir</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="sub">
    Tipos permitidos: PDF, JPG, PNG. Límite <?= $maxMB ?> MB.
    <?php if ($usuario_id !== $mi_id): ?> | Viendo el expediente del usuario #<?= (int)$usuario_id ?><?php endif; ?>
  </p>
</div>

<script>
/* UX uploader documentos */
document.querySelectorAll('.js-upload').forEach(function(form){
  const row    = form.closest('.doc');
  const input  = form.querySelector('input[type="file"]');
  const nameEl = form.querySelector('.file-name');
  const btnUp  = form.querySelector('button[type="submit"]');
  const btnClr = form.querySelector('.btn-clear');
  const label  = form.querySelector('.file-label');

  label.addEventListener('click', () => input.click());

  function resetState(){
    nameEl.textContent = nameEl.dataset.placeholder || 'No se ha seleccionado archivo';
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
    input.dispatchEvent(new Event('change'));
  });
});

/* UX foto: preview + habilitar */
(function(){
  const file = document.getElementById('foto');
  if (!file) return;
  const img  = document.getElementById('pfp-img');
  const name = document.getElementById('foto-name');
  const btn  = document.getElementById('btn-foto');
  const clr  = document.getElementById('btn-foto-clear');

  function reset(){
    name.textContent = 'No se ha seleccionado archivo';
    btn.disabled = true;
    clr.disabled = true;
  }

  file.addEventListener('change', () => {
    if (file.files && file.files[0]) {
      name.textContent = file.files[0].name;
      img.src = URL.createObjectURL(file.files[0]);
      btn.disabled = false;
      clr.disabled = false;
    } else {
      reset();
    }
  });

  clr?.addEventListener('click', () => {
    file.value = '';
    reset();
    // Opcional: volver a la imagen anterior (no guardada)
    // location.reload();
  });
})();
</script>
</body>
</html>
