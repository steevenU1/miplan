<?php
// retiro_sims_confirmar.php — Aplica retiro de las SIMs del carrito
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

if (($_POST['csrf'] ?? '') !== ($_SESSION['retiro_token'] ?? '')) { die('CSRF inválido'); }
$motivo = trim($_POST['motivo'] ?? '');
if (strlen($motivo) < 5) { $_SESSION['flash_err'] = 'Motivo muy corto.'; header("Location: retiro_sims.php"); exit(); }

$ids = $_SESSION['carrito_retiro'] ?? [];
if (!count($ids)) { $_SESSION['flash_err'] = 'Carrito vacío.'; header("Location: retiro_sims.php"); exit(); }

$idUsuario = (int)$_SESSION['id_usuario'];

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$conn->begin_transaction();
try {
  // Cabecera
  $stmt = $conn->prepare("INSERT INTO retiros_sims (id_usuario, motivo, total_items) VALUES (?,?,0)");
  $stmt->bind_param('is', $idUsuario, $motivo);
  $stmt->execute();
  $id_retiro = $stmt->insert_id;

  // Cargar info de las SIMs
  $place = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));
  $sql = "SELECT id, iccid, dn, operador, caja_id, id_sucursal, estatus
          FROM inventario_sims
          WHERE id IN ($place) FOR UPDATE";
  $stmt2 = $conn->prepare($sql);
  $stmt2->bind_param($types, ...array_map('intval', $ids));
  $stmt2->execute();
  $rs = $stmt2->get_result();

  $ok = 0;
  $upd = $conn->prepare("UPDATE inventario_sims
                         SET estatus='Retirado', fecha_retiro=NOW(), id_usuario_retiro=?, motivo_retiro=?
                         WHERE id=? AND estatus IN ('Disponible','En transito')");
  $ins = $conn->prepare("INSERT INTO retiros_sims_det
                         (id_retiro,id_sim,iccid_snap,dn_snap,operador_snap,caja_snap,id_sucursal_snap,estado)
                         VALUES (?,?,?,?,?,?,?,'Retirado')");

  while($row = $rs->fetch_assoc()){
    $idSim = (int)$row['id'];
    if (!in_array($row['estatus'], ['Disponible','En transito'], true)) { continue; }

    $upd->bind_param('isi', $idUsuario, $motivo, $idSim);
    $upd->execute();
    if ($upd->affected_rows > 0) {
      $ok++;
      $ins->bind_param('isssssi', $id_retiro, $idSim, $row['iccid'], $row['dn'], $row['operador'], $row['caja_id'], $row['id_sucursal']);
      $ins->execute();
    }
  }

  // Actualiza total_items
  $conn->query("UPDATE retiros_sims SET total_items={$ok} WHERE id={$id_retiro}");
  $conn->commit();

  $_SESSION['carrito_retiro'] = [];
  $_SESSION['flash_ok'] = "Retiro #{$id_retiro} aplicado: {$ok} SIM(s).";
  header("Location: retiro_sims_historial.php?id=".$id_retiro); exit();
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_err'] = "Error al retirar: ".$e->getMessage();
  header("Location: retiro_sims.php"); exit();
}
