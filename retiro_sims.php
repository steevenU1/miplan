<?php
// retiro_sims.php — Búsqueda por ICCID + Carrito + Confirmación + Selector de Sucursal (Admin)
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

if (!isset($_SESSION['carrito_retiro'])) $_SESSION['carrito_retiro'] = []; // array de IDs
if (empty($_SESSION['retiro_token'])) $_SESSION['retiro_token'] = bin2hex(random_bytes(16));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== helpers de URL para preservar filtros =====
function baseUrl(): string {
  $self = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
  return $self ?: 'retiro_sims.php';
}
function qs(array $extra = []): string {
  $keep = $_GET; 
  unset($keep['a'], $keep['id']); // limpiamos acciones
  $q = array_merge($keep, $extra);
  return $q ? ('?'.http_build_query($q)) : '';
}

// ===== Filtros (incluye Sucursal global) =====
$sucursalSel = trim($_GET['sucursal'] ?? '');   // '' = Todas
$q_iccid     = trim($_GET['iccid'] ?? '');
$q_dn        = trim($_GET['dn'] ?? '');
$q_caja      = trim($_GET['caja'] ?? '');

// ===== Acciones de carrito (ANTES de imprimir navbar/HTML) =====
$accion = $_GET['a'] ?? '';

if ($accion === 'add' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if (!in_array($id, $_SESSION['carrito_retiro'], true)) {
    $row = $conn->query("SELECT estatus FROM inventario_sims WHERE id={$id} LIMIT 1")->fetch_assoc();
    // SOLO se permite 'Disponible'
    if ($row && $row['estatus'] === 'Disponible') {
      $_SESSION['carrito_retiro'][] = $id;
      $_SESSION['flash_ok'] = "SIM agregada al carrito.";
    } else {
      $_SESSION['flash_err'] = "No se puede agregar: solo se permiten SIMs en estatus Disponible.";
    }
  }
  header("Location: ".baseUrl().qs()); 
  exit();
}

if ($accion === 'del' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $_SESSION['carrito_retiro'] = array_values(array_filter($_SESSION['carrito_retiro'], fn($x)=>$x!=$id));
  $_SESSION['flash_ok'] = "SIM removida del carrito.";
  header("Location: ".baseUrl().qs()); 
  exit();
}

if ($accion === 'vaciar') {
  $_SESSION['carrito_retiro'] = [];
  $_SESSION['flash_ok'] = "Carrito vaciado.";
  header("Location: ".baseUrl().qs()); 
  exit();
}

/* ==== Agregar caja completa al carrito ==== */
if ($accion === 'add_caja') {
  $cajaParam = trim($_GET['caja'] ?? '');
  if ($cajaParam === '') {
    $_SESSION['flash_err'] = "No se especificó la caja a retirar.";
    header("Location: ".baseUrl().qs());
    exit();
  }

  $types = 's';
  $params = [$cajaParam];
  $sqlCaja = "SELECT id, estatus 
              FROM inventario_sims 
              WHERE caja_id = ? 
                AND estatus = 'Disponible'"; // SOLO Disponible

  // si hay sucursal seleccionada, respetar filtro de sucursal
  if ($sucursalSel !== '') {
    $sqlCaja .= " AND id_sucursal = ?";
    $types   .= 'i';
    $params[] = (int)$sucursalSel;
  }

  $stmtCaja = $conn->prepare($sqlCaja);
  $stmtCaja->bind_param($types, ...$params);
  $stmtCaja->execute();
  $resCaja = $stmtCaja->get_result();

  $agregadas = 0;
  while ($row = $resCaja->fetch_assoc()) {
    $idSim = (int)$row['id'];
    if (!in_array($idSim, $_SESSION['carrito_retiro'], true)) {
      $_SESSION['carrito_retiro'][] = $idSim;
      $agregadas++;
    }
  }

  if ($agregadas > 0) {
    $_SESSION['flash_ok'] = "Se agregaron {$agregadas} SIM(s) de la caja {$cajaParam} al carrito.";
  } else {
    $_SESSION['flash_err'] = "No se encontraron SIMs en estatus Disponible para la caja {$cajaParam} con los filtros actuales.";
  }

  header("Location: ".baseUrl().qs());
  exit();
}

// ===== Navbar (ya podemos imprimir) =====
require_once __DIR__.'/navbar.php';

// ===== Catálogos =====
$suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$ops = ['Bait','AT&T','Virgin','Unefon','Telcel','Movistar'];

// map de sucursal para mostrar nombre
$sucMap = [];
foreach ($suc as $sx) {
  $sucMap[(int)$sx['id']] = $sx['nombre'];
}

// ===== Búsqueda de SIMs retirables en sucursal seleccionada =====
// SOLO estatus Disponible
$where = ["estatus = 'Disponible'"];
$types=''; 
$params=[];

if ($sucursalSel !== '') { 
  $where[]="id_sucursal = ?"; 
  $types.='i'; 
  $params[]=(int)$sucursalSel; 
}
if ($q_iccid !== '') { 
  $where[]="iccid LIKE ?";    
  $types.='s'; 
  $params[]='%'.$q_iccid.'%'; 
}
if ($q_dn !== '') { 
  $where[]="dn LIKE ?";       
  $types.='s'; 
  $params[]='%'.$q_dn.'%'; 
}
if ($q_caja !== '') { 
  $where[]="caja_id = ?";     
  $types.='s'; 
  $params[]=$q_caja; 
}

$sql = "SELECT id, iccid, dn, operador, caja_id, lote, tipo_plan, id_sucursal, estatus, fecha_ingreso
        FROM inventario_sims
        ".(count($where)?'WHERE '.implode(' AND ',$where):'')."
        ORDER BY fecha_ingreso DESC
        LIMIT 50";
$stmt = $conn->prepare($sql);
if ($types!=='') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* ==== Conteo de SIMs disponibles por caja para botón "retirar caja" ==== */
$totalCajaDisponibles = null;
if ($q_caja !== '') {
  $typesCaja = 's';
  $paramsCaja = [$q_caja];
  $whereCaja = ["caja_id = ?", "estatus = 'Disponible'"]; // SOLO Disponible

  if ($sucursalSel !== '') {
    $whereCaja[] = "id_sucursal = ?";
    $typesCaja .= 'i';
    $paramsCaja[] = (int)$sucursalSel;
  }

  $sqlCountCaja = "SELECT COUNT(*) AS total 
                   FROM inventario_sims 
                   WHERE ".implode(' AND ', $whereCaja);
  $stmtCount = $conn->prepare($sqlCountCaja);
  $stmtCount->bind_param($typesCaja, ...$paramsCaja);
  $stmtCount->execute();
  $resCount = $stmtCount->get_result()->fetch_assoc();
  $totalCajaDisponibles = (int)($resCount['total'] ?? 0);
}

// ===== Carrito (consulta detalles) =====
$carrito = [];
if (count($_SESSION['carrito_retiro'])) {
  $ids = implode(',', array_map('intval', $_SESSION['carrito_retiro']));
  $carrito = $conn->query("
      SELECT id, iccid, dn, operador, caja_id, id_sucursal 
      FROM inventario_sims 
      WHERE id IN ($ids)
  ")->fetch_all(MYSQLI_ASSOC);
}

// ===== Flash =====
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Retiro de SIMs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    .sticky-actions {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 5;
    }
  </style>
</head>
<body>
<div class="container py-3">
  <h3 class="mb-3">Retiro de SIMs (carrito)</h3>

  <!-- Barra de acciones superiores -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <a class="btn btn-outline-dark mb-2" href="retiro_sims_historial.php">
      Ver historial de retiros
    </a>
    <button type="button" class="btn btn-outline-primary mb-2" onclick="irAlCarrito()">
      Ir al carrito
    </button>
  </div>

  <?php if($flash_ok):  ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <!-- Filtros globales -->
  <form class="row g-2 mb-3" method="get" id="formFiltros">
    <div class="col-md-3">
      <label class="form-label">Sucursal</label>
      <select name="sucursal" class="form-select" onchange="document.getElementById('formFiltros').submit()">
        <option value="">Todas</option>
        <?php foreach($suc as $r): ?>
          <option value="<?= (int)$r['id']; ?>" <?= ($sucursalSel!=='' && (int)$sucursalSel===(int)$r['id']?'selected':'') ?>>
            <?= h($r['nombre']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">ICCID</label>
      <input name="iccid" class="form-control" value="<?=h($q_iccid)?>" placeholder="Buscar por ICCID">
    </div>
    <div class="col-md-2">
      <label class="form-label">DN</label>
      <input name="dn" class="form-control" value="<?=h($q_dn)?>" placeholder="Opcional">
    </div>
    <div class="col-md-2">
      <label class="form-label">CAJA</label>
      <input name="caja" class="form-control" value="<?=h($q_caja)?>" placeholder="ID de caja">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-primary w-100">Buscar</button>
    </div>
  </form>

  <?php if($q_caja !== '' && $totalCajaDisponibles !== null): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
      <div>
        Caja <strong><?=h($q_caja)?></strong>: 
        <strong><?= $totalCajaDisponibles ?></strong> SIM(s) en estatus <strong>Disponible</strong>
        <?= $sucursalSel !== '' ? 'en la sucursal seleccionada.' : 'en las sucursales filtradas.' ?>
      </div>
      <div>
        <a class="btn btn-sm btn-outline-primary<?= $totalCajaDisponibles ? '' : ' disabled'; ?>"
           href="<?= h(baseUrl().qs(['a'=>'add_caja','caja'=>$q_caja])) ?>">
          Agregar caja completa al carrito
        </a>
      </div>
    </div>
  <?php endif; ?>

  <!-- Resultados -->
  <div class="table-responsive mb-4">
    <table class="table table-sm table-hover align-middle">
      <thead class="sticky-actions">
        <tr>
          <th>ICCID</th>
          <th>DN</th>
          <th>Operador</th>
          <th>CAJA</th>
          <th>Sucursal</th>
          <th>Estatus</th>
          <th class="text-end">Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php while($r = $result->fetch_assoc()): ?>
        <?php
          $enCarrito = in_array($r['id'], $_SESSION['carrito_retiro'], true);
          $nombreSucursal = $sucMap[(int)$r['id_sucursal']] ?? ('ID '.$r['id_sucursal']);
        ?>
        <tr id="row-<?= (int)$r['id'] ?>">
          <td><?=h($r['iccid'])?></td>
          <td><?=h($r['dn'])?></td>
          <td><?=h($r['operador'])?></td>
          <td><?=h($r['caja_id'])?></td>
          <td><?=h($nombreSucursal)?></td>
          <td><?=h($r['estatus'])?></td>
          <td class="text-end">
            <?php if($enCarrito): ?>
              <a class="btn btn-outline-danger btn-sm" 
                 href="<?= h(baseUrl().qs(['a'=>'del','id'=>$r['id']])) ?>">
                Quitar
              </a>
            <?php else: ?>
              <a class="btn btn-success btn-sm" 
                 href="<?= h(baseUrl().qs(['a'=>'add','id'=>$r['id']])) ?>">
                Agregar
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- Carrito -->
  <div id="carrito" class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Carrito (<?= count($_SESSION['carrito_retiro']) ?>)</strong>
      <div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(baseUrl().qs(['a'=>'vaciar'])) ?>">Vaciar</a>
        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#confirmModal" <?= count($_SESSION['carrito_retiro'])? '' : 'disabled' ?>>
          Confirmar retiro
        </button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ICCID</th>
              <th>DN</th>
              <th>Operador</th>
              <th>CAJA</th>
              <th>Sucursal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($carrito as $c): 
              $nombreSucursalC = $sucMap[(int)$c['id_sucursal']] ?? ('ID '.$c['id_sucursal']);
          ?>
            <tr>
              <td><?=h($c['iccid'])?></td>
              <td><?=h($c['dn'])?></td>
              <td><?=h($c['operador'])?></td>
              <td><?=h($c['caja_id'])?></td>
              <td><?=h($nombreSucursalC)?></td>
              <td>
                <a class="btn btn-outline-danger btn-sm" 
                   href="<?= h(baseUrl().qs(['a'=>'del','id'=>$c['id']])) ?>">
                  Quitar
                </a>
              </td>
            </tr>
          <?php endforeach; if(!count($carrito)): ?>
            <tr><td colspan="6" class="text-center text-muted">Sin elementos</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Modal Confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" action="retiro_sims_confirmar.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar retiro de <?= count($carrito) ?> SIM(s)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['retiro_token']) ?>">
        <div class="mb-3">
          <label class="form-label">Motivo del retiro</label>
          <input name="motivo" class="form-control" required minlength="5" placeholder="Describe el motivo">
        </div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>ICCID</th>
                <th>DN</th>
                <th>Operador</th>
                <th>CAJA</th>
                <th>Sucursal</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($carrito as $c): 
                $nombreSucursalC = $sucMap[(int)$c['id_sucursal']] ?? ('ID '.$c['id_sucursal']);
            ?>
              <tr>
                <td><?=h($c['iccid'])?></td>
                <td><?=h($c['dn'])?></td>
                <td><?=h($c['operador'])?></td>
                <td><?=h($c['caja_id'])?></td>
                <td><?=h($nombreSucursalC)?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0">
          Se marcarán como <strong>Retirado</strong> y quedarán fuera del inventario operativo.
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Confirmar retiro</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Guardar y restaurar scroll para que no te mande hasta arriba en cada acción
window.addEventListener('beforeunload', function() {
  try {
    localStorage.setItem('retiro_sims_scroll', String(window.scrollY || 0));
  } catch(e) {}
});

window.addEventListener('load', function() {
  try {
    var y = localStorage.getItem('retiro_sims_scroll');
    if (y !== null) {
      var pos = parseInt(y, 10);
      if (!isNaN(pos)) {
        window.scrollTo(0, pos);
      }
    }
  } catch(e) {}
});

// Scroll suave al carrito
function irAlCarrito() {
  var el = document.getElementById('carrito');
  if (!el) return;
  var rect = el.getBoundingClientRect();
  var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
  var target = rect.top + scrollTop - 80; // pequeño offset por el navbar
  window.scrollTo({ top: target, behavior: 'smooth' });
}
</script>
</body>
</html>
