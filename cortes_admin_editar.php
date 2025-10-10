<?php
// cortes_admin_editar.php — Admin: editar / eliminar cortes de caja
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt_to_input($mysqlDt){
  if (!$mysqlDt) return '';
  $t = strtotime($mysqlDt);
  return $t ? date('Y-m-d\TH:i', $t) : '';
}
function parse_datetime_local($val){
  $val = trim($val ?? '');
  if ($val==='') return [true, null]; // opcional
  $d = DateTime::createFromFormat('Y-m-d\TH:i', $val) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
  return $d ? [true, $d->format('Y-m-d H:i:00')] : [false, null];
}

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ---------------- Filtros ---------------- */
$hoy = date('Y-m-d');
$ini = isset($_GET['ini']) ? $_GET['ini'] : date('Y-m-01');
$fin = isset($_GET['fin']) ? $_GET['fin'] : $hoy;
$suc = isset($_GET['suc']) ? (int)$_GET['suc'] : 0;
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$msg = '';
$err = '';

/* ---------------- Sucursales ---------------- */
$sucursales = [];
if ($rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")) {
  while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
}

/* ---------------- Acciones POST ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['accion'])) {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $err = 'CSRF inválido. Refresca la página.';
  } else {
    $idCorte = (int)($_POST['id_corte'] ?? 0);
    if ($idCorte <= 0) $err = 'ID de corte inválido.';

    if ($err === '') {
      if ($_POST['accion'] === 'actualizar') {
        // Campos editables
        $fecha_operacion = trim($_POST['fecha_operacion'] ?? '');
        $estado          = trim($_POST['estado'] ?? 'Pendiente'); // Pendiente|Cerrado
        $total_efectivo  = (float)($_POST['total_efectivo'] ?? 0);
        $total_tarjeta   = (float)($_POST['total_tarjeta'] ?? 0);
        $total_com_esp   = (float)($_POST['total_comision_especial'] ?? 0);
        $total_general   = (float)($_POST['total_general'] ?? 0);
        $depositado      = isset($_POST['depositado']) ? 1 : 0;
        $fecha_dep_in    = $_POST['fecha_deposito'] ?? '';
        $monto_dep       = (float)($_POST['monto_depositado'] ?? 0);
        $observaciones   = trim($_POST['observaciones'] ?? '');

        if (!in_array($estado, ['Pendiente','Cerrado'], true)) $err = 'Estado inválido.';
        if ($err === '' && $fecha_operacion !== '' && !DateTime::createFromFormat('Y-m-d', $fecha_operacion)) {
          $err = 'Fecha de operación inválida.';
        }
        [$okfd, $fecha_dep_norm] = parse_datetime_local($fecha_dep_in);
        if ($err === '' && !$okfd) $err = 'Fecha de depósito inválida.';

        // Update
        if ($err === '') {
          $sql = "UPDATE cortes_caja
                     SET fecha_operacion = ?,
                         estado = ?,
                         total_efectivo = ?,
                         total_tarjeta = ?,
                         total_comision_especial = ?,
                         total_general = ?,
                         depositado = ?,
                         fecha_deposito = ?,
                         monto_depositado = ?,
                         observaciones = ?
                   WHERE id = ?
                   LIMIT 1";
          $st = $conn->prepare($sql);
          // tipos: s s d d d d i s d s i
          $st->bind_param(
            "ssddddisdsi",
            $fecha_operacion,
            $estado,
            $total_efectivo,
            $total_tarjeta,
            $total_com_esp,
            $total_general,
            $depositado,
            $fecha_dep_norm, // puede ser null, bind lo envia como string; si null, usamos set_null abajo
            $monto_dep,
            $observaciones,
            $idCorte
          );
          // Ajuste para NULL en fecha_deposito
          if ($fecha_dep_norm === null) {
            // Re-preparar para usar NULL adecuadamente
            $st->close();
            $sql = "UPDATE cortes_caja
                       SET fecha_operacion=?, estado=?, total_efectivo=?, total_tarjeta=?, total_comision_especial=?, total_general=?, depositado=?, fecha_deposito=NULL, monto_depositado=?, observaciones=?
                     WHERE id=? LIMIT 1";
            $st = $conn->prepare($sql);
            $st->bind_param(
              "ssddddidsi",
              $fecha_operacion, $estado, $total_efectivo, $total_tarjeta, $total_com_esp, $total_general, $depositado,
              $monto_dep, $observaciones, $idCorte
            );
          }
          if ($st->execute()) {
            $msg = 'Corte actualizado correctamente.';
            $edit = $idCorte;
          } else {
            $err = 'No se pudo actualizar el corte.';
          }
          $st->close();
        }
      }
      elseif ($_POST['accion'] === 'eliminar') {
        // Eliminar corte: liberar cobros y borrar el corte
        $conn->begin_transaction();
        try {
          // 1) Liberar cobros
          $st1 = $conn->prepare("UPDATE cobros SET id_corte = NULL, corte_generado = 0 WHERE id_corte = ?");
          $st1->bind_param("i", $idCorte);
          if (!$st1->execute()) throw new Exception('No se pudieron liberar cobros.');
          $st1->close();

          // (Opcional: si existe tabla detalle de cortes, limpiarla aquí)
          // $conn->query("DELETE FROM cortes_caja_detalle WHERE id_corte = $idCorte");

          // 2) Borrar el corte
          $st2 = $conn->prepare("DELETE FROM cortes_caja WHERE id = ? LIMIT 1");
          $st2->bind_param("i", $idCorte);
          if (!$st2->execute()) throw new Exception('No se pudo eliminar el corte.');
          $st2->close();

          $conn->commit();
          $msg = 'Corte eliminado y cobros liberados.';
          $edit = 0; // ya no existe
        } catch (Throwable $e) {
          $conn->rollback();
          $err = 'Error al eliminar: '.$e->getMessage();
        }
      }
    }
  }
}

/* ---------------- Cargar corte en edición ---------------- */
$editRow = null;
$cobrosDelCorte = [];
if ($edit > 0) {
  $st = $conn->prepare("
    SELECT c.*, s.nombre AS sucursal, u.nombre AS usuario
    FROM cortes_caja c
    LEFT JOIN sucursales s ON s.id = c.id_sucursal
    LEFT JOIN usuarios u   ON u.id = c.id_usuario
    WHERE c.id = ? LIMIT 1
  ");
  $st->bind_param("i", $edit);
  $st->execute();
  $editRow = $st->get_result()->fetch_assoc();
  $st->close();

  // Cobros del corte
  $st2 = $conn->prepare("
    SELECT cb.id, cb.fecha_cobro, cb.motivo, cb.tipo_pago,
           cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta, cb.comision_especial
    FROM cobros cb
    WHERE cb.id_corte = ?
    ORDER BY cb.fecha_cobro ASC, cb.id ASC
  ");
  $st2->bind_param("i", $edit);
  $st2->execute();
  $rs2 = $st2->get_result();
  while ($r = $rs2->fetch_assoc()) $cobrosDelCorte[] = $r;
  $st2->close();
}

/* ---------------- Listado de cortes ---------------- */
$iniDt = $ini;
$finDt = $fin;
$where = " WHERE c.fecha_operacion BETWEEN ? AND ? ";
$types = "ss";
$params = [$iniDt, $finDt];

if ($suc > 0) {
  $where .= " AND c.id_sucursal = ? ";
  $types .= "i";
  $params[] = $suc;
}

$sqlL = "SELECT c.id, c.fecha_operacion, c.fecha_corte, c.estado,
                c.total_efectivo, c.total_tarjeta, c.total_comision_especial, c.total_general,
                c.depositado, c.fecha_deposito, c.monto_depositado,
                s.nombre AS sucursal, u.nombre AS usuario
         FROM cortes_caja c
         LEFT JOIN sucursales s ON s.id = c.id_sucursal
         LEFT JOIN usuarios u   ON u.id = c.id_usuario
         $where
         ORDER BY c.fecha_operacion DESC, c.id DESC
         LIMIT 200";
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
<title>Cortes de Caja — Administración</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px;background:#f7f7fb;}
  h1{margin:0 0 12px}
  .box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
  .grid{display:grid;gap:8px}
  .grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
  label{font-size:12px;color:#374151}
  input,select,textarea{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px}
  textarea{min-height:80px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px;border-bottom:1px solid #eee;text-align:left;font-size:14px}
  th{background:#fafafa}
  .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #111827;background:#111827;color:#fff;text-decoration:none}
  .btn.secondary{background:#fff;color:#111827}
  .btn.danger{background:#b91c1c;border-color:#b91c1c}
  .badge{font-size:12px;background:#e5e7eb;border-radius:999px;padding:2px 8px}
  .msg{padding:10px;border-radius:8px;margin-bottom:12px}
  .ok{background:#ecfdf5;color:#065f46;border:1px solid #10b981}
  .err{background:#fef2f2;color:#991b1b;border:1px solid #ef4444}
  .muted{color:#6b7280;font-size:12px}
  .actions a, .actions form{display:inline-block;margin-right:6px}
</style>
</head>
<body>

<h1>Cortes de Caja <span class="badge">Admin</span></h1>
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
  <p class="muted">Máximo 200 cortes más recientes del rango.</p>
</div>

<?php if ($editRow): ?>
<div class="box">
  <h2 style="margin-top:0">Editar corte #<?= (int)$editRow['id'] ?></h2>
  <p class="muted">
    Sucursal: <b><?=h($editRow['sucursal'] ?? '—')?></b> ·
    Generado por: <b><?=h($editRow['usuario'] ?? '—')?></b> ·
    Creado: <b><?=h($editRow['fecha_corte'])?></b>
  </p>

  <form method="post" class="grid grid-3" id="formEdit">
    <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
    <input type="hidden" name="accion" value="actualizar">
    <input type="hidden" name="id_corte" value="<?= (int)$editRow['id'] ?>">

    <div>
      <label>Fecha de operación (día del corte)</label>
      <input type="date" name="fecha_operacion" value="<?=h($editRow['fecha_operacion'])?>">
    </div>

    <div>
      <label>Estado</label>
      <select name="estado">
        <?php foreach(['Pendiente','Cerrado'] as $e): ?>
          <option value="<?=$e?>" <?=$e===$editRow['estado']?'selected':''?>><?=$e?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Total efectivo</label>
      <input type="number" step="0.01" min="0" name="total_efectivo" value="<?=h($editRow['total_efectivo'])?>">
    </div>

    <div>
      <label>Total tarjeta</label>
      <input type="number" step="0.01" min="0" name="total_tarjeta" value="<?=h($editRow['total_tarjeta'])?>">
    </div>

    <div>
      <label>Total comisión especial</label>
      <input type="number" step="0.01" min="0" name="total_comision_especial" value="<?=h($editRow['total_comision_especial'])?>">
    </div>

    <div>
      <label>Total general</label>
      <input type="number" step="0.01" min="0" name="total_general" value="<?=h($editRow['total_general'])?>">
    </div>

    <div>
      <label>¿Depositado?</label><br>
      <input type="checkbox" name="depositado" value="1" <?=((int)$editRow['depositado']===1?'checked':'')?>>
    </div>

    <div>
      <label>Fecha de depósito</label>
      <input type="datetime-local" name="fecha_deposito" value="<?=h(dt_to_input($editRow['fecha_deposito']))?>">
    </div>

    <div>
      <label>Monto depositado</label>
      <input type="number" step="0.01" min="0" name="monto_depositado" value="<?=h($editRow['monto_depositado'])?>">
    </div>

    <div style="grid-column:1/-1">
      <label>Observaciones</label>
      <textarea name="observaciones"><?=h($editRow['observaciones'])?></textarea>
    </div>

    <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
      <button class="btn" type="submit">Guardar cambios</button>
      <a class="btn secondary" href="?ini=<?=h($ini)?>&fin=<?=h($fin)?>&suc=<?=$suc?>">Cerrar</a>

      <form method="post" onsubmit="return confirmarEliminar();" style="display:inline-block;margin-left:auto">
        <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id_corte" value="<?= (int)$editRow['id'] ?>">
        <button class="btn danger" type="submit">Eliminar corte</button>
      </form>
    </div>
    <p class="muted" style="grid-column:1/-1">
      Al eliminar el corte: todos los cobros asociados quedarán con <b>id_corte = NULL</b> y <b>corte_generado = 0</b>.
    </p>
  </form>
</div>

<div class="box">
  <h3 style="margin-top:0">Cobros incluidos en este corte</h3>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Motivo</th>
          <th>Tipo</th>
          <th>Total</th>
          <th>Efectivo</th>
          <th>Tarjeta</th>
          <th>Com. Esp.</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$cobrosDelCorte): ?>
          <tr><td colspan="8" class="muted">No hay cobros vinculados.</td></tr>
        <?php else: foreach($cobrosDelCorte as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= h($c['fecha_cobro']) ?></td>
            <td><?= h($c['motivo']) ?></td>
            <td><?= h($c['tipo_pago']) ?></td>
            <td><?= number_format((float)$c['monto_total'],2) ?></td>
            <td><?= number_format((float)$c['monto_efectivo'],2) ?></td>
            <td><?= number_format((float)$c['monto_tarjeta'],2) ?></td>
            <td><?= number_format((float)$c['comision_especial'],2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="box">
  <h2 style="margin-top:0">Listado de cortes</h2>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha operación</th>
          <th>Creado</th>
          <th>Sucursal</th>
          <th>Usuario</th>
          <th>Estado</th>
          <th>Total $</th>
          <th>Depositado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted">Sin resultados.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h($r['fecha_operacion']) ?></td>
          <td><?= h($r['fecha_corte']) ?></td>
          <td><?= h($r['sucursal'] ?? '—') ?></td>
          <td><?= h($r['usuario']  ?? '—') ?></td>
          <td><?= h($r['estado']) ?></td>
          <td><?= number_format((float)$r['total_general'],2) ?></td>
          <td><?= ((int)$r['depositado']===1?'Sí':'No') ?></td>
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
function confirmarEliminar(){
  return confirm("¿Eliminar este corte?\n\nSe liberarán todos los cobros (id_corte = NULL, corte_generado = 0). Esta acción no se puede deshacer.");
}
</script>

</body>
</html>
