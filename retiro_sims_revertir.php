<?php
// retiro_sims_revertir.php — Revertir un retiro (uno o todo)
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$accion = $_POST['accion'] ?? '';
$motivo = trim($_POST['motivo_rev'] ?? '');
if (strlen($motivo) < 5) { $_SESSION['flash_err']='Motivo de reversión muy corto.'; header("Location: retiro_sims_historial.php"); exit(); }
$idUsuario = (int)$_SESSION['id_usuario'];

try {
  $conn->begin_transaction();

  if ($accion === 'revertir_una') {
    $id_det = (int)($_POST['id_det'] ?? 0);
    if ($id_det<=0) throw new Exception('Detalle inválido');

    $row = $conn->query("SELECT d.id, d.id_retiro, d.id_sim, d.estado, i.estatus
                         FROM retiros_sims_det d
                         LEFT JOIN inventario_sims i ON i.id=d.id_sim
                         WHERE d.id={$id_det} FOR UPDATE")->fetch_assoc();
    if (!$row) throw new Exception('No existe el detalle');
    if ($row['estado'] !== 'Retirado') throw new Exception('Ya está revertido');

    // Reponer a Disponible
    $conn->query("UPDATE inventario_sims
                  SET estatus='Disponible', fecha_retiro=NULL, id_usuario_retiro=NULL, motivo_retiro=NULL
                  WHERE id=".(int)$row['id_sim']." AND estatus='Retirado'");

    if ($conn->affected_rows<=0) throw new Exception('No se pudo revertir la SIM (estatus cambió)');

    $stmt = $conn->prepare("UPDATE retiros_sims_det
                            SET estado='Revertido', fecha_reversion=NOW(), id_usuario_rev=?, motivo_reversion=?
                            WHERE id=?");
    $stmt->bind_param('isi', $idUsuario, $motivo, $id_det);
    $stmt->execute();

    $_SESSION['flash_ok'] = 'SIM revertida correctamente.';
    $conn->commit();
    header("Location: retiro_sims_historial.php?id=".(int)$row['id_retiro']); exit();

  } elseif ($accion === 'revertir_todo') {
    $id_retiro = (int)($_POST['id_retiro'] ?? 0);
    if ($id_retiro<=0) throw new Exception('Retiro inválido');

    // Selecciona todos los detalles aún Retirado
    $det = $conn->query("SELECT id, id_sim FROM retiros_sims_det
                         WHERE id_retiro={$id_retiro} AND estado='Retirado' FOR UPDATE");

    $idsSim = [];
    while($r=$det->fetch_assoc()){ $idsSim[] = (int)$r['id_sim']; }

    if (count($idsSim)) {
      $place = implode(',', $idsSim);
      $conn->query("UPDATE inventario_sims
                    SET estatus='Disponible', fecha_retiro=NULL, id_usuario_retiro=NULL, motivo_retiro=NULL
                    WHERE id IN ($place) AND estatus='Retirado'");

      $stmt = $conn->prepare("UPDATE retiros_sims_det
                              SET estado='Revertido', fecha_reversion=NOW(), id_usuario_rev=?, motivo_reversion=?
                              WHERE id_retiro=? AND estado='Retirado'");
      $stmt->bind_param('isi', $idUsuario, $motivo, $id_retiro);
      $stmt->execute();
    }

    $_SESSION['flash_ok'] = 'Retiro revertido por completo.';
    $conn->commit();
    header("Location: retiro_sims_historial.php?id=".$id_retiro); exit();
  } else {
    throw new Exception('Acción no válida');
  }

} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_err'] = 'Error: '.$e->getMessage();
  header("Location: retiro_sims_historial.php"); exit();
}
