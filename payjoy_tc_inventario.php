<?php
// payjoy_tc_inventario.php — Inventario de TC PayJoy por sucursal + ingresos + traspasos + estadísticas + exports

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

// Solo admin / gerencias
$ROLES_PERMITIDOS = ['Admin','Super','SuperAdmin','Gerente','Gerente General','GerenteZona','GerenteSucursal'];
if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    header("Location: 403.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$mensaje          = $_GET['msg'] ?? null;
$error            = $_GET['err'] ?? null;
$idSucursalFiltro = (int)($_GET['id_sucursal_filtro'] ?? 0);

/* =====================================================
   0) Exports (inventario / movimientos) — CSV
   ===================================================== */
if (isset($_GET['export'])) {
    $tipoExport = $_GET['export'];
    $fechaFile  = date('Ymd_His');

    if ($tipoExport === 'inventario') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventario_payjoy_tc_' . $fechaFile . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sucursal', 'Tarjetas disponibles']);

        $sqlInvExport = "
            SELECT
                s.id,
                s.nombre,
                COALESCE(SUM(
                    CASE WHEN k.tipo = 'INGRESO' THEN k.cantidad ELSE -k.cantidad END
                ),0) AS tarjetas_disponibles
            FROM sucursales s
            LEFT JOIN payjoy_tc_kardex k
                ON k.id_sucursal = s.id
        ";
        if ($idSucursalFiltro > 0) {
            $sqlInvExport .= " WHERE s.id = ? ";
        }
        $sqlInvExport .= " GROUP BY s.id, s.nombre ORDER BY s.nombre";

        if ($idSucursalFiltro > 0) {
            $stmt = $conn->prepare($sqlInvExport);
            $stmt->bind_param("i", $idSucursalFiltro);
            $stmt->execute();
            $rs = $stmt->get_result();
        } else {
            $rs = $conn->query($sqlInvExport);
        }

        while ($row = $rs->fetch_assoc()) {
            fputcsv($out, [$row['nombre'], (int)$row['tarjetas_disponibles']]);
        }
        $rs->close();
        fclose($out);
        exit();
    }

    if ($tipoExport === 'movimientos') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="movimientos_payjoy_tc_' . $fechaFile . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Fecha', 'Sucursal', 'Tipo', 'Concepto', 'Cantidad', 'Usuario', 'Comentario']);

        $sqlMovExp = "
            SELECT
                k.fecha_mov,
                s.nombre AS sucursal,
                k.tipo,
                k.concepto,
                k.cantidad,
                u.nombre AS usuario,
                k.comentario
            FROM payjoy_tc_kardex k
            INNER JOIN sucursales s ON s.id = k.id_sucursal
            LEFT JOIN usuarios  u ON u.id = k.id_usuario
            WHERE 1
        ";
        if ($idSucursalFiltro > 0) {
            $sqlMovExp .= " AND k.id_sucursal = ? ";
        }
        $sqlMovExp .= " ORDER BY k.fecha_mov DESC";

        if ($idSucursalFiltro > 0) {
            $stmt = $conn->prepare($sqlMovExp);
            $stmt->bind_param("i", $idSucursalFiltro);
            $stmt->execute();
            $rs = $stmt->get_result();
        } else {
            $rs = $conn->query($sqlMovExp);
        }

        while ($row = $rs->fetch_assoc()) {
            fputcsv($out, [
                $row['fecha_mov'],
                $row['sucursal'],
                $row['tipo'],
                $row['concepto'],
                (int)$row['cantidad'],
                $row['usuario'],
                $row['comentario']
            ]);
        }
        $rs->close();
        fclose($out);
        exit();
    }
}

/* =====================================================
   1) Procesar POST (ingreso o traspaso)
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion = $_POST['accion'] ?? 'traspaso';

    /* --------- A) INGRESO DE TARJETAS --------- */
    if ($accion === 'ingreso') {

        $idSucursalIng = (int)($_POST['id_sucursal_ingreso'] ?? 0);
        $cantidadIng   = (int)($_POST['cantidad_ingreso'] ?? 0);
        $comentarioIng = trim($_POST['comentario_ingreso'] ?? '');

        if ($idSucursalIng <= 0 || $cantidadIng <= 0) {
            $error = "Datos de ingreso inválidos.";
        } else {
            try {
                $sqlMov = "INSERT INTO payjoy_tc_kardex
                           (id_sucursal, tipo, concepto, cantidad, id_usuario, comentario)
                           VALUES (?,?,?,?,?,?)";

                $stmt = $conn->prepare($sqlMov);
                $tipo      = 'INGRESO';
                $concepto  = 'ALTA_INICIAL'; // o 'AJUSTE'
                $stmt->bind_param("issiis",
                    $idSucursalIng, $tipo, $concepto, $cantidadIng, $idUsuario, $comentarioIng
                );
                $stmt->execute();
                $stmt->close();

                header("Location: payjoy_tc_inventario.php?msg=" . urlencode("✅ Ingreso registrado correctamente"));
                exit();
            } catch (Throwable $e) {
                $error = "Error al registrar el ingreso: " . $e->getMessage();
            }
        }

    /* --------- B) TRASPASO ENTRE SUCURSALES --------- */
    } else { // 'traspaso' por defecto

        $origen     = (int)($_POST['id_sucursal_origen'] ?? 0);
        $destino    = (int)($_POST['id_sucursal_destino'] ?? 0);
        $cantidad   = (int)($_POST['cantidad'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');

        if ($origen <= 0 || $destino <= 0 || $origen === $destino || $cantidad <= 0) {
            $error = "Datos de traspaso inválidos.";
        } else {
            // Obtener saldo actual de la sucursal origen
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN tipo = 'INGRESO' THEN cantidad ELSE -cantidad END
                ), 0) AS saldo
                FROM payjoy_tc_kardex
                WHERE id_sucursal = ?
            ");
            $stmt->bind_param("i", $origen);
            $stmt->execute();
            $stmt->bind_result($saldoOrigen);
            $stmt->fetch();
            $stmt->close();

            if ($saldoOrigen < $cantidad) {
                $error = "La sucursal de origen no tiene suficientes tarjetas. Saldo actual: {$saldoOrigen}.";
            } else {
                try {
                    $conn->begin_transaction();

                    $sqlMov = "INSERT INTO payjoy_tc_kardex
                               (id_sucursal, tipo, concepto, cantidad, id_usuario, comentario)
                               VALUES (?,?,?,?,?,?)";

                    // SALIDA en origen
                    $stmt = $conn->prepare($sqlMov);
                    $tipo      = 'SALIDA';
                    $concepto  = 'TRASPASO';
                    $stmt->bind_param("issiis",
                        $origen, $tipo, $concepto, $cantidad, $idUsuario, $comentario
                    );
                    $stmt->execute();
                    $stmt->close();

                    // INGRESO en destino
                    $stmt = $conn->prepare($sqlMov);
                    $tipo      = 'INGRESO';
                    $concepto  = 'TRASPASO';
                    $stmt->bind_param("issiis",
                        $destino, $tipo, $concepto, $cantidad, $idUsuario, $comentario
                    );
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    header("Location: payjoy_tc_inventario.php?msg=" . urlencode("✅ Traspaso registrado correctamente"));
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Error al registrar el traspaso: " . $e->getMessage();
                }
            }
        }
    }
}

/* =====================================================
   2) Catálogo de sucursales
   ===================================================== */
$rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
$sucursales = $rs->fetch_all(MYSQLI_ASSOC);
$rs->close();

/* =====================================================
   3) Inventario por sucursal (completo para stats)
   ===================================================== */
$sqlInv = "
    SELECT
        s.id,
        s.nombre,
        COALESCE(SUM(
            CASE WHEN k.tipo = 'INGRESO' THEN k.cantidad ELSE -k.cantidad END
        ),0) AS tarjetas_disponibles
    FROM sucursales s
    LEFT JOIN payjoy_tc_kardex k
        ON k.id_sucursal = s.id
    GROUP BY s.id, s.nombre
    ORDER BY s.nombre
";
$rs = $conn->query($sqlInv);
$inventarioTotal = $rs->fetch_all(MYSQLI_ASSOC);
$rs->close();

// Stats globales
$totalGlobal       = 0;
$sucursalesConStock = 0;
$stockSucursalFiltro = null;

foreach ($inventarioTotal as $fila) {
    $stock = (int)$fila['tarjetas_disponibles'];
    $totalGlobal += $stock;
    if ($stock > 0) {
        $sucursalesConStock++;
    }
    if ($idSucursalFiltro > 0 && (int)$fila['id'] === $idSucursalFiltro) {
        $stockSucursalFiltro = $stock;
    }
}

// Inventario a mostrar (aplica filtro si viene)
$inventario = $inventarioTotal;
if ($idSucursalFiltro > 0) {
    $inventario = array_values(array_filter($inventarioTotal, function ($row) use ($idSucursalFiltro) {
        return (int)$row['id'] === $idSucursalFiltro;
    }));
}

/* =====================================================
   4) Historial reciente de movimientos (últimos 50)
   ===================================================== */
$sqlHist = "
    SELECT
        k.fecha_mov,
        s.nombre AS sucursal,
        k.tipo,
        k.concepto,
        k.cantidad,
        u.nombre AS usuario,
        k.comentario
    FROM payjoy_tc_kardex k
    INNER JOIN sucursales s ON s.id = k.id_sucursal
    LEFT JOIN usuarios  u ON u.id = k.id_usuario
    WHERE 1
";
if ($idSucursalFiltro > 0) {
    $sqlHist .= " AND k.id_sucursal = ? ";
}
$sqlHist .= " ORDER BY k.fecha_mov DESC LIMIT 50";

if ($idSucursalFiltro > 0) {
    $stmt = $conn->prepare($sqlHist);
    $stmt->bind_param("i", $idSucursalFiltro);
    $stmt->execute();
    $rsHist = $stmt->get_result();
} else {
    $rsHist = $conn->query($sqlHist);
}
$historial = $rsHist->fetch_all(MYSQLI_ASSOC);
$rsHist->close();

// Navbar hasta el final para no estorbar headers()
require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario TC PayJoy</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background:#f5f7fb; }
  .card-shadow { border-radius:18px; border:none; box-shadow:0 8px 20px rgba(0,0,0,.06); }
</style>
</head>
<body>
<div class="container my-4">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="mb-2 mb-md-0">💳 Inventario de tarjetas PayJoy</h3>

    <!-- Filtro por sucursal -->
    <form class="d-flex gap-2" method="get">
      <select name="id_sucursal_filtro" class="form-select form-select-sm" style="min-width:220px;">
        <option value="0">Todas las sucursales</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $idSucursalFiltro === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">Aplicar filtro</button>
    </form>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-success"><?= h($mensaje) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- Cards estadísticas -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card card-shadow">
        <div class="card-body">
          <div class="text-muted small">Total de tarjetas en sistema</div>
          <div class="display-6"><?= (int)$totalGlobal ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card card-shadow">
        <div class="card-body">
          <div class="text-muted small">Sucursales con stock &gt; 0</div>
          <div class="display-6"><?= (int)$sucursalesConStock ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card card-shadow">
        <div class="card-body">
          <div class="text-muted small">
            <?= $idSucursalFiltro > 0 ? 'Tarjetas en sucursal seleccionada' : 'Tarjetas sucursal seleccionada' ?>
          </div>
          <div class="display-6">
            <?php
              if ($idSucursalFiltro > 0) {
                  echo (int)($stockSucursalFiltro ?? 0);
              } else {
                  echo '-';
              }
            ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Botones de export -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a href="payjoy_tc_inventario.php?export=inventario<?= $idSucursalFiltro>0 ? '&id_sucursal_filtro='.$idSucursalFiltro : '' ?>"
       class="btn btn-sm btn-outline-success">
      Exportar inventario (CSV)
    </a>
    <a href="payjoy_tc_inventario.php?export=movimientos<?= $idSucursalFiltro>0 ? '&id_sucursal_filtro='.$idSucursalFiltro : '' ?>"
       class="btn btn-sm btn-outline-secondary">
      Exportar historial de movimientos (CSV)
    </a>
  </div>

  <!-- Inventario por sucursal -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white">
      <strong>Existencias por sucursal</strong>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Sucursal</th>
              <th class="text-end">Tarjetas disponibles</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i = 1;
          $totalVisible = 0;
          foreach ($inventario as $fila):
            $totalVisible += (int)$fila['tarjetas_disponibles'];
          ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($fila['nombre']) ?></td>
              <td class="text-end">
                <span class="badge bg-<?= ((int)$fila['tarjetas_disponibles'] > 0 ? 'success' : 'secondary') ?>">
                  <?= (int)$fila['tarjetas_disponibles'] ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light">
              <th colspan="2">
                Total en vista <?= $idSucursalFiltro>0 ? '(sucursal filtrada)' : '(todas)' ?>
              </th>
              <th class="text-end"><?= (int)$totalVisible ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Formulario de ingreso (altas desde proveedor / PayJoy) -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white">
      <strong>Ingreso de tarjetas a una sucursal</strong>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="accion" value="ingreso">
        <div class="col-md-4">
          <label class="form-label">Sucursal</label>
          <select name="id_sucursal_ingreso" class="form-select" required>
            <option value="">— Selecciona —</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Cantidad</label>
          <input type="number" min="1" name="cantidad_ingreso" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Comentario</label>
          <input type="text" name="comentario_ingreso" class="form-control" maxlength="255"
                 placeholder="Ej. Lote nuevo enviado por PayJoy / proveedor">
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success">Registrar ingreso</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Formulario de traspaso -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white">
      <strong>Traspaso de tarjetas entre sucursales</strong>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="accion" value="traspaso">
        <div class="col-md-4">
          <label class="form-label">Sucursal origen</label>
          <select name="id_sucursal_origen" class="form-select" required>
            <option value="">— Selecciona —</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Sucursal destino</label>
          <select name="id_sucursal_destino" class="form-select" required>
            <option value="">— Selecciona —</option>
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Cantidad</label>
          <input type="number" min="1" name="cantidad" class="form-control" required>
        </div>
        <div class="col-md-12">
          <label class="form-label">Comentario (opcional)</label>
          <input type="text" name="comentario" class="form-control" maxlength="255" placeholder="Ej. Traspaso desde almacén">
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-primary">Guardar traspaso</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Historial reciente de movimientos -->
  <div class="card card-shadow mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <strong>Historial reciente de movimientos (últimos 50)</strong>
      <span class="badge bg-light text-dark">
        <?= $idSucursalFiltro>0 ? 'Filtrado por sucursal seleccionada' : 'Todas las sucursales' ?>
      </span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Fecha</th>
              <th>Sucursal</th>
              <th>Tipo</th>
              <th>Concepto</th>
              <th class="text-end">Cantidad</th>
              <th>Usuario</th>
              <th>Comentario</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($historial)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">Sin movimientos registrados.</td></tr>
          <?php else: ?>
            <?php foreach ($historial as $mov): ?>
              <tr>
                <td><?= h($mov['fecha_mov']) ?></td>
                <td><?= h($mov['sucursal']) ?></td>
                <td>
                  <span class="badge bg-<?= $mov['tipo']==='INGRESO' ? 'success' : 'danger' ?>">
                    <?= h($mov['tipo']) ?>
                  </span>
                </td>
                <td><?= h($mov['concepto']) ?></td>
                <td class="text-end"><?= (int)$mov['cantidad'] ?></td>
                <td><?= h($mov['usuario'] ?? 'N/D') ?></td>
                <td><?= h($mov['comentario']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
