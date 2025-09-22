<?php
// inventario_sims_admin.php — LUGA · Admin de SIMs con eliminación segura
// Requisitos: roles Admin o Gerente

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($ROL, ['Admin','Gerente'])) {
  echo "<div class='container my-4'><div class='alert alert-danger'>No autorizado.</div></div>";
  exit();
}

date_default_timezone_set('America/Mexico_City');

// ===================== Helpers (sin redeclarar) =====================
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('i')) {
  function i($v){ return (int)($v ?? 0); }
}

// ---------------------- CSRF ----------------------
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf'];

// ---------------------- LOG (si no existe) ----------------------
$conn->query("
  CREATE TABLE IF NOT EXISTS inventario_sims_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accion ENUM('delete') NOT NULL,
    id_sim INT,
    iccid VARCHAR(25),
    dn VARCHAR(20),
    operador ENUM('Bait','AT&T') NULL,
    tipo_plan ENUM('Prepago','Pospago') NULL,
    id_usuario_actor INT NOT NULL,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------------------- Filtros ----------------------
$buscar     = trim($_GET['q'] ?? '');
$operador   = trim($_GET['operador'] ?? '');
$tipo_plan  = trim($_GET['tipo_plan'] ?? '');
$estatus    = trim($_GET['estatus'] ?? 'Disponible'); // por defecto Disponibles
$id_suc     = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;
$caja       = trim($_GET['caja'] ?? '');

$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = min(200, max(10, (int)($_GET['pp'] ?? 50)));
$offset     = ($page - 1) * $per_page;

$where = "1=1";
$params = [];
$types  = "";

if ($buscar !== '') {
  $where .= " AND (iccid LIKE CONCAT('%', ?, '%') OR dn LIKE CONCAT('%', ?, '%'))";
  $params[] = $buscar; $params[] = $buscar; $types .= "ss";
}
if ($operador !== '') { $where .= " AND operador = ?"; $params[] = $operador; $types .= "s"; }
if ($tipo_plan !== '') { $where .= " AND tipo_plan = ?"; $params[] = $tipo_plan; $types .= "s"; }
if ($estatus !== '')   { $where .= " AND estatus = ?";   $params[] = $estatus;   $types .= "s"; }
if ($id_suc > 0)       { $where .= " AND id_sucursal = ?"; $params[] = $id_suc;  $types .= "i"; }
if ($caja !== '')      { $where .= " AND caja_id LIKE CONCAT('%', ?, '%')"; $params[] = $caja; $types .= "s"; }

// ---------------------- Borrado (POST) ----------------------
$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'delete') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $mensaje = "<div class='alert alert-danger'>Token inválido.</div>";
  } else {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    if (isset($_POST['id_unico'])) { $ids[] = (int)$_POST['id_unico']; }
    $ids = array_values(array_unique(array_filter($ids)));

    if (empty($ids)) {
      $mensaje = "<div class='alert alert-warning'>No seleccionaste SIMs.</div>";
    } else {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $typeIds = str_repeat('i', count($ids));

      // Traer datos para validar y loguear
      $sqlSel = $conn->prepare("SELECT id, iccid, dn, operador, tipo_plan, estatus FROM inventario_sims WHERE id IN ($placeholders)");
      $sqlSel->bind_param($typeIds, ...$ids);
      $sqlSel->execute();
      $resSel = $sqlSel->get_result();

      $validos = [];
      $skipped = 0;
      $rowsSel = [];
      while ($r = $resSel->fetch_assoc()) {
        $rowsSel[] = $r;
        if (strcasecmp($r['estatus'], 'Disponible')===0) $validos[] = (int)$r['id']; else $skipped++;
      }
      $sqlSel->close();

      if (!empty($validos)) {
        // Delete
        $phv = implode(',', array_fill(0, count($validos), '?'));
        $typesv = str_repeat('i', count($validos));
        $del = $conn->prepare("DELETE FROM inventario_sims WHERE id IN ($phv)");
        $del->bind_param($typesv, ...$validos);
        $del->execute();
        $eliminados = $del->affected_rows;
        $del->close();

        // Log
        $ins = $conn->prepare("
          INSERT INTO inventario_sims_log (accion, id_sim, iccid, dn, operador, tipo_plan, id_usuario_actor, ip)
          VALUES ('delete', ?, ?, ?, ?, ?, ?, ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        foreach ($rowsSel as $r) {
          if (in_array((int)$r['id'], $validos, true)) {
            $idSim = (int)$r['id'];
            $iccid = (string)$r['iccid'];
            $dn    = (string)$r['dn'];
            $op    = $r['operador'] ?? null;
            $tp    = $r['tipo_plan'] ?? null;
            $uid   = (int)$_SESSION['id_usuario'];
            $ins->bind_param('issssis', $idSim, $iccid, $dn, $op, $tp, $uid, $ip);
            $ins->execute();
          }
        }
        $ins->close();

        $mensaje = "<div class='alert alert-success'>Eliminados: <b>$eliminados</b>.".
                   ($skipped>0 ? " Omitidos (estatus ≠ Disponible): <b>$skipped</b>." : "") .
                   "</div>";
      } else {
        $mensaje = "<div class='alert alert-warning'>Ninguno elegible (estatus ≠ Disponible).</div>";
      }
    }
  }
}

// ---------------------- Combos ----------------------
$sucRs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
$sucursales = $sucRs ? $sucRs->fetch_all(MYSQLI_ASSOC) : [];

$ops = ['Bait','AT&T'];
$planes = ['Prepago','Pospago'];
$estados = ['Disponible','En tránsito','Vendida'];

// ---------------------- Conteo ----------------------
$sqlCount = "SELECT COUNT(*) AS t FROM inventario_sims WHERE $where";
$stmtC = $conn->prepare($sqlCount);
if ($types !== "") { $stmtC->bind_param($types, ...$params); }
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['t'] ?? 0);
$stmtC->close();

// ---------------------- Datos de página ----------------------
$sql = "SELECT id, iccid, dn, operador, caja_id, tipo_plan, estatus, id_sucursal, id_usuario_venta, fecha_ingreso, fecha_venta
        FROM inventario_sims
        WHERE $where
        ORDER BY id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($types !== "") {
  $typesQ = $types . "ii";
  $paramsQ = array_merge($params, [$per_page, $offset]);
  $stmt->bind_param($typesQ, ...$paramsQ);
} else {
  $stmt->bind_param("ii", $per_page, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$lastPage = max(1, (int)ceil($total / $per_page));
?>
<div class="container-fluid my-3">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h3 class="m-0">Inventario de SIMs <small class="text-muted">Admin</small></h3>
    <div>
      <span class="badge bg-secondary">Total: <?= number_format($total) ?></span>
      <span class="badge bg-info text-dark">Página <?= $page ?> / <?= $lastPage ?></span>
    </div>
  </div>

  <?= $mensaje ?>

  <form class="card mt-3 shadow-sm" method="get">
    <div class="card-body row g-2">
      <div class="col-12 col-md-3">
        <label class="form-label">Buscar (ICCID/DN)</label>
        <input type="text" class="form-control" name="q" value="<?= e($buscar) ?>" placeholder="8952… o DN">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Operador</label>
        <select name="operador" class="form-select">
          <option value="">Todos</option>
          <?php foreach($ops as $op): ?>
            <option value="<?= e($op) ?>" <?= $operador===$op?'selected':'' ?>><?= e($op) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Tipo plan</label>
        <select name="tipo_plan" class="form-select">
          <option value="">Todos</option>
          <?php foreach($planes as $p): ?>
            <option value="<?= e($p) ?>" <?= $tipo_plan===$p?'selected':'' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Estatus</label>
        <select name="estatus" class="form-select">
          <option value="">Todos</option>
          <?php foreach($estados as $es): ?>
            <option value="<?= e($es) ?>" <?= $estatus===$es?'selected':'' ?>><?= e($es) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Sucursal</label>
        <select name="id_sucursal" class="form-select">
          <option value="0">Todas</option>
          <?php foreach($sucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $id_suc===(int)$s['id']?'selected':'' ?>><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Caja</label>
        <input type="text" class="form-control" name="caja" value="<?= e($caja) ?>" placeholder="X01E5">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Por página</label>
        <select name="pp" class="form-select">
          <?php foreach([25,50,100,200] as $pp): ?>
            <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3 d-flex align-items-end">
        <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
      </div>
    </div>
  </form>

  <form method="post" id="formDelete">
    <input type="hidden" name="accion" value="delete">
    <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
    <div class="card mt-3 shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <b>Resultados</b>
          <small class="text-muted">— solo se eliminarán SIMs con estatus <b>Disponible</b>.</small>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="checkAll">Marcar todo</button>
          <button type="submit" class="btn btn-danger btn-sm" onclick="return confirmarLote();">
            <i class="bi bi-trash"></i> Eliminar seleccionadas
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:32px"></th>
              <th>ID</th>
              <th>ICCID</th>
              <th>DN</th>
              <th>Operador</th>
              <th>Plan</th>
              <th>Estatus</th>
              <th>Sucursal</th>
              <th>Caja</th>
              <th>Ingreso</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="11" class="text-center py-4 text-muted">Sin resultados.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr class="<?= (strcasecmp($r['estatus'],'Disponible')===0)?'':'table-warning' ?>">
              <td><input type="checkbox" class="form-check-input sel" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
              <td><?= (int)$r['id'] ?></td>
              <td><code><?= e($r['iccid']) ?></code></td>
              <td><?= e($r['dn']) ?></td>
              <td><?= e($r['operador']) ?></td>
              <td><?= e($r['tipo_plan']) ?></td>
              <td><?= e($r['estatus']) ?></td>
              <td><?= (int)$r['id_sucursal'] ?></td>
              <td><?= e($r['caja_id']) ?></td>
              <td><small><?= e($r['fecha_ingreso']) ?></small></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirmarUno(this);">
                  <input type="hidden" name="accion" value="delete">
                  <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
                  <input type="hidden" name="id_unico" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($lastPage>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted">Mostrando <?= count($rows) ?> de <?= number_format($total) ?></div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php
              $mk = function($p) use ($per_page,$buscar,$operador,$tipo_plan,$estatus,$id_suc,$caja){
                $q = http_build_query([
                  'page'=>$p,'pp'=>$per_page,'q'=>$buscar,'operador'=>$operador,
                  'tipo_plan'=>$tipo_plan,'estatus'=>$estatus,'id_sucursal'=>$id_suc,'caja'=>$caja
                ]);
                return "?$q";
              };
            ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $mk(max(1,$page-1)) ?>">&laquo;</a></li>
            <li class="page-item active"><span class="page-link"><?= $page ?></span></li>
            <li class="page-item <?= $page>=$lastPage?'disabled':'' ?>"><a class="page-link" href="<?= $mk(min($lastPage,$page+1)) ?>">&raquo;</a></li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
  document.getElementById('checkAll')?.addEventListener('click', () => {
    document.querySelectorAll('.sel').forEach(cb => cb.checked = true);
  });
  function confirmarUno(form){
    return confirm('¿Eliminar esta SIM? Solo procede si está en estatus "Disponible".');
  }
  function confirmarLote(){
    const marcados = document.querySelectorAll('.sel:checked').length;
    if (!marcados){ alert('No seleccionaste SIMs.'); return false; }
    return confirm('Vas a eliminar ' + marcados + ' SIM(s). Solo se eliminarán las que estén en "Disponible". ¿Continuar?');
  }
</script>
