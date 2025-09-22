<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$id_corte = intval($_GET['id_corte'] ?? 0);
if ($id_corte <= 0) die("Corte inválido.");

// 🔹 Obtener info del corte
$sqlCorte = "
    SELECT cc.*, s.nombre AS sucursal
    FROM cortes_caja cc
    INNER JOIN sucursales s ON s.id = cc.id_sucursal
    WHERE cc.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlCorte);
$stmt->bind_param("i", $id_corte);
$stmt->execute();
$corte = $stmt->get_result()->fetch_assoc();
if (!$corte) die("Corte no encontrado.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto = floatval($_POST['monto_depositado'] ?? 0);
    $diferencia = floatval($_POST['diferencia_autorizada'] ?? 0);
    $obs = $_POST['observaciones'] ?? '';
    $accion = $_POST['accion'] ?? '';

    $estadoDeposito = 'Pendiente';
    $estadoCorte = 'Pendiente';

    if ($accion === 'Confirmar') {
        if ($monto >= $corte['total_efectivo']) {
            $estadoDeposito = 'Validado';
            $estadoCorte = 'Validado';
        }
    } elseif ($accion === 'Parcial') {
        $suma = $monto + $diferencia;
        if ($suma >= $corte['total_efectivo']) {
            $estadoDeposito = 'Validado';
            $estadoCorte = 'Validado';
        } else {
            $estadoDeposito = 'Parcial';
            $estadoCorte = 'Pendiente';
        }
    } elseif ($accion === 'Rechazar') {
        $estadoDeposito = 'Rechazado';
        $estadoCorte = 'Pendiente';
        $monto = 0;
        $diferencia = 0;
    }

    // 🔹 Calcular monto validado final
    $monto_validado = ($estadoDeposito === 'Validado') ? ($monto + $diferencia) : 0;

    // 🔹 Insertar en depositos_sucursal
    $stmtDep = $conn->prepare("
        INSERT INTO depositos_sucursal
        (id_sucursal, id_corte, fecha_deposito, monto_depositado, monto_validado, ajuste, motivo_ajuste, estado, id_admin_valida, observaciones, creado_en, actualizado_en)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmtDep->bind_param(
        "iidddssiss",
        $corte['id_sucursal'],  // id_sucursal
        $id_corte,              // id_corte
        $monto,                 // monto_depositado
        $monto_validado,        // monto_validado
        $diferencia,            // ajuste
        $obs,                   // motivo_ajuste
        $estadoDeposito,        // estado
        $_SESSION['id_usuario'],// id_admin_valida
        $obs                    // observaciones
    );
    $stmtDep->execute();

    // 🔹 Actualizar estado de corte
    $stmtUpd = $conn->prepare("UPDATE cortes_caja SET estado_deposito=? WHERE id=?");
    $stmtUpd->bind_param("si", $estadoCorte, $id_corte);
    $stmtUpd->execute();

    header("Location: depositos.php");
    exit();
}
?>
