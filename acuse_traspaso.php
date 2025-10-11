<?php
// acuse_traspaso.php — Acuse imprimible (equipos + accesorios por piezas)
// Soporta scope=original|recibidos e ids=csv. Si ?print=1 → window.print().

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

/* ------------------- Config/logo ------------------- */
$LOGO_URL = 'https://i.ibb.co/Hpd0t2dm/Dise-o-sin-t-tulo.png';
$logoSrc  = $LOGO_URL;
try { if (($img = @file_get_contents($LOGO_URL)) !== false) $logoSrc = 'data:image/png;base64,'.base64_encode($img); } catch (Throwable $e) {}

/* ------------------- Helpers ------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}

/* ------------------- Params ------------------- */
$idTraspaso = (int)($_GET['id'] ?? 0);
$scope      = strtolower(trim((string)($_GET['scope'] ?? 'original'))); // original | recibidos
$idsCsv     = trim((string)($_GET['ids'] ?? ''));
$doPrint    = (int)($_GET['print'] ?? 0) === 1;

if ($idTraspaso <= 0) {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><div style='padding:2rem;font:14px system-ui'>Falta parámetro <b>id</b> de traspaso.</div>";
  exit();
}

/* ------------------- Head ------------------- */
$hasT_FechaRecep     = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio = hasColumn($conn, 'traspasos', 'usuario_recibio');

$sqlHead = "
  SELECT 
    t.id, t.id_sucursal_origen, t.id_sucursal_destino,
    t.fecha_traspaso, t.estatus,
    ".($hasT_FechaRecep ? "t.fecha_recepcion," : "NULL AS fecha_recepcion,")."
    uo.nombre AS usuario_origen,
    ".($hasT_UsuarioRecibio ? "ur.nombre AS usuario_recibio," : "NULL AS usuario_recibio,")."
    so.nombre AS sucursal_origen,
    sd.nombre AS sucursal_destino
  FROM traspasos t
  LEFT JOIN usuarios  uo ON uo.id = t.usuario_creo
  ".($hasT_UsuarioRecibio ? "LEFT JOIN usuarios ur ON ur.id=t.usuario_recibio" : "LEFT JOIN usuarios ur ON 1=0")."
  LEFT JOIN sucursales so ON so.id = t.id_sucursal_origen
  LEFT JOIN sucursales sd ON sd.id = t.id_sucursal_destino
  WHERE t.id=?
";
$stHead = $conn->prepare($sqlHead);
$stHead->bind_param("i", $idTraspaso);
$stHead->execute();
$head = $stHead->get_result()->fetch_assoc();
$stHead->close();
if (!$head) { http_response_code(404); echo "<!doctype html><meta charset='utf-8'><div style='padding:2rem'>Traspaso no encontrado.</div>"; exit(); }

$folio      = (int)$head['id'];
$origenNom  = $head['sucursal_origen'] ?? '—';
$destNom    = $head['sucursal_destino'] ?? '—';
$fCreacion  = $head['fecha_traspaso'] ? date('d/m/Y H:i', strtotime($head['fecha_traspaso'])) : '—';
$fRecepcion = $head['fecha_recepcion'] ? date('d/m/Y H:i', strtotime($head['fecha_recepcion'])) : null;
$estatus    = $head['estatus'] ?? '—';
$usrCrea    = $head['usuario_origen'] ?? '—';
$usrRecibio = $head['usuario_recibio'] ?? null;

$hasDT_Resultado = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_Cantidad  = hasColumn($conn, 'detalle_traspaso', 'cantidad'); // recomendado para accesorios

/* ------------------- Filtro IDs ------------------- */
$idsFiltro = [];
if ($idsCsv !== '') {
  foreach (explode(',', $idsCsv) as $x) { $n=(int)trim($x); if ($n>0) $idsFiltro[]=$n; }
  $idsFiltro = array_values(array_unique($idsFiltro));
}

/* =================== Consulta DETALLE separando equipos/accesorios =================== */
/* Regla de accesorio: p.tipo_producto='Accesorio' OR ambos IMEIs vacíos */
$commonWhere = "dt.id_traspaso = ?";
$params = [$idTraspaso];
$types  = "i";

if ($scope === 'recibidos' && $hasDT_Resultado) { $commonWhere .= " AND dt.resultado=?"; $params[]='Recibido'; $types.="s"; }
if (!empty($idsFiltro)) {
  $in = implode(',', array_fill(0, count($idsFiltro), '?'));
  $commonWhere .= " AND i.id IN ($in)";
  foreach ($idsFiltro as $idv){ $params[]=$idv; $types.="i"; }
}

/* --- EQUIPOS (con IMEI) --- */
$sqlEq = "
  SELECT i.id AS id_inventario, p.marca, p.modelo, p.color, p.imei1, p.imei2
  FROM detalle_traspaso dt
  INNER JOIN inventario i ON i.id = dt.id_inventario
  INNER JOIN productos  p ON p.id = i.id_producto
  WHERE $commonWhere
    AND (COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NOT NULL)
  ORDER BY p.marca, p.modelo, i.id
";
$stEq = $conn->prepare($sqlEq);
$stEq->bind_param($types, ...$params);
$stEq->execute();
$equipos = $stEq->get_result()->fetch_all(MYSQLI_ASSOC);
$stEq->close();

/* --- ACCESORIOS (por piezas) --- */
$sqlAcc = "
  SELECT 
    p.id AS id_producto,
    p.marca, p.modelo, p.color,
    ".($hasDT_Cantidad ? "SUM(dt.cantidad)" : "COUNT(*)")." AS piezas
  FROM detalle_traspaso dt
  INNER JOIN inventario i ON i.id = dt.id_inventario
  INNER JOIN productos  p ON p.id = i.id_producto
  WHERE $commonWhere
    AND (p.tipo_producto='Accesorio' OR COALESCE(NULLIF(TRIM(p.imei1),''), NULLIF(TRIM(p.imei2),'')) IS NULL)
  GROUP BY p.id, p.marca, p.modelo, p.color
  HAVING ".($hasDT_Cantidad ? "SUM(dt.cantidad)" : "COUNT(*)")." > 0
  ORDER BY p.marca, p.modelo, p.color
";
$stAcc = $conn->prepare($sqlAcc);
$stAcc->bind_param($types, ...$params);
$stAcc->execute();
$accesorios = $stAcc->get_result()->fetch_all(MYSQLI_ASSOC);
$stAcc->close();

/* Advertencia si pides recibidos pero no hay columna resultado, o si no hay dt.cantidad */
$warns = [];
if ($scope==='recibidos' && !$hasDT_Resultado && empty($idsFiltro)) {
  $warns[] = "Este acuse está en modo <b>recibidos</b>, pero <code>detalle_traspaso.resultado</code> no existe; se muestran todos los ítems como respaldo.";
}
if (!$hasDT_Cantidad && !empty($accesorios)) {
  $warns[] = "No se encontró <code>detalle_traspaso.cantidad</code>. Las piezas de accesorios se estiman como 1 por renglón, lo cual puede no coincidir con la realidad si consolidaste múltiples piezas.";
}

$esRecepcion = ($scope === 'recibidos');
$tituloAcuse = $esRecepcion ? "Acuse de recepción" : "Acuse de entrega";

// Totales
$totalEquipos = count($equipos);
$totalAccPzas = 0;
foreach ($accesorios as $a) $totalAccPzas += (int)$a['piezas'];

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= h($tituloAcuse) ?> · #<?= (int)$folio ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{ --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#0d6efd; }
    *{ box-sizing:border-box }
    html,body{ margin:0; padding:0; color:var(--ink); font:13px/1.45 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    .wrap{ max-width:1000px; margin:24px auto; padding:0 16px 32px; }
    header{ display:flex; align-items:center; justify-content:space-between; gap:16px; padding-bottom:14px; border-bottom:2px solid var(--ink); }
    .brand{ display:flex; align-items:center; gap:12px; }
    h1{ font-size:20px; margin:0; }
    .meta{ margin-top:6px; color:var(--muted); }
    .kv{ display:grid; grid-template-columns:140px 1fr; gap:6px 10px; min-width:280px; }
    .kv .k{ color:var(--muted) } .kv .v{ font-weight:600 }
    .grid{ margin-top:18px; display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .card{ border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:#fff; }
    .card b{ color:#0b1220 }
    table{ width:100%; border-collapse:collapse; margin-top:18px; border:1px solid var(--line); }
    thead th{ text-transform:uppercase; letter-spacing:.4px; font-size:11px; background:#f8fafc; border-bottom:1px solid var(--line); }
    th,td{ padding:8px; border-bottom:1px solid var(--line); }
    tbody tr:nth-child(even){ background:#fcfdff }
    code{ font-family:ui-monospace, Menlo, Consolas, "Liberation Mono", monospace; background:#f1f5f9; padding:2px 6px; border-radius:6px; }
    .footer{ margin-top:18px; display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .sign{ border-top:1px dashed var(--line); padding-top:10px; min-height:84px; }
    .sign small{ color:var(--muted) }
    .note{ margin-top:10px; color:#7c3aed; background:#faf5ff; border:1px solid #e9d5ff; padding:8px 10px; border-radius:8px; }
    .tot{ margin-top:12px; font-weight:700 }
    .toolbar{ display:flex; gap:8px; justify-content:flex-end; margin-bottom:10px; }
    .toolbar .btn{ border:1px solid var(--line); background:#fff; padding:6px 10px; border-radius:8px; cursor:pointer; }
    .toolbar .btn.primary{ border-color:#c7d2fe; background:#eef2ff; }
    .logo-img{ width:56px; height:56px; object-fit:contain; border-radius:12px; border:2px solid var(--ink); background:#fff; }
    @media print{ .wrap{ margin:0; max-width:none; padding:0 } .no-print{ display:none !important } body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
  </style>
</head>
<body>
<div class="wrap">

  <div class="no-print toolbar">
    <button class="btn" onclick="window.history.back()">← Regresar</button>
    <button class="btn" onclick="window.print()"><b>🖨️ Imprimir</b></button>
    <a class="btn primary" href="<?= h($_SERVER['REQUEST_URI']) ?>">↻ Recargar</a>
  </div>

  <header>
    <div class="brand">
      <img class="logo-img" src="<?= h($logoSrc) ?>" alt="Logo">
      <div>
        <h1><?= h($tituloAcuse) ?></h1>
        <div class="meta">Folio <b>#<?= (int)$folio ?></b> · Generado el <?= date('d/m/Y H:i') ?></div>
      </div>
    </div>
    <div class="kv">
      <div class="k">Estatus:</div><div class="v"><?= h($estatus) ?></div>
      <div class="k">Fecha traspaso:</div><div class="v"><?= h($fCreacion) ?></div>
      <?php if ($esRecepcion && $fRecepcion): ?>
        <div class="k">Fecha recepción:</div><div class="v"><?= h($fRecepcion) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <div class="grid">
    <div class="card">
      <b>Origen</b>
      <div class="kv" style="margin-top:6px">
        <div class="k">Sucursal:</div><div class="v"><?= h($origenNom) ?></div>
        <div class="k">Generó:</div><div class="v"><?= h($usrCrea ?: '—') ?></div>
      </div>
    </div>
    <div class="card">
      <b>Destino</b>
      <div class="kv" style="margin-top:6px">
        <div class="k">Sucursal:</div><div class="v"><?= h($destNom) ?></div>
        <div class="k"><?= $esRecepcion ? 'Recibió:' : 'Por recibir:' ?></div><div class="v"><?= h($usrRecibio ?: '—') ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($warns)): ?>
    <div class="note">
      <?php foreach ($warns as $w): ?><div><?= $w ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- EQUIPOS -->
  <table>
    <thead><tr>
      <th style="width:80px">ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>IMEI 1</th><th>IMEI 2</th>
    </tr></thead>
    <tbody>
      <?php if ($totalEquipos>0): foreach ($equipos as $it): ?>
        <tr>
          <td><b><?= (int)$it['id_inventario'] ?></b></td>
          <td><?= h($it['marca']) ?></td>
          <td><?= h($it['modelo']) ?></td>
          <td><?= h($it['color']) ?></td>
          <td><code><?= h($it['imei1']) ?></code></td>
          <td><?= $it['imei2'] ? '<code>'.h($it['imei2']).'</code>' : '—' ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;color:#64748b;padding:14px 8px">Sin equipos en este acuse.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- ACCESORIOS -->
  <table>
    <thead><tr>
      <th>Producto</th><th>Color</th><th style="width:160px" class="text-end">Piezas</th>
    </tr></thead>
    <tbody>
      <?php if (!empty($accesorios)): foreach ($accesorios as $a): ?>
        <tr>
          <td><b><?= h(($a['marca'] ?? '').' '.($a['modelo'] ?? '')) ?></b></td>
          <td><?= h($a['color'] ?? '—') ?></td>
          <td style="text-align:right"><b><?= (int)$a['piezas'] ?></b></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" style="text-align:center;color:#64748b;padding:14px 8px">Sin accesorios en este acuse.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="tot">Total equipos: <?= (int)$totalEquipos ?> · Total accesorios: <?= (int)$totalAccPzas ?> pzas</div>

  <div class="footer">
    <div class="sign"><div><b>Entrega (Origen)</b></div><div style="height:52px"></div><small>Nombre y firma</small></div>
    <div class="sign"><div><b>Recepción (Destino)</b></div><div style="height:52px"></div><small>Nombre y firma</small></div>
  </div>

  <div class="no-print" style="margin-top:10px;color:#475569">
    <small>Imprimir en 1 copia para destino y 1 para el archivo del origen.</small>
  </div>
</div>

<?php if ($doPrint): ?>
<script>window.addEventListener('load',()=>{try{window.focus();window.print();}catch(e){}});</script>
<?php endif; ?>
</body>
</html>
