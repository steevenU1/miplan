<?php
// eliminar_traspaso.php
// Reglas:
// - Solo Admin
// - Solo traspasos en estatus 'Pendiente'
// - Reversa SOLO piezas del traspaso que estén "en tránsito" al inventario de la sucursal de origen
// - Marca estatus='Disponible' y restaura id_sucursal = origen
// - Borra detalle y encabezado en una transacción
// - Si existe inventario.fecha_actualizacion, la actualiza a NOW()

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

// Normaliza rol y valida Admin
$rolUsuario = $_SESSION['rol'] ?? '';
if (strcasecmp(trim((string)$rolUsuario), 'Admin') !== 0) {
    header("Location: traspasos_salientes.php?msg=no_permitido");
    exit();
}

require_once __DIR__ . '/db.php';

/* -------------------- Helpers -------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
    $rs = $conn->query($sql);
    return $rs && $rs->num_rows > 0;
}

/* -------------------- Input -------------------- */
$idTraspaso = isset($_POST['id_traspaso']) ? (int)$_POST['id_traspaso'] : 0;
if ($idTraspaso <= 0) {
    header("Location: traspasos_salientes.php?msg=no_encontrado");
    exit();
}

/* -------------------- Valida encabezado -------------------- */
$stmt = $conn->prepare("SELECT id, estatus, id_sucursal_origen FROM traspasos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $idTraspaso);
$stmt->execute();
$tras = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tras) {
    header("Location: traspasos_salientes.php?msg=no_encontrado");
    exit();
}
if (strcasecmp($tras['estatus'], 'Pendiente') !== 0) {
    header("Location: traspasos_salientes.php?msg=no_pendiente");
    exit();
}

$idSucursalOrigen = (int)$tras['id_sucursal_origen'];

/* -------------------- Esquema opcional -------------------- */
$invTable       = 'inventario';
$hasInvFechaAct = hasColumn($conn, $invTable, 'fecha_actualizacion');

/*
  Nota sobre estatus "en tránsito":
  La mayoría de colaciones en MySQL son case/acentos-insensibles (ej. utf8mb4_general_ci),
  pero para ser explícitos incluimos varias variantes comunes.
*/
$condTransito = "
  (i.estatus='En tránsito' OR i.estatus='En transito' OR
   i.estatus='en tránsito' OR i.estatus='en transito' OR
   i.estatus='TRANSITO'    OR i.estatus='Tránsito'    OR
   i.estatus='Transito'    OR i.estatus='EN TRANSITO')
";

/* -------------------- Transacción -------------------- */
$conn->begin_transaction();

try {
    // 1) Revertir SOLO piezas del traspaso que estén en tránsito
    $sqlUpdateInv = "
        UPDATE $invTable i
        JOIN detalle_traspaso dt ON dt.id_inventario = i.id
        JOIN traspasos t         ON t.id = dt.id_traspaso
        SET 
            i.estatus      = 'Disponible',
            i.id_sucursal  = t.id_sucursal_origen" .
            ($hasInvFechaAct ? ", i.fecha_actualizacion = NOW()" : "") . "
        WHERE dt.id_traspaso = ?
          AND $condTransito
    ";
    $up = $conn->prepare($sqlUpdateInv);
    $up->bind_param("i", $idTraspaso);
    if (!$up->execute()) {
        throw new Exception("Fallo al actualizar inventario (reversa tránsito): " . $conn->error);
    }
    $afectados = $up->affected_rows; // Puede ser 0 si algo estaba fuera de tránsito (no debería si es Pendiente)
    $up->close();

    // 2) Borrar detalles
    $delDet = $conn->prepare("DELETE FROM detalle_traspaso WHERE id_traspaso = ?");
    $delDet->bind_param("i", $idTraspaso);
    if (!$delDet->execute()) {
        throw new Exception("Fallo al eliminar detalle: " . $conn->error);
    }
    $delDet->close();

    // 3) Borrar encabezado
    $delEnc = $conn->prepare("DELETE FROM traspasos WHERE id = ? LIMIT 1");
    $delEnc->bind_param("i", $idTraspaso);
    if (!$delEnc->execute()) {
        throw new Exception("Fallo al eliminar traspaso: " . $conn->error);
    }
    $delEnc->close();

    // 4) Commit
    $conn->commit();

    // Opcional: si $afectados==0, podrías redirigir con otro msg informativo. Por simplicidad, éxito.
    header("Location: traspasos_salientes.php?msg=eliminado");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    // error_log('[ELIMINAR_TRASPASO] ' . $e->getMessage());
    header("Location: traspasos_salientes.php?msg=error");
    exit();
}
