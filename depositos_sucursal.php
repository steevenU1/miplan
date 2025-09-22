<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once 'db.php';

$rol        = $_SESSION['rol'] ?? '';
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

/*
  Permisos:
  - Gerente / Admin => siempre
  - Ejecutivo       => solo si su sucursal NO tiene gerente activo
*/
$hayGerente = true; // por seguridad, asumir que sí hay
if ($idSucursal > 0) {
    if ($st = $conn->prepare("
        SELECT COUNT(*)
        FROM usuarios
        WHERE id_sucursal = ?
          AND rol IN ('Gerente','GerenteSucursal')
          AND activo = 1
    ")) {
        $st->bind_param("i", $idSucursal);
        $st->execute();
        $st->bind_result($cnt);
        $st->fetch();
        $st->close();
        $hayGerente = ((int)$cnt > 0);
    }
}

$allow = in_array($rol, ['Gerente','Admin'], true) || ($rol === 'Ejecutivo' && !$hayGerente);
if (!$allow) {
    header("Location: 403.php");
    exit();
}

include 'navbar.php';

$idUsuario  = (int)$_SESSION['id_usuario'];
$idSucursal = (int)$_SESSION['id_sucursal'];
$rolUsuario = $_SESSION['rol'];

$msg = '';
$MAX_BYTES = 10 * 1024 * 1024; // 10MB
$ALLOWED   = [
  'application/pdf' => 'pdf',
  'image/jpeg'      => 'jpg',
  'image/png'       => 'png',
];

// Bancos permitidos (lista blanca)
$ALLOWED_BANKS = [
  'BBVA','Citibanamex','Banorte','Santander','HSBC','Scotiabank',
  'Inbursa','Banco Azteca','BanCoppel','Banregio','Afirme',
  'Banco del Bajío','Banca Mifel','Compartamos Banco'
];

/* ------- helper: guardar comprobante para un depósito ------- */
function guardar_comprobante(mysqli $conn, int $deposito_id, array $file, int $idUsuario, int $MAX_BYTES, array $ALLOWED, &$errMsg): bool {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    $errMsg = 'Debes adjuntar el comprobante.'; return false;
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMsg = 'Error al subir archivo (código '.$file['error'].').'; return false;
  }
  if ($file['size'] <= 0 || $file['size'] > $MAX_BYTES) {
    $errMsg = 'El archivo excede 10 MB o está vacío.'; return false;
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
  if (!isset($ALLOWED[$mime])) {
    $errMsg = 'Tipo de archivo no permitido. Solo PDF/JPG/PNG.'; return false;
  }
  $ext = $ALLOWED[$mime];

  // Carpeta destino
  $baseDir = __DIR__ . '/uploads/depositos/' . $deposito_id;
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
    if (!file_exists($baseDir.'/.htaccess')) {
      file_put_contents($baseDir.'/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phar|phtml|shtml|cgi|pl)$\">\nDeny from all\n</FilesMatch>\n");
    }
  }

  $storedName = 'comprobante.' . $ext;
  $fullPath   = $baseDir . '/' . $storedName;
  if (file_exists($fullPath)) @unlink($fullPath);

  if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    $errMsg = 'No se pudo guardar el archivo en el servidor.'; return false;
  }

  $relPath = 'uploads/depositos/' . $deposito_id . '/' . $storedName;
  $orig    = substr(basename($file['name']), 0, 200);

  $stmt = $conn->prepare("
    UPDATE depositos_sucursal SET
      comprobante_archivo = ?, comprobante_nombre = ?, comprobante_mime = ?,
      comprobante_size = ?, comprobante_subido_en = NOW(), comprobante_subido_por = ?
    WHERE id = ?
  ");
  $size = (int)$file['size'];
  $stmt->bind_param('sssiii', $relPath, $orig, $mime, $size, $idUsuario, $deposito_id);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    @unlink($fullPath);
    $errMsg = 'Error al actualizar el depósito con el comprobante.';
    return false;
  }
  return true;
}

/* ------- Registrar DEPÓSITO (referencia OBLIGATORIA y NUMÉRICA) ------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion']==='registrar') {
  $id_corte        = (int)($_POST['id_corte'] ?? 0);
  $fecha_deposito  = $_POST['fecha_deposito'] ?? date('Y-m-d');
  $banco           = trim($_POST['banco'] ?? ''); // viene del hidden sincronizado
  $monto           = (float)($_POST['monto_depositado'] ?? 0);
  $referencia      = trim($_POST['referencia'] ?? '');
  $motivo          = trim($_POST['motivo'] ?? '');

  // 1) Validar archivo obligatorio (antes de tocar BD)
  if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] === UPLOAD_ERR_NO_FILE) {
    $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes adjuntar el comprobante del depósito.</div>";
  } elseif ($_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
    $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al subir el archivo (código ".$_FILES['comprobante']['error']. ").</div>";
  } elseif ($_FILES['comprobante']['size'] <= 0 || $_FILES['comprobante']['size'] > $MAX_BYTES) {
    $msg = "<div class='alert alert-warning shadow-sm'>⚠ El comprobante debe pesar hasta 10 MB.</div>";
  } else {
    // Validar MIME permitido
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['comprobante']['tmp_name']) ?: 'application/octet-stream';
    if (!isset($ALLOWED[$mime])) {
      $msg = "<div class='alert alert-warning shadow-sm'>⚠ Tipo de archivo no permitido. Solo PDF/JPG/PNG.</div>";
    } else {
      // 2) Validar datos y pendiente del corte
      if ($id_corte>0 && $monto>0 && $banco!=='') {
        if (!in_array($banco, $ALLOWED_BANKS, true)) {
          $msg = "<div class='alert alert-warning shadow-sm'>⚠ Selecciona un banco válido del listado.</div>";
        } else {
          $sqlCheck = "SELECT cc.total_efectivo, IFNULL(SUM(ds.monto_depositado),0) AS suma_actual
                       FROM cortes_caja cc
                       LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
                       WHERE cc.id = ? GROUP BY cc.id";
          $stmt = $conn->prepare($sqlCheck);
          $stmt->bind_param("i", $id_corte);
          $stmt->execute();
          $corte = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          if ($corte) {
            $pendiente = (float)$corte['total_efectivo'] - (float)$corte['suma_actual'];
            if ($monto > $pendiente + 0.0001) {
              $msg = "<div class='alert alert-danger shadow-sm'>❌ El depósito excede el monto pendiente del corte. Solo queda $".number_format($pendiente,2)."</div>";
            } else {
              // 3) Insertar y adjuntar (si adjuntar falla, revertimos)
              $stmtIns = $conn->prepare("
                INSERT INTO depositos_sucursal
                  (id_sucursal, id_corte, fecha_deposito, monto_depositado, banco, referencia, observaciones, estado, creado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', NOW())
              ");
              // i, i, s, d, s, s, s   ->  id_sucursal, id_corte, fecha, monto, banco, referencia, motivo
              $stmtIns->bind_param("iisdsss", $idSucursal, $id_corte, $fecha_deposito, $monto, $banco, $referencia, $motivo);
              if ($stmtIns->execute()) {
                $deposito_id = $stmtIns->insert_id;
                $stmtIns->close();

                $errUp = '';
                if (guardar_comprobante($conn, $deposito_id, $_FILES['comprobante'], $idUsuario, $MAX_BYTES, $ALLOWED, $errUp)) {
                  $msg = "<div class='alert alert-success shadow-sm'>✅ Depósito registrado y comprobante adjuntado.</div>";
                } else {
                  // revertir
                  $del = $conn->prepare("DELETE FROM depositos_sucursal WHERE id=?");
                  $del->bind_param('i', $deposito_id);
                  $del->execute();
                  $del->close();
                  $msg = "<div class='alert alert-danger shadow-sm'>❌ No se guardó el depósito porque falló el comprobante: ".htmlspecialchars($errUp)."</div>";
                }
              } else {
                $msg = "<div class='alert alert-danger shadow-sm'>❌ Error al registrar depósito.</div>";
              }
            }
          } else {
            $msg = "<div class='alert alert-danger shadow-sm'>❌ Corte no encontrado.</div>";
          }
        }
      } else {
        $msg = "<div class='alert alert-warning shadow-sm'>⚠ Debes llenar todos los campos obligatorios.</div>";
      }
    }
  }
}

/* =========================
   Filtros e Historial (GET)
   ========================= */
$per_page = max(10, (int)($_GET['pp'] ?? 25));
$page     = max(1, (int)($_GET['p']  ?? 1));
$f_inicio = trim($_GET['f_inicio'] ?? '');
$f_fin    = trim($_GET['f_fin']    ?? '');
$f_banco  = trim($_GET['f_banco']  ?? '');
$f_estado = trim($_GET['f_estado'] ?? '');
$f_q      = trim($_GET['q']        ?? '');

$conds = [ 'ds.id_sucursal = ?' ];
$types = 'i';
$params = [ $idSucursal ];

if ($f_inicio !== '') { $conds[] = 'ds.fecha_deposito >= ?'; $types .= 's'; $params[] = $f_inicio; }
if ($f_fin    !== '') { $conds[] = 'ds.fecha_deposito <= ?'; $types .= 's'; $params[] = $f_fin; }
if ($f_banco  !== '') { $conds[] = 'ds.banco = ?';             $types .= 's'; $params[] = $f_banco; }
if ($f_estado !== '') { $conds[] = 'ds.estado = ?';            $types .= 's'; $params[] = $f_estado; }
if ($f_q      !== '') {
  $conds[] = '(ds.referencia LIKE ? OR ds.banco LIKE ? OR ds.observaciones LIKE ?)';
  $types  .= 'sss';
  $like = '%'.$f_q.'%';
  array_push($params, $like, $like, $like);
}
$where = implode(' AND ', $conds);

/* ------- Export CSV si se pide ------- */
if (isset($_GET['export']) && $_GET['export'] == '1') {
  $sqlExp = "SELECT ds.id, ds.id_corte, cc.fecha_corte, ds.fecha_deposito, ds.monto_depositado,
                    ds.banco, ds.referencia, ds.estado
             FROM depositos_sucursal ds
             INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
             WHERE $where
             ORDER BY ds.fecha_deposito DESC, ds.id DESC";
  $stmt = $conn->prepare($sqlExp);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="depositos_'.date('Ymd_His').'.csv"');
  // BOM para Excel
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID Depósito','ID Corte','Fecha Corte','Fecha Depósito','Monto','Banco','Referencia','Estado']);
  while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
      $row['id'], $row['id_corte'], $row['fecha_corte'], $row['fecha_deposito'],
      number_format((float)$row['monto_depositado'], 2, '.', ''), $row['banco'], $row['referencia'], $row['estado']
    ]);
  }
  fclose($out);
  exit();
}

/* ------- Consultas para render ------- */
// Cortes pendientes
$sqlPendientes = "
  SELECT cc.id, cc.fecha_corte, cc.total_efectivo,
         IFNULL(SUM(ds.monto_depositado),0) AS total_depositado
  FROM cortes_caja cc
  LEFT JOIN depositos_sucursal ds ON ds.id_corte = cc.id
  WHERE cc.id_sucursal = ? AND cc.estado='Pendiente'
  GROUP BY cc.id
  ORDER BY cc.fecha_corte ASC";
$stmtPend = $conn->prepare($sqlPendientes);
$stmtPend->bind_param("i", $idSucursal);
$stmtPend->execute();
$cortesPendientes = $stmtPend->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtPend->close();

// Historial paginado
$sqlCount = "SELECT COUNT(*) AS n
             FROM depositos_sucursal ds
             INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
             WHERE $where";
$stmtC = $conn->prepare($sqlCount);
$stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total_rows = (int)$stmtC->get_result()->fetch_assoc()['n'];
$stmtC->close();

$offset = ($page - 1) * $per_page;
$sqlHistorial = "
  SELECT ds.*, cc.fecha_corte
  FROM depositos_sucursal ds
  INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
  WHERE $where
  ORDER BY ds.fecha_deposito DESC, ds.id DESC
  LIMIT ? OFFSET ?";
$stmtHist = $conn->prepare($sqlHistorial);
$types2 = $types . 'ii';
$params2 = array_merge($params, [ $per_page, $offset ]);
$stmtHist->bind_param($types2, ...$params2);
$stmtHist->execute();
$historial = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHist->close();

// KPIs rápidos
$totalPendiente = 0.0;
foreach ($cortesPendientes as $c) {
  $totalPendiente += ((float)$c['total_efectivo'] - (float)$c['total_depositado']);
}
$numCortes = count($cortesPendientes);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Depósitos Sucursal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --brand1:#0ea5e9; --brand2:#22c55e; --ink:#0f172a; --muted:#6b7280; --surface:#fff;
    }
    body{ background:#f6f7fb; color:var(--ink); }
    .page-hero{background:linear-gradient(135deg,var(--brand1),var(--brand2));color:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 10px 30px rgba(2,6,23,.18)}
    .page-title{margin:0;font-weight:800;letter-spacing:.3px}
    .hero-kpis{gap:1rem}
    .kpi{display:flex;align-items:center;gap:.75rem;padding:10px 14px;background:rgba(255,255,255,.15);border-radius:12px}
    .kpi .num{font-weight:800}

    .card-surface{background:var(--surface);border:1px solid rgba(0,0,0,.05);box-shadow:0 10px 30px rgba(2,6,23,.06);border-radius:18px}
    .chip{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .6rem;border-radius:999px;font-weight:600;font-size:.85rem}
    .chip-success{background:#e7f8ef;color:#0f7a3d;border:1px solid #b7f1cf}
    .chip-warn{background:#fff6e6;color:#9a6200;border:1px solid #ffe1a8}
    .chip-pending{background:#eef2ff;color:#3f51b5;border:1px solid #dfe3ff}

    .form-mini .form-control,.form-mini .form-select{height:38px}
    .form-mini .form-control[type=file]{height:auto}

    .sticky-head thead th{position:sticky;top:0;z-index:1;background:#fff}

    /* ===== Filtros Modernos ===== */
    .filters-wrap{border:1px solid rgba(0,0,0,.06);background:#fff;border-radius:16px;padding:14px;box-shadow:0 8px 24px rgba(2,6,23,.06)}
    .filters-modern .form-floating>.form-control, .filters-modern .form-floating>.form-select{border-radius:12px;border-color:#e5e7eb}
    .filters-modern .form-floating>label{color:#64748b}
    .filters-modern .form-control:focus, .filters-modern .form-select:focus{box-shadow:0 0 0 0.25rem rgba(14,165,233,.12);border-color:#a5d8f5}
    .filters-chips .badge{background:#f1f5f9;border:1px solid #e2e8f0;color:#0f172a}
    .filters-chips .badge:hover{background:#e2e8f0}

    .shadow-soft{box-shadow:0 8px 20px rgba(2,6,23,.06)}
    .btn-soft{border:1px solid rgba(0,0,0,.08);background:#fff}
    .btn-soft:hover{background:#f9fafb}
  </style>
</head>
<body>
<div class="container py-3">

  <div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="page-title">🏦 Depósitos de Sucursal</h1>
        <div class="opacity-75">Usuario <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong> · Rol <strong><?= htmlspecialchars($rolUsuario) ?></strong></div>
      </div>
      <div class="hero-kpis d-none d-md-flex">
        <div class="kpi"><i class="bi bi-cash-coin"></i> <div><div class="small">Pendiente</div><div class="num">$<?= number_format($totalPendiente,2) ?></div></div></div>
        <div class="kpi"><i class="bi bi-clipboard-check"></i> <div><div class="small">Cortes</div><div class="num"><?= (int)$numCortes ?></div></div></div>
        <div class="kpi"><i class="bi bi-archive"></i> <div><div class="small">Registros</div><div class="num"><?= (int)$total_rows ?></div></div></div>
      </div>
    </div>
  </div>

  <?= $msg ?>

  <!-- Cortes pendientes de depósito -->
  <div class="card-surface p-3 p-md-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h4 class="m-0"><i class="bi bi-list-check me-2"></i>Cortes pendientes de depósito</h4>
      <span class="text-muted small">Adjunta comprobante (PDF/JPG/PNG, máx 10MB)</span>
    </div>

    <?php if (count($cortesPendientes) == 0): ?>
      <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay cortes pendientes de depósito.</div>
    <?php else: ?>
      <div class="table-responsive shadow-soft rounded sticky-head">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="min-width:120px;">ID Corte</th>
              <th>Fecha Corte</th>
              <th>Efectivo a Depositar</th>
              <th>Total Depositado</th>
              <th>Pendiente</th>
              <th style="min-width:760px;">Registrar Depósito</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cortesPendientes as $c):
              $pendiente = (float)$c['total_efectivo'] - (float)$c['total_depositado']; ?>
              <tr>
                <td><span class="badge text-bg-secondary">#<?= (int)$c['id'] ?></span></td>
                <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                <td>$<?= number_format($c['total_efectivo'],2) ?></td>
                <td>$<?= number_format($c['total_depositado'],2) ?></td>
                <td class="fw-bold text-danger">$<?= number_format($pendiente,2) ?></td>
                <td>
                  <form method="POST" class="row g-2 align-items-end form-mini deposito-form" enctype="multipart/form-data"
                        novalidate
                        data-pendiente="<?= htmlspecialchars($pendiente) ?>"
                        data-idcorte="<?= (int)$c['id'] ?>"
                        data-fechacorte="<?= htmlspecialchars($c['fecha_corte']) ?>">
                    <input type="hidden" name="accion" value="registrar">
                    <input type="hidden" name="id_corte" value="<?= (int)$c['id'] ?>">

                    <div class="col-6 col-md-2">
                      <label class="form-label small">Fecha depósito</label>
                      <input type="date" name="fecha_deposito" class="form-control form-control-sm" required>
                      <div class="invalid-feedback">Requerida.</div>
                    </div>

                    <div class="col-6 col-md-2">
                      <label class="form-label small">Monto</label>
                      <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" name="monto_depositado" class="form-control" placeholder="0.00" required>
                      </div>
                      <div class="invalid-feedback">Ingresa un monto válido.</div>
                    </div>

                    <div class="col-6 col-md-3">
                      <label class="form-label small">Banco</label>
                      <select class="form-select form-select-sm" name="banco_select" required>
                        <option value="">Elegir...</option>
                        <?php foreach ($ALLOWED_BANKS as $b): ?>
                          <option><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input type="hidden" name="banco" value="">
                      <div class="invalid-feedback">Selecciona un banco.</div>
                    </div>

                    <div class="col-6 col-md-2">
                      <label class="form-label small">Referencia
                        <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
                           title="Es el número de folio, ticket o referencia de tu ticket de depósito"></i>
                      </label>
                      <input type="text" name="referencia" class="form-control form-control-sm" placeholder="Folio/ticket" pattern="^[0-9]+$" inputmode="numeric" required oninput="this.value=this.value.replace(/\D/g,'')">
                      <div class="invalid-feedback">Requerida y solo dígitos (0–9).</div>
                    </div>

                    <div class="col-12 col-md-3">
                      <label class="form-label small">Motivo (opcional)</label>
                      <input type="text" name="motivo" class="form-control form-control-sm" placeholder="Motivo">
                    </div>

                    <div class="col-12 col-md-6">
                      <label class="form-label small">Comprobante</label>
                      <input type="file" name="comprobante" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                      <div class="form-text">PDF / JPG / PNG · Máx 10 MB.</div>
                      <div class="invalid-feedback">Adjunta el comprobante.</div>
                    </div>

                    <div class="col-12 col-md-3 ms-auto">
                      <button type="button" class="btn btn-success btn-sm w-100 btn-confirmar-deposito">
                        <i class="bi bi-shield-check me-1"></i> Validar y registrar
                      </button>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Historial con filtros y paginación -->
  <div class="card-surface p-3 p-md-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <h4 class="m-0"><i class="bi bi-clock-history me-2"></i>Historial de Depósitos</h4>
      <div class="d-flex gap-2">
        <a class="btn btn-soft btn-sm" href="?<?= http_build_query(array_merge($_GET,['export'=>1])) ?>"><i class="bi bi-filetype-csv me-1"></i>Exportar CSV</a>
      </div>
    </div>

    <!-- FILTROS -->
    <div class="filters-wrap mb-3">
      <form method="get" class="filters-modern row g-3 align-items-end">
        <input type="hidden" name="p" value="1">

        <div class="col-12 col-lg-4">
          <div class="form-floating">
            <input type="text" name="q" id="f_q" value="<?= htmlspecialchars($f_q) ?>" class="form-control" placeholder="Buscar">
            <label for="f_q"><i class="bi bi-search me-1"></i>Buscar (referencia, banco, motivo)</label>
          </div>
        </div>

        <div class="col-6 col-lg-2">
          <div class="form-floating">
            <input type="date" name="f_inicio" id="f_inicio" value="<?= htmlspecialchars($f_inicio) ?>" class="form-control" placeholder="Desde">
            <label for="f_inicio"><i class="bi bi-calendar3 me-1"></i>Desde</label>
          </div>
        </div>
        <div class="col-6 col-lg-2">
          <div class="form-floating">
            <input type="date" name="f_fin" id="f_fin" value="<?= htmlspecialchars($f_fin) ?>" class="form-control" placeholder="Hasta">
            <label for="f_fin"><i class="bi bi-calendar3 me-1"></i>Hasta</label>
          </div>
        </div>

        <div class="col-6 col-lg-2">
          <div class="form-floating">
            <select name="f_banco" id="f_banco" class="form-select">
              <option value=""></option>
              <?php foreach ($ALLOWED_BANKS as $b): $sel = ($f_banco===$b)?'selected':''; ?>
                <option <?= $sel ?>><?= htmlspecialchars($b) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="f_banco"><i class="bi bi-bank me-1"></i>Banco</label>
          </div>
        </div>

        <div class="col-6 col-lg-2">
          <div class="form-floating">
            <select name="f_estado" id="f_estado" class="form-select">
              <?php $estados=[''=>'Todos','Pendiente'=>'Pendiente','Parcial'=>'Parcial','Validado'=>'Validado'];
                foreach($estados as $k=>$v): $sel = ($f_estado===$k)?'selected':''; ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $sel ?>><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="f_estado"><i class="bi bi-flag me-1"></i>Estado</label>
          </div>
        </div>

        <div class="col-6 col-lg-2">
          <div class="form-floating">
            <select name="pp" id="pp" class="form-select">
              <?php foreach([10,25,50,100] as $pp): $sel=($per_page==$pp)?'selected':''; ?>
                <option value="<?= $pp ?>" <?= $sel ?>><?= $pp ?>/pág</option>
              <?php endforeach; ?>
            </select>
            <label for="pp"><i class="bi bi-list-ul me-1"></i>Tamaño</label>
          </div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
          <a class="btn btn-outline-secondary" href="?"><i class="bi bi-x-circle me-1"></i>Limpiar</a>
        </div>
      </form>

      <?php
        // Chips de filtros activos (links para limpiar cada uno)
        $chips = [];
        if ($f_q      !== '') $chips[] = ['label' => 'Búsqueda: '.$f_q, 'key' => 'q'];
        if ($f_inicio !== '') $chips[] = ['label' => 'Desde: '.$f_inicio, 'key' => 'f_inicio'];
        if ($f_fin    !== '') $chips[] = ['label' => 'Hasta: '.$f_fin, 'key' => 'f_fin'];
        if ($f_banco  !== '') $chips[] = ['label' => 'Banco: '.$f_banco, 'key' => 'f_banco'];
        if ($f_estado !== '') $chips[] = ['label' => 'Estado: '.$f_estado, 'key' => 'f_estado'];
      ?>
      <?php if ($chips): ?>
        <div class="filters-chips mt-2">
          <?php foreach ($chips as $ch): $qs = $_GET; unset($qs[$ch['key']], $qs['p']); $href = '?' . http_build_query($qs); ?>
            <a class="badge rounded-pill text-decoration-none me-2 mb-2" href="<?= $href ?>">
              <i class="bi bi-x-lg me-1"></i><?= htmlspecialchars($ch['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="table-responsive sticky-head">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID Depósito</th>
            <th>ID Corte</th>
            <th>Fecha Corte</th>
            <th>Fecha Depósito</th>
            <th class="text-end">Monto</th>
            <th>Banco</th>
            <th>Referencia</th>
            <th>Comprobante</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$historial): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">Sin resultados con los filtros seleccionados.</td></tr>
          <?php else: foreach ($historial as $h): ?>
            <tr>
              <td><span class="badge text-bg-secondary">#<?= (int)$h['id'] ?></span></td>
              <td><?= (int)$h['id_corte'] ?></td>
              <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
              <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
              <td class="text-end">$<?= number_format($h['monto_depositado'],2) ?></td>
              <td><?= htmlspecialchars($h['banco']) ?></td>
              <td><?= htmlspecialchars($h['referencia']) ?></td>
              <td>
                <?php if (!empty($h['comprobante_archivo'])): ?>
                  <a class="btn btn-soft btn-sm" target="_blank" href="deposito_comprobante.php?id=<?= (int)$h['id'] ?>">
                    <i class="bi bi-file-earmark-arrow-down"></i> Ver
                  </a>
                <?php else: ?>
                  <span class="text-muted small">Sin archivo</span>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $estado = htmlspecialchars($h['estado']);
                  if ($estado === 'Validado') {
                    echo '<span class="chip chip-success"><i class="bi bi-check2-circle"></i> Validado</span>';
                  } elseif ($estado === 'Parcial') {
                    echo '<span class="chip chip-warn"><i class="bi bi-hourglass-split"></i> Parcial</span>';
                  } else {
                    echo '<span class="chip chip-pending"><i class="bi bi-hourglass"></i> Pendiente</span>';
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <nav class="mt-3">
      <ul class="pagination pagination-sm justify-content-end">
        <?php
          $qs = $_GET; unset($qs['p']);
          $base = '?' . http_build_query($qs);
          $prev = max(1, $page-1); $next = min($total_pages, $page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $base.'&p='.$prev ?>" tabindex="-1">&laquo;</a>
        </li>
        <?php
          $start = max(1, $page-2); $end = min($total_pages, $page+2);
          if ($start>1) echo '<li class="page-item"><a class="page-link" href="'.$base.'&p=1">1</a></li><li class="page-item disabled"><span class="page-link">…</span></li>';
          for($i=$start;$i<=$end;$i++){
            $active = ($i==$page)?'active':'';
            echo '<li class="page-item '.$active.'"><a class="page-link" href="'.$base.'&p='.$i.'">'.$i.'</a></li>';
          }
          if ($end<$total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li><li class="page-item"><a class="page-link" href="'.$base.'&p='.$total_pages.'">'.$total_pages.'</a></li>';
        ?>
        <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $base.'&p='.$next ?>">&raquo;</a>
        </li>
      </ul>
    </nav>
  </div>

</div>

<!-- MODAL CONFIRMACIÓN -->
<div class="modal fade" id="modalConfirmarDeposito" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>Confirmar registro de depósito</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">ID Corte</div>
              <div id="confCorteId" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">Fecha Corte</div>
              <div id="confFechaCorte" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">Fecha Depósito</div>
              <div id="confFechaDeposito" class="h5 m-0">—</div>
            </div>
          </div>

          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">Monto</div>
              <div id="confMonto" class="h5 m-0">—</div>
              <div id="confPendienteHelp" class="small text-danger mt-1 d-none"><i class="bi bi-exclamation-triangle me-1"></i>El monto supera el pendiente.</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">Banco</div>
              <div id="confBanco" class="h5 m-0">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card card-surface p-3">
              <div class="text-muted small">Referencia</div>
              <div id="confReferencia" class="h5 m-0">—</div>
            </div>
          </div>

          <div class="col-12">
            <div class="card card-surface p-3">
              <div class="text-muted small">Motivo (opcional)</div>
              <div id="confMotivo" class="m-0">—</div>
            </div>
          </div>

          <div class="col-12">
            <div class="card card-surface p-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="text-muted small">Comprobante</div>
                  <div id="confArchivo" class="m-0">—</div>
                </div>
                <div id="confPreview" class="ms-3"></div>
              </div>
              <div class="small text-muted mt-2">Se validará tamaño (≤10MB) y tipo (PDF/JPG/PNG).</div>
            </div>
          </div>

          <div id="confErrors" class="col-12 d-none">
            <div class="alert alert-danger mb-0"><i class="bi bi-x-octagon me-1"></i><span class="conf-errors-text">Hay errores en los datos. Corrige antes de continuar.</span></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnModalCancelar" type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver y corregir</button>
        <button id="btnModalConfirmar" type="button" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirmar y registrar</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(() => {
  const modalEl   = document.getElementById('modalConfirmarDeposito');
  const modal     = new bootstrap.Modal(modalEl);
  let formToSubmit = null;

  // tooltips
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

  // Lista blanca en el cliente (debiera coincidir con PHP)
  const allowedBanks = [
    "BBVA","Citibanamex","Banorte","Santander","HSBC","Scotiabank",
    "Inbursa","Banco Azteca","BanCoppel","Banregio","Afirme",
    "Banco del Bajío","Banca Mifel","Compartamos Banco"
  ];

  // Sincroniza select de bancos con el hidden "banco"
  document.querySelectorAll('.deposito-form').forEach(form => {
    const sel = form.querySelector('select[name="banco_select"]');
    const hidden = form.querySelector('input[name="banco"]');
    if (!sel || !hidden) return;
    const sync = () => { hidden.value = sel.value || ""; };
    sel.addEventListener('change', sync);
    sync(); // init
  });

  const formatMXN = (n) => new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(n);

  function validateFile(file){
    if(!file) return {ok:false, msg:'Adjunta el comprobante.'};
    const allowed = ['application/pdf','image/jpeg','image/png'];
    if (!allowed.includes(file.type)) {
      const name = (file.name||'').toLowerCase();
      const extOk = name.endsWith('.pdf') || name.endsWith('.jpg') || name.endsWith('.jpeg') || name.endsWith('.png');
      if(!extOk) return {ok:false, msg:'Tipo de archivo no permitido.'};
    }
    if (file.size <= 0 || file.size > (10 * 1024 * 1024)) return {ok:false, msg:'El archivo excede 10 MB o está vacío.'};
    return {ok:true};
  }

  document.querySelectorAll('.deposito-form').forEach(form => {
    form.querySelector('.btn-confirmar-deposito').addEventListener('click', () => {
      form.classList.add('was-validated');
      if (!form.checkValidity()) return;

      const pendiente  = parseFloat(form.dataset.pendiente || '0');
      const idCorte    = form.dataset.idcorte || '';
      const fechaCorte = form.dataset.fechacorte || '';

      const fechaDep   = form.querySelector('input[name="fecha_deposito"]').value;
      const monto      = parseFloat(form.querySelector('input[name="monto_depositado"]').value || '0');
      const banco      = form.querySelector('input[name="banco"]').value.trim();
      const referencia = form.querySelector('input[name="referencia"]').value.trim();
      const motivo     = form.querySelector('input[name="motivo"]').value.trim();
      const fileInput  = form.querySelector('input[name="comprobante"]');
      const file       = fileInput?.files?.[0];

      let errors = [];
      if(!(monto > 0)) errors.push('Ingresa un monto mayor a 0.');
      if(monto > (pendiente + 0.0001)) errors.push('El monto supera el pendiente del corte.');
      if(!banco) errors.push('Banco es requerido.');
      else if(!allowedBanks.includes(banco)) errors.push('Selecciona un banco válido del listado.');
      if(!referencia) errors.push('Referencia es requerida.');
      else if(!/^\d+$/.test(referencia)) errors.push('La referencia debe ser numérica (solo dígitos).');
      const fileRes = validateFile(file);
      if(!fileRes.ok) errors.push(fileRes.msg);

      document.getElementById('confCorteId').textContent = '#' + idCorte;
      document.getElementById('confFechaCorte').textContent = fechaCorte || '—';
      document.getElementById('confFechaDeposito').textContent = fechaDep || '—';
      document.getElementById('confMonto').textContent = formatMXN(isFinite(monto) ? monto : 0);
      document.getElementById('confPendienteHelp').classList.toggle('d-none', !(monto > (pendiente + 0.0001)));
      document.getElementById('confBanco').textContent = banco || '—';
      document.getElementById('confReferencia').textContent = referencia || '—';
      document.getElementById('confMotivo').textContent = motivo || '—';

      const archivoTxt = file ? `${file.name} · ${(file.size/1024/1024).toFixed(2)} MB` : '—';
      document.getElementById('confArchivo').textContent = archivoTxt;

      const prev = document.getElementById('confPreview');
      prev.innerHTML = '';
      if (file && file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.style.maxHeight = '80px';
        img.style.borderRadius = '8px';
        img.style.border = '1px solid rgba(0,0,0,.1)';
        prev.appendChild(img);
      }

      const errorsBox = document.getElementById('confErrors');
      const errorsText = errorsBox.querySelector('.conf-errors-text');
      if (errors.length) {
        errorsText.textContent = 'Hay errores: ' + errors.join(' ');
        errorsBox.classList.remove('d-none');
      } else {
        errorsBox.classList.add('d-none');
      }

      formToSubmit = errors.length ? null : form;
      document.getElementById('btnModalConfirmar').disabled = !!errors.length;
      modal.show();
    });
  });

  document.getElementById('btnModalConfirmar').addEventListener('click', () => {
    if (formToSubmit) {
      formToSubmit.submit();
      formToSubmit = null;
      modal.hide();
    }
  });
})();
</script>
</body>
</html>