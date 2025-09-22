<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';

$filtroSucursal = isset($_GET['sucursal']) ? (int)$_GET['sucursal'] : 0;

// 🔹 Obtener sucursales (solo tiendas para filtro)
$sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre ASC");

// 🔹 Consultar traspasos
$sql = "
SELECT t.id, t.fecha_traspaso, t.estatus, 
       s_origen.nombre AS sucursal_origen, 
       s_dest.nombre AS sucursal_destino, 
       u.nombre AS usuario_creo
FROM traspasos t
INNER JOIN sucursales s_origen ON s_origen.id = t.id_sucursal_origen
INNER JOIN sucursales s_dest ON s_dest.id = t.id_sucursal_destino
INNER JOIN usuarios u ON u.id = t.usuario_creo
WHERE 1=1
";

if ($filtroSucursal > 0) {
    $sql .= " AND t.id_sucursal_destino = $filtroSucursal";
}

$sql .= " ORDER BY t.fecha_traspaso DESC";

$traspasos = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Traspasos</title>
    <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>📜 Historial de Traspasos</h2>

    <!-- Filtro por sucursal -->
    <form method="GET" class="mb-3 d-flex">
        <select name="sucursal" class="form-select w-auto me-2">
            <option value="0">-- Todas las sucursales --</option>
            <?php while($row = $sucursales->fetch_assoc()): ?>
                <option value="<?= $row['id'] ?>" <?= $filtroSucursal==$row['id']?'selected':'' ?>><?= $row['nombre'] ?></option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>

    <?php if ($traspasos->num_rows > 0): ?>
        <div class="accordion" id="accordionTraspasos">
        <?php while($traspaso = $traspasos->fetch_assoc()): ?>
            <?php
            $idTraspaso = $traspaso['id'];
            $detalles = $conn->query("
                SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2, i.estatus
                FROM detalle_traspaso dt
                INNER JOIN inventario i ON i.id = dt.id_inventario
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE dt.id_traspaso = $idTraspaso
            ");
            $badge = $traspaso['estatus']=='Completado' 
                ? "<span class='badge bg-success'>Completado</span>" 
                : "<span class='badge bg-warning text-dark'>Pendiente</span>";
            ?>
            <div class="accordion-item mb-2 shadow-sm">
                <h2 class="accordion-header" id="heading<?=$idTraspaso?>">
                    <button class="accordion-button collapsed d-flex justify-content-between align-items-center" 
                            type="button" data-bs-toggle="collapse" 
                            data-bs-target="#collapse<?=$idTraspaso?>" 
                            aria-expanded="false" aria-controls="collapse<?=$idTraspaso?>">
                        <div class="w-100 d-flex justify-content-between">
                            <span>
                                <strong>Traspaso #<?=$idTraspaso?></strong> 
                                | <?=$traspaso['sucursal_origen']?> → <?=$traspaso['sucursal_destino']?> 
                                | Fecha: <?=$traspaso['fecha_traspaso']?>
                            </span>
                            <span><?=$badge?></span>
                        </div>
                    </button>
                </h2>
                <div id="collapse<?=$idTraspaso?>" class="accordion-collapse collapse" aria-labelledby="heading<?=$idTraspaso?>" data-bs-parent="#accordionTraspasos">
                    <div class="accordion-body p-0">
                        <table class="table table-striped table-bordered table-sm mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID Inv</th>
                                    <th>Marca</th>
                                    <th>Modelo</th>
                                    <th>Color</th>
                                    <th>IMEI1</th>
                                    <th>IMEI2</th>
                                    <th>Estatus Actual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $detalles->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= $row['marca'] ?></td>
                                        <td><?= $row['modelo'] ?></td>
                                        <td><?= $row['color'] ?></td>
                                        <td><?= $row['imei1'] ?></td>
                                        <td><?= $row['imei2'] ?: '-' ?></td>
                                        <td><?= $row['estatus'] ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <div class="p-2 bg-light border-top text-end">
                            Creado por: <?=$traspaso['usuario_creo']?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No hay traspasos para mostrar.</div>
    <?php endif; ?>
</div>

</body>
</html>
