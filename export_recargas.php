<?php
// export_recargas.php — Export detallado de recargas a Excel

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Ejecutivo','Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';

// Fechas desde GET (mismos nombres que en historial_recargas)
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
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   Limpia buffers antes de headers
======================== */
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
}

/* ========================
   Encabezados Excel (HTML compatible)
======================== */
$filename = "historial_recargas_" . $fecha_desde . "_a_" . $fecha_hasta . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

// BOM para UTF-8
echo "\xEF\xBB\xBF";
?>
<table border="1">
    <thead>
        <tr>
            <th>ID</th>
            <th>Fecha / hora recarga</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Número telefónico</th>
            <th>Compañía</th>
            <th>Monto</th>
            <th>Estatus</th>
            <th>Fecha cancelación</th>
            <th>ID Cobro</th>
            <th>ID Corte</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr>
            <td colspan="11">Sin recargas en el rango seleccionado.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h($r['fecha_recarga']) ?></td>
                <td><?= h($r['nombre_sucursal']) ?></td>
                <td><?= h($r['nombre_usuario']) ?></td>
                <td><?= h($r['numero_telefonico']) ?></td>
                <td><?= h($r['compania']) ?></td>
                <td><?= number_format((float)$r['monto'], 2, '.', '') ?></td>
                <td><?= h($r['estatus']) ?></td>
                <td><?= h($r['fecha_cancelacion']) ?></td>
                <td><?= h($r['id_cobro']) ?></td>
                <td><?= h($r['id_corte']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
