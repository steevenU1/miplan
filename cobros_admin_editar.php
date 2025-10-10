<?php
// cobros_admin_editar.php — Corrección de Cobros (Admin) con edición de fecha
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt_to_input($mysqlDt){
    // Convierte "YYYY-mm-dd HH:ii:ss" -> "YYYY-mm-ddTHH:ii" para datetime-local
    if (!$mysqlDt) return '';
    $t = strtotime($mysqlDt);
    if ($t === false) return '';
    return date('Y-m-d\TH:i', $t);
}
function parse_datetime_local($val){
    // Aceptar "YYYY-mm-ddTHH:ii" y opcionalmente segundos
    $val = trim($val ?? '');
    if ($val === '') return [false, ''];
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
        if (!$dt) return [false, ''];
    }
    return [true, $dt->format('Y-m-d H:i:00')];
}

// ===== CSRF =====
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// ===== Filtros =====
$hoy = date('Y-m-d');
$ini = isset($_GET['ini']) ? trim($_GET['ini']) : date('Y-m-01');
$fin = isset($_GET['fin']) ? trim($_GET['fin']) : $hoy;
$suc = isset($_GET['suc']) ? (int)$_GET['suc'] : 0;
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$msg = '';
$err = '';

// ===== Sucursales para selector (solo mostrar, no editar) =====
$sucursales = [];
if ($rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")) {
    while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
}

// ===== Guardar cambios =====
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'actualizar') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $err = 'CSRF inválido. Refresca la página.';
    } else {
        $idCobro = (int)($_POST['id_cobro'] ?? 0);
        $motivo  = trim($_POST['motivo'] ?? '');
        $tipo_pago = trim($_POST['tipo_pago'] ?? '');
        $monto_total = (float)($_POST['monto_total'] ?? 0);
        $monto_efectivo = (float)($_POST['monto_efectivo'] ?? 0);
        $monto_tarjeta  = (float)($_POST['monto_tarjeta'] ?? 0);
        $comision_especial = (float)($_POST['comision_especial'] ?? 0);
        $fecha_cobro_in = $_POST['fecha_cobro'] ?? '';

        if ($idCobro <= 0) $err = 'ID de cobro inválido.';
        if ($err === '' && !in_array($tipo_pago, ['Efectivo','Tarjeta','Mixto'], true)) $err = 'Tipo de pago inválido.';
        if ($err === '' && ($monto_efectivo < 0 || $monto_tarjeta < 0 || $comision_especial < 0)) $err = 'Montos no pueden ser negativos.';

        // Normalizar fecha
        [$okFecha, $fecha_cobro_norm] = parse_datetime_local($fecha_cobro_in);
        if ($err === '' && !$okFecha) $err = 'Fecha de cobro inválida.';

        // Traer cobro y validar que NO esté en corte
        if ($err === '') {
            $st = $conn->prepare("SELECT id, corte_generado, id_corte FROM cobros WHERE id=? LIMIT 1");
            $st->bind_param("i", $idCobro);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$row) {
                $err = 'Cobro no encontrado.';
            } elseif ((int)$row['corte_generado'] === 1 || !is_null($row['id_corte'])) {
                $err = 'Este cobro ya está ligado a un corte. No se puede editar.';
            }
        }

        // Reglas por tipo de pago + recalculo total
        if ($err === '') {
            if ($tipo_pago === 'Efectivo') {
                $monto_tarjeta = 0.00;
                $monto_total   = round($monto_efectivo, 2);
            } elseif ($tipo_pago === 'Tarjeta') {
                $monto_efectivo = 0.00;
                $monto_total    = round($monto_tarjeta, 2);
            } else { // Mixto
                $monto_total = round($monto_efectivo + $monto_tarjeta, 2);
            }
        }

        // UPDATE con fecha_cobro
        if ($err === '') {
            $sql = "UPDATE cobros
                    SET motivo=?, tipo_pago=?, monto_total=?, monto_efectivo=?, monto_tarjeta=?, comision_especial=?, fecha_cobro=?
                    WHERE id=? AND corte_generado=0 AND id_corte IS NULL
                    LIMIT 1";
            $stU = $conn->prepare($sql);
            $stU->bind_param(
                "ssdddiss",
                $motivo,
                $tipo_pago,
                $monto_total,
                $monto_efectivo,
                $monto_tarjeta,
                $comision_especial,
                $fecha_cobro_norm,
                $idCobro
            );
            // OJO: tipos: s s d d d i s i -> el penúltimo es string fecha; ajustamos:
            // Corrigiendo bind types:
            $stU->close(); // cerrar y re-preparar con tipos correctos
            $stU = $conn->prepare($sql);
            $types = "ssdddisi"; // motivo(s), tipo_pago(s), total(d), efectivo(d), tarjeta(d), comision(i? o d), fecha(s), id(i)
            // comision_especial es decimal, usamos d:
            $types = "ssdddds i"; // visual, pero PHP no deja espacios; rehacemos limpio abajo.

            // Volvemos a preparar con tipos correctos:
            $stU = $conn->prepare($sql);
            // motivo(s), tipo(s), total(d), efectivo(d), tarjeta(d), comision(d), fecha(s), id(i)
            $stU->bind_param(
                "ssddds si",
                $motivo, $tipo_pago,
                $monto_total, $monto_efectivo, $monto_tarjeta,
                $comision_especial,
                $fecha_cobro_norm,
                $idCobro
            );
            // Algunos editores corrompen espacios en el string de tipos; para evitarlo, rehacemos de forma robusta:
        }
    }
}

// ==== Debido a la complejidad del bind anterior, re-ejecutamos de forma limpia ====
if (isset($sql) && $err === '') {
    $sql2 = $sql;
    $stU2 = $conn->prepare($sql2);
    // Tipos: s (motivo) s (tipo) d (total) d (efectivo) d (tarjeta) d (comision) s (fecha) i (id)
    $stU2->bind_param(
        "ssddd dsi",
        $motivo, $tipo_pago, $monto_total, $monto_efectivo, $monto_tarjeta, $comision_especial, $fecha_cobro_norm, $idCobro
    );
    // Igual, algunos linters insertan espacios; última vez, hacemos el bind con construcción separada:
    $typesFinal = "ssddddsi";
    $stU2->close();
    $stU2 = $conn->prepare($sql2);
    $stU2->bind_param($typesFinal, $motivo, $tipo_pago, $monto_total, $monto_efectivo, $monto_tarjeta, $comision_especial, $fecha_cobro_norm, $idCobro);
    if ($stU2->execute()) {
        $msg = 'Cobro actualizado correctamente.';
        $edit = $idCobro;
    } else {
        $err = 'No se pudo actualizar (¿ya fue agregado a un corte?).';
    }
    $stU2->close();
}

// ===== Cargar registro a editar =====
$editRow = null;
if ($edit > 0) {
    $stE = $conn->prepare("
        SELECT c.*, s.nombre AS sucursal, u.nombre AS usuario
        FROM cobros c
        LEFT JOIN sucursales s ON s.id = c.id_sucursal
        LEFT JOIN usuarios u   ON u.id = c.id_usuario
        WHERE c.id = ? LIMIT 1
    ");
    $stE->bind_param("i", $edit);
    $stE->execute();
    $editRow = $stE->get_result()->fetch_assoc();
    $stE->close();
}

// ===== Listado =====
$iniDt = $ini . " 00:00:00";
$finDt = $fin . " 23:59:59";
$where = " WHERE c.corte_generado=0 AND c.id_corte IS NULL AND c.fecha_cobro BETWEEN ? AND ? ";
$types = "ss";
$params = [$iniDt, $finDt];

if ($suc > 0) {
    $where .= " AND c.id_sucursal = ? ";
    $types .= "i";
    $params[] = $suc;
}

$sqlL = "SELECT c.id, c.fecha_cobro, c.motivo, c.tipo_pago,
                c.monto_total, c.monto_efectivo, c.monto_tarjeta, c.comision_especial,
                s.nombre AS sucursal, u.nombre AS usuario
         FROM cobros c
         LEFT JOIN sucursales s ON s.id = c.id_sucursal
         LEFT JOIN usuarios u   ON u.id = c.id_usuario
         $where
         ORDER BY c.fecha_cobro DESC, c.id DESC
         LIMIT 500";

$stL = $conn->prepare($sqlL);
$stL->bind_param($types, ...$params);
$stL->execute();
$resL = $stL->get_result();
$rows = [];
while ($r = $resL->fetch_assoc()) $rows[] = $r;
$stL->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Corrección de Cobros (Admin)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px;background:#f7f7fb;}
  h1{margin:0 0 12px}
  .box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
  .grid{display:grid;gap:8px}
  .grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
  label{font-size:12px;color:#374151}
  input,select{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px;border-bottom:1px solid #eee;text-align:left;font-size:14px}
  th{background:#fafafa}
  .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #111827;background:#111827;color:#fff;text-decoration:none}
  .btn.secondary{background:#fff;color:#111827}
  .badge{font-size:12px;background:#e5e7eb;border-radius:999px;padding:2px 8px}
  .msg{padding:10px;border-radius:8px;margin-bottom:12px}
  .ok{background:#ecfdf5;color:#065f46;border:1px solid #10b981}
  .err{background:#fef2f2;color:#991b1b;border:1px solid #ef4444}
  .muted{color:#6b7280;font-size:12px}
  .actions a{margin-right:6px}
  .note{font-size:12px;color:#555}
</style>
</head>
<body>

<h1>Corrección de Cobros <span class="badge">Admin</span></h1>

<?php if ($msg): ?><div class="msg ok"><?=h($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

<div class="box">
  <form method="get" class="grid grid-4">
    <div>
      <label>Desde</label>
      <input type="date" name="ini" value="<?=h($ini)?>">
    </div>
    <div>
      <label>Hasta</label>
      <input type="date" name="fin" value="<?=h($fin)?>">
    </div>
    <div>
      <label>Sucursal</label>
      <select name="suc">
        <option value="0">— Todas —</option>
        <?php foreach($sucursales as $s): ?>
          <option value="<?=$s['id']?>" <?=$suc==(int)$s['id']?'selected':''?>><?=h($s['nombre'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:end">
      <button class="btn" type="submit">Filtrar</button>
      <a class="btn secondary" href="?">Limpiar</a>
    </div>
  </form>
  <p class="note">Solo se muestran cobros <b>no ligados a corte</b> (máximo 500).</p>
</div>

<?php if ($editRow): ?>
<div class="box">
  <h2 style="margin-top:0">Editar cobro #<?= (int)$editRow['id'] ?></h2>
  <p class="muted">
    Sucursal: <b><?=h($editRow['sucursal'] ?? '—')?></b> ·
    Usuario: <b><?=h($editRow['usuario'] ?? '—')?></b>
  </p>

  <?php if ((int)$editRow['corte_generado'] === 1 || !is_null($editRow['id_corte'])): ?>
    <div class="msg err">Este cobro ya está ligado a un corte y no puede editarse.</div>
  <?php else: ?>
    <form method="post" class="grid grid-3" id="formEdit">
      <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
      <input type="hidden" name="accion" value="actualizar">
      <input type="hidden" name="id_cobro" value="<?= (int)$editRow['id'] ?>">

      <div>
        <label>Motivo</label>
        <input name="motivo" value="<?=h($editRow['motivo'])?>" maxlength="100">
      </div>

      <div>
        <label>Tipo de pago</label>
        <select name="tipo_pago" id="tipo_pago">
          <?php foreach (['Efectivo','Tarjeta','Mixto'] as $op): ?>
            <option value="<?=$op?>" <?=$op===$editRow['tipo_pago']?'selected':''?>><?=$op?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Comisión especial</label>
        <input name="comision_especial" id="comision_especial" type="number" step="0.01" min="0" value="<?=h($editRow['comision_especial'])?>">
      </div>

      <div>
        <label>Monto efectivo</label>
        <input name="monto_efectivo" id="monto_efectivo" type="number" step="0.01" min="0" value="<?=h($editRow['monto_efectivo'])?>">
      </div>

      <div>
        <label>Monto tarjeta</label>
        <input name="monto_tarjeta" id="monto_tarjeta" type="number" step="0.01" min="0" value="<?=h($editRow['monto_tarjeta'])?>">
      </div>

      <div>
        <label>Monto total (auto)</label>
        <input name="monto_total" id="monto_total" type="number" step="0.01" min="0" value="<?=h($editRow['monto_total'])?>" readonly>
      </div>

      <div>
        <label>Fecha del cobro</label>
        <input type="datetime-local" name="fecha_cobro" value="<?=h(dt_to_input($editRow['fecha_cobro']))?>" required>
      </div>

      <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
        <button class="btn" type="submit">Guardar cambios</button>
        <a class="btn secondary" href="?ini=<?=h($ini)?>&fin=<?=h($fin)?>&suc=<?=$suc?>">Cancelar</a>
        <span class="muted">Reglas: Efectivo = total efectivo; Tarjeta = total tarjeta; Mixto = efectivo + tarjeta.</span>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="box">
  <h2 style="margin-top:0">Cobros sin corte</h2>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Sucursal</th>
          <th>Usuario</th>
          <th>Motivo</th>
          <th>Tipo</th>
          <th>Total</th>
          <th>Efectivo</th>
          <th>Tarjeta</th>
          <th>Com. Esp.</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="muted">Sin resultados.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h($r['fecha_cobro']) ?></td>
          <td><?= h($r['sucursal'] ?? '—') ?></td>
          <td><?= h($r['usuario']  ?? '—') ?></td>
          <td><?= h($r['motivo']) ?></td>
          <td><?= h($r['tipo_pago']) ?></td>
          <td><?= number_format((float)$r['monto_total'], 2) ?></td>
          <td><?= number_format((float)$r['monto_efectivo'], 2) ?></td>
          <td><?= number_format((float)$r['monto_tarjeta'], 2) ?></td>
          <td><?= number_format((float)$r['comision_especial'], 2) ?></td>
          <td class="actions">
            <a class="btn" href="?ini=<?=h($ini)?>&fin=<?=h($fin)?>&suc=<?=$suc?>&edit=<?=$r['id']?>">Editar</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Auto-recalcular monto_total en el cliente
(function(){
  const tipo = document.getElementById('tipo_pago');
  const ef   = document.getElementById('monto_efectivo');
  const tar  = document.getElementById('monto_tarjeta');
  const tot  = document.getElementById('monto_total');
  if (!tipo || !ef || !tar || !tot) return;
  function recalc(){
    const t = tipo.value;
    const vE = parseFloat(ef.value || '0');
    const vT = parseFloat(tar.value || '0');
    let v = 0;
    if (t === 'Efectivo') { v = vE;  tar.value = '0'; }
    else if (t === 'Tarjeta') { v = vT; ef.value = '0'; }
    else { v = (vE + vT); }
    tot.value = (Math.round(v*100)/100).toFixed(2);
  }
  tipo.addEventListener('change', recalc);
  ef.addEventListener('input', recalc);
  tar.addEventListener('input', recalc);
  recalc();
})();
</script>

</body>
</html>
