<?php
// historial_recargas.php — Listado de recargas de tiempo aire

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Ejecutivo','Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = date('Y-m-d');

$where  = "r.fecha_recarga BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$fecha_desde, $fecha_hasta];
$types  = "ss";

if ($rol === 'Ejecutivo') {
    $where .= " AND r.id_usuario = ?";
    $params[] = $id_usuario;
    $types   .= "i";
} elseif ($rol === 'Gerente') {
    $where .= " AND r.id_sucursal = ?";
    $params[] = $id_sucursal;
    $types   .= "i";
} else {
    // Admin ve todo
}

$sql = "
    SELECT
        r.id,
        r.fecha_recarga,
        r.numero_telefonico,
        r.compania,
        r.monto,
        r.estatus,
        r.fecha_cancelacion,
        r.id_cobro,
        c.id_corte,
        u.nombre   AS nombre_usuario,
        s.nombre   AS nombre_sucursal
    FROM recargas_tiempo_aire r
    JOIN usuarios  u ON u.id = r.id_usuario
    JOIN sucursales s ON s.id = r.id_sucursal
    LEFT JOIN cobros c ON c.id = r.id_cobro
    WHERE $where
    ORDER BY r.fecha_recarga DESC, r.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$recargas = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de recargas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Íconos de Bootstrap para el icono de información -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Historial de recargas</h3>
        <a href="recarga_tiempo_aire.php" class="btn btn-outline-secondary btn-sm">Nueva recarga</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success py-2"><?=h($msg)?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger py-2"><?=h($err)?></div>
    <?php endif; ?>

    <form class="card mb-3 p-3" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?=h($fecha_desde)?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?=h($fecha_hasta)?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <button class="btn btn-primary mt-3 w-100" type="submit">Filtrar</button>
            </div>
            <div class="col-sm-4 col-md-3">
                <!-- Botón de exportar, respeta filtros de fecha -->
                <a href="export_recargas.php?desde=<?=h($fecha_desde)?>&hasta=<?=h($fecha_hasta)?>"
                   class="btn btn-success mt-3 w-100">
                    Exportar Excel
                </a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fecha / hora</th>
                        <th>Sucursal</th>
                        <th>Usuario</th>
                        <th>Número</th>
                        <th>Compañía</th>
                        <th class="text-end">Monto</th>
                        <th>Estatus</th>
                        <?php if ($rol === 'Admin'): ?>
                            <th class="text-center">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recargas): ?>
                    <tr>
                        <td colspan="<?= $rol === 'Admin' ? 8 : 7 ?>" class="text-center py-3">
                            Sin recargas en el rango seleccionado.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recargas as $r): ?>
                        <tr>
                            <td><?=h($r['fecha_recarga'])?></td>
                            <td><?=h($r['nombre_sucursal'])?></td>
                            <td><?=h($r['nombre_usuario'])?></td>
                            <td><?=h($r['numero_telefonico'])?></td>
                            <td><?=h($r['compania'])?></td>
                            <td class="text-end">$<?=number_format($r['monto'], 2)?></td>
                            <td>
                                <?php if ($r['estatus'] === 'Activa'): ?>
                                    <span class="badge bg-success">Activa</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Cancelada</span><br>
                                    <small class="text-muted"><?=h($r['fecha_cancelacion'])?></small>
                                <?php endif; ?>
                            </td>

                            <?php if ($rol === 'Admin'): ?>
                                <td class="text-center">
                                    <?php
                                        $esActiva   = ($r['estatus'] === 'Activa');
                                        $tieneCorte = !empty($r['id_corte']); // viene de LEFT JOIN cobros
                                    ?>

                                    <?php if (!$esActiva): ?>
                                        <!-- Ya cancelada -->
                                        —

                                    <?php elseif ($tieneCorte): ?>
                                        <!-- Icono informativo: no se puede cancelar porque está en corte -->
                                        <span style="cursor: help;"
                                              class="text-secondary"
                                              title="La recarga no se puede cancelar porque el cobro ya está incluido en un corte.">
                                            <i class="bi bi-info-circle-fill"></i>
                                        </span>

                                    <?php else: ?>
                                        <!-- Se puede cancelar normalmente -->
                                        <form method="post" action="cancelar_recarga.php"
                                              onsubmit="return confirm('¿Cancelar esta recarga y eliminar su cobro?');">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Cancelar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>

