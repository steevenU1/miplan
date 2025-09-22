<?php
// revertir_retiro.php — Reversión total o parcial
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table=$conn->real_escape_string($table); $column=$conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'"); return $rs && $rs->num_rows>0;
}

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idRetiro    = (int)($_POST['id_retiro'] ?? 0);
$idSucursal  = (int)($_POST['id_sucursal'] ?? 0);
$notaRev     = trim($_POST['nota_reversion'] ?? '');
$items       = isset($_POST['items']) && is_array($_POST['items']) ? array_values(array_unique(array_map('intval', $_POST['items']))) : [];

if ($idRetiro<=0 || $idSucursal<=0) { header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode('Datos incompletos.')); exit(); }

// Validar retiro existe y pertenece a la sucursal
$st = $conn->prepare("SELECT id, id_sucursal, revertido FROM inventario_retiros WHERE id=? LIMIT 1");
$st->bind_param("i", $idRetiro); $st->execute();
$ret = $st->get_result()->fetch_assoc(); $st->close();
if (!$ret || (int)$ret['id_sucursal'] !== $idSucursal) {
  header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode('Retiro inválido.')); exit();
}
if ((int)$ret['revertido'] === 1) {
  header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode('El retiro ya está revertido.')); exit();
}

// Traer todos los items del retiro
$all = [];
$st = $conn->prepare("SELECT id_inventario FROM inventario_retiros_detalle WHERE retiro_id=?");
$st->bind_param("i", $idRetiro); $st->execute();
$r = $st->get_result(); while($row=$r->fetch_assoc()) $all[]=(int)$row['id_inventario']; $st->close();
if (empty($all)) { header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode('El retiro no tiene detalle.')); exit(); }

// Si no mandan items[] -> revertir todo
if (empty($items)) $items = $all;

// Filtra a sólo los que pertenecen al retiro
$items = array_values(array_intersect($items, $all));
if (empty($items)) { header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode('Nada que revertir.')); exit(); }

// Flags de columnas en detalle
$hasDetRev   = hasColumn($conn,'inventario_retiros_detalle','revertido');
$hasDetFrev  = hasColumn($conn,'inventario_retiros_detalle','fecha_reversion');

$conn->begin_transaction();
try {
  // Preparar statements
  $qUpdInv = $conn->prepare("UPDATE inventario SET estatus='Disponible', id_sucursal=? WHERE id=? AND estatus='Retirado'");
  $qUpdDet = ($hasDetRev || $hasDetFrev)
    ? $conn->prepare("UPDATE inventario_retiros_detalle SET ".
        ($hasDetRev? "revertido=1, ":"").
        ($hasDetFrev? "fecha_reversion=NOW(), ":"").
        " retiro_id=retiro_id WHERE retiro_id=? AND id_inventario=?")
    : null;

  foreach ($items as $idInv) {
    // regresa al almacén/sucursal del retiro
    $qUpdInv->bind_param("ii", $idSucursal, $idInv);
    $qUpdInv->execute();
    if ($qUpdInv->affected_rows === 0) {
      throw new Exception("Inventario {$idInv} no estaba 'Retirado' o no se pudo revertir.");
    }
    if ($qUpdDet) { $qUpdDet->bind_param("ii", $idRetiro, $idInv); $qUpdDet->execute(); }
  }
  $qUpdInv->close(); if ($qUpdDet) $qUpdDet->close();

  // ¿Quedan piezas retiradas en este retiro?
  // Contamos cuántos del retiro siguen con estatus 'Retirado'
  $inPlaceholders = implode(',', array_fill(0, count($all), '?'));
  $types = str_repeat('i', count($all));
  $sqlRest = "SELECT COUNT(*) AS c FROM inventario WHERE id IN ($inPlaceholders) AND estatus='Retirado'";
  $st = $conn->prepare($sqlRest); $st->bind_param($types, ...$all); $st->execute();
  $cRest = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();

  if ($cRest === 0) {
    // Reversión total alcanzada
    $st = $conn->prepare("UPDATE inventario_retiros SET revertido=1, fecha_reversion=NOW(), nota_reversion=? WHERE id=?");
    $st->bind_param("si", $notaRev, $idRetiro);
    $st->execute(); $st->close();
  } else {
    // Parcial: si mandaron nota, guardarla (opcional)
    if ($notaRev !== '') {
      $st = $conn->prepare("UPDATE inventario_retiros SET nota_reversion=? WHERE id=?");
      $st->bind_param("si", $notaRev, $idRetiro); $st->execute(); $st->close();
    }
  }

  $conn->commit();
  header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=revok"); exit();

} catch (Throwable $e) {
  $conn->rollback();
  header("Location: inventario_retiros.php?sucursal={$idSucursal}&msg=err&errdetail=".urlencode($e->getMessage())); exit();
}
