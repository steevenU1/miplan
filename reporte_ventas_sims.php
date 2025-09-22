<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$idUsuario = $_SESSION['id_usuario'];
$idSucursal = $_SESSION['id_sucursal'];
$rol = $_SESSION['rol'] ?? 'Ejecutivo';

// 🔹 Filtros GET
$filtroTipo = $_GET['tipo'] ?? 'Todas';
$filtroUsuario = $_GET['usuario'] ?? 0;
$filtroSucursal = $_GET['sucursal'] ?? 0;
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');

// 🔹 Construcción dinámica de SQL
$sql = "
    SELECT vs.id, vs.tipo_venta, vs.precio_total, vs.comision_ejecutivo, vs.comision_gerente,
           vs.fecha_venta, vs.nombre_cliente, vs.numero_cliente,
           vs.id_usuario, u.nombre AS nombre_usuario,
           vs.id_sucursal, s.nombre AS nombre_sucursal,
           COALESCE(inv.iccid, 'eSIM') AS iccid
    FROM ventas_sims vs
    LEFT JOIN detalle_venta_sims dvs ON dvs.id_venta = vs.id
    LEFT JOIN inventario_sims inv ON inv.id = dvs.id_sim
    INNER JOIN usuarios u ON u.id = vs.id_usuario
    INNER JOIN sucursales s ON s.id = vs.id_sucursal
    WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?
";

$params = [$fechaInicio, $fechaFin];
$types = "ss";

// 🔹 Filtro por rol
if ($rol == 'Ejecutivo') {
    $sql .= " AND vs.id_usuario = ? ";
    $params[] = $idUsuario;
    $types .= "i";
} elseif ($rol == 'Gerente') {
    $sql .= " AND vs.id_sucursal = ? ";
    $params[] = $idSucursal;
    $types .= "i";
} else {
    // Admin/Administrativo: aplica filtros si los usa
    if ($filtroUsuario > 0) {
        $sql .= " AND vs.id_usuario = ? ";
        $params[] = $filtroUsuario;
        $types .= "i";
    }
    if ($filtroSucursal > 0) {
        $sql .= " AND vs.id_sucursal = ? ";
        $params[] = $filtroSucursal;
        $types .= "i";
    }
}

// 🔹 Filtro por tipo de venta
if ($filtroTipo != 'Todas') {
    $sql .= " AND vs.tipo_venta = ? ";
    $params[] = $filtroTipo;
    $types .= "s";
}

$sql .= " ORDER BY vs.fecha_venta DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// 🔹 Listar usuarios y sucursales para filtros (solo admin)
$usuarios = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre");
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas SIMs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📊 Reporte de Ventas SIMs</h2>

    <!-- 🔹 Formulario de filtros -->
    <form class="row mb-3" method="GET">
        <div class="col-md-2">
            <label class="form-label">Tipo de venta</label>
            <select name="tipo" class="form-select">
                <option <?= $filtroTipo=='Todas'?'selected':'' ?>>Todas</option>
                <option <?= $filtroTipo=='Prepago'?'selected':'' ?>>Prepago</option>
                <option <?= $filtroTipo=='Pospago'?'selected':'' ?>>Pospago</option>
            </select>
        </div>

        <?php if($rol == 'Administrador' || $rol == 'Administrativo'): ?>
            <div class="col-md-2">
                <label class="form-label">Usuario</label>
                <select name="usuario" class="form-select">
                    <option value="0">Todos</option>
                    <?php while($u = $usuarios->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>" <?= $filtroUsuario==$u['id']?'selected':'' ?>>
                            <?= $u['nombre'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Sucursal</label>
                <select name="sucursal" class="form-select">
                    <option value="0">Todas</option>
                    <?php while($s = $sucursales->fetch_assoc()): ?>
                        <option value="<?= $s['id'] ?>" <?= $filtroSucursal==$s['id']?'selected':'' ?>>
                            <?= $s['nombre'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="col-md-2">
            <label class="form-label">Fecha inicio</label>
            <input type="date" name="fecha_inicio" class="form-control" value="<?= $fechaInicio ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Fecha fin</label>
            <input type="date" name="fecha_fin" class="form-control" value="<?= $fechaFin ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>

    <!-- 🔹 Tabla de resultados -->
    <div class="card shadow">
        <div class="card-body table-responsive">
            <table class="table table-striped table-bordered table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>ICCID / eSIM</th>
                        <th>Cliente</th>
                        <th>Teléfono</th>
                        <th>Usuario</th>
                        <th>Sucursal</th>
                        <th>Precio</th>
                        <th>Com. Ejecutivo</th>
                        <th>Com. Gerente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= $row['fecha_venta'] ?></td>
                            <td><?= $row['tipo_venta'] ?></td>
                            <td><?= $row['iccid'] ?></td>
                            <td><?= $row['nombre_cliente'] ?></td>
                            <td><?= $row['numero_cliente'] ?></td>
                            <td><?= $row['nombre_usuario'] ?></td>
                            <td><?= $row['nombre_sucursal'] ?></td>
                            <td>$<?= number_format($row['precio_total'],2) ?></td>
                            <td>$<?= number_format($row['comision_ejecutivo'],2) ?></td>
                            <td>$<?= number_format($row['comision_gerente'],2) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
