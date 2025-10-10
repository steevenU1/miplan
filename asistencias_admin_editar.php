<?php
/* asistencias_admin.php
   Listado + edición de asistencias (Admin) en un solo archivo
*/
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Admin'])) {
    header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

/* =========================
   Helpers
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function validTime($t){ return $t === '' || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t); }
function validDate($d){ return $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
if (!function_exists('normTime')) {
  function normTime($t){
    $t = trim((string)$t);
    if ($t === '') return '';
    if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t.':00';      // HH:MM -> HH:MM:00
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;       // HH:MM:SS ok
    return $t;
  }
}

/* =========================
   Token anti doble-submit
========================= */
if (empty($_SESSION['a_token'])) {
    $_SESSION['a_token'] = bin2hex(random_bytes(16));
}
$TOKEN = $_SESSION['a_token'];

/* =========================
   Guardar edición (POST)
========================= */
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['token'] ?? '') === $TOKEN) {

  $id          = (int)($_POST['id'] ?? 0);
  $id_usuario  = (int)($_POST['id_usuario'] ?? 0);
  $id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
  $fecha       = trim($_POST['fecha'] ?? '');

  // Horas crudas y normalizadas a HH:MM:SS
  $hora_entrada_raw = trim($_POST['hora_entrada'] ?? '');
  $hora_salida_raw  = trim($_POST['hora_salida'] ?? '');
  $hora_entrada = ($hora_entrada_raw === '') ? '' : normTime($hora_entrada_raw);
  $hora_salida  = ($hora_salida_raw  === '') ? '' : normTime($hora_salida_raw);

  $retardo     = isset($_POST['retardo']) ? 1 : 0;
  $retardo_min = (int)($_POST['retardo_minutos'] ?? 0);
  $duracion_min= trim($_POST['duracion_minutos'] ?? '');
  $latitud     = trim($_POST['latitud'] ?? '');
  $longitud    = trim($_POST['longitud'] ?? '');
  $ip          = trim($_POST['ip'] ?? '');
  $metodo      = $_POST['metodo'] ?? 'web';

  // Validaciones
  $errs = [];
  if ($id <= 0)                       $errs[] = "ID inválido.";
  if (!validDate($fecha))             $errs[] = "Fecha inválida (YYYY-MM-DD).";
  if ($hora_entrada_raw === '')       $errs[] = "Hora de entrada requerida.";
  elseif (!validTime($hora_entrada))  $errs[] = "Hora de entrada inválida (HH:MM).";
  if ($hora_salida_raw !== '' && !validTime($hora_salida)) $errs[] = "Hora de salida inválida (HH:MM).";
  if (!in_array($metodo, ['web','movil'], true)) $errs[] = "Método inválido.";

  // Consistencia retardo
  if ($retardo_min > 0) { $retardo = 1; }
  if ($retardo === 0)   { $retardo_min = 0; }

  // Recalcular duración si está vacía y hay horas válidas
  if ($duracion_min === '' && $hora_entrada !== '' && $hora_salida !== '') {
    $h1 = strtotime("1970-01-01 {$hora_entrada} UTC");
    $h2 = strtotime("1970-01-01 {$hora_salida} UTC");
    if ($h1 !== false && $h2 !== false && $h2 >= $h1) {
      $duracion_min = (string)(($h2 - $h1) / 60);
    }
  }

  // ---- Adaptación TIME vs DATETIME/TIMESTAMP ----
  // Si las columnas son DATETIME/TIMESTAMP, concatenamos fecha + hora
  $isEntradaDT = false; $isSalidaDT = false;
  if ($res = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'hora_entrada'")) {
    if ($col = $res->fetch_assoc()) {
      $t = strtolower($col['Type'] ?? '');
      $isEntradaDT = (strpos($t, 'datetime') !== false) || (strpos($t, 'timestamp') !== false);
    }
    $res->free();
  }
  if ($res = $conn->query("SHOW COLUMNS FROM asistencias LIKE 'hora_salida'")) {
    if ($col = $res->fetch_assoc()) {
      $t = strtolower($col['Type'] ?? '');
      $isSalidaDT = (strpos($t, 'datetime') !== false) || (strpos($t, 'timestamp') !== false);
    }
    $res->free();
  }
  $val_hora_entrada = $isEntradaDT ? ($fecha . ' ' . $hora_entrada) : $hora_entrada;
  $val_hora_salida  = ($hora_salida === '') ? '' : ($isSalidaDT ? ($fecha . ' ' . $hora_salida) : $hora_salida);
  // ------------------------------------------------

  if (!$errs) {
    // '' -> NULL con NULLIF en campos opcionales
    $sql = "UPDATE asistencias
            SET id_usuario=?,
                id_sucursal=?,
                fecha=?,
                hora_entrada=?,
                retardo=?,
                retardo_minutos=?,
                hora_salida=NULLIF(?, ''),
                duracion_minutos=NULLIF(?, ''),
                latitud=NULLIF(?, ''),
                longitud=NULLIF(?, ''),
                ip=NULLIF(?, ''),
                metodo=?
            WHERE id=?";

    if ($st = $conn->prepare($sql)) {
      // 13 tipos exactos (sin espacios)
      $types = 'iissii' . str_repeat('s', 6) . 'i'; // i i s s i i s s s s s s i
      $st->bind_param(
        $types,
        $id_usuario,
        $id_sucursal,
        $fecha,
        $val_hora_entrada, // TIME -> HH:MM:SS  | DATETIME -> YYYY-MM-DD HH:MM:SS
        $retardo,
        $retardo_min,
        $val_hora_salida,  // '' -> NULL (NULLIF)
        $duracion_min,
        $latitud,
        $longitud,
        $ip,
        $metodo,
        $id
      );
      if ($st->execute()) $flash = "✅ Asistencia #{$id} actualizada.";
      else                $flash = "❌ Error al guardar: ".$st->error;
      $st->close();
    } else {
      $flash = "❌ Error de preparación: ".$conn->error;
    }
  } else {
    $flash = "❌ ".implode(' ', $errs);
  }
}

/* =========================
   Catálogos (usuarios/sucursales)
========================= */
$usuarios = [];
$resU = $conn->query("SELECT id, nombre FROM usuarios WHERE activo=1 ORDER BY nombre ASC");
if ($resU) { while ($r = $resU->fetch_assoc()) { $usuarios[(int)$r['id']] = $r['nombre']; } }

$sucursales = [];
$resS = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");
if ($resS) { while ($r = $resS->fetch_assoc()) { $sucursales[(int)$r['id']] = $r['nombre']; } }

/* =========================
   Filtros y consulta
========================= */
$fil_u = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$fil_s = isset($_GET['s']) ? (int)$_GET['s'] : 0;
$f1     = trim($_GET['f1'] ?? date('Y-m-01'));
$f2     = trim($_GET['f2'] ?? date('Y-m-d'));
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types  = '';

if ($fil_u > 0) { $where[] = "a.id_usuario = ?";  $types .= 'i'; $params[] = $fil_u; }
if ($fil_s > 0) { $where[] = "a.id_sucursal = ?"; $types .= 'i'; $params[] = $fil_s; }
if (validDate($f1) && validDate($f2)) {
    $where[] = "a.fecha BETWEEN ? AND ?";
    $types .= 'ss'; $params[] = $f1; $params[] = $f2;
}
$wSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* Conteo para paginación */
$total = 0;
$sqlCount = "SELECT COUNT(*) FROM asistencias a $wSQL";
if ($st = $conn->prepare($sqlCount)) {
    if ($types) { $st->bind_param($types, ...$params); }
    $st->execute(); $st->bind_result($total); $st->fetch(); $st->close();
}

/* Datos (normaliza HH:MM) */
$sql = "
SELECT
  a.*,
  DATE_FORMAT(a.hora_entrada, '%H:%i') AS hora_entrada_fmt,
  DATE_FORMAT(a.hora_salida,  '%H:%i') AS hora_salida_fmt,
  u.nombre AS usuario,
  s.nombre AS sucursal
FROM asistencias a
LEFT JOIN usuarios   u ON u.id = a.id_usuario
LEFT JOIN sucursales s ON s.id = a.id_sucursal
$wSQL
ORDER BY a.fecha DESC, a.hora_entrada DESC, a.id DESC
LIMIT ? OFFSET ?
";
$typesData   = $types . 'ii';
$paramsData  = $params;
$paramsData[] = $limit;
$paramsData[] = $offset;

$rows = [];
if ($st = $conn->prepare($sql)) {
    $st->bind_param($typesData, ...$paramsData);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['hora_entrada'] = $r['hora_entrada_fmt'] ?? (isset($r['hora_entrada']) ? substr((string)$r['hora_entrada'],0,5) : '');
        $r['hora_salida']  = $r['hora_salida_fmt']  ?? (isset($r['hora_salida'])  ? substr((string)$r['hora_salida'], 0,5) : '');
        $rows[] = $r;
    }
    $st->close();
}

$pages = max(1, (int)ceil($total / $limit));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Asistencias (Admin)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding:16px;}
  .wrap{max-width:1200px; margin:0 auto;}
  .filters{display:grid; grid-template-columns:1fr 1fr 1fr 1fr 1fr; gap:8px; align-items:end; margin:12px 0 16px;}
  label{display:block; font-size:12px; color:#555; margin-bottom:4px;}
  input, select{width:100%; padding:8px; border:1px solid #ccc; border-radius:8px;}
  table{width:100%; border-collapse:collapse; margin-top:8px;}
  th, td{border-bottom:1px solid #eee; padding:8px; font-size:14px; vertical-align:top;}
  th{background:#fafafa; position:sticky; top:0; z-index:1;}
  .badge{display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px;}
  .ok{background:#e6ffed; color:#087443; border:1px solid #b7f5c8;}
  .warn{background:#fff6e5; color:#8a5b00; border:1px solid #ffe2b3;}
  .err{background:#ffe8e8; color:#8a0000; border:1px solid #ffb3b3;}
  .btn{padding:6px 10px; border:1px solid #ddd; border-radius:8px; background:#fff; cursor:pointer;}
  .btn:hover{background:#f5f5f5;}
  .btn.primary{background:#111827; color:#fff; border-color:#111827;}
  .flex{display:flex; gap:8px; align-items:center;}
  .right{margin-left:auto;}
  .center{text-align:center;}
  .pagination{display:flex; gap:6px; justify-content:center; margin:14px 0;}
  dialog{border:none; border-radius:16px; width:920px; max-width:95vw; padding:0;}
  .modal-header{padding:12px 16px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;}
  .modal-body{padding:16px;}
  .grid2{display:grid; grid-template-columns:1.2fr .8fr; gap:16px;}
  .grid3{display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;}
  .muted{color:#777; font-size:12px;}
</style>
</head>
<body>
<div class="wrap">
  <h2>Asistencias — Admin</h2>

  <?php if (!empty($flash)): ?>
    <div class="badge <?php echo strpos($flash,'✅')!==false?'ok':'err'; ?>"><?php echo h($flash); ?></div>
  <?php endif; ?>

  <form class="filters" method="get">
    <div>
      <label>Usuario</label>
      <select name="u">
        <option value="0">— Todos —</option>
        <?php foreach ($usuarios as $id=>$nom): ?>
          <option value="<?php echo $id; ?>" <?php if ($fil_u===$id) echo 'selected'; ?>><?php echo h($nom); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Sucursal</label>
      <select name="s">
        <option value="0">— Todas —</option>
        <?php foreach ($sucursales as $id=>$nom): ?>
          <option value="<?php echo $id; ?>" <?php if ($fil_s===$id) echo 'selected'; ?>><?php echo h($nom); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Desde</label>
      <input type="date" name="f1" value="<?php echo h($f1); ?>">
    </div>
    <div>
      <label>Hasta</label>
      <input type="date" name="f2" value="<?php echo h($f2); ?>">
    </div>
    <div>
      <button class="btn primary" type="submit">Aplicar</button>
    </div>
  </form>

  <div class="flex">
    <div class="muted">
      Registros: <?php echo number_format($total); ?> &middot; Página <?php echo $page; ?> de <?php echo $pages; ?>
    </div>
    <div class="right"></div>
  </div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Fecha</th>
        <th>Usuario</th>
        <th>Sucursal</th>
        <th>Entrada</th>
        <th>Retardo</th>
        <th>Salida</th>
        <th>Duración (min)</th>
        <th>Método</th>
        <th>IP</th>
        <th>Geo</th>
        <th class="center">Acción</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" class="center">Sin resultados.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>#<?php echo (int)$r['id']; ?></td>
          <td><?php echo h($r['fecha']); ?></td>
          <td><?php echo h($r['usuario'] ?: ("ID ".$r['id_usuario'])); ?></td>
          <td><?php echo h($r['sucursal'] ?: ("ID ".$r['id_sucursal'])); ?></td>
          <td><?php echo h($r['hora_entrada']); ?></td>
          <td>
            <?php if ((int)$r['retardo']===1): ?>
              <span class="badge warn">Sí (<?php echo (int)$r['retardo_minutos']; ?>m)</span>
            <?php else: ?>
              <span class="badge ok">No</span>
            <?php endif; ?>
          </td>
          <td><?php echo h($r['hora_salida']); ?></td>
          <td><?php echo h($r['duracion_minutos']); ?></td>
          <td><?php echo h($r['metodo']); ?></td>
          <td><?php echo h($r['ip']); ?></td>
          <td><?php echo h($r['latitud']).", ".h($r['longitud']); ?></td>
          <td class="center">
            <button class="btn" onclick="openEdit(<?php echo (int)$r['id']; ?>)">Editar</button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="pagination">
    <?php if ($page>1): ?>
      <a class="btn" href="?<?php echo h(http_build_query(['u'=>$fil_u,'s'=>$fil_s,'f1'=>$f1,'f2'=>$f2,'p'=>$page-1])); ?>">&laquo; Anterior</a>
    <?php endif; ?>
    <?php if ($page<$pages): ?>
      <a class="btn" href="?<?php echo h(http_build_query(['u'=>$fil_u,'s'=>$fil_s,'f1'=>$f1,'f2'=>$f2,'p'=>$page+1])); ?>">Siguiente &raquo;</a>
    <?php endif; ?>
  </div>
</div>

<!-- Modal de edición -->
<dialog id="dlg">
  <div class="modal-header">
    <strong>Editar asistencia</strong>
    <button class="btn" type="button" onclick="document.getElementById('dlg').close()">Cerrar</button>
  </div>

  <form class="modal-body" method="post">
    <input type="hidden" name="token" value="<?php echo h($TOKEN); ?>">
    <input type="hidden" name="id" id="f_id">

    <div class="grid2" style="align-items:start;">
      <!-- Columna izquierda: Formulario -->
      <div>
        <div class="grid3">
          <div>
            <label>Usuario</label>
            <select name="id_usuario" id="f_id_usuario" required>
              <?php foreach ($usuarios as $id=>$nom): ?>
                <option value="<?php echo $id; ?>"><?php echo h($nom); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Sucursal</label>
            <select name="id_sucursal" id="f_id_sucursal" required>
              <?php foreach ($sucursales as $id=>$nom): ?>
                <option value="<?php echo $id; ?>"><?php echo h($nom); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Fecha</label>
            <input type="date" name="fecha" id="f_fecha" required>
          </div>
        </div>

        <div class="grid3" style="margin-top:8px;">
          <div>
            <label>Hora entrada</label>
            <input type="time" name="hora_entrada" id="f_hora_entrada">
          </div>
          <div>
            <label>Hora salida</label>
            <input type="time" name="hora_salida" id="f_hora_salida">
          </div>
          <div>
            <label>Duración (min) <span class="muted">(vacío = recalcular)</span></label>
            <input type="number" name="duracion_minutos" id="f_duracion_minutos" min="0" step="1" placeholder="Auto">
          </div>
        </div>

        <div class="grid3" style="margin-top:8px;">
          <div class="flex">
            <label style="margin-right:8px;">Retardo</label>
            <input type="checkbox" name="retardo" id="f_retardo">
          </div>
          <div>
            <label>Retardo (min)</label>
            <input type="number" name="retardo_minutos" id="f_retardo_minutos" min="0" step="1" value="0">
          </div>
          <div>
            <label>Método</label>
            <select name="metodo" id="f_metodo">
              <option value="web">web</option>
              <option value="movil">movil</option>
            </select>
          </div>
        </div>

        <div class="grid3" style="margin-top:8px;">
          <div>
            <label>Latitud</label>
            <input type="text" name="latitud" id="f_latitud" placeholder="19.4326">
          </div>
          <div>
            <label>Longitud</label>
            <input type="text" name="longitud" id="f_longitud" placeholder="-99.1332">
          </div>
          <div>
            <label>IP</label>
            <input type="text" name="ip" id="f_ip" maxlength="45">
          </div>
        </div>

        <div class="flex" style="margin-top:14px;">
          <div class="muted">* <em>creado_en</em> y <em>actualizado_en</em> se manejan en BD.</div>
          <div class="right">
            <button type="submit" class="btn primary">Guardar cambios</button>
          </div>
        </div>
      </div>

      <!-- Columna derecha: Previsualización/calculos -->
      <div style="border:1px solid #eee; border-radius:12px; padding:12px;">
        <div style="font-weight:600; margin-bottom:8px;">Previsualización</div>
        <div class="muted" style="margin-bottom:8px">Comparación de valores y duración calculada</div>

        <div style="font-size:13px; line-height:1.4;">
          <div><strong>ID:</strong> <span id="pv_id">—</span></div>
          <div><strong>Usuario:</strong> <span id="pv_usuario">—</span></div>
          <div><strong>Sucursal:</strong> <span id="pv_sucursal">—</span></div>
          <hr>

          <div><strong>Fecha:</strong> <span id="pv_fecha_ori" class="muted">—</span> ➜ <span id="pv_fecha_new">—</span></div>
          <div><strong>Entrada:</strong> <span id="pv_ent_ori" class="muted">—</span> ➜ <span id="pv_ent_new">—</span></div>
          <div><strong>Salida:</strong> <span id="pv_sal_ori" class="muted">—</span> ➜ <span id="pv_sal_new">—</span></div>

          <div style="margin-top:6px;">
            <strong>Duración (min):</strong>
            <span id="pv_dur_ori" class="muted">—</span> ➜
            <span id="pv_dur_new">—</span>
            <span id="pv_dur_auto" class="badge warn" style="display:none; margin-left:6px;">Auto</span>
          </div>

          <div style="margin-top:6px;">
            <strong>Retardo:</strong>
            <span id="pv_ret_ori" class="muted">—</span> ➜
            <span id="pv_ret_new">—</span>
            <span>(<span id="pv_retmin_new">0</span> min)</span>
          </div>

          <div id="pv_warn" class="badge err" style="display:none; margin-top:8px;">Revisa horas: salida &lt; entrada</div>
        </div>
      </div>
    </div>
  </form>
</dialog>

<script>
  // ====== Datos enviados desde PHP, normalizados HH:MM ======
  const data = <?php
    $jsRows = array_map(function($r) {
      $hEnt = isset($r['hora_entrada']) && $r['hora_entrada'] !== null ? substr((string)$r['hora_entrada'], 0, 5) : '';
      $hSal = isset($r['hora_salida'])  && $r['hora_salida']  !== null ? substr((string)$r['hora_salida'],  0, 5) : '';
      return [
        'id'               => (int)$r['id'],
        'id_usuario'       => (int)$r['id_usuario'],
        'id_sucursal'      => (int)$r['id_sucursal'],
        'usuario'          => $r['usuario']  ?? '',
        'sucursal'         => $r['sucursal'] ?? '',
        'fecha'            => (string)($r['fecha'] ?? ''),
        'hora_entrada'     => $hEnt,
        'hora_salida'      => $hSal,
        'duracion_minutos' => isset($r['duracion_minutos']) && $r['duracion_minutos'] !== null ? (int)$r['duracion_minutos'] : null,
        'retardo'          => (int)($r['retardo'] ?? 0),
        'retardo_minutos'  => isset($r['retardo_minutos']) && $r['retardo_minutos'] !== null ? (int)$r['retardo_minutos'] : 0,
        'latitud'          => $r['latitud']  ?? '',
        'longitud'         => $r['longitud'] ?? '',
        'ip'               => $r['ip']       ?? '',
        'metodo'           => $r['metodo']   ?? 'web',
      ];
    }, $rows);
    echo json_encode($jsRows, JSON_UNESCAPED_UNICODE);
  ?>;

  const dlg = document.getElementById('dlg');
  let currentRow = null;

  const $     = (id) => document.getElementById(id);
  const setTxt= (id, v) => { const el = $(id); if (el) el.textContent = (v ?? '—'); };
  const setVal= (id, v) => { const el = $(id); if (el) el.value = (v ?? ''); };
  const setChk= (id, v) => { const el = $(id); if (el) el.checked = !!v; };

  function parseHM(hhmm) {
    if (!hhmm) return null;
    const m = /^(\d{2}):(\d{2})/.exec(hhmm);
    if (!m) return null;
    return parseInt(m[1],10)*60 + parseInt(m[2],10);
  }

  function calcPreview() {
    if (!currentRow) return;

    setTxt('pv_id', currentRow.id);
    setTxt('pv_usuario', currentRow.usuario || ('ID ' + currentRow.id_usuario));
    setTxt('pv_sucursal', currentRow.sucursal || ('ID ' + currentRow.id_sucursal));

    // Originales
    setTxt('pv_fecha_ori', currentRow.fecha || '—');
    setTxt('pv_ent_ori', currentRow.hora_entrada || '—');
    setTxt('pv_sal_ori', currentRow.hora_salida  || '—');
    setTxt('pv_dur_ori', currentRow.duracion_minutos ?? '—');
    setTxt('pv_ret_ori', currentRow.retardo === 1 ? 'Sí' : 'No');

    // Nuevos
    const f = {
      fecha: $('f_fecha')?.value || '',
      ent:   $('f_hora_entrada')?.value || '',
      sal:   $('f_hora_salida')?.value  || '',
      dur:   $('f_duracion_minutos')?.value,
      ret:   $('f_retardo')?.checked,
      retmin:$('f_retardo_minutos')?.value
    };
    setTxt('pv_fecha_new', f.fecha || '—');
    setTxt('pv_ent_new',   f.ent   || '—');
    setTxt('pv_sal_new',   f.sal   || '—');

    const autoBadge = $('pv_dur_auto');
    const warn = $('pv_warn');
    let durNew = f.dur !== '' ? parseInt(f.dur,10) : null;
    warn.style.display = 'none';
    autoBadge.style.display = 'none';

    const mEnt = parseHM(f.ent);
    const mSal = parseHM(f.sal);
    if (durNew == null) {
      if (mEnt != null && mSal != null) {
        if (mSal >= mEnt) {
          durNew = mSal - mEnt;
          autoBadge.style.display = 'inline-block';
        } else {
          warn.style.display = 'inline-block';
        }
      }
    }
    setTxt('pv_dur_new', durNew != null ? durNew : '—');
    setTxt('pv_ret_new', f.ret ? 'Sí' : 'No');
    setTxt('pv_retmin_new', f.retmin || 0);
  }

  function openEdit(id) {
    const r = data.find(x => x.id === id);
    if (!r) return;
    currentRow = r;

    // Rellenar formulario
    setVal('f_id', r.id);
    setVal('f_id_usuario',  r.id_usuario);
    setVal('f_id_sucursal', r.id_sucursal);
    setVal('f_fecha',       r.fecha || '');
    setVal('f_hora_entrada',r.hora_entrada || '');
    setVal('f_hora_salida', r.hora_salida  || '');
    setVal('f_duracion_minutos', r.duracion_minutos ?? '');
    setChk('f_retardo', r.retardo === 1);
    setVal('f_retardo_minutos', r.retardo_minutos ?? 0);
    setVal('f_latitud',  r.latitud);
    setVal('f_longitud', r.longitud);
    setVal('f_ip',       r.ip);
    setVal('f_metodo',   r.metodo || 'web');

    calcPreview();

    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
  }

  // Listeners para refrescar previsualización
  ['f_fecha','f_hora_entrada','f_hora_salida','f_duracion_minutos','f_retardo_minutos','f_metodo','f_id_usuario','f_id_sucursal','f_latitud','f_longitud','f_ip']
    .forEach(id => { const el = $(id); if (el) el.addEventListener('input', calcPreview); });
  const chkRet = $('f_retardo');
  if (chkRet) chkRet.addEventListener('change', calcPreview);

  (function(){
    const el = $('f_retardo_minutos');
    if (!el) return;
    el.addEventListener('input', e => {
      const v = parseInt(e.target.value || '0', 10);
      const c = $('f_retardo');
      if (c) c.checked = v > 0;
      calcPreview();
    });
  })();
</script>
</body>
</html>
