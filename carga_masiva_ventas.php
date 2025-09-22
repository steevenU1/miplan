<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = '';
$previewData = [];

// Crear tabla de control si no existe
$conn->query("
    CREATE TABLE IF NOT EXISTS cargas_masivas_ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        usuario_id INT,
        archivo_nombre VARCHAR(255),
        estado ENUM('Completada','Revertida') DEFAULT 'Completada',
        observaciones TEXT
    )
");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'preview' && isset($_FILES['archivo'])) {
    $archivoTmp = $_FILES['archivo']['tmp_name'];

    if (($handle = fopen($archivoTmp, 'r')) !== FALSE) {
        $fila = 0;
        while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
            $fila++;
            if ($fila == 1) continue; // Encabezado

            // CSV esperado: 15 columnas
            list($tag,$cliente,$telefono,$fecha_venta,$tipo_venta,$usuario,$imei1,
                 $precio_unitario,$precio_venta,$enganche,$forma_pago_enganche,
                 $plazo_semanas,$financiera,$comentarios,$tipo_producto) = array_pad($data, 15, '');

            // Limpieza
            $tag = trim($tag);
            $cliente = trim($cliente);
            $telefono = trim($telefono);
            $fecha_venta = trim($fecha_venta);
            $tipo_venta = trim($tipo_venta);
            $usuario = trim($usuario);
            $imei1 = trim($imei1);
            $precio_unitario = (float)$precio_unitario;
            $precio_venta = (float)$precio_venta;
            $enganche = (float)$enganche;
            $forma_pago_enganche = trim($forma_pago_enganche);
            $plazo_semanas = (int)$plazo_semanas;
            $financiera = trim($financiera);
            $comentarios = trim($comentarios);
            $tipo_producto = trim($tipo_producto);

            // Validar existencia del producto
            $stmt = $conn->prepare("SELECT p.id, i.id AS id_inventario, i.estatus, i.id_sucursal
                                    FROM productos p
                                    INNER JOIN inventario i ON i.id_producto=p.id
                                    WHERE (p.imei1=? OR p.imei2=?) LIMIT 1");
            $stmt->bind_param("ss", $imei1, $imei1);
            $stmt->execute();
            $producto = $stmt->get_result()->fetch_assoc();

            $estatus = 'OK';
            $motivo = 'Listo para insertar';
            $idProducto = null;
            $idInventario = null;
            $idSucursal = null;

            if (!$producto) {
                $estatus = 'Ignorada';
                $motivo = 'Producto no encontrado en inventario';
            } elseif ($producto['estatus'] != 'Disponible') {
                $estatus = 'Ignorada';
                $motivo = 'Producto no disponible';
            } else {
                $idProducto = $producto['id'];
                $idInventario = $producto['id_inventario'];
                $idSucursal = $producto['id_sucursal'];
            }

            $previewData[] = [
                'tag'=>$tag,'cliente'=>$cliente,'telefono'=>$telefono,'fecha_venta'=>$fecha_venta,
                'tipo_venta'=>$tipo_venta,'usuario'=>$usuario,'imei1'=>$imei1,
                'precio_unitario'=>$precio_unitario,'precio_venta'=>$precio_venta,
                'enganche'=>$enganche,'forma_pago_enganche'=>$forma_pago_enganche,
                'plazo_semanas'=>$plazo_semanas,'financiera'=>$financiera,'comentarios'=>$comentarios,
                'tipo_producto'=>$tipo_producto,
                'estatus'=>$estatus,'motivo'=>$motivo,
                'id_producto'=>$idProducto,'id_inventario'=>$idInventario,'id_sucursal'=>$idSucursal
            ];
        }
        fclose($handle);
    } else {
        $msg = "❌ Error al abrir el archivo CSV.";
    }
}

// Confirmar inserción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['action'] == 'insertar' && isset($_POST['data'])) {
    $data = json_decode($_POST['data'], true);

    // Crear registro de carga masiva
    $archivoNombre = $_POST['archivo_nombre'];
    $stmtCarga = $conn->prepare("INSERT INTO cargas_masivas_ventas (usuario_id, archivo_nombre) VALUES (?,?)");
    $stmtCarga->bind_param("is", $_SESSION['id_usuario'], $archivoNombre);
    $stmtCarga->execute();
    $idCarga = $stmtCarga->insert_id;

    $insertadas = 0;

    // Agrupar por TAG
    $ventasAgrupadas = [];
    foreach ($data as $venta) {
        if ($venta['estatus']!='OK') continue;
        $ventasAgrupadas[$venta['tag']][] = $venta;
    }

    foreach ($ventasAgrupadas as $tag=>$items) {
        $ventaBase = $items[0];

        // Obtener id_usuario
        $stmtU = $conn->prepare("SELECT id FROM usuarios WHERE nombre=? LIMIT 1");
        $stmtU->bind_param("s", $ventaBase['usuario']);
        $stmtU->execute();
        $rowU = $stmtU->get_result()->fetch_assoc();
        $idUsuario = $rowU['id'] ?? null;
        if (!$idUsuario) continue;

        // Insertar venta
        $stmtVenta = $conn->prepare("INSERT INTO ventas 
            (tag, nombre_cliente, telefono_cliente, tipo_venta, precio_venta, fecha_venta,
             id_usuario, id_sucursal, enganche, forma_pago_enganche, plazo_semanas, financiera, comentarios,
             id_carga_masiva, comision, comision_gerente, comision_especial)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,0)");
        $stmtVenta->bind_param("ssssdsiississsi",
            $ventaBase['tag'], $ventaBase['cliente'], $ventaBase['telefono'],
            $ventaBase['tipo_venta'], $ventaBase['precio_venta'], $ventaBase['fecha_venta'],
            $idUsuario, $ventaBase['id_sucursal'], $ventaBase['enganche'], $ventaBase['forma_pago_enganche'],
            $ventaBase['plazo_semanas'], $ventaBase['financiera'], $ventaBase['comentarios'],
            $idCarga
        );
        $stmtVenta->execute();
        $idVenta = $stmtVenta->insert_id;

        // Insertar detalle por cada producto
        foreach ($items as $prod) {
            $stmtDetalle = $conn->prepare("INSERT INTO detalle_venta 
                (id_venta,id_producto,imei1,precio_unitario,comision_regular,comision_especial,comision,id_carga_masiva)
                VALUES (?,?,?,?,0,0,0,?)");
            $stmtDetalle->bind_param("iisdi", $idVenta,$prod['id_producto'],$prod['imei1'],$prod['precio_unitario'],$idCarga);
            $stmtDetalle->execute();

            // Actualizar inventario
            $stmtInv = $conn->prepare("UPDATE inventario SET estatus='Vendido' WHERE id=?");
            $stmtInv->bind_param("i", $prod['id_inventario']);
            $stmtInv->execute();
        }

        $insertadas++;
    }

    echo "<div class='container mt-4'>
            <div class='alert alert-success'>
                ✅ Se insertaron $insertadas ventas correctamente.<br>
                <a href='listar_cargas_masivas.php'>Ver cargas masivas</a>
            </div>
          </div>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga Masiva de Ventas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📥 Carga Masiva de Ventas Iniciales</h2>
    <p>CSV: TAG, Cliente, Teléfono, Fecha_Venta, Tipo_Venta, Usuario, IMEI, Precio_Unitario, Precio_Venta, Enganche, Forma_Pago_Enganche, Plazo_Semanas, Financiera, Comentarios, Tipo_Producto</p>

    <?php if($msg) echo "<div class='alert alert-info'>$msg</div>"; ?>

    <?php if(empty($previewData)): ?>
        <form method="POST" enctype="multipart/form-data" class="card p-3 shadow-sm">
            <input type="hidden" name="action" value="preview">
            <input type="file" name="archivo" accept=".csv" class="form-control mb-3" required>
            <button type="submit" class="btn btn-primary">👀 Vista Previa</button>
        </form>
    <?php else: ?>
        <div class="card shadow p-3 mt-3">
            <h5>Vista Previa</h5>
            <form method="POST">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="archivo_nombre" value="<?= htmlspecialchars($_FILES['archivo']['name']) ?>">
                <input type="hidden" name="data" value='<?= json_encode($previewData) ?>'>
                <table class="table table-bordered table-sm mt-3">
                    <thead class="table-light">
                        <tr>
                            <th>TAG</th><th>Cliente</th><th>Teléfono</th><th>Fecha</th><th>Tipo</th><th>Usuario</th>
                            <th>IMEI</th><th>Precio Unitario</th><th>Precio Venta</th><th>Estatus</th><th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($previewData as $p): ?>
                        <tr class="<?= $p['estatus']=='OK'?'':'table-warning' ?>">
                            <td><?= htmlspecialchars($p['tag']) ?></td>
                            <td><?= htmlspecialchars($p['cliente']) ?></td>
                            <td><?= htmlspecialchars($p['telefono']) ?></td>
                            <td><?= $p['fecha_venta'] ?></td>
                            <td><?= $p['tipo_venta'] ?></td>
                            <td><?= $p['usuario'] ?></td>
                            <td><?= $p['imei1'] ?></td>
                            <td>$<?= number_format($p['precio_unitario'],2) ?></td>
                            <td>$<?= number_format($p['precio_venta'],2) ?></td>
                            <td><?= $p['estatus'] ?></td>
                            <td><?= $p['motivo'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-success mt-3">✅ Confirmar e Insertar</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
