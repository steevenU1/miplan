<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// Obtener listado de cargas masivas con conteo de ventas y productos
$sql = "
    SELECT c.id, c.fecha_carga, c.archivo_nombre, c.usuario_id, c.estado, c.observaciones,
           COUNT(DISTINCT v.id) AS total_ventas,
           COUNT(dv.id) AS total_productos
    FROM cargas_masivas_ventas c
    LEFT JOIN ventas v ON v.id_carga_masiva = c.id
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    GROUP BY c.id
    ORDER BY c.id DESC
";
$cargas = $conn->query($sql);
?>
<div class="container mt-4">
    <h2>📦 Historial de Cargas Masivas de Ventas</h2>
    <p>Desde aquí puedes consultar todas las cargas masivas realizadas, ver sus detalles y revertir si es necesario.</p>

    <table class="table table-bordered table-striped mt-3 align-middle">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Archivo</th>
                <th>Usuario</th>
                <th>Ventas</th>
                <th>Productos</th>
                <th>Estado</th>
                <th>Observaciones</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php while($c = $cargas->fetch_assoc()): ?>
            <tr class="<?= $c['estado']=='Revertida'?'table-warning':'' ?>">
                <td><?= $c['id'] ?></td>
                <td><?= $c['fecha_carga'] ?></td>
                <td><?= htmlspecialchars($c['archivo_nombre']) ?></td>
                <td><?= $c['usuario_id'] ?></td>
                <td><?= $c['total_ventas'] ?></td>
                <td><?= $c['total_productos'] ?></td>
                <td><?= $c['estado'] ?></td>
                <td><?= htmlspecialchars($c['observaciones']) ?></td>
                <td>
                    <a href="ver_detalle_carga.php?id=<?= $c['id'] ?>" class="btn btn-info btn-sm">👀 Ver Detalle</a>
                    <?php if($c['estado']=='Completada'): ?>
                        <a href="revertir_carga.php?id=<?= $c['id'] ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Revertir esta carga? Se eliminarán todas las ventas y los equipos volverán a inventario.')">
                           ⬅ Revertir
                        </a>
                    <?php else: ?>
                        <small>Ya revertida</small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
