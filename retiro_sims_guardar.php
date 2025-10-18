<?php
// retiro_sims_guardar.php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$token  = $_POST['csrf'] ?? '';
$ids    = $_POST['ids']  ?? [];
$motivo = trim($_POST['motivo'] ?? '');

if (!$token || $token !== ($_SESSION['retiro_token'] ?? '')) { die('CSRF inválido'); }
if (!is_array($ids) || !count($ids)) { die('Sin elementos seleccionados'); }
if (strlen($motivo) < 5) { die('Motivo muy corto'); }

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// Solo permite retirar si estatus actual es Disponible o En transito
$sql = "UPDATE inventario_sims
        SET estatus='Retirado',
            fecha_retiro=NOW(),
            id_usuario_retiro=?,
            motivo_retiro=?
        WHERE id IN (%s) AND estatus IN ('Disponible','En transito')";
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = sprintf($sql, $placeholders);

$stmt = $conn->prepare($sql);

$types = 'is' . str_repeat('i', count($ids));
$params = [$idUsuario, $motivo, ...array_map('intval',$ids)];
$stmt->bind_param($types, ...$params);

$conn->begin_transaction();
try {
  $stmt->execute();
  $af = $conn->affected_rows;
  $conn->commit();
  $_SESSION['flash_ok'] = "Retiro completado: {$af} SIM(s).";
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_err'] = "Error al retirar: ".$e->getMessage();
}

header("Location: retiro_sims.php");
exit;
