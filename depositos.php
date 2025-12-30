<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') != 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

/* =======================
   Helpers
   ======================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$t}'
              AND COLUMN_NAME = '{$c}'
            LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}
function csv_escape($v){
    $v = (string)$v;
    $v = str_replace(["\r","\n"], [' ',' '], $v);
    $needs = strpbrk($v, ",\"\t") !== false;
    return $needs ? '"' . str_replace('"','""',$v) . '"' : $v;
}
function renderDetalleCorteHTML(mysqli $conn, int $idCorte): string {
    // Cobros
    $qc = $conn->prepare("
      SELECT cb.id, cb.motivo, cb.tipo_pago, cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta,
             cb.comision_especial, cb.fecha_cobro, u.nombre AS ejecutivo
      FROM cobros cb
      LEFT JOIN usuarios u ON u.id = cb.id_usuario
      WHERE cb.id_corte = ?
      ORDER BY cb.fecha_cobro ASC, cb.id ASC
    ");
    $qc->bind_param('i', $idCorte);
    $qc->execute();
    $rowsCobros = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
    $qc->close();

    // Depósitos
    $qd = $conn->prepare("
      SELECT ds.id, ds.monto_depositado, ds.banco, ds.referencia, ds.estado, ds.fecha_deposito,
             ds.comprobante_archivo, ds.comentario_admin, ds.motivo_ajuste, ds.monto_validado, ds.ajuste,
             s.nombre AS sucursal
      FROM depositos_sucursal ds
      INNER JOIN sucursales s ON s.id = ds.id_sucursal
      WHERE ds.id_corte = ?
      ORDER BY ds.id ASC
    ");
    $qd->bind_param('i', $idCorte);
    $qd->execute();
    $rowsDep = $qd->get_result()->fetch_all(MYSQLI_ASSOC);
    $qd->close();

    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0"><i class="bi bi-list-ul me-1"></i> Detalle del corte #<?= (int)$idCorte ?></h5>
      <a class="btn btn-outline-success btn-sm" href="?export=csv_corte&id=<?= (int)$idCorte ?>" target="_blank">
        <i class="bi bi-filetype-csv me-1"></i> Exportar CSV
      </a>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="fw-semibold mb-2"><i class="bi bi-receipt"></i> Cobros del corte</div>
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>ID Cobro</th>
                <th>Fecha/Hora</th>
                <th>Ejecutivo</th>
                <th>Motivo</th>
                <th>Tipo pago</th>
                <th class="text-end">Total</th>
                <th class="text-end">Efectivo</th>
                <th class="text-end">Tarjeta</th>
                <th class="text-end">Com. Esp.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rowsCobros as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['fecha_cobro']) ?></td>
                <td><?= htmlspecialchars($r['ejecutivo'] ?? 'N/D') ?></td>
                <td><?= htmlspecialchars($r['motivo']) ?></td>
                <td><?= htmlspecialchars($r['tipo_pago']) ?></td>
                <td class="text-end">$<?= number_format($r['monto_total'],2) ?></td>
                <td class="text-end">$<?= number_format($r['monto_efectivo'],2) ?></td>
                <td class="text-end">$<?= number_format($r['monto_tarjeta'],2) ?></td>
                <td class="text-end">$<?= number_format($r['comision_especial'],2) ?></td>
              </tr>
              <?php endforeach; if(!$rowsCobros): ?>
              <tr><td colspan="9" class="text-muted">Sin cobros ligados a este corte.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="fw-semibold mb-2"><i class="bi bi-bank"></i> Depósitos del corte</div>
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead>
              <tr>
                <th>ID Dep.</th>
                <th>Sucursal</th>
                <th class="text-end">Monto</th>
                <th>Banco</th>
                <th>Ref</th>
                <th>Estado</th>
                <th>Comp.</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rowsDep as $d): ?>
              <tr class="<?= $d['estado']==='Validado'?'table-success':($d['estado']==='Parcial'?'table-warning':'') ?>">
                <td><?= (int)$d['id'] ?></td>
                <td><?= htmlspecialchars($d['sucursal']) ?></td>
                <td class="text-end">$<?= number_format($d['monto_depositado'],2) ?></td>
                <td><?= htmlspecialchars($d['banco']) ?></td>
                <td><code><?= htmlspecialchars($d['referencia']) ?></code></td>
                <td>
                  <span class="badge <?= $d['estado']==='Validado'?'bg-success':($d['estado']==='Parcial'?'bg-warning text-dark':'bg-secondary') ?>">
                    <?= htmlspecialchars($d['estado']) ?>
                  </span>
                  <?php if (!empty($d['motivo_ajuste'])): ?>
                    <div class="small text-muted mt-1 text-break"><?= nl2br(htmlspecialchars($d['motivo_ajuste'])) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($d['comprobante_archivo'])): ?>
                    <button class="btn btn-outline-primary btn-sm js-ver"
                            data-src="deposito_comprobante.php?id=<?= (int)$d['id'] ?>"
                            data-bs-toggle="modal" data-bs-target="#visorModal">
                      Ver
                    </button>
                  <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($d['estado'] !== 'Validado'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$d['id'] ?>, '<?= htmlspecialchars($d['sucursal'],ENT_QUOTES) ?>', '<?= number_format($d['monto_depositado'],2) ?>');">
                      <input type="hidden" name="id_deposito" value="<?= (int)$d['id'] ?>">
                      <button name="accion" value="Validar" class="btn btn-success btn-sm">
                        Validar
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; if(!$rowsDep): ?>
              <tr><td colspan="8" class="text-muted">Sin depósitos ligados a este corte.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/* =======================
   Auto-migración segura
   ======================= */
if (!hasColumn($conn, 'depositos_sucursal', 'comentario_admin')) {
    @$conn->query("ALTER TABLE depositos_sucursal
                   ADD COLUMN comentario_admin TEXT NULL AFTER referencia");
}
if (!hasColumn($conn, 'depositos_sucursal', 'motivo_ajuste')) {
    @$conn->query("ALTER TABLE depositos_sucursal
                   ADD COLUMN motivo_ajuste TEXT NULL AFTER ajuste");
}
if (!hasColumn($conn, 'depositos_sucursal', 'monto_validado')) {
    @$conn->query("ALTER TABLE depositos_sucursal
                   ADD COLUMN monto_validado DECIMAL(10,2) NULL AFTER comprobante_subido_por");
}
// (ajuste normalmente ya existe, pero si no, lo creamos)
if (!hasColumn($conn, 'depositos_sucursal', 'ajuste')) {
    @$conn->query("ALTER TABLE depositos_sucursal
                   ADD COLUMN ajuste DECIMAL(10,2) NULL AFTER monto_depositado");
}

/* =======================
   AJAX detalle de corte
   ======================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalle_corte') {
    $id = (int)($_GET['id'] ?? 0);
    header('Content-Type: text/html; charset=UTF-8');
    if ($id > 0) {
        echo renderDetalleCorteHTML($conn, $id);
    } else {
        echo '<div class="alert alert-warning mb-0">Corte inválido.</div>';
    }
    exit;
}

/* =======================
   Export CSV del corte
   ======================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv_corte') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        die('Corte inválido');
    }
    $meta = $conn->query("
      SELECT cc.id, s.nombre AS sucursal, cc.fecha_operacion, cc.fecha_corte, cc.total_efectivo, cc.total_tarjeta, cc.total_comision_especial, cc.total_general
      FROM cortes_caja cc
      INNER JOIN sucursales s ON s.id = cc.id_sucursal
      WHERE cc.id = {$id}
      LIMIT 1
    ")->fetch_assoc();

    $qc = $conn->prepare("
      SELECT cb.id, cb.fecha_cobro, u.nombre AS ejecutivo, cb.motivo, cb.tipo_pago,
             cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta, cb.comision_especial
      FROM cobros cb
      LEFT JOIN usuarios u ON u.id = cb.id_usuario
      WHERE cb.id_corte = ?
      ORDER BY cb.fecha_cobro ASC, cb.id ASC
    ");
    $qc->bind_param('i', $id);
    $qc->execute();
    $rowsCobros = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
    $qc->close();

    $qd = $conn->prepare("
      SELECT ds.id, s.nombre AS sucursal, ds.monto_depositado, ds.banco, ds.referencia,
             ds.estado, ds.fecha_deposito, ds.motivo_ajuste, ds.monto_validado, ds.ajuste, ds.comentario_admin
      FROM depositos_sucursal ds
      INNER JOIN sucursales s ON s.id = ds.id_sucursal
      WHERE ds.id_corte = ?
      ORDER BY ds.id ASC
    ");
    $qd->bind_param('i', $id);
    $qd->execute();
    $rowsDep = $qd->get_result()->fetch_all(MYSQLI_ASSOC);
    $qd->close();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="corte_'.$id.'_detalle.csv"');
    $out = fopen('php://output', 'w');

    if ($meta) {
        fputs($out, "Corte,".csv_escape($meta['id']).",Sucursal,".csv_escape($meta['sucursal']).",Fecha Operación,".csv_escape($meta['fecha_operacion']).",Fecha Corte,".csv_escape($meta['fecha_corte'])."\r\n");
        fputs($out, "Total Efectivo,".csv_escape($meta['total_efectivo']).",Total Tarjeta,".csv_escape($meta['total_tarjeta']).",Com. Esp.,".csv_escape($meta['total_comision_especial']).",Total General,".csv_escape($meta['total_general'])."\r\n\r\n");
    }

    fputs($out, "Sección,Cobros\r\n");
    fputcsv($out, ['ID Cobro','Fecha/Hora','Ejecutivo','Motivo','Tipo pago','Total','Efectivo','Tarjeta','Com. Esp.']);
    foreach ($rowsCobros as $r) {
        fputcsv($out, [
            $r['id'],$r['fecha_cobro'],$r['ejecutivo'],$r['motivo'],$r['tipo_pago'],
            $r['monto_total'],$r['monto_efectivo'],$r['monto_tarjeta'],$r['comision_especial']
        ]);
    }
    fputs($out, "\r\n");

    fputs($out, "Sección,Depósitos\r\n");
    fputcsv($out, ['ID Depósito','Sucursal','Monto','Banco','Referencia','Estado','Fecha Depósito','Motivo Ajuste','Monto Validado','Ajuste','Comentario Admin']);
    foreach ($rowsDep as $d) {
        fputcsv($out, [
            $d['id'],$d['sucursal'],$d['monto_depositado'],$d['banco'],$d['referencia'],$d['estado'],$d['fecha_deposito'],
            $d['motivo_ajuste'],$d['monto_validado'],$d['ajuste'],$d['comentario_admin']
        ]);
    }
    fclose($out);
    exit;
}

/* =======================
   POST acciones
   ======================= */
$msg = '';
$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
    $idDeposito = intval($_POST['id_deposito']);
    $accion     = $_POST['accion'];

    if ($accion === 'Validar') {
        $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET estado='Validado', id_admin_valida=?, actualizado_en=NOW()
            WHERE id=? AND estado='Pendiente'
        ");
        $stmt->bind_param("ii", $_SESSION['id_usuario'], $idDeposito);
        $stmt->execute();

        // Cierre de corte si procede (MISMA LÓGICA)
        $sqlCorte = "
            SELECT ds.id_corte, cc.total_efectivo,
                   IFNULL(SUM(ds2.monto_depositado),0) AS suma_depositos
            FROM depositos_sucursal ds
            INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
            INNER JOIN depositos_sucursal ds2 ON ds2.id_corte = ds.id_corte AND ds2.estado='Validado'
            WHERE ds.id = ?
            GROUP BY ds.id_corte
        ";
        $stmtCorte = $conn->prepare($sqlCorte);
        $stmtCorte->bind_param("i", $idDeposito);
        $stmtCorte->execute();
        $corteData = $stmtCorte->get_result()->fetch_assoc();

        if ($corteData && $corteData['suma_depositos'] >= $corteData['total_efectivo']) {
            $stmtClose = $conn->prepare("
                UPDATE cortes_caja
                SET estado='Cerrado', depositado=1, monto_depositado=?, fecha_deposito=NOW()
                WHERE id=?
            ");
            $stmtClose->bind_param("di", $corteData['suma_depositos'], $corteData['id_corte']);
            $stmtClose->execute();
        }

        $msg = "<div class='alert alert-success mb-3'>✅ Depósito validado correctamente.</div>";

    } elseif ($accion === 'GuardarComentario') {
        $comentario = trim($_POST['comentario_admin'] ?? '');
        $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET comentario_admin = ?, actualizado_en = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("si", $comentario, $idDeposito);
        $stmt->execute();

        if ($esAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        } else {
            $msg = "<div class='alert alert-primary mb-3'>📝 Comentario guardado.</div>";
        }

    } elseif ($accion === 'SolicitarCorreccion') {
        $motivo = trim($_POST['motivo_ajuste'] ?? '');
        $coment = trim($_POST['comentario_admin'] ?? '');

        $monto_validado = (isset($_POST['monto_validado']) && $_POST['monto_validado'] !== '') ? (float)$_POST['monto_validado'] : null;
        $ajuste         = (isset($_POST['ajuste']) && $_POST['ajuste'] !== '') ? (float)$_POST['ajuste'] : null;

        if ($motivo === '') {
            $msg = "<div class='alert alert-warning mb-3'>⚠ Especifica el motivo de la corrección.</div>";
        } else {
            // Pasa a Parcial y guarda motivo/nota (acepta también editar una corrección ya en Parcial)
            $stmt = $conn->prepare("
                UPDATE depositos_sucursal
                SET estado='Parcial',
                    motivo_ajuste=?,
                    comentario_admin=?,
                    monto_validado=?,
                    ajuste=?,
                    id_admin_valida=?,
                    actualizado_en=NOW()
                WHERE id=? AND estado IN ('Pendiente','Parcial')
            ");
            $idAdmin = (int)($_SESSION['id_usuario'] ?? 0);
            $stmt->bind_param("ssddii", $motivo, $coment, $monto_validado, $ajuste, $idAdmin, $idDeposito);
            $stmt->execute();

            $msg = "<div class='alert alert-warning mb-3'>🟡 Corrección solicitada. La sucursal podrá corregir y reenviar.</div>";
        }
    }
}

/* =======================
   Consultas principales
   ======================= */
$sqlPendientes = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           cc.total_efectivo,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo,
           ds.comentario_admin,
           ds.motivo_ajuste,
           ds.monto_validado,
           ds.ajuste
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE ds.estado IN ('Pendiente','Parcial')
    ORDER BY (ds.estado='Pendiente') DESC, cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);

$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? '');

if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
    $yr = (int)$m[1]; $wk = (int)$m[2];
    $dt = new DateTime();
    $dt->setISODate($yr, $wk);
    $desde = $dt->format('Y-m-d');
    $dt->modify('+6 days');
    $hasta = $dt->format('Y-m-d');
}

$sqlHistorial = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           ds.fecha_deposito,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo,
           ds.comentario_admin,
           ds.motivo_ajuste,
           ds.monto_validado,
           ds.ajuste
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE 1=1
";
$types = ''; $params = [];
if ($sucursal_id > 0) { $sqlHistorial .= " AND s.id = ? "; $types .= 'i'; $params[] = $sucursal_id; }
if ($desde !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito) >= ? "; $types .= 's'; $params[] = $desde; }
if ($hasta !== '')    { $sqlHistorial .= " AND DATE(ds.fecha_deposito) <= ? "; $types .= 's'; $params[] = $hasta; }
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stmtH = $conn->prepare($sqlHistorial);
if ($types) { $stmtH->bind_param($types, ...$params); }
$stmtH->execute();
$historial = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

$c_sucursal_id = isset($_GET['c_sucursal_id']) ? (int)$_GET['c_sucursal_id'] : 0;
$c_desde       = trim($_GET['c_desde'] ?? '');
$c_hasta       = trim($_GET['c_hasta'] ?? '');

$sqlCortes = "
  SELECT cc.id,
         s.nombre AS sucursal,
         cc.fecha_operacion,
         cc.fecha_corte,
         cc.estado,
         cc.total_efectivo,
         cc.total_tarjeta,
         cc.total_comision_especial,
         cc.total_general,
         cc.depositado,
         cc.monto_depositado,
         (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE 1=1
";
$typesC = ''; $paramsC = [];
if ($c_sucursal_id > 0) { $sqlCortes .= " AND cc.id_sucursal = ? "; $typesC .= 'i'; $paramsC[] = $c_sucursal_id; }
if ($c_desde !== '')    { $sqlCortes .= " AND cc.fecha_operacion >= ? "; $typesC .= 's'; $paramsC[] = $c_desde; }
if ($c_hasta !== '')    { $sqlCortes .= " AND cc.fecha_operacion <= ? "; $typesC .= 's'; $paramsC[] = $c_hasta; }
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";
$stmtC = $conn->prepare($sqlCortes);
if ($typesC) { $stmtC->bind_param($typesC, ...$paramsC); }
$stmtC->execute();
$cortes = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

/* =======================
   Métricas UI
   ======================= */
$pendCount = count($pendientes);
$pendMonto = 0.0; foreach ($pendientes as $p) { $pendMonto += (float)$p['monto_depositado']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Validación de Depósitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{
      --brand:#0d6efd;
      --bg1:#f8fafc;
      --ink:#0f172a;
      --muted:#64748b;
      --soft:#eef2ff;
    }
    body{ background:
      radial-gradient(1200px 400px at 120% -50%, rgba(13,110,253,.07), transparent),
      radial-gradient(1000px 380px at -10% 120%, rgba(25,135,84,.06), transparent),
      var(--bg1);
    }
    .page-title{font-weight:800; letter-spacing:.2px; color:var(--ink);}
    .card-elev{border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);}
    .section-title{font-size:.95rem; font-weight:700; color:#334155; letter-spacing:.6px; text-transform:uppercase; display:flex; align-items:center; gap:.5rem;}
    .badge-soft{background:var(--soft); color:#1e40af; border:1px solid #dbeafe;}
    .help-text{color:var(--muted); font-size:.9rem;}
    .table thead th{ position:sticky; top:0; background:#0f172a; color:#fff; z-index:1;}
    .table-hover tbody tr:hover{ background: rgba(13,110,253,.06); }
    .table-xs td, .table-xs th{ padding:.45rem .6rem; font-size:.92rem; vertical-align: middle; }
    .nav-tabs .nav-link{border:0; border-bottom:2px solid transparent;}
    .nav-tabs .nav-link.active{border-bottom-color:var(--brand); font-weight:700;}
    .sticky-actions{ position:sticky; bottom:0; background:#fff; padding:.5rem; border-top:1px solid #e5e7eb;}
    code{background:#f1f5f9; padding:.1rem .35rem; border-radius:.35rem;}
    .comment-cell textarea{ min-width: 240px; min-height: 38px; }
    @media (max-width: 992px){
      .comment-cell textarea{ min-width: 160px; }
    }
    /* Overlay de carga */
    .loading-backdrop{
      position: fixed; inset:0; background: rgba(15,23,42,.35);
      display:none; align-items:center; justify-content:center; z-index: 2000;
    }
    .loading-card{
      background:#fff; border-radius: .75rem; padding: 1.25rem 1.5rem; box-shadow: 0 10px 24px rgba(15,23,42,.18);
      display:flex; align-items:center; gap:.75rem;
    }
    .spinner{
      width: 26px; height: 26px; border:3px solid #e5e7eb; border-top-color: var(--brand);
      border-radius: 50%; animation: spin 1s linear infinite;
    }
    @keyframes spin{ to { transform: rotate(360deg);} }

    /* Spinner en visor de comprobante */
    #visorWrap{ position: relative; }
    #visorSpinner{
      position:absolute; inset:0; display:none; align-items:center; justify-content:center; background: rgba(255,255,255,.65);
      z-index: 5;
    }

    /* === Modal Detalle de Corte: 80% viewport === */
    #detalleCorteModal .modal-dialog { max-width: 80vw; }
    #detalleCorteModal .modal-content { height: 80vh; }
    #detalleCorteModal .modal-body { overflow: auto; }

    /* Ocultar cualquier navbar que se cuele en el contenido inyectado del modal */
    #detalleCorteModal .navbar,
    #detalleCorteBody .navbar { display:none !important; height:0 !important; overflow:hidden !important; }

    /* Cabeceras fijas dentro del modal */
    #detalleCorteModal table thead th{
      position: sticky; top: 0; background:#0f172a; color:#fff; z-index: 2;
    }
  </style>
</head>
<body>

<div class="container my-4">

  <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-bank2 me-2"></i>Validación de Depósitos</h2>
      <div class="help-text">Administra <b>pendientes</b>, consulta <b>historial</b>, revisa <b>cortes</b> y <b>saldos</b>.</div>
    </div>
    <?php if(!empty($msg)) echo $msg; ?>
  </div>

  <!-- EXPORTAR POR DÍA -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-download"></i> Exportar transacciones por día</div>
      <span class="help-text">Cobros de todas las sucursales en CSV</span>
    </div>
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="export_transacciones_dia.php" target="_blank">
        <div class="col-sm-4 col-md-3">
          <label class="form-label mb-0">Día</label>
          <input type="date" name="dia" class="form-control" required>
        </div>
        <div class="col-sm-8 col-md-5">
          <div class="help-text">Descarga el detalle de <b>cobros</b> del día seleccionado, con sucursal y ejecutivo.</div>
        </div>
        <div class="col-md-4 text-end">
          <button class="btn btn-outline-success"><i class="bi bi-filetype-csv me-1"></i> Descargar CSV</button>
        </div>
      </form>
    </div>
  </div>

  <!-- TABS -->
  <ul class="nav nav-tabs mb-3" id="depTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="pend-tab" data-bs-toggle="tab" data-bs-target="#pend" type="button" role="tab">
        <i class="bi bi-inbox me-1"></i>Pendientes
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="hist-tab" data-bs-toggle="tab" data-bs-target="#hist" type="button" role="tab">
        <i class="bi bi-clock-history me-1"></i>Historial
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="cortes-tab" data-bs-toggle="tab" data-bs-target="#cortes" type="button" role="tab">
        <i class="bi bi-clipboard-data me-1"></i>Cortes
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="saldos-tab" data-bs-toggle="tab" data-bs-target="#saldos" type="button" role="tab">
        <i class="bi bi-graph-up me-1"></i>Saldos
      </button>
    </li>
  </ul>

  <div class="tab-content" id="depTabsContent">
    <!-- PENDIENTES -->
    <div class="tab-pane fade show active" id="pend" role="tabpanel" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="section-title mb-0"><i class="bi bi-inbox"></i> Depósitos pendientes / correcciones</div>
          <span class="badge rounded-pill badge-soft"><?= (int)$pendCount ?> en bandeja</span>
        </div>
        <div class="card-body p-0">
          <?php if ($pendCount === 0): ?>
            <div class="p-3"><div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay depósitos en bandeja.</div></div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover table-xs align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID Depósito</th>
                    <th>Sucursal</th>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th class="text-end">Monto</th>
                    <th>Banco</th>
                    <th>Referencia</th>
                    <th>Comprobante</th>
                    <th>Comentario admin</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $lastCorte = null;
                foreach ($pendientes as $p):
                  if ($lastCorte !== $p['id_corte']): ?>
                    <tr class="table-secondary">
                      <td colspan="10" class="fw-semibold">
                        <i class="bi bi-journal-check me-1"></i>Corte #<?= (int)$p['id_corte'] ?> ·
                        <span class="text-primary"><?= htmlspecialchars($p['sucursal']) ?></span>
                        <span class="ms-2 text-muted">Fecha: <?= htmlspecialchars($p['fecha_corte']) ?></span>
                        <span class="ms-2 badge rounded-pill bg-light text-dark">Efectivo corte: $<?= number_format($p['total_efectivo'],2) ?></span>
                        <button class="btn btn-sm btn-outline-primary ms-2 js-corte-modal" data-id="<?= (int)$p['id_corte'] ?>">
                          <i class="bi bi-list-ul me-1"></i> Ver detalle
                        </button>
                      </td>
                    </tr>
                  <?php endif; ?>

                  <tr class="<?= ($p['estado'] ?? '') === 'Parcial' ? 'table-warning' : '' ?>">
                    <td>
                      #<?= (int)$p['id_deposito'] ?>
                      <?php if (($p['estado'] ?? '') === 'Parcial'): ?>
                        <span class="badge bg-warning text-dark ms-1">Parcial</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['sucursal']) ?></td>
                    <td><?= (int)$p['id_corte'] ?></td>
                    <td><?= htmlspecialchars($p['fecha_corte']) ?></td>
                    <td class="text-end">$<?= number_format($p['monto_depositado'],2) ?></td>
                    <td><?= htmlspecialchars($p['banco']) ?></td>
                    <td><code><?= htmlspecialchars($p['referencia']) ?></code></td>
                    <td>
                      <?php if (!empty($p['comprobante_archivo'])): ?>
                        <button class="btn btn-outline-primary btn-sm js-ver"
                                data-src="deposito_comprobante.php?id=<?= (int)$p['id_deposito'] ?>"
                                data-bs-toggle="modal" data-bs-target="#visorModal">
                          <i class="bi bi-eye"></i> Ver
                        </button>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>

                    <td class="comment-cell">
                      <form method="POST" class="d-flex gap-2 align-items-start js-comment-form">
                        <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                        <textarea name="comentario_admin" class="form-control form-control-sm" placeholder="Nota admin / seguimiento"><?= htmlspecialchars($p['comentario_admin'] ?? '') ?></textarea>
                        <button name="accion" value="GuardarComentario" class="btn btn-outline-primary btn-sm" title="Guardar comentario">
                          <i class="bi bi-floppy"></i>
                        </button>
                      </form>

                      <?php if (!empty($p['motivo_ajuste'])): ?>
                        <div class="small text-muted mt-1 text-break">
                          <i class="bi bi-exclamation-triangle me-1"></i><?= nl2br(htmlspecialchars($p['motivo_ajuste'])) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td class="text-center">
                      <div class="d-flex gap-1 justify-content-center flex-wrap">
                        <?php if (($p['estado'] ?? '') === 'Pendiente'): ?>
                          <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$p['id_deposito'] ?>, '<?= htmlspecialchars($p['sucursal'],ENT_QUOTES) ?>', '<?= number_format($p['monto_depositado'],2) ?>');">
                            <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                            <button name="accion" value="Validar" class="btn btn-success btn-sm">
                              <i class="bi bi-check2-circle me-1"></i> Validar
                            </button>
                          </form>
                        <?php endif; ?>

                        <button type="button"
                                class="btn btn-outline-warning btn-sm js-correccion"
                                data-bs-toggle="modal"
                                data-bs-target="#correccionModal"
                                data-id="<?= (int)$p['id_deposito'] ?>"
                                data-suc="<?= htmlspecialchars($p['sucursal'],ENT_QUOTES) ?>"
                                data-monto="<?= number_format((float)$p['monto_depositado'],2,'.','') ?>"
                                data-estado="<?= htmlspecialchars($p['estado'] ?? '',ENT_QUOTES) ?>"
                                data-motivo="<?= htmlspecialchars($p['motivo_ajuste'] ?? '',ENT_QUOTES) ?>"
                                data-coment="<?= htmlspecialchars($p['comentario_admin'] ?? '',ENT_QUOTES) ?>">
                          <i class="bi bi-arrow-return-left me-1"></i> Pedir corrección
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php $lastCorte = $p['id_corte']; endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- HISTORIAL -->
    <div class="tab-pane fade" id="hist" role="tabpanel" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header">
          <div class="section-title mb-0"><i class="bi bi-clock-history"></i> Historial de depósitos</div>
          <div class="help-text">Al elegir <b>semana</b>, se ignoran las fechas.</div>
        </div>
        <div class="card-body">
          <form class="row g-2 align-items-end mb-3" method="get">
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id===(int)$s['id']?'selected':'' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="desde" class="form-control form-select-sm" value="<?= htmlspecialchars($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="hasta" class="form-control form-select-sm" value="<?= htmlspecialchars($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Semana (ISO)</label>
              <input type="week" name="semana" class="form-control form-select-sm" value="<?= htmlspecialchars($semana) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Aplicar filtros</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Sucursal</th>
                  <th>ID Corte</th>
                  <th>Fecha Corte</th>
                  <th>Fecha Depósito</th>
                  <th class="text-end">Monto</th>
                  <th>Banco</th>
                  <th>Referencia</th>
                  <th>Comprobante</th>
                  <th>Estado</th>
                  <th>Comentario admin</th>
                  <th>Motivo ajuste</th>
                  <th class="text-center">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$historial): ?>
                  <tr><td colspan="13" class="text-muted">Sin resultados con los filtros actuales.</td></tr>
                <?php endif; ?>
                <?php foreach ($historial as $h): ?>
                  <tr class="<?= $h['estado']=='Validado'?'table-success':($h['estado']=='Parcial'?'table-warning':'') ?>">
                    <td>#<?= (int)$h['id_deposito'] ?></td>
                    <td><?= htmlspecialchars($h['sucursal']) ?></td>
                    <td>
                      <?= (int)$h['id_corte'] ?>
                      <button class="btn btn-outline-primary btn-xs btn-sm ms-1 js-corte-modal" data-id="<?= (int)$h['id_corte'] ?>">
                        <i class="bi bi-list-ul"></i>
                      </button>
                    </td>
                    <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
                    <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
                    <td class="text-end">$<?= number_format($h['monto_depositado'],2) ?></td>
                    <td><?= htmlspecialchars($h['banco']) ?></td>
                    <td><code><?= htmlspecialchars($h['referencia']) ?></code></td>
                    <td>
                      <?php if (!empty($h['comprobante_archivo'])): ?>
                        <button class="btn btn-outline-primary btn-sm js-ver"
                                data-src="deposito_comprobante.php?id=<?= (int)$h['id_deposito'] ?>"
                                data-bs-toggle="modal" data-bs-target="#visorModal">
                          <i class="bi bi-eye"></i> Ver
                        </button>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                      <span class="badge <?= $h['estado']=='Validado'?'bg-success':($h['estado']=='Parcial'?'bg-warning text-dark':'bg-secondary') ?>">
                        <?= htmlspecialchars($h['estado']) ?>
                      </span>
                    </td>
                    <td style="max-width:320px;">
                      <div class="text-break"><?= nl2br(htmlspecialchars($h['comentario_admin'] ?? '')) ?></div>
                    </td>
                    <td style="max-width:320px;">
                      <div class="text-break"><?= nl2br(htmlspecialchars($h['motivo_ajuste'] ?? '')) ?></div>
                    </td>
                    <td class="text-center">
                      <?php if ($h['estado'] === 'Pendiente'): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$h['id_deposito'] ?>, '<?= htmlspecialchars($h['sucursal'],ENT_QUOTES) ?>', '<?= number_format($h['monto_depositado'],2) ?>');">
                          <input type="hidden" name="id_deposito" value="<?= (int)$h['id_deposito'] ?>">
                          <button name="accion" value="Validar" class="btn btn-success btn-sm">
                            <i class="bi bi-check2-circle me-1"></i> Validar
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- CORTES -->
    <div class="tab-pane fade" id="cortes" role="tabpanel" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header">
          <div class="section-title mb-0"><i class="bi bi-clipboard-data"></i> Cortes de caja</div>
          <div class="help-text">Filtra por sucursal y fechas; despliega cobros y depósitos por corte.</div>
        </div>
        <div class="card-body">
          <form class="row g-2 align-items-end mb-3" method="get">
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Sucursal</label>
              <select name="c_sucursal_id" class="form-select form-select-sm">
                <option value="0">Todas</option>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $c_sucursal_id===(int)$s['id']?'selected':'' ?>>
                    <?= htmlspecialchars($s['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Desde</label>
              <input type="date" name="c_desde" class="form-control form-select-sm" value="<?= htmlspecialchars($c_desde) ?>">
            </div>
            <div class="col-md-4 col-lg-3">
              <label class="form-label mb-0">Hasta</label>
              <input type="date" name="c_hasta" class="form-control form-select-sm" value="<?= htmlspecialchars($c_hasta) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filtrar cortes</button>
              <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>ID Corte</th>
                  <th>Sucursal</th>
                  <th>Fecha Operación</th>
                  <th>Fecha Corte</th>
                  <th class="text-end">Efectivo</th>
                  <th class="text-end">Tarjeta</th>
                  <th class="text-end">Com. Esp.</th>
                  <th class="text-end">Total</th>
                  <th>Depositado</th>
                  <th>Estado</th>
                  <th>Detalle</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$cortes): ?>
                  <tr><td colspan="11" class="text-muted">Sin cortes con los filtros seleccionados.</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                  <tr>
                    <td>#<?= (int)$c['id'] ?></td>
                    <td><?= htmlspecialchars($c['sucursal']) ?></td>
                    <td><?= htmlspecialchars($c['fecha_operacion']) ?></td>
                    <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                    <td class="text-end">$<?= number_format($c['total_efectivo'],2) ?></td>
                    <td class="text-end">$<?= number_format($c['total_tarjeta'],2) ?></td>
                    <td class="text-end">$<?= number_format($c['total_comision_especial'],2) ?></td>
                    <td class="text-end fw-semibold">$<?= number_format($c['total_general'],2) ?></td>
                    <td><?= $c['depositado'] ? ('$'.number_format($c['monto_depositado'],2)) : '<span class="text-muted">No</span>' ?></td>
                    <td><span class="badge <?= $c['estado']==='Cerrado'?'bg-success':'bg-warning text-dark' ?>"><?= htmlspecialchars($c['estado']) ?></span></td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary js-corte-modal" data-id="<?= (int)$c['id'] ?>">
                        <i class="bi bi-list-ul me-1"></i> Ver detalle
                      </button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- SALDOS -->
    <div class="tab-pane fade" id="saldos" role="tabpanel" tabindex="0">
      <div class="card card-elev mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="section-title mb-0"><i class="bi bi-graph-up"></i> Saldos por sucursal</div>
          <span class="help-text">Comparativo de efectivo cobrado vs depositado</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead>
                <tr>
                  <th>Sucursal</th>
                  <th class="text-end">Total Efectivo Cobrado</th>
                  <th class="text-end">Total Depositado</th>
                  <th class="text-end">Saldo Pendiente</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $sqlSaldos = "
                    SELECT
                        s.id,
                        s.nombre AS sucursal,
                        IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,
                        IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_depositado,
                        GREATEST(
                            IFNULL(SUM(c.monto_efectivo),0) - IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0),
                        0) AS saldo_pendiente
                    FROM sucursales s
                    LEFT JOIN cobros c
                        ON c.id_sucursal = s.id
                       AND c.corte_generado = 1
                    GROUP BY s.id
                    ORDER BY saldo_pendiente DESC
                  ";
                  $saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);
                  foreach($saldos as $s): ?>
                <tr class="<?= $s['saldo_pendiente']>0?'table-warning':'' ?>">
                  <td><?= htmlspecialchars($s['sucursal']) ?></td>
                  <td class="text-end">$<?= number_format($s['total_efectivo'],2) ?></td>
                  <td class="text-end">$<?= number_format($s['total_depositado'],2) ?></td>
                  <td class="text-end fw-semibold">$<?= number_format($s['saldo_pendiente'],2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="sticky-actions text-end">
            <span class="help-text"><i class="bi bi-info-circle me-1"></i>Los saldos consideran cobros con corte generado y depósitos validados.</span>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /tab-content -->

</div>

<!-- Modal visor (comprobante) con spinner -->
<div class="modal fade" id="visorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-image me-1"></i> Comprobante de depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0" id="visorWrap">
        <div id="visorSpinner"><div class="spinner"></div></div>
        <iframe id="visorFrame" src="" style="width:100%;height:80vh;border:0;"></iframe>
      </div>
      <div class="modal-footer">
        <a id="btnAbrirNueva" href="#" target="_blank" class="btn btn-outline-secondary">Abrir en nueva pestaña</a>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Listo</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Detalle de Corte (80% viewport, sin navbar) -->
<div class="modal fade" id="detalleCorteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-list-ul me-1"></i> Detalle del corte</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="detalleCorteBody">
        <!-- contenido por AJAX -->
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal rápido de éxito de comentario -->
<div class="modal fade" id="comentarioOkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-check2-circle fs-1 text-success d-block mb-2"></i>
        <div class="fw-semibold">Comentario guardado</div>
      </div>
    </div>
  </div>
</div>

<!-- Overlay de carga general -->
<div class="loading-backdrop" id="loadingBackdrop">
  <div class="loading-card">
    <div class="spinner"></div>
    <div class="fw-semibold">Cargando detalle…</div>
  </div>
</div>

<!-- Modal Solicitar Corrección -->
<div class="modal fade" id="correccionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i> Solicitar corrección</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_deposito" id="corr_id_deposito" value="">

        <div class="small text-muted mb-2">
          Depósito <b id="corr_info">—</b>
        </div>

        <label class="form-label">Motivo / Instrucciones para sucursal</label>
        <textarea class="form-control" name="motivo_ajuste" id="corr_motivo" rows="3" required
          placeholder="Ej. No coincide con efectivo del corte. Re-subir comprobante correcto y verificar referencia."></textarea>

        <div class="row g-2 mt-2">
          <div class="col-6">
            <label class="form-label">Monto validado (opcional)</label>
            <input type="number" step="0.01" class="form-control" name="monto_validado" id="corr_monto_validado" placeholder="Ej. 1500.00">
          </div>
          <div class="col-6">
            <label class="form-label">Ajuste (opcional)</label>
            <input type="number" step="0.01" class="form-control" name="ajuste" id="corr_ajuste" placeholder="Ej. -200.00">
          </div>
        </div>

        <label class="form-label mt-2">Comentario admin (opcional)</label>
        <input type="text" class="form-control" name="comentario_admin" id="corr_coment" placeholder="Nota interna / seguimiento">

        <div class="form-text">
          Al enviar, el depósito pasa a <b>Parcial</b> y la sucursal podrá corregir y reenviar.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" name="accion" value="SolicitarCorreccion">
          <i class="bi bi-send me-1"></i> Enviar a sucursal
        </button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Historial: toggle fechas cuando se selecciona semana
  const semanaInput = document.querySelector('input[name="semana"]');
  const desdeInput  = document.querySelector('input[name="desde"]');
  const hastaInput  = document.querySelector('input[name="hasta"]');
  if (semanaInput) {
    semanaInput.addEventListener('input', () => {
      const usingWeek = semanaInput.value.trim() !== '';
      if (desdeInput && hastaInput){
        desdeInput.disabled = usingWeek;
        hastaInput.disabled = usingWeek;
        if (usingWeek) { desdeInput.value=''; hastaInput.value=''; }
      }
    });
  }

  // Visor modal con spinner
  const visorModal  = document.getElementById('visorModal');
  const visorFrame  = document.getElementById('visorFrame');
  const btnAbrir    = document.getElementById('btnAbrirNueva');
  const visorSpinner= document.getElementById('visorSpinner');
  document.querySelectorAll('.js-ver').forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-src');
      if (visorSpinner) visorSpinner.style.display = 'flex';
      visorFrame.src = src;
      btnAbrir.href  = src;
    });
  });
  if (visorModal) {
    visorModal.addEventListener('hidden.bs.modal', () => {
      visorFrame.src = '';
      btnAbrir.href  = '#';
      if (visorSpinner) visorSpinner.style.display = 'none';
    });
  }
  if (visorFrame) {
    visorFrame.addEventListener('load', () => {
      if (visorSpinner) visorSpinner.style.display = 'none';
    });
  }

  // Confirmación al validar depósito (UX)
  function confirmarValidacion(id, sucursal, monto){
    return confirm(`¿Validar el depósito #${id} de ${sucursal} por $${monto}?`);
  }
  window.confirmarValidacion = confirmarValidacion;

  // Guardar comentario por AJAX (modal rápido)
  const okModalEl = document.getElementById('comentarioOkModal');
  const okModal = (window.bootstrap && okModalEl) ? new bootstrap.Modal(okModalEl, {backdrop: 'static', keyboard: false}) : null;

  document.querySelectorAll('.js-comment-form').forEach(form => {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(form);
      if (!fd.get('accion')) fd.append('accion', 'GuardarComentario');

      try {
        const resp = await fetch(location.href, {
          method: 'POST',
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          body: fd
        });
        let ok = false;
        try { ok = !!(await resp.json()).ok; } catch(e) { ok = resp.ok; }
        if (ok) {
          if (okModal) { okModal.show(); setTimeout(() => okModal.hide(), 1200); }
          else { alert('Comentario guardado'); }
        } else {
          alert('No se pudo guardar el comentario. Intenta de nuevo.');
        }
      } catch (err) {
        console.error(err);
        alert('Error de red al guardar comentario.');
      }
    }, {passive:false});
  });

  // Modal Detalle de Corte (AJAX) + Overlay Cargando
  const detalleModalEl = document.getElementById('detalleCorteModal');
  const detalleBody = document.getElementById('detalleCorteBody');
  const loadingBackdrop = document.getElementById('loadingBackdrop');
  const detalleModal = (window.bootstrap && detalleModalEl) ? new bootstrap.Modal(detalleModalEl) : null;

  function showLoading(show){ if (loadingBackdrop) loadingBackdrop.style.display = show ? 'flex' : 'none'; }

  async function cargarDetalleCorte(idCorte){
    showLoading(true);
    try{
      const resp = await fetch(`?ajax=detalle_corte&id=${encodeURIComponent(idCorte)}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
      const html = await resp.text();
      detalleBody.innerHTML = html;
      if (detalleModal) detalleModal.show();

      // Re-wire botones "Ver" del HTML inyectado
      detalleBody.querySelectorAll('.js-ver').forEach(btn => {
        btn.addEventListener('click', () => {
          const src = btn.getAttribute('data-src');
          if (document.getElementById('visorSpinner')) document.getElementById('visorSpinner').style.display = 'flex';
          document.getElementById('visorFrame').src = src;
          document.getElementById('btnAbrirNueva').href  = src;
          new bootstrap.Modal(document.getElementById('visorModal')).show();
        });
      });
    }catch(err){
      console.error(err);
      detalleBody.innerHTML = '<div class="alert alert-danger">No se pudo cargar el detalle del corte.</div>';
      if (detalleModal) detalleModal.show();
    }finally{
      showLoading(false);
    }
  }

  document.querySelectorAll('.js-corte-modal').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-id');
      cargarDetalleCorte(id);
    });
  });

  // Modal corrección: precarga datos
  const corrModalEl = document.getElementById('correccionModal');
  corrModalEl?.addEventListener('show.bs.modal', (ev) => {
    const b = ev.relatedTarget;
    const id = b.getAttribute('data-id');
    const suc = b.getAttribute('data-suc');
    const monto = b.getAttribute('data-monto');
    const estado = b.getAttribute('data-estado');

    document.getElementById('corr_id_deposito').value = id;
    document.getElementById('corr_info').textContent = `#${id} · ${suc} · $${monto} · ${estado}`;

    document.getElementById('corr_motivo').value = b.getAttribute('data-motivo') || '';
    document.getElementById('corr_coment').value = b.getAttribute('data-coment') || '';

    const mv = document.getElementById('corr_monto_validado');
    const aj = document.getElementById('corr_ajuste');
    if (mv) mv.value = '';
    if (aj) aj.value = '';
  });
</script>
</body>
</html>
