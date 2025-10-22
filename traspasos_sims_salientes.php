<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}
require_once __DIR__ . '/db.php';

$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rolUsuario = $_SESSION['rol'] ?? '';
$mensaje    = "";

/* ==========================
   Helpers / Config
========================== */
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function canDeletePending($rol,$idSucursalOrigen,$idSucursalUser){
    if ($rol === 'Admin') return true;
    if ($rol === 'Gerente' && (int)$idSucursalOrigen === (int)$idSucursalUser) return true;
    return false;
}
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

// Aumentar límite por si hay muchas cajas
@$conn->query("SET SESSION group_concat_max_len = 8192");

// Detectar columnas variables
$CAND_CAJA = ['caja_id','id_caja','caja','id_caja_sello'];
$CAND_LOTE = ['lote','id_lote','lote_id','num_lote'];
$COL_CAJA = null; $COL_LOTE = null;
foreach ($CAND_CAJA as $c) if (hasColumn($conn,'inventario_sims',$c)) { $COL_CAJA = $c; break; }
foreach ($CAND_LOTE as $c) if (hasColumn($conn,'inventario_sims',$c)) { $COL_LOTE = $c; break; }
if ($COL_CAJA === null) {
    $mensaje .= "<div class='alert alert-warning mb-2'>⚠️ No se encontró columna de <b>caja</b> en <code>inventario_sims</code>. (Buscadas: ".esc(implode(', ',$CAND_CAJA)).")</div>";
}
if ($COL_LOTE === null) {
    $mensaje .= "<div class='alert alert-warning mb-2'>⚠️ No se encontró columna de <b>lote</b> en <code>inventario_sims</code>. (Buscadas: ".esc(implode(', ',$CAND_LOTE)).")</div>";
}

/* ==========================
   CSRF
========================== */
if (empty($_SESSION['csrf_trs'])) {
    $_SESSION['csrf_trs'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_trs'];

/* ==========================
   POST: Eliminar traspaso **PENDIENTE**
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_pendiente') {
    $idTraspasoDel = (int)($_POST['id_traspaso'] ?? 0);
    $csrfIn = $_POST['csrf'] ?? '';
    if (!$idTraspasoDel || !hash_equals($csrf, $csrfIn)) {
        $mensaje .= "<div class='alert alert-danger'>Solicitud inválida.</div>";
    } else {
        $sqlCab = "SELECT id, id_sucursal_origen, estatus FROM traspasos_sims WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sqlCab);
        $stmt->bind_param("i", $idTraspasoDel);
        $stmt->execute();
        $cab = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cab) {
            $mensaje .= "<div class='alert alert-danger'>El traspaso no existe.</div>";
        } else if (strcasecmp($cab['estatus'], 'Pendiente') !== 0) {
            $mensaje .= "<div class='alert alert-warning'>Solo se pueden eliminar traspasos con estatus <b>Pendiente</b>.</div>";
        } else if (!canDeletePending($rolUsuario, (int)$cab['id_sucursal_origen'], $idSucursal)) {
            $mensaje .= "<div class='alert alert-danger'>No tienes permisos para eliminar este traspaso pendiente.</div>";
        } else {
            // Detalle
            $sqlDet = "SELECT ds.id_sim, i.iccid, i.estatus
                         FROM detalle_traspaso_sims ds
                         JOIN inventario_sims i ON i.id = ds.id_sim
                        WHERE ds.id_traspaso = ?";
            $stmt = $conn->prepare($sqlDet);
            $stmt->bind_param("i", $idTraspasoDel);
            $stmt->execute();
            $sims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!$sims) {
                $mensaje .= "<div class='alert alert-danger'>El traspaso pendiente no tiene detalle de SIMs.</div>";
            } else {
                $errores = [];
                foreach ($sims as $s) {
                    if (preg_match('/vendid|asignad/i', (string)$s['estatus'])) {
                        $errores[] = "SIM {$s['iccid']} con estatus '{$s['estatus']}' no se puede revertir.";
                    }
                }
                if ($errores) {
                    $mensaje .= "<div class='alert alert-danger'><b>No se puede eliminar:</b><ul><li>".implode('</li><li>', array_map('esc',$errores))."</li></ul></div>";
                } else {
                    $conn->begin_transaction();
                    try {
                        $idOrigen = (int)$cab['id_sucursal_origen'];
                        $stmtU = $conn->prepare("UPDATE inventario_sims SET id_sucursal=?, estatus='Disponible' WHERE id=?");
                        foreach ($sims as $s) {
                            $idSim = (int)$s['id_sim'];
                            $stmtU->bind_param("ii",$idOrigen,$idSim);
                            if (!$stmtU->execute()) throw new Exception("Error al revertir SIM id={$idSim}");
                        }
                        $stmtU->close();

                        $stmtD = $conn->prepare("DELETE FROM detalle_traspaso_sims WHERE id_traspaso=?");
                        $stmtD->bind_param("i",$idTraspasoDel);
                        if (!$stmtD->execute()) throw new Exception("No se pudo borrar el detalle.");
                        $stmtD->close();

                        $stmtH = $conn->prepare("DELETE FROM traspasos_sims WHERE id=? AND estatus='Pendiente'");
                        $stmtH->bind_param("i",$idTraspasoDel);
                        if (!$stmtH->execute()) throw new Exception("No se pudo borrar la cabecera.");
                        $stmtH->close();

                        $conn->commit();
                        $mensaje .= "<div class='alert alert-success'>✅ Traspaso #".esc($idTraspasoDel)." eliminado. SIMs regresadas a origen como <b>Disponible</b>.</div>";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $mensaje .= "<div class='alert alert-danger'>Error: ".esc($e->getMessage())."</div>";
                    }
                }
            }
        }
    }
}

/* ==========================
   Filtros (GET)
========================== */
$activeTab = $_GET['tab'] ?? 'pendientes'; // 'pendientes' | 'historico'
$q         = trim($_GET['q'] ?? '');
$fCaja     = trim($_GET['caja'] ?? '');
$fLote     = trim($_GET['lote'] ?? '');
$fDest     = (int)($_GET['destino'] ?? 0); // id_sucursal_destino

// Construir filtros como subconsulta EXISTS sobre detalle
function buildExistsFilters(string $aliasTs, string $colCaja=null, string $colLote=null, string $q='', string $fCaja='', string $fLote=''): array {
    $where = [];
    $types = ''; $params = [];

    if ($q !== '') {
        $where[] = "(i.iccid LIKE CONCAT('%', ?, '%') OR i.dn LIKE CONCAT('%', ?, '%'))";
        $types  .= "ss";
        $params[] = $q; $params[] = $q;
    }
    if ($colCaja && $fCaja !== '') {
        $where[] = "CAST(i.`$colCaja` AS CHAR) LIKE CONCAT('%', ?, '%')";
        $types  .= "s";
        $params[] = $fCaja;
    }
    if ($colLote && $fLote !== '') {
        $where[] = "CAST(i.`$colLote` AS CHAR) LIKE CONCAT('%', ?, '%')";
        $types  .= "s";
        $params[] = $fLote;
    }

    if (!$where) return ['', '', []];

    $sql = " AND EXISTS (
               SELECT 1
                 FROM detalle_traspaso_sims dts
                 JOIN inventario_sims i ON i.id = dts.id_sim
                WHERE dts.id_traspaso = {$aliasTs}.id
                  AND ".implode(' AND ', $where)."
             )";
    return [$sql, $types, $params];
}

/* ==========================
   Catálogo sucursales destino
========================== */
$sucursales = [];
$resS = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");
while ($row=$resS->fetch_assoc()) $sucursales[] = $row;

/* ==========================
   Columnas calculadas (chips)
========================== */
$selectListaCajas = $COL_CAJA
  ? "(SELECT GROUP_CONCAT(DISTINCT i.`$COL_CAJA` ORDER BY i.`$COL_CAJA` SEPARATOR ',')
        FROM detalle_traspaso_sims dts
        JOIN inventario_sims i ON i.id = dts.id_sim
       WHERE dts.id_traspaso = ts.id) AS lista_cajas,"
  : "NULL AS lista_cajas,";

$selectTotalCajas = $COL_CAJA
  ? "(SELECT COUNT(DISTINCT i.`$COL_CAJA`)
        FROM detalle_traspaso_sims dts
        JOIN inventario_sims i ON i.id = dts.id_sim
       WHERE dts.id_traspaso = ts.id) AS total_cajas,"
  : "NULL AS total_cajas,";

/* ==========================
   Query base + filtros dinámicos
========================== */
function fetchTraspasos(mysqli $conn, int $idSucursal, string $estado, string $selectTotalCajas, string $selectListaCajas, ?int $fDest, string $q, ?string $COL_CAJA, ?string $COL_LOTE, string $historyCut=null) {
    // $estado: 'Pendiente' o 'Historico' (!=Pendiente)
    [$existsSql, $types, $params] = buildExistsFilters('ts', $COL_CAJA, $COL_LOTE, $q, $_GET['caja'] ?? '', $_GET['lote'] ?? '');

    $sql = "
      SELECT ts.id, ts.fecha_traspaso, ts.id_sucursal_origen, ts.id_sucursal_destino,
             so.nombre AS sucursal_origen, sd.nombre AS sucursal_destino,
             u.nombre AS usuario_creo, ts.estatus,
             (SELECT COUNT(*) FROM detalle_traspaso_sims dts WHERE dts.id_traspaso = ts.id) AS total_sims,
             $selectTotalCajas
             $selectListaCajas
             1 AS _ok
        FROM traspasos_sims ts
        JOIN sucursales so ON so.id = ts.id_sucursal_origen
        JOIN sucursales sd ON sd.id = ts.id_sucursal_destino
        JOIN usuarios   u  ON u.id = ts.usuario_creo
       WHERE ts.id_sucursal_origen = ?
    ";

    $bindTypes = 'i';
    $bindParams = [$idSucursal];

    if ($estado === 'Pendiente') {
        $sql .= " AND ts.estatus = 'Pendiente' ";
    } else {
        $sql .= " AND ts.estatus <> 'Pendiente' ";
        if ($historyCut) {
            $sql .= " AND ts.fecha_traspaso >= ? ";
            $bindTypes .= 's';
            $bindParams[] = $historyCut; // e.g. DATE_SUB(CURDATE(), INTERVAL 90 DAY) eval desde PHP
        }
    }

    if ($fDest) {
        $sql .= " AND ts.id_sucursal_destino = ? ";
        $bindTypes .= 'i';
        $bindParams[] = $fDest;
    }

    if ($existsSql) {
        $sql .= $existsSql;
        $bindTypes .= $types;
        array_push($bindParams, ...$params);
    }

    $sql .= ($estado === 'Pendiente')
          ? " ORDER BY ts.fecha_traspaso ASC, ts.id ASC"
          : " ORDER BY ts.fecha_traspaso DESC, ts.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    return $res;
}

// Cut de histórico: últimos 90 días
$cutDate = (new DateTime('now', new DateTimeZone('America/Mexico_City')))
            ->sub(new DateInterval('P90D'))->format('Y-m-d');

$pendientes = fetchTraspasos($conn, $idSucursal, 'Pendiente', $selectTotalCajas, $selectListaCajas, $fDest ?: null, $q, $COL_CAJA, $COL_LOTE, null);
$historico  = fetchTraspasos($conn, $idSucursal, 'Historico', $selectTotalCajas, $selectListaCajas, $fDest ?: null, $q, $COL_CAJA, $COL_LOTE, $cutDate);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Traspasos de SIMs — Salientes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    .toggle-link { text-decoration: none; }
    .toggle-link[aria-expanded="true"] .lbl-open { display:none; }
    .toggle-link[aria-expanded="false"] .lbl-close { display:none; }
    .chips { display:flex; flex-wrap:wrap; gap:.35rem; }
    .chip-badge { border-radius: 9999px; }
    @media (max-width: 576px){
      .table { font-size: 12.5px; }
      .table td, .table th { padding: .4rem .5rem; }
      .card-header .badge { font-size: .78rem; }
      .container { padding-left: 12px; padding-right: 12px; }
    }
    .search-card { position: sticky; top: 0; z-index: 1010; }
  </style>
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container mt-3">

  <?= $mensaje ?>

  <!-- ========= Filtros ========= -->
  <div class="card shadow-sm mb-3 search-card">
    <div class="card-body py-3">
      <form class="row g-2 align-items-end" method="get">
        <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Buscar (ICCID o DN)</label>
          <input type="text" name="q" value="<?= esc($q) ?>" class="form-control" placeholder="Ej. 8952..., 551234..." autocomplete="off">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Caja<?= $COL_CAJA ? " (<code>".esc($COL_CAJA)."</code>)":"" ?></label>
          <input type="text" name="caja" value="<?= esc($fCaja) ?>" class="form-control" placeholder="Caja...">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label mb-1">Lote<?= $COL_LOTE ? " (<code>".esc($COL_LOTE)."</code>)":"" ?></label>
          <input type="text" name="lote" value="<?= esc($fLote) ?>" class="form-control" placeholder="Lote...">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label mb-1">Sucursal destino</label>
          <select class="form-select" name="destino">
            <option value="0">— Todas —</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $fDest===(int)$s['id']?'selected':'' ?>>
                <?= esc($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> Aplicar</button>
          <a class="btn btn-outline-secondary" href="?tab=<?= esc($activeTab) ?>">Limpiar</a>
        </div>
      </form>
      <div class="small text-muted mt-2">
        Los filtros aplican a <b>ambas pestañas</b>. Origen fijo: tu sucursal (ID <?= (int)$idSucursal ?>).
      </div>
    </div>
  </div>

  <!-- ========= Tabs ========= -->
  <ul class="nav nav-tabs" id="traspTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $activeTab==='pendientes'?'active':'' ?>" id="tab-pendientes" data-bs-toggle="tab" data-bs-target="#pane-pendientes" type="button" role="tab" aria-controls="pane-pendientes" aria-selected="<?= $activeTab==='pendientes'?'true':'false' ?>">Pendientes</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $activeTab==='historico'?'active':'' ?>" id="tab-historico" data-bs-toggle="tab" data-bs-target="#pane-historico" type="button" role="tab" aria-controls="pane-historico" aria-selected="<?= $activeTab==='historico'?'true':'false' ?>">Histórico (90 días)</button>
    </li>
  </ul>

  <div class="tab-content">
    <!-- ======= PENDIENTES ======= -->
    <div class="tab-pane fade <?= $activeTab==='pendientes'?'show active':'' ?>" id="pane-pendientes" role="tabpanel" aria-labelledby="tab-pendientes" tabindex="0">
      <div class="py-3">
        <?php
          $cntPend = $pendientes ? $pendientes->num_rows : 0;
          if ($cntPend === 0) {
            echo "<div class='alert alert-success'>No tienes traspasos salientes pendientes. ✅</div>";
          }
        ?>
        <?php while($pendientes && $t = $pendientes->fetch_assoc()): ?>
          <?php
            $idTr = (int)$t['id'];
            $permEliminar = canDeletePending($rolUsuario, (int)$t['id_sucursal_origen'], $idSucursal);
            $collapseId = "detTrPend_".$idTr;

            // Chips de caja
            $chips = [];
            if (!empty($t['lista_cajas'])) {
                foreach (explode(',', $t['lista_cajas']) as $cx) {
                    $cx = trim($cx);
                    if ($cx !== '') $chips[] = $cx;
                }
            }

            // Detalle para tabla
            $sqlDet = "
              SELECT i.id, i.iccid, i.dn, ".($COL_CAJA ? "i.`$COL_CAJA`":"NULL")." AS caja_val,
                     ".($COL_LOTE ? "i.`$COL_LOTE`":"NULL")." AS lote_val,
                     i.estatus, i.id_sucursal
                FROM detalle_traspaso_sims ds
                JOIN inventario_sims i ON i.id = ds.id_sim
               WHERE ds.id_traspaso = ?
               ORDER BY ".($COL_CAJA ? "i.`$COL_CAJA`, ":"")." i.iccid
            ";
            $stmtDet = $conn->prepare($sqlDet);
            $stmtDet->bind_param("i",$idTr);
            $stmtDet->execute();
            $rows = $stmtDet->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!$chips && $COL_CAJA) {
                $u=[]; foreach ($rows as $r) { $cv=trim((string)($r['caja_val']??'')); if($cv!=='') $u[$cv]=true; }
                $chips = array_keys($u); sort($chips,SORT_NATURAL);
            }
          ?>
          <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
              <div class="d-flex justify-content-between flex-wrap gap-2">
                <div class="d-flex flex-column flex-md-row gap-2">
                  <div>
                    <b>#<?= esc($idTr) ?></b> · Origen: <?= esc($t['sucursal_origen']) ?> · Destino: <?= esc($t['sucursal_destino']) ?> · Fecha: <?= esc($t['fecha_traspaso']) ?>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-light text-dark">SIMs: <?= (int)$t['total_sims'] ?></span>
                    <span class="badge text-bg-light text-dark">Cajas: <?= esc((string)$t['total_cajas']) ?></span>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span>Estatus: <span class="badge bg-warning text-dark"><?= esc($t['estatus']) ?></span> · Creado por: <?= esc($t['usuario_creo']) ?></span>
                </div>
              </div>
              <?php if ($chips): ?>
                <div class="mt-2 chips">
                  <?php foreach ($chips as $chip): ?>
                    <span class="badge text-bg-info text-dark chip-badge">Caja: <?= esc($chip) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="card-body border-bottom py-2">
              <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted">
                  Resumen: <b><?= (int)$t['total_sims'] ?></b> SIMs<?= $t['total_cajas']!==null ? " en <b>".esc((string)$t['total_cajas'])."</b> caja(s)" : "" ?>.
                </div>
                <a class="btn btn-sm btn-outline-light text-white border-white toggle-link" data-bs-toggle="collapse" href="#<?= esc($collapseId) ?>" role="button" aria-expanded="false" aria-controls="<?= esc($collapseId) ?>">
                  <span class="lbl-open">Ver SIMs (<?= (int)$t['total_sims'] ?>)</span>
                  <span class="lbl-close">Ocultar SIMs</span>
                </a>
              </div>
            </div>

            <div id="<?= esc($collapseId) ?>" class="collapse">
              <div class="card-body p-0">
                <table class="table table-bordered table-sm mb-0 align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>ID SIM</th>
                      <th>ICCID</th>
                      <th>DN</th>
                      <th>Caja</th>
                      <th>Lote</th>
                      <th>Estatus</th>
                      <th>Sucursal actual</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($rows as $sim): ?>
                    <tr>
                      <td><?= esc($sim['id']) ?></td>
                      <td><?= esc($sim['iccid']) ?></td>
                      <td><?= $sim['dn'] ? esc($sim['dn']) : '-' ?></td>
                      <td><?= $COL_CAJA ? esc((string)$sim['caja_val']) : '-' ?></td>
                      <td><?= $COL_LOTE ? esc((string)$sim['lote_val']) : '-' ?></td>
                      <td><?= esc($sim['estatus']) ?></td>
                      <td><?= esc((string)$sim['id_sucursal']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card-footer d-flex justify-content-end gap-2 flex-wrap">
              <button type="button" class="btn btn-outline-light btn-sm" onclick="openAcuse(<?= $idTr ?>)">
                Reimprimir acuse
              </button>
              <?php if ($permEliminar): ?>
                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalEliminar<?= $idTr ?>">
                  Eliminar traspaso pendiente & revertir SIMs
                </button>
              <?php else: ?>
                <span class="text-muted">Sin permisos para eliminar.</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Modal confirmación -->
          <div class="modal fade" id="modalEliminar<?= $idTr ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <form method="post">
                  <div class="modal-header">
                    <h5 class="modal-title">Confirmar eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <p>Eliminar traspaso <b>#<?= esc($idTr) ?></b> (Pendiente).</p>
                    <p>Las SIMs regresarán a la <b>sucursal de origen</b> con estatus <b>Disponible</b>. La caja/lote se preservan.</p>
                    <p class="mb-0 text-danger"><b>Acción irreversible.</b></p>
                  </div>
                  <div class="modal-footer">
                    <input type="hidden" name="accion" value="eliminar_pendiente">
                    <input type="hidden" name="id_traspaso" value="<?= esc($idTr) ?>">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, eliminar</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <?php $stmtDet->close(); ?>
        <?php endwhile; ?>
      </div>
    </div>

    <!-- ======= HISTÓRICO ======= -->
    <div class="tab-pane fade <?= $activeTab==='historico'?'show active':'' ?>" id="pane-historico" role="tabpanel" aria-labelledby="tab-historico" tabindex="0">
      <div class="py-3">
        <?php
          $cntHist = $historico ? $historico->num_rows : 0;
          if ($cntHist === 0) {
            echo "<div class='alert alert-info'>Sin histórico en los últimos 90 días con los filtros actuales.</div>";
          }
        ?>
        <?php while($historico && $t = $historico->fetch_assoc()): ?>
          <?php
            $idTr = (int)$t['id'];
            $collapseId = "detTrHist_".$idTr;

            $chips = [];
            if (!empty($t['lista_cajas'])) {
                foreach (explode(',', $t['lista_cajas']) as $cx) {
                    $cx = trim($cx);
                    if ($cx !== '') $chips[] = $cx;
                }
            }

            $sqlDetH = "
              SELECT i.id, i.iccid, i.dn, ".($COL_CAJA ? "i.`$COL_CAJA`":"NULL")." AS caja_val,
                     ".($COL_LOTE ? "i.`$COL_LOTE`":"NULL")." AS lote_val,
                     i.estatus, i.id_sucursal
                FROM detalle_traspaso_sims ds
                JOIN inventario_sims i ON i.id = ds.id_sim
               WHERE ds.id_traspaso = ?
               ORDER BY ".($COL_CAJA ? "i.`$COL_CAJA`, ":"")." i.iccid
            ";
            $stmtDetH = $conn->prepare($sqlDetH);
            $stmtDetH->bind_param("i",$idTr);
            $stmtDetH->execute();
            $rowsH = $stmtDetH->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!$chips && $COL_CAJA) {
                $u=[]; foreach ($rowsH as $r) { $cv=trim((string)($r['caja_val']??'')); if($cv!=='') $u[$cv]=true; }
                $chips = array_keys($u); sort($chips,SORT_NATURAL);
            }

            $badgeStatus = 'text-bg-secondary';
            if (preg_match('/recib|acept/i', $t['estatus'])) $badgeStatus='text-bg-success';
            if (preg_match('/rechaz|cancel/i', $t['estatus'])) $badgeStatus='text-bg-danger';
          ?>
          <div class="card mb-3 shadow-sm">
            <div class="card-header bg-white">
              <div class="d-flex justify-content-between flex-wrap gap-2">
                <div class="d-flex flex-column flex-md-row gap-2">
                  <div>
                    <b>#<?= esc($idTr) ?></b> · Origen: <?= esc($t['sucursal_origen']) ?> · Destino: <?= esc($t['sucursal_destino']) ?> · Fecha: <?= esc($t['fecha_traspaso']) ?>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-light">SIMs: <?= (int)$t['total_sims'] ?></span>
                    <span class="badge text-bg-light">Cajas: <?= esc((string)$t['total_cajas']) ?></span>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span>Estatus: <span class="badge <?= $badgeStatus ?>"><?= esc($t['estatus']) ?></span> · Creado por: <?= esc($t['usuario_creo']) ?></span>
                </div>
              </div>
              <?php if ($chips): ?>
                <div class="mt-2 chips">
                  <?php foreach ($chips as $chip): ?>
                    <span class="badge text-bg-info text-dark chip-badge">Caja: <?= esc($chip) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="card-body border-bottom py-2">
              <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted">
                  Resumen: <b><?= (int)$t['total_sims'] ?></b> SIMs<?= $t['total_cajas']!==null ? " en <b>".esc((string)$t['total_cajas'])."</b> caja(s)" : "" ?>.
                </div>
                <a class="btn btn-sm btn-outline-secondary toggle-link" data-bs-toggle="collapse" href="#<?= esc($collapseId) ?>" role="button" aria-expanded="false" aria-controls="<?= esc($collapseId) ?>">
                  <span class="lbl-open">Ver SIMs (<?= (int)$t['total_sims'] ?>)</span>
                  <span class="lbl-close">Ocultar SIMs</span>
                </a>
              </div>
            </div>

            <div id="<?= esc($collapseId) ?>" class="collapse">
              <div class="card-body p-0">
                <table class="table table-bordered table-sm mb-0 align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>ID SIM</th>
                      <th>ICCID</th>
                      <th>DN</th>
                      <th>Caja</th>
                      <th>Lote</th>
                      <th>Estatus</th>
                      <th>Sucursal actual</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($rowsH as $sim): ?>
                    <tr>
                      <td><?= esc($sim['id']) ?></td>
                      <td><?= esc($sim['iccid']) ?></td>
                      <td><?= $sim['dn'] ? esc($sim['dn']) : '-' ?></td>
                      <td><?= $COL_CAJA ? esc((string)$sim['caja_val']) : '-' ?></td>
                      <td><?= $COL_LOTE ? esc((string)$sim['lote_val']) : '-' ?></td>
                      <td><?= esc($sim['estatus']) ?></td>
                      <td><?= esc((string)$sim['id_sucursal']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card-footer d-flex justify-content-end gap-2 flex-wrap">
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openAcuse(<?= $idTr ?>)">
                Reimprimir acuse
              </button>
            </div>
          </div>
          <?php $stmtDetH->close(); ?>
        <?php endwhile; ?>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Reimprimir Acuse -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de Traspaso <span id="hdrAcuseId" class="text-muted"></span></h5>
        <div class="d-flex gap-2">
          <a id="btnNuevaPestana" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
          <button id="btnPrintAcuse" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Imprimir</button>
          <button class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
      <div class="modal-body p-0">
        <iframe id="acuseFrame" src="" style="width:100%; height:75vh; border:0;"></iframe>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Mantener pestaña activa al enviar filtros
  const tabs = document.querySelectorAll('button[data-bs-toggle="tab"]');
  tabs.forEach(btn=>{
    btn.addEventListener('shown.bs.tab', (e)=>{
      const tabId = e.target.id === 'tab-historico' ? 'historico' : 'pendientes';
      const hidden = document.querySelector('input[name="tab"]');
      if (hidden) hidden.value = tabId;
      const url = new URL(window.location);
      url.searchParams.set('tab', tabId);
      history.replaceState(null, '', url.toString());
    });
  });

  // Modal acuse
  const modalAcuse    = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const acuseFrame    = document.getElementById('acuseFrame');
  const hdrAcuseId    = document.getElementById('hdrAcuseId');
  const btnPrintAcuse = document.getElementById('btnPrintAcuse');
  const btnNuevaPest  = document.getElementById('btnNuevaPestana');

  window.openAcuse = function(id){
    const url = 'acuse_traspaso_sims.php?id=' + encodeURIComponent(id);
    acuseFrame.src = url;
    hdrAcuseId.textContent = '#' + id;
    btnNuevaPest.href = url;
    modalAcuse.show();
  };
  btnPrintAcuse.addEventListener('click', ()=>{
    const frame = acuseFrame;
    if (!frame || !frame.contentWindow) return;
    if (frame.contentDocument && frame.contentDocument.readyState !== 'complete') {
      frame.addEventListener('load', () => frame.contentWindow.print(), { once:true });
    } else {
      frame.contentWindow.print();
    }
  });
  document.getElementById('modalAcuse').addEventListener('hidden.bs.modal', ()=> {
    acuseFrame.src = '';
  });
})();
</script>
</body>
</html>

