<?php
// revertir_retiro.php
// - Permite revertir TODO un retiro o solo algunas líneas.
// - Soporta parciales en accesorios sin serie usando:
//     rows[ID_DETALLE][check] y rows[ID_DETALLE][qty]
// - Usa inventario_retiros_detalle.cantidad y cantidad_revertida
//   para controlar cuánto queda pendiente por revertir.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idRetiro    = (int)($_POST['id_retiro']     ?? 0);
$idSucursal  = (int)($_POST['id_sucursal']   ?? 0);
$notaRev     = trim($_POST['nota_reversion'] ?? '');

if ($idRetiro <= 0 || $idSucursal <= 0) {
  header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Parámetros inválidos."));
  exit();
}

/* ==========================
   Validar retiro + sucursal
   ========================== */
$st = $conn->prepare("SELECT id, id_sucursal, revertido FROM inventario_retiros WHERE id = ? LIMIT 1");
$st->bind_param("i", $idRetiro);
$st->execute();
$res = $st->get_result();
if (!$res || $res->num_rows === 0) {
  $st->close();
  header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("Retiro no encontrado."));
  exit();
}
$rowRet = $res->fetch_assoc();
$st->close();

if ((int)$rowRet['id_sucursal'] !== $idSucursal) {
  header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("El retiro no pertenece a la sucursal indicada."));
  exit();
}

/* ==========================
   Datos enviados (parciales)
   ========================== */
$rows = [];
if (isset($_POST['rows']) && is_array($_POST['rows'])) {
  $rows = $_POST['rows']; // rows[ID_DETALLE] = ['check' => '1', 'qty' => 'X']
}

$conn->begin_transaction();

try {

  // Traer detalle + inventario con FOR UPDATE
  $qDet = $conn->prepare("
    SELECT 
      d.id,
      d.id_inventario,
      d.id_producto,
      d.imei1,
      COALESCE(d.cantidad, 1)            AS cantidad,
      COALESCE(d.cantidad_revertida, 0)  AS cantidad_revertida,
      inv.cantidad                       AS stock_actual,
      inv.estatus                        AS estatus_inv
    FROM inventario_retiros_detalle d
    INNER JOIN inventario inv ON inv.id = d.id_inventario
    WHERE d.retiro_id = ?
    FOR UPDATE
  ");
  $qDet->bind_param("i", $idRetiro);
  $qDet->execute();
  $detalle = $qDet->get_result()->fetch_all(MYSQLI_ASSOC);
  $qDet->close();

  if (!$detalle) {
    throw new Exception("El retiro no tiene detalle.");
  }

  // Helper: ¿este request es "revertir TODO"?
  $esRevertirTodo = empty($rows);

  // Preparar updates
  $qUpdInv = $conn->prepare("
    UPDATE inventario
    SET cantidad = ?, estatus = ?
    WHERE id = ?
  ");

  $qUpdDet = $conn->prepare("
    UPDATE inventario_retiros_detalle
    SET cantidad_revertida = cantidad_revertida + ?
    WHERE id = ?
  ");

  $huboCambios = false;

  foreach ($detalle as $d) {
    $detId       = (int)$d['id'];
    $idInv       = (int)$d['id_inventario'];
    $cantTotal   = (int)$d['cantidad'];
    $cantRev     = (int)$d['cantidad_revertida'];
    $stockActual = (int)$d['stock_actual'];
    $estatusInv  = $d['estatus_inv'];

    if ($cantTotal <= 0) $cantTotal = 1;
    if ($cantRev   <  0) $cantRev   = 0;

    $pendiente = max(0, $cantTotal - $cantRev);
    if ($pendiente <= 0) {
      // Nada pendiente en este renglón
      continue;
    }

    // Determinar si este renglón se toma en cuenta
    $qtySolicitada = 0;

    if ($esRevertirTodo) {
      // Caso "Revertir TODO": revertimos todo lo pendiente
      $qtySolicitada = $pendiente;
    } else {
      if (!isset($rows[$detId]) || empty($rows[$detId]['check'])) {
        continue; // no marcado
      }
      $qtySolicitada = isset($rows[$detId]['qty']) ? (int)$rows[$detId]['qty'] : $pendiente;
      if ($qtySolicitada <= 0) {
        continue;
      }
      if ($qtySolicitada > $pendiente) {
        $qtySolicitada = $pendiente;
      }
    }

    if ($qtySolicitada <= 0) {
      continue;
    }

    // Actualizar inventario: sumamos la cantidad revertida
    $nuevoStock = $stockActual + $qtySolicitada;
    // Si hay stock, el estatus vuelve a Disponible
    $nuevoStatus = ($nuevoStock > 0) ? 'Disponible' : $estatusInv;

    $qUpdInv->bind_param("isi", $nuevoStock, $nuevoStatus, $idInv);
    $qUpdInv->execute();
    if ($qUpdInv->affected_rows < 0) {
      throw new Exception("Error al actualizar inventario #{$idInv}.");
    }

    // Actualizar detalle: acumulamos cantidad_revertida
    $qUpdDet->bind_param("ii", $qtySolicitada, $detId);
    $qUpdDet->execute();

    $huboCambios = true;
  }

  // Si no hubo cambios, no hacemos nada más
  if (!$huboCambios) {
    $conn->rollback();
    header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail=" . urlencode("No hay piezas pendientes por revertir en la selección."));
    exit();
  }

  // ¿Quedó TODO el retiro revertido?
  $qPend = $conn->prepare("
    SELECT COUNT(*) AS pendientes
    FROM inventario_retiros_detalle
    WHERE retiro_id = ?
      AND COALESCE(cantidad,1) > COALESCE(cantidad_revertida,0)
  ");
  $qPend->bind_param("i", $idRetiro);
  $qPend->execute();
  $rowPend = $qPend->get_result()->fetch_assoc();
  $qPend->close();

  $pendientes = (int)($rowPend['pendientes'] ?? 0);

  if ($pendientes === 0) {
    // Marcar cabecera como revertida
    $qCab = $conn->prepare("
      UPDATE inventario_retiros
      SET revertido = 1,
          fecha_reversion = NOW(),
          nota_reversion  = CASE 
                              WHEN ? <> '' THEN ?
                              ELSE nota_reversion
                            END
      WHERE id = ?
    ");
    $qCab->bind_param("ssi", $notaRev, $notaRev, $idRetiro);
    $qCab->execute();
    $qCab->close();
  } elseif ($notaRev !== '') {
    // Si aún queda pendiente pero se envió nota, la guardamos (anexamos)
    $qCab = $conn->prepare("
      UPDATE inventario_retiros
      SET nota_reversion = 
        CASE 
          WHEN nota_reversion IS NULL OR nota_reversion = '' THEN ?
          ELSE CONCAT(nota_reversion, ' | ', ?)
        END
      WHERE id = ?
    ");
    $qCab->bind_param("ssi", $notaRev, $notaRev, $idRetiro);
    $qCab->execute();
    $qCab->close();
  }

  $conn->commit();
  header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=revok");
  exit();

} catch (Throwable $e) {
  $conn->rollback();
  $err = urlencode($e->getMessage());
  header("Location: inventario_retiros_v2.php?sucursal={$idSucursal}&msg=err&errdetail={$err}");
  exit();
}
