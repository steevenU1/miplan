<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin','Gerente'], true)) {
  http_response_code(403); echo "Sin permiso."; exit;
}

/* Includes */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) require_once __DIR__ . '/includes/docs_lib.php';
else require_once __DIR__ . '/docs_lib.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* --------- Filtros --------- */
$roleFilter = $_GET['rol'] ?? 'Ejecutivo';   // Admin/Gerente/Ejecutivo/Todos
$search     = trim($_GET['q'] ?? '');
$status     = $_GET['status'] ?? 'all';      // all/complete/pending

/* Campos que cuentan para el progreso (igual que en mi_expediente.php) */
$requiredFields = [
  'tel_contacto','fecha_nacimiento','fecha_ingreso','curp','nss','rfc','genero',
  'contacto_emergencia','tel_emergencia','clabe'
];

/* --------- Usuarios + sucursal (excluir Subdistribuidor y Master Admin) --------- */
$sql = "SELECT u.id, u.nombre, u.usuario, u.rol, u.activo, u.id_sucursal,
               s.nombre AS sucursal_nombre, s.zona AS sucursal_zona, s.subtipo AS sucursal_subtipo
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE 1=1
          AND (s.subtipo IS NULL OR s.subtipo NOT IN ('Subdistribuidor','Master Admin'))";

$params = []; $types = '';
if ($roleFilter && $roleFilter !== 'Todos') {
  $sql .= " AND u.rol = ?";
  $params[] = $roleFilter; $types .= 's';
}
if ($search !== '') {
  $sql .= " AND (u.nombre LIKE ? OR u.usuario LIKE ? OR s.nombre LIKE ?)";
  $like = "%$search%";
  $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
$sql .= " ORDER BY s.nombre IS NULL, s.nombre ASC, u.nombre ASC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res  = $stmt->get_result();
$users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$userIds = array_map(fn($u)=> (int)$u['id'], $users);

/* --------- Expedientes (todos los usuarios listados) --------- */
$expedientes = [];
if ($userIds) {
  $in = implode(',', array_map('intval', $userIds));
  $rs = $conn->query("SELECT * FROM usuarios_expediente WHERE usuario_id IN ($in)");
  while ($row = $rs->fetch_assoc()) $expedientes[(int)$row['usuario_id']] = $row;
}

/* --------- Tipos de documento requeridos --------- */
$docTypesReq = [];
$docTypeIds  = [];
$rs = $conn->query("SELECT id, codigo, nombre FROM doc_tipos WHERE requerido=1 ORDER BY id");
while ($row = $rs->fetch_assoc()) { $docTypesReq[(int)$row['id']] = $row; $docTypeIds[] = (int)$row['id']; }
$totalDocReq = count($docTypeIds);

/* --------- Documentos vigentes por usuario (solo requeridos) --------- */
$userDocs = []; // [usuario_id][doc_tipo_id] = doc_id_vigente
if ($userIds && $docTypeIds) {
  $inU = implode(',', array_map('intval', $userIds));
  $inT = implode(',', array_map('intval', $docTypeIds));
  $sql = "SELECT ud.usuario_id, ud.doc_tipo_id, ud.id
          FROM usuario_documentos ud
          WHERE ud.vigente=1 AND ud.usuario_id IN ($inU) AND ud.doc_tipo_id IN ($inT)";
  $rs = $conn->query($sql);
  while ($row = $rs->fetch_assoc()) {
    $uid = (int)$row['usuario_id']; $tid = (int)$row['doc_tipo_id'];
    $userDocs[$uid][$tid] = (int)$row['id'];
  }
}

/* --------- Cálculo por usuario --------- */
function percent_for_user(array $exp, array $requiredFields, int $totalDocReq, array $docsForUser): array {
  $filled = 0;
  foreach ($requiredFields as $f) {
    $val = $exp[$f] ?? '';
    $isFilled = in_array($f, ['fecha_nacimiento','fecha_ingreso'], true) ? !empty($val) : (trim((string)$val) !== '');
    if ($isFilled) $filled++;
  }
  $docsOk = $docsForUser ? count($docsForUser) : 0;
  $total  = count($requiredFields) + $totalDocReq;
  $done   = $filled + $docsOk;
  $pct    = $total>0 ? floor(($done/$total)*100) : 0;
  return [$pct, $filled, $docsOk, $total];
}

/* Faltantes de docs (nombres) */
function missing_docs_names(array $docTypesReq, array $docsForUser): array {
  $have = $docsForUser ? array_keys($docsForUser) : [];
  $haveSet = array_fill_keys($have, true);
  $miss = [];
  foreach ($docTypesReq as $id=>$row) {
    if (!isset($haveSet[$id])) $miss[] = $row['nombre'];
  }
  return $miss;
}

$rows = [];
foreach ($users as $u) {
  $uid  = (int)$u['id'];
  $exp  = $expedientes[$uid] ?? [];
  [$pct, $filled, $docsOk, $total] = percent_for_user($exp, $requiredFields, $totalDocReq, $userDocs[$uid] ?? []);
  $missNames = missing_docs_names($docTypesReq, $userDocs[$uid] ?? []);
  $missingCount = count($missNames) + (count($requiredFields) - $filled);
  $rows[] = [
    'user' => $u,
    'pct'  => $pct,
    'missingCount' => $missingCount,
    'missDocs' => $missNames,
    'docs' => $userDocs[$uid] ?? [],
    'exp'  => $exp
  ];
}

/* Filtro por status (opcional) */
if ($status !== 'all') {
  $rows = array_values(array_filter($rows, function($r) use ($status){
    return $status === 'complete' ? ($r['pct'] == 100) : ($r['pct'] < 100);
  }));
}

/* --------- Resumen por sucursal (promedio, conteos) --------- */
$bySucursal = []; // [id_sucursal] => ['name','zona','cnt','sumPct','complete']
foreach ($rows as $r) {
  $u = $r['user'];
  $sid = (int)($u['id_sucursal'] ?? 0);
  $name = $u['sucursal_nombre'] ?: '—';
  $zona = $u['sucursal_zona'] ?: '';
  if (!isset($bySucursal[$sid])) $bySucursal[$sid] = ['name'=>$name,'zona'=>$zona,'cnt'=>0,'sumPct'=>0,'complete'=>0];
  $bySucursal[$sid]['cnt']++;
  $bySucursal[$sid]['sumPct'] += (int)$r['pct'];
  if ((int)$r['pct'] === 100) $bySucursal[$sid]['complete']++;
}
foreach ($bySucursal as $sid=>&$s) {
  $s['avg'] = $s['cnt'] ? round($s['sumPct'] / $s['cnt'], 1) : 0.0;
}
unset($s);
/* ordenar de peor a mejor */
uasort($bySucursal, function($a,$b){
  if ($a['avg'] === $b['avg']) return strcmp($a['name'], $b['name']);
  return ($a['avg'] < $b['avg']) ? -1 : 1;
});

/* Helpers UI */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$maxMB = defined('DOCS_MAX_SIZE') ? (int)(DOCS_MAX_SIZE/1024/1024) : 10;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de expedientes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--primary:#2b6cb0;--ok:#2f855a;--warn:#dd6b20;--muted:#6b7280}
  /* FIX navbar: sin margen en body; márgenes en container */
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f7fafc}
  .container{max-width:1250px;margin:16px auto;padding:0 12px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:14px}
  .title{margin:0 0 12px 0}
  .controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .controls input,.controls select{padding:8px 10px;border:1px solid #cbd5e0;border-radius:10px;background:#fff}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top}
  /* Sticky scopeado para no empujar el navbar */
  .card table thead th{font-size:12px;letter-spacing:.02em;color:#374151;background:#f9fafb;position:sticky;top:0;z-index:1}
  .progress{background:#edf2f7;height:10px;border-radius:999px;overflow:hidden}
  .bar{height:100%;background:var(--ok)}
  .badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px}
  .badge-ok{background:#c6f6d5;color:#22543d;border:1px solid #9ae6b4}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#1e3a8a;border:1px solid #c7d2fe;margin:2px;font-size:12px}
  .muted{color:var(--muted);font-size:12px}
  .actions a{display:inline-block;padding:8px 10px;border-radius:8px;border:1px solid #cbd5e0;background:#fff;text-decoration:none;color:#111}
  .actions a:hover{background:#f3f4f6}
  details summary{cursor:pointer;user-select:none;list-style:none}
  details summary::marker, details summary::-webkit-details-marker{display:none}
  details[open]{background:#fbfdff}
  .rowtitle{font-weight:600}
  .docs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  @media (max-width:900px){.docs-grid{grid-template-columns:1fr}}
  .suc-table td,.suc-table th{padding:8px;border-bottom:1px solid #eef2f7}
</style>
</head>
<body>
<div class="container">
  <h2 class="title">Panel de expedientes</h2>

  <div class="card">
    <form class="controls" method="get" action="">
      <input type="text" name="q" value="<?=h($search)?>" placeholder="Buscar por nombre, usuario o sucursal…">
      <select name="rol">
        <?php foreach (['Todos','Ejecutivo','Gerente','Admin'] as $opt): ?>
          <option value="<?=$opt?>" <?=$opt===$roleFilter?'selected':''?>><?=$opt?></option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <option value="all" <?=$status==='all'?'selected':''?>>Todos</option>
        <option value="complete" <?=$status==='complete'?'selected':''?>>Completos (100%)</option>
        <option value="pending" <?=$status==='pending'?'selected':''?>>Pendientes (&lt;100%)</option>
      </select>
      <button type="submit" class="actions">Filtrar</button>
      <span class="muted">Se excluyen sucursales Subdistribuidor / Master Admin · Límite doc <?=$maxMB?>MB</span>
    </form>
  </div>

  <!-- Resumen por sucursal -->
  <div class="card">
    <h3 class="title" style="margin-bottom:6px">Resumen por sucursal</h3>
    <?php if (!$bySucursal): ?>
      <div class="muted">Sin datos con los filtros actuales.</div>
    <?php else: ?>
      <table class="suc-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr>
            <th style="width:38%">Sucursal</th>
            <th style="width:15%">Zona</th>
            <th style="width:12%">Usuarios</th>
            <th style="width:12%">100% ✔</th>
            <th style="width:15%">Promedio %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bySucursal as $sid=>$s): ?>
            <tr>
              <td><?=h($s['name'])?></td>
              <td><?=h($s['zona'])?></td>
              <td><?= (int)$s['cnt'] ?></td>
              <td><?= (int)$s['complete'] ?></td>
              <td><?= number_format($s['avg'],1) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="muted" style="margin-top:6px">Ordenado de menor a mayor promedio para priorizar sucursales con menor avance.</div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0">
    <table>
      <thead>
        <tr>
          <th style="width:26%">Usuario</th>
          <th style="width:20%">Sucursal</th>
          <th style="width:15%">Rol</th>
          <th style="width:24%">Progreso</th>
          <th style="width:15%">Acciones</th>
        </tr>
      </thead>
      <tbody id="rows">
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="muted">Sin usuarios con los filtros actuales.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
          $u = $r['user']; $uid = (int)$u['id']; $pct = (int)$r['pct'];
          $missDocs = $r['missDocs'];
          $missData = [];
          foreach ($requiredFields as $f) { if (empty($r['exp'][$f])) $missData[] = $f; }
        ?>
        <tr>
          <td>
            <div class="rowtitle"><?=h($u['nombre'])?></div>
            <div class="muted">@<?=h($u['usuario'])?> <?=($u['activo']?'':' · Inactivo')?></div>
          </td>
          <td>
            <div><?=h($u['sucursal_nombre'] ?: '—')?></div>
            <div class="muted"><?=h($u['sucursal_zona'] ?: '')?></div>
          </td>
          <td><?=h($u['rol'])?></td>
          <td>
            <div class="progress" aria-label="Progreso de <?=h($u['nombre'])?>">
              <div class="bar" style="width: <?=$pct?>%"></div>
            </div>
            <div class="muted" style="margin-top:4px">
              <?=$pct?>% 
              <?php if ($pct===100): ?><span class="badge badge-ok">✅ completo</span><?php endif; ?>
            </div>

            <details style="margin-top:6px">
              <summary class="muted">Ver detalle / faltantes</summary>
              <div class="docs-grid" style="margin-top:8px">
                <!-- Datos pendientes -->
                <div>
                  <strong>Datos:</strong><br>
                  <?php if (!$missData): ?>
                    <span class="muted">—</span>
                  <?php else: foreach ($missData as $md): ?>
                    <span class="pill"><?=h(str_replace('_',' ', $md))?></span>
                  <?php endforeach; endif; ?>
                </div>
                <!-- Docs por tipo -->
                <div>
                  <strong>Documentos:</strong><br>
                  <?php foreach ($docTypesReq as $tid=>$dt): 
                    $docId = $r['docs'][$tid] ?? null; ?>
                    <div style="margin-top:4px">
                      <?=h($dt['nombre'])?>:
                      <?php if ($docId): ?>
                        <a class="actions" style="margin-left:6px" target="_blank" href="documento_descargar.php?id=<?=$docId?>">Ver</a>
                      <?php else: ?>
                        <span class="pill">Pendiente</span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </details>
          </td>
          <td class="actions">
            <a href="documentos_historial.php?usuario_id=<?=$uid?>">Docs</a>
            <a href="expediente_usuario.php?usuario_id=<?=$uid?>">Expediente</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
/* Búsqueda rápida en cliente (además del filtro servidor) */
const q = document.querySelector('input[name="q"]');
const tbody = document.getElementById('rows');
if (q && tbody) {
  q.addEventListener('input', () => {
    const term = q.value.toLowerCase().trim();
    Array.from(tbody.rows).forEach(tr => {
      const txt = tr.innerText.toLowerCase();
      tr.style.display = term==='' || txt.includes(term) ? '' : 'none';
    });
  });
}
</script>
</body>
</html>
