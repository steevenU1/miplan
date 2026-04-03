<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");


/* ===== Debug (si ocupas ver errores, cambia 0 -> 1) ===== */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR);
$conn->set_charset("utf8mb4");

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function get($k,$d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function clampInt($v,$min,$max){ $n=(int)$v; if($n<$min)$n=$min; if($n>$max)$n=$max; return $n; }

function tableExists(mysqli $conn, string $table): bool {
  // En tu MariaDB, SHOW TABLES LIKE ? con prepare falla, así que escapamos y listo
  $t = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
  return ($rs && $rs->num_rows > 0);
}

function badgeEstatus($e){
  $e = (string)$e;
  if ($e === 'Completado') return "<span class='badge text-bg-success'>Recibido</span>";
  if ($e === 'Parcial')    return "<span class='badge text-bg-info'>Parcial</span>";
  if ($e === 'Rechazado')  return "<span class='badge text-bg-danger'>Rechazado</span>";
  return "<span class='badge text-bg-warning text-dark'>En tránsito</span>";
}
function chipTipo($t){
  if ($t === 'SIM') return "<span class='badge text-bg-dark'>SIM</span>";
  return "<span class='badge text-bg-primary'>Equipo</span>";
}
function buildUrl($overrides=[]){
  $qs = $_GET;
  foreach($overrides as $k=>$v){
    if($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return basename(__FILE__).'?'.http_build_query($qs);
}

/* ===== Inputs ===== */
$f_tipo     = get('tipo', 'todos');        // todos | equipos | sims
$f_estatus  = get('estatus', 'todos');     // todos | transito | recibidos | parciales | rechazados
$f_sucursal = (int)get('sucursal', '0');   // 0=todas (origen o destino)
$f_desde    = get('desde', '');
$f_hasta    = get('hasta', '');
$f_q        = get('q', '');

$page    = clampInt(get('page', 1), 1, 999999);
$perPage = clampInt(get('per_page', 25), 10, 200);
$offset  = ($page - 1) * $perPage;

$desdeDT = $f_desde ? ($f_desde . " 00:00:00") : '';
$hastaDT = $f_hasta ? ($f_hasta . " 23:59:59") : '';

/* ===== Sucursales (filtro) ===== */
$sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre ASC");

/* ===== SIM tables detection ===== */
$hasSims = tableExists($conn, 'traspasos_sims')
        && tableExists($conn, 'detalle_traspaso_sims')
        && tableExists($conn, 'inventario_sims');

/* ===== WHERE filters para dataset unificado ===== */
$where = " WHERE 1=1 ";
$params = [];
$types  = "";

// Fecha
if ($desdeDT && $hastaDT) {
  $where .= " AND X.fecha BETWEEN ? AND ? ";
  $params[] = $desdeDT; $types .= "s";
  $params[] = $hastaDT; $types .= "s";
} elseif ($desdeDT) {
  $where .= " AND X.fecha >= ? ";
  $params[] = $desdeDT; $types .= "s";
} elseif ($hastaDT) {
  $where .= " AND X.fecha <= ? ";
  $params[] = $hastaDT; $types .= "s";
}

// Sucursal (origen o destino)
if ($f_sucursal > 0) {
  $where .= " AND (X.id_sucursal_origen = ? OR X.id_sucursal_destino = ?) ";
  $params[] = $f_sucursal; $types .= "i";
  $params[] = $f_sucursal; $types .= "i";
}

// Estatus
if ($f_estatus !== "todos") {
  if ($f_estatus === "transito") {
    $where .= " AND X.estatus IN ('Pendiente','Parcial') ";
  } elseif ($f_estatus === "recibidos") {
    $where .= " AND X.estatus IN ('Completado') ";
  } elseif ($f_estatus === "parciales") {
    $where .= " AND X.estatus IN ('Parcial') ";
  } elseif ($f_estatus === "rechazados") {
    $where .= " AND X.estatus IN ('Rechazado') ";
  }
}

// Tipo
if ($f_tipo !== "todos") {
  $where .= " AND X.tipo = ? ";
  $params[] = ($f_tipo === "sims") ? "SIM" : "Equipo";
  $types .= "s";
}

// Search (IMEI/ICCID/texto)
if ($f_q !== "") {
  $where .= " AND (X.identificador LIKE ? OR X.texto LIKE ?) ";
  $like = "%".$f_q."%";
  $params[] = $like; $types .= "s";
  $params[] = $like; $types .= "s";
}

/* ===== Dataset unificado =====
   IMPORTANTE: IMEI sale de productos (p.imei1 / p.imei2) como tu esquema.
*/
$parts = [];

/* --- EQUIPOS --- */
$parts[] = "
  SELECT
    t.fecha_traspaso AS fecha,
    'Equipo' COLLATE utf8mb4_general_ci AS tipo,
    t.id AS id_traspaso,
    (t.estatus COLLATE utf8mb4_general_ci) AS estatus,
    t.id_sucursal_origen,
    t.id_sucursal_destino,
    (u.nombre COLLATE utf8mb4_general_ci) AS usuario_creo,
    (MIN(p.imei1) COLLATE utf8mb4_general_ci) AS identificador,
    (
      CONCAT(
        so.nombre,' -> ',sd.nombre,
        ' | ',
        COALESCE(MIN(CONCAT_WS(' ', p.marca, p.modelo, p.color, COALESCE(p.descripcion,''))), '')
      ) COLLATE utf8mb4_general_ci
    ) AS texto
  FROM traspasos t
  INNER JOIN detalle_traspaso dt ON dt.id_traspaso = t.id
  INNER JOIN inventario i ON i.id = dt.id_inventario
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN sucursales so ON so.id = t.id_sucursal_origen
  INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
  INNER JOIN usuarios u ON u.id = t.usuario_creo
  GROUP BY t.id
";


/* --- SIMS --- */
if ($hasSims) {
  $parts[] = "
    SELECT
      ts.fecha_traspaso AS fecha,
      'SIM' COLLATE utf8mb4_general_ci AS tipo,
      ts.id AS id_traspaso,
      (CASE WHEN ts.estatus='Completado' THEN 'Completado' ELSE 'Pendiente' END COLLATE utf8mb4_general_ci) AS estatus,
      ts.id_sucursal_origen,
      ts.id_sucursal_destino,
      (u.nombre COLLATE utf8mb4_general_ci) AS usuario_creo,
      (MIN(s.iccid) COLLATE utf8mb4_general_ci) AS identificador,
      (
        CONCAT(
          so.nombre,' -> ',sd.nombre,
          ' | SIM ',
          COALESCE(MIN(s.operador),''),
          ' | ',
          COALESCE(MIN(s.tipo_plan),'')
        ) COLLATE utf8mb4_general_ci
      ) AS texto
    FROM traspasos_sims ts
    INNER JOIN detalle_traspaso_sims dts ON dts.id_traspaso = ts.id
    INNER JOIN inventario_sims s ON s.id = dts.id_sim
    INNER JOIN sucursales so ON so.id = ts.id_sucursal_origen
    INNER JOIN sucursales sd ON sd.id = ts.id_sucursal_destino
    INNER JOIN usuarios u ON u.id = ts.usuario_creo
    GROUP BY ts.id
  ";
}


$union = implode("\nUNION ALL\n", $parts);

$sqlBase = "
  SELECT
    X.*,
    so2.nombre AS sucursal_origen,
    sd2.nombre AS sucursal_destino
  FROM (
    $union
  ) X
  INNER JOIN sucursales so2 ON so2.id = X.id_sucursal_origen
  INNER JOIN sucursales sd2 ON sd2.id = X.id_sucursal_destino
";

/* ===== Ejecutar ===== */
$errorFriendly = '';
$rowsAll = [];
$total = 0;

try {
  $sqlAll = $sqlBase . $where . " ORDER BY X.fecha DESC, X.id_traspaso DESC";
  $stmtAll = $conn->prepare($sqlAll);
  if (!$stmtAll) throw new Exception("prepare() falló: ".$conn->error);

  if ($types !== "") $stmtAll->bind_param($types, ...$params);
  $stmtAll->execute();

  $resAll = $stmtAll->get_result();
  while($r = $resAll->fetch_assoc()){
    $rowsAll[] = $r;
  }
  $stmtAll->close();

  $total = count($rowsAll);
} catch (Throwable $e) {
  $errorFriendly = $e->getMessage();
  $rowsAll = [];
  $total = 0;
}

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page-1) * $perPage; }
$rowsPage = array_slice($rowsAll, $offset, $perPage);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Historial de Traspasos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .soft { background: #f8fafc; }
  </style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">

  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h3 class="mb-1">📜 Historial de Traspasos</h3>
      <div class="text-muted">Equipos y SIMs, todo junto con filtros y búsqueda.</div>
      <?php if (!$hasSims): ?>
        <div class="text-muted small">Nota: No detecté tablas SIMs en esta central (traspasos_sims / detalle_traspaso_sims / inventario_sims).</div>
      <?php endif; ?>
    </div>
    <div class="text-end">
      <div class="small text-muted">Total encontrados</div>
      <div class="fs-5"><strong><?= (int)$total ?></strong></div>
    </div>
  </div>

  <?php if ($errorFriendly): ?>
    <div class="alert alert-danger shadow-sm">
      <div class="fw-bold">Se detectó un error en la consulta</div>
      <div class="small mono"><?= h($errorFriendly) ?></div>
      <div class="small text-muted mt-2">Si quieres ver más detalle, cambia display_errors a 1 arriba del archivo.</div>
    </div>
  <?php endif; ?>

  <!-- Filtros -->
  <form method="GET" class="card card-body mb-3 shadow-sm">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-2">
        <label class="form-label">Tipo</label>
        <select name="tipo" class="form-select">
          <option value="todos" <?= $f_tipo==='todos'?'selected':'' ?>>Todos</option>
          <option value="equipos" <?= $f_tipo==='equipos'?'selected':'' ?>>Equipos</option>
          <option value="sims" <?= $f_tipo==='sims'?'selected':'' ?>>SIMs</option>
        </select>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">Estatus</label>
        <select name="estatus" class="form-select">
          <option value="todos" <?= $f_estatus==='todos'?'selected':'' ?>>Todos</option>
          <option value="transito" <?= $f_estatus==='transito'?'selected':'' ?>>En tránsito</option>
          <option value="recibidos" <?= $f_estatus==='recibidos'?'selected':'' ?>>Recibidos</option>
          <option value="parciales" <?= $f_estatus==='parciales'?'selected':'' ?>>Parciales</option>
          <option value="rechazados" <?= $f_estatus==='rechazados'?'selected':'' ?>>Rechazados</option>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Sucursal (Origen o Destino)</label>
        <select name="sucursal" class="form-select">
          <option value="0">-- Todas --</option>
          <?php
          $suc2 = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre ASC");
          while($s = $suc2->fetch_assoc()):
          ?>
            <option value="<?= (int)$s['id'] ?>" <?= $f_sucursal==(int)$s['id']?'selected':'' ?>>
              <?= h($s['nombre']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="desde" value="<?= h($f_desde) ?>" class="form-control">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="hasta" value="<?= h($f_hasta) ?>" class="form-control">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Buscar (IMEI / ICCID / texto)</label>
        <input type="text" name="q" value="<?= h($f_q) ?>" class="form-control" placeholder="IMEI | ICCID | iPhone | Bait | descripción">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Por página</label>
        <select name="per_page" class="form-select">
          <?php foreach([25,50,100,200] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2 d-grid">
        <button type="submit" class="btn btn-primary">Aplicar</button>
      </div>

      <div class="col-12 col-md-2 d-grid">
        <a class="btn btn-outline-secondary" href="<?= h(basename(__FILE__)) ?>">Limpiar</a>
      </div>
    </div>
  </form>

  <?php if ($total <= 0): ?>
    <div class="alert alert-info shadow-sm">No hay traspasos para mostrar con esos filtros.</div>
  <?php else: ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <div class="small text-muted">
        Página <strong><?= (int)$page ?></strong> de <strong><?= (int)$totalPages ?></strong>
      </div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>max(1,$page-1)])) ?>">Anterior</a>
          </li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>min($totalPages,$page+1)])) ?>">Siguiente</a>
          </li>
        </ul>
      </nav>
    </div>

    <div class="accordion shadow-sm" id="accordionTraspasos">
      <?php foreach($rowsPage as $t): ?>
        <?php
          $idTraspaso = (int)$t['id_traspaso'];
          $tipoTxt    = (string)$t['tipo'];
          $badge      = badgeEstatus($t['estatus']);
          $chip       = chipTipo($tipoTxt);

          $detalles = null;

          if ($tipoTxt === 'Equipo') {
            $stmtD = $conn->prepare("
              SELECT
                i.id AS id_inv,
                p.marca, p.modelo, p.color,
                p.imei1, p.imei2,
                COALESCE(p.descripcion,'') AS descripcion,
                i.estatus AS estatus_actual
              FROM detalle_traspaso dt
              INNER JOIN inventario i ON i.id = dt.id_inventario
              INNER JOIN productos p ON p.id = i.id_producto
              WHERE dt.id_traspaso = ?
              ORDER BY dt.id ASC
            ");
            $stmtD->bind_param("i", $idTraspaso);
            $stmtD->execute();
            $detalles = $stmtD->get_result();
            $stmtD->close();

          } elseif ($tipoTxt === 'SIM' && $hasSims) {
            $stmtD = $conn->prepare("
              SELECT
                s.id AS id_sim,
                s.iccid,
                s.dn,
                s.operador,
                s.tipo_plan,
                s.estatus AS estatus_actual,
                s.id_sucursal
              FROM detalle_traspaso_sims dts
              INNER JOIN inventario_sims s ON s.id = dts.id_sim
              WHERE dts.id_traspaso = ?
              ORDER BY dts.id ASC
            ");
            $stmtD->bind_param("i", $idTraspaso);
            $stmtD->execute();
            $detalles = $stmtD->get_result();
            $stmtD->close();
          }
        ?>

        <div class="accordion-item mb-2">
          <h2 class="accordion-header" id="heading<?= $tipoTxt.$idTraspaso ?>">
            <button class="accordion-button collapsed soft" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse<?= $tipoTxt.$idTraspaso ?>">
              <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <strong>#<?= $idTraspaso ?></strong>
                  <span class="ms-2"><?= $chip ?></span>
                  <span class="ms-2"><?= h($t['sucursal_origen']) ?> → <?= h($t['sucursal_destino']) ?></span>
                  <span class="text-muted ms-2 small">| <?= h($t['fecha']) ?></span>
                  <?php if (!empty($t['identificador'])): ?>
                    <span class="ms-2 small mono text-muted">| <?= h($t['identificador']) ?></span>
                  <?php endif; ?>
                </div>
                <div><?= $badge ?></div>
              </div>
            </button>
          </h2>

          <div id="collapse<?= $tipoTxt.$idTraspaso ?>" class="accordion-collapse collapse"
               data-bs-parent="#accordionTraspasos">
            <div class="accordion-body p-0">
              <?php if (!$detalles || $detalles->num_rows <= 0): ?>
                <div class="p-3 text-muted">Sin detalle para este traspaso.</div>
              <?php else: ?>

                <?php if ($tipoTxt === 'Equipo'): ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                      <thead class="table-dark">
                        <tr>
                          <th>ID Inv</th>
                          <th>Marca</th>
                          <th>Modelo</th>
                          <th>Color</th>
                          <th>IMEI1</th>
                          <th>IMEI2</th>
                          <th>Descripción</th>
                          <th>Estatus actual</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php while($r = $detalles->fetch_assoc()): ?>
                          <tr>
                            <td><?= (int)$r['id_inv'] ?></td>
                            <td><?= h($r['marca']) ?></td>
                            <td><?= h($r['modelo']) ?></td>
                            <td><?= h($r['color']) ?></td>
                            <td class="mono"><?= h($r['imei1']) ?></td>
                            <td class="mono"><?= h($r['imei2'] ?: '-') ?></td>
                            <td><?= h($r['descripcion']) ?></td>
                            <td><?= h($r['estatus_actual']) ?></td>
                          </tr>
                        <?php endwhile; ?>
                      </tbody>
                    </table>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                      <thead class="table-dark">
                        <tr>
                          <th>ID SIM</th>
                          <th>ICCID</th>
                          <th>DN</th>
                          <th>Operador</th>
                          <th>Plan</th>
                          <th>Estatus</th>
                          <th>Sucursal actual</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php while($r = $detalles->fetch_assoc()): ?>
                          <tr>
                            <td><?= (int)$r['id_sim'] ?></td>
                            <td class="mono"><?= h($r['iccid']) ?></td>
                            <td class="mono"><?= h($r['dn'] ?: '-') ?></td>
                            <td><?= h($r['operador']) ?></td>
                            <td><?= h($r['tipo_plan'] ?: '-') ?></td>
                            <td><?= h($r['estatus_actual']) ?></td>
                            <td><?= (int)$r['id_sucursal'] ?></td>
                          </tr>
                        <?php endwhile; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

              <?php endif; ?>

              <div class="p-2 border-top d-flex justify-content-between flex-wrap gap-2">
                <div class="small text-muted">Creado por: <strong><?= h($t['usuario_creo']) ?></strong></div>
                <div class="small text-muted">Texto: <?= h($t['texto']) ?></div>
              </div>

            </div>
          </div>
        </div>

      <?php endforeach; ?>
    </div>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
      <div class="small text-muted">
        Mostrando <strong><?= (int)min($perPage, max(0, $total - $offset)) ?></strong> de <strong><?= (int)$total ?></strong>
      </div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>max(1,$page-1)])) ?>">Anterior</a>
          </li>
          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
            <a class="page-link" href="<?= h(buildUrl(['page'=>min($totalPages,$page+1)])) ?>">Siguiente</a>
          </li>
        </ul>
      </nav>
    </div>

  <?php endif; ?>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
