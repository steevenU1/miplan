<?php
include 'db.php';
session_start();

if(!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin'){
    exit("Sin permisos");
}

$id = intval($_POST['id'] ?? 0);
$precio = $_POST['precio'] ?? '';

if($id > 0 && $precio !== ''){
    // 🔹 Normalizar valor: quitar símbolos y comas
    $precio = str_replace(['$',',',' '], '', $precio);
    $precio = floatval($precio);

    if($precio > 0){
        // Obtener producto de ese inventario
        $q = $conn->prepare("SELECT id_producto FROM inventario WHERE id=? LIMIT 1");
        $q->bind_param("i", $id);
        $q->execute();
        $res = $q->get_result()->fetch_assoc();

        if($res){
            $idProd = $res['id_producto'];
            $upd = $conn->prepare("UPDATE productos SET precio_lista=? WHERE id=?");
            $upd->bind_param("di", $precio, $idProd);
            if($upd->execute()){
                echo "Precio actualizado correctamente a $".number_format($precio,2);
            } else {
                echo "Error al actualizar";
            }
        } else {
            echo "Inventario no encontrado";
        }
    } else {
        echo "Datos inválidos (precio no numérico)";
    }
} else {
    echo "Datos inválidos (sin ID o precio)";
}
?>
