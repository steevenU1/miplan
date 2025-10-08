<?php
// eliminar_venta_accesorio.php
// Requiere rol Admin. Restaura inventario y borra la venta (header + detalle por FK ON DELETE CASCADE).
declare(strict_types=1);

if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

$rol = (string)($_SESSION['rol'] ?? 'Ejecutivo');
if ($rol !== 'Admin') { header("Location: 403.php"); exit; }

require_once __DIR__ . '/db.php';

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header("Location: historial_ventas_accesorios.php?err=" . urlencode("Método no permitido")); exit;
}

$id_venta = (int)($_POST['id'] ?? 0);

// CSRF
$csrf_post = (string)($_POST['csrf'] ?? '');
$csrf_sess = (string)($_SESSION['csrf'] ?? '');
if (!$csrf_post || !$csrf_sess || !hash_equals($csrf_sess, $csrf_post)) {
  header("Location: historial_ventas_accesorios.php?err=" . urlencode("CSRF inválido")); exit;
}

// Para regresar con los filtros activos
$redir_qs = [];
foreach (['mode','date','month','sucursal'] as $k) {
  if (isset($_POST[$k])) $redir_qs[$k] = $_POST[$k];
}
$redir_base = 'historial_ventas_accesorios.php';

if ($id_venta <= 0) {
  $redir_qs['err'] = "ID de venta inválido";
  header("Location: $redir_base?" . http_build_query($redir_qs)); exit;
}

try {
  $conn->begin_transaction();

  // Bloqueamos la venta y obtenemos su sucursal
  $stmt = $conn->prepare("SELECT id_sucursal FROM ventas_accesorios WHERE id=? FOR UPDATE");
  $stmt->bind_param("i", $id_venta);
  $stmt->execute();
  $rowVenta = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$rowVenta) {
    throw new RuntimeException("La venta #$id_venta no existe.");
  }
  $id_sucursal = (int)$rowVenta['id_sucursal'];

  // Traemos detalle (items) a restituir
  $stmtD = $conn->prepare("
    SELECT dva.id_producto, dva.cantidad
    FROM detalle_ventas_accesorios dva
    WHERE dva.id_venta_accesorio=?
    FOR UPDATE
  ");
  $stmtD->bind_param("i", $id_venta);
  $stmtD->execute();
  $resD = $stmtD->get_result();
  $lineas = $resD->fetch_all(MYSQLI_ASSOC);
  $stmtD->close();

  // Regresar stock (una sola fila por producto/sucursal/estatus)
  foreach ($lineas as $ln) {
    $idp = (int)$ln['id_producto'];
    $qty = max(1, (int)$ln['cantidad']);

    // Intentamos actualizar la fila existente
    $sel = $conn->prepare("SELECT id FROM inventario WHERE id_producto=? AND id_sucursal=? AND estatus='Disponible' FOR UPDATE");
    $sel->bind_param("ii", $idp, $id_sucursal);
    $sel->execute();
    $ex = $sel->get_result()->fetch_assoc();
    $sel->close();

    if ($ex) {
      $upd = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");
      $upd->bind_param("ii", $qty, $ex['id']);
      $upd->execute();
      $upd->close();
    } else {
      // Si tienes el índice único (id_producto,id_sucursal,estatus) esto quedará consistente
      // y no habrá más de una fila Disponible por producto/sucursal.
      if (columnExists($conn,'inventario','cantidad')) {
        $ins = $conn->prepare("
          INSERT INTO inventario (id_producto, id_sucursal, estatus, cantidad, fecha_ingreso)
          VALUES (?, ?, 'Disponible', ?, NOW())
        ");
        $ins->bind_param("iii", $idp, $id_sucursal, $qty);
      } else {
        // fallback si tu inventario aún no tiene columna cantidad
        $ins = $conn->prepare("
          INSERT INTO inventario (id_producto, id_sucursal, estatus, fecha_ingreso)
          VALUES (?, ?, 'Disponible', NOW())
        ");
        $ins->bind_param("ii", $idp, $id_sucursal);
      }
      $ins->execute();
      $ins->close();
    }
  }

  // Borramos la venta (detalle se borra por ON DELETE CASCADE)
  $del = $conn->prepare("DELETE FROM ventas_accesorios WHERE id=?");
  $del->bind_param("i", $id_venta);
  $del->execute();
  $del->close();

  $conn->commit();

  $redir_qs['msg'] = "Venta #$id_venta eliminada y stock devuelto.";
  header("Location: $redir_base?" . http_build_query($redir_qs)); exit;

} catch (Throwable $e) {
  $conn->rollback();
  $redir_qs['err'] = "Error al eliminar: " . $e->getMessage();
  header("Location: $redir_base?" . http_build_query($redir_qs)); exit;
}

/** Helper: existe columna? */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  return $rs && $rs->num_rows > 0;
}
