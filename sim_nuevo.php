<?php
// sim_nuevo.php — Alta individual de SIM en inventario_sims (solo Admin)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

// ===== Helpers
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function digits($s){ return preg_replace('/\D+/', '', (string)$s); }
function moneyOrNull($s){
  $x = trim((string)$s);
  if ($x==='') return null;
  // soporta "1.234,56" o "1234.56"
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $x)) { $x=str_replace('.','',$x); $x=str_replace(',','.', $x); }
  else { $x=str_replace(',','', $x); }
  return is_numeric($x) ? number_format((float)$x, 2, '.', '') : null;
}
function getEnumOptions(mysqli $conn, string $table, string $column): array {
  $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute(); $stmt->bind_result($colType); $stmt->fetch(); $stmt->close();
  if (!$colType || stripos($colType,'enum(')!==0) return [];
  preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $colType, $m);
  return array_map(fn($v)=>str_replace("\\'", "'", $v), $m[1] ?? []);
}

// ===== Catálogos desde BD (enum) + sucursales
$OPERADORES = getEnumOptions($conn, 'inventario_sims', 'operador');     // ej. ['Bait','AT&T']
$PLANES     = getEnumOptions($conn, 'inventario_sims', 'tipo_plan');    // ej. ['Prepago','Pospago']
$SUCURSALES = [];
$q = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($q) { while($r=$q->fetch_assoc()){ $SUCURSALES[(int)$r['id']] = $r['nombre']; } }

// ===== Estado UI
$msg=''; $alert='info';

// ===== POST: crear
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $iccid = digits($_POST['iccid'] ?? '');
  $dn    = digits($_POST['dn'] ?? '');
  $oper  = $_POST['operador'] ?? '';
  $caja  = trim((string)($_POST['caja_id'] ?? ''));
  $plan  = $_POST['tipo_plan'] ?? '';
  $costo = moneyOrNull($_POST['costo'] ?? '');
  $pventa= moneyOrNull($_POST['precio_venta'] ?? '');
  $idSuc = (int)($_POST['id_sucursal'] ?? 0);

  // Validaciones
  if ($iccid === '' || strlen($iccid)<18 || strlen($iccid)>22) {
    $msg="❌ ICCID inválido. Debe contener solo dígitos (18–22)."; $alert='danger';
  } elseif (!in_array($oper, $OPERADORES, true)) {
    $msg="❌ Operador inválido."; $alert='danger';
  } elseif ($plan!=='' && !in_array($plan, $PLANES, true)) {
    $msg="❌ Tipo de plan inválido."; $alert='danger';
  } elseif (!$idSuc || !isset($SUCURSALES[$idSuc])) {
    $msg="❌ Debes seleccionar una sucursal válida."; $alert='danger';
  } else {
    // Duplicado por ICCID
    $st=$conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
    $st->bind_param("s",$iccid); $st->execute(); $st->store_result();
    if ($st->num_rows>0){ $msg="⚠️ Ya existe un SIM con ese ICCID."; $alert='warning'; }
    $st->close();

    // Insert
    if ($msg==='') {
      $sql = "INSERT INTO inventario_sims (iccid, dn, operador, caja_id, tipo_plan, costo, precio_venta, estatus, id_sucursal)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Disponible', ?)";
      $st = $conn->prepare($sql);
      $st->bind_param("ssssddsi", $iccid, $dn ?: null, $oper, $caja ?: null, $plan ?: null,
                      $costo!==null?(float)$costo:null, $pventa!==null?(float)$pventa:null, $idSuc);
      $ok = $st->execute(); $err=$st->error; $st->close();

      if ($ok) {
        $msg="✅ SIM creado y asignado a <b>".esc($SUCURSALES[$idSuc])."</b>.";
        $alert='success';
        // Limpia el formulario después de éxito
        $_POST = [];
      } else {
        $msg="❌ Error al insertar: ".esc($err); $alert='danger';
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Alta de SIM — Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body{background:#f8fafc}
    .card-soft{ border:1px solid #e9ecf1; border-radius:16px; box-shadow:0 2px 12px rgba(16,24,40,.06); }
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
  </style>
</head>
<body>
<div class="container my-4">
  <h3>🆕 Alta individual de SIM</h3>
  <p class="text-muted">Solo disponible para Administradores. El SIM se creará con estatus <span class="badge bg-success">Disponible</span>.</p>

  <?php if($msg): ?>
    <div class="alert alert-<?=esc($alert)?>"><?= $msg ?></div>
  <?php endif; ?>

  <div class="card card-soft">
    <div class="card-body">
      <form method="POST" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">ICCID *</label>
          <input name="iccid" class="form-control mono" required maxlength="22"
                 value="<?= esc($_POST['iccid'] ?? '') ?>" placeholder="8952...">
          <div class="form-text">Solo dígitos (18–22).</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">DN / MSISDN</label>
          <input name="dn" class="form-control mono" maxlength="20" value="<?= esc($_POST['dn'] ?? '') ?>" placeholder="Número (opcional)">
        </div>

        <div class="col-md-4">
          <label class="form-label">Operador *</label>
          <select name="operador" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php
              $opSel = $_POST['operador'] ?? ($OPERADORES[0] ?? '');
              foreach($OPERADORES as $op){
                $sel = ($opSel===$op)?'selected':'';
                echo "<option value=\"".esc($op)."\" $sel>".esc($op)."</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tipo de plan</label>
          <select name="tipo_plan" class="form-select">
            <option value="">(sin definir)</option>
            <?php
              $plSel = $_POST['tipo_plan'] ?? '';
              foreach($PLANES as $pl){
                $sel = ($plSel===$pl)?'selected':'';
                echo "<option value=\"".esc($pl)."\" $sel>".esc($pl)."</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Caja / Lote</label>
          <input name="caja_id" class="form-control" value="<?= esc($_POST['caja_id'] ?? '') ?>" placeholder="Caja o identificador (opcional)">
        </div>

        <div class="col-md-4">
          <label class="form-label">Costo</label>
          <input name="costo" class="form-control" value="<?= esc($_POST['costo'] ?? '') ?>" placeholder="0.00">
        </div>
        <div class="col-md-4">
          <label class="form-label">Precio venta</label>
          <input name="precio_venta" class="form-control" value="<?= esc($_POST['precio_venta'] ?? '') ?>" placeholder="0.00">
        </div>
        <div class="col-md-4">
          <label class="form-label">Sucursal *</label>
          <select name="id_sucursal" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php
              $sSel = (int)($_POST['id_sucursal'] ?? 0);
              foreach($SUCURSALES as $id=>$nom){
                $sel = ($sSel===$id)?'selected':'';
                echo "<option value=\"$id\" $sel>".esc($nom)."</option>";
              }
            ?>
          </select>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success"><i class="bi bi-sim me-1"></i>Guardar SIM</button>
          <a href="inventario_sims_listado.php" class="btn btn-outline-secondary">Regresar</a>
        </div>
      </form>

      <div class="mt-3 small text-muted">
        Notas:
        <ul class="mb-0">
          <li>El estatus inicial se fija en <b>Disponible</b>.</li>
          <li><code>fecha_ingreso</code> usa el <b>DEFAULT CURRENT_TIMESTAMP</b> de la tabla.</li>
          <li>Si necesitas otros operadores o planes, solo agrega valores al <b>ENUM</b> en MySQL; el formulario se actualiza solo.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap icons (opcional para el icono) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
