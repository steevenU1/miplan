<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = trim($_SESSION['rol'] ?? '');

$rolesPermitidos = ['Gerente', 'Admin', 'Super', 'SuperAdmin', 'Gerente General', 'GerenteZona'];
if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    die('No tienes permisos para iniciar inventarios cíclicos.');
}

$idInventario = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$inventario = obtenerInventarioCiclicoPorId($idInventario);
if (!$inventario) {
    die('No se encontró el inventario cíclico.');
}

/**
 * Seguridad:
 * - Gerente solo debe iniciar inventarios de su sucursal
 * - Admin-like puede iniciar cualquiera
 */
$rolesAdminLike = ['Admin', 'Super', 'SuperAdmin', 'Gerente General'];
$esAdminLike = in_array($rol, $rolesAdminLike, true);

if (!$esAdminLike && (int)$inventario['id_sucursal'] !== $idSucursal) {
    http_response_code(403);
    die('No puedes iniciar un inventario de otra sucursal.');
}

if (!in_array($inventario['estatus'], ['Programado', 'BloqueoPrevio'], true)) {
    die('Este inventario no se puede iniciar en su estatus actual.');
}

/**
 * Helpers locales
 */
if (!function_exists('ic_snapshot_existe')) {
    function ic_snapshot_existe(mysqli $conn, int $idInventario): bool
    {
        $sql = "SELECT id FROM inventarios_ciclicos_snapshot WHERE id_inventario_ciclico = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        if ($res) {
            $res->free();
        }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ic_insert_snapshot_producto_serializado')) {
    function ic_insert_snapshot_producto_serializado(mysqli $conn, int $idInventario, int $idSucursal): int
    {
        $sql = "
            INSERT INTO inventarios_ciclicos_snapshot
            (
                id_inventario_ciclico,
                tipo_item,
                seccion,
                id_producto,
                id_inventario,
                codigo,
                descripcion_snapshot,
                identificador,
                cantidad_esperada,
                id_sucursal_sistema,
                estatus_sistema,
                origen_tabla
            )
            SELECT
                ? AS id_inventario_ciclico,
                'producto_serializado' AS tipo_item,
                CASE
                    WHEN p.tipo_producto = 'Scooter' THEN 'scooter'
                    ELSE 'telefonia'
                END AS seccion,
                p.id AS id_producto,
                i.id AS id_inventario,
                p.codigo_producto AS codigo,
                COALESCE(NULLIF(p.descripcion, ''), NULLIF(p.nombre_comercial, ''), CONCAT(p.marca, ' ', p.modelo)) AS descripcion_snapshot,
                p.imei1 AS identificador,
                1 AS cantidad_esperada,
                i.id_sucursal AS id_sucursal_sistema,
                i.estatus AS estatus_sistema,
                'inventario' AS origen_tabla
            FROM inventario i
            INNER JOIN productos p ON p.id = i.id_producto
            WHERE i.id_sucursal = ?
              AND i.estatus = 'Disponible'
              AND p.imei1 IS NOT NULL
              AND TRIM(p.imei1) <> ''
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("ii", $idInventario, $idSucursal);
        $stmt->execute();
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();

        return max(0, $afectadas);
    }
}

if (!function_exists('ic_insert_snapshot_producto_cantidad')) {
    function ic_insert_snapshot_producto_cantidad(mysqli $conn, int $idInventario, int $idSucursal): int
    {
        $sql = "
            INSERT INTO inventarios_ciclicos_snapshot
            (
                id_inventario_ciclico,
                tipo_item,
                seccion,
                id_producto,
                id_inventario,
                codigo,
                descripcion_snapshot,
                identificador,
                cantidad_esperada,
                id_sucursal_sistema,
                estatus_sistema,
                origen_tabla
            )
            SELECT
                ? AS id_inventario_ciclico,
                'producto_cantidad' AS tipo_item,
                'accesorios' AS seccion,
                p.id AS id_producto,
                NULL AS id_inventario,
                p.codigo_producto AS codigo,
                COALESCE(NULLIF(p.descripcion, ''), NULLIF(p.nombre_comercial, ''), CONCAT(p.marca, ' ', p.modelo)) AS descripcion_snapshot,
                NULL AS identificador,
                SUM(i.cantidad) AS cantidad_esperada,
                i.id_sucursal AS id_sucursal_sistema,
                'Disponible' AS estatus_sistema,
                'inventario' AS origen_tabla
            FROM inventario i
            INNER JOIN productos p ON p.id = i.id_producto
            WHERE i.id_sucursal = ?
              AND i.estatus = 'Disponible'
              AND (p.imei1 IS NULL OR TRIM(p.imei1) = '')
            GROUP BY
                p.id, p.codigo_producto, p.descripcion, p.nombre_comercial, p.marca, p.modelo, i.id_sucursal
            HAVING SUM(i.cantidad) > 0
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("ii", $idInventario, $idSucursal);
        $stmt->execute();
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();

        return max(0, $afectadas);
    }
}

if (!function_exists('ic_insert_snapshot_sims')) {
    function ic_insert_snapshot_sims(mysqli $conn, int $idInventario, int $idSucursal): int
    {
        $sql = "
            INSERT INTO inventarios_ciclicos_snapshot
            (
                id_inventario_ciclico,
                tipo_item,
                seccion,
                id_producto,
                id_inventario,
                codigo,
                descripcion_snapshot,
                identificador,
                cantidad_esperada,
                id_sucursal_sistema,
                estatus_sistema,
                origen_tabla
            )
            SELECT
                ? AS id_inventario_ciclico,
                'sim' AS tipo_item,
                'sims' AS seccion,
                NULL AS id_producto,
                NULL AS id_inventario,
                NULL AS codigo,
                CONCAT(
                    'SIM ',
                    COALESCE(s.operador, ''),
                    CASE WHEN s.tipo_plan IS NOT NULL AND s.tipo_plan <> '' THEN CONCAT(' · ', s.tipo_plan) ELSE '' END,
                    CASE WHEN s.lote IS NOT NULL AND s.lote <> '' THEN CONCAT(' · Lote ', s.lote) ELSE '' END
                ) AS descripcion_snapshot,
                s.iccid AS identificador,
                1 AS cantidad_esperada,
                s.id_sucursal AS id_sucursal_sistema,
                s.estatus AS estatus_sistema,
                'inventario_sims' AS origen_tabla
            FROM inventario_sims s
            WHERE s.id_sucursal = ?
              AND s.estatus = 'Disponible'
              AND s.iccid IS NOT NULL
              AND TRIM(s.iccid) <> ''
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("ii", $idInventario, $idSucursal);
        $stmt->execute();
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();

        return max(0, $afectadas);
    }
}

if (!function_exists('ic_insert_snapshot_payjoy')) {
    function ic_insert_snapshot_payjoy(mysqli $conn, int $idInventario, int $idSucursal): int
    {
        $saldo = obtenerSaldoPayJoySucursal($idSucursal);

        $sql = "
            INSERT INTO inventarios_ciclicos_snapshot
            (
                id_inventario_ciclico,
                tipo_item,
                seccion,
                id_producto,
                id_inventario,
                codigo,
                descripcion_snapshot,
                identificador,
                cantidad_esperada,
                id_sucursal_sistema,
                estatus_sistema,
                origen_tabla
            )
            VALUES
            (
                ?, 'payjoy_cantidad', 'payjoy',
                NULL, NULL,
                'PAYJOY-TC',
                'Tarjetas PayJoy',
                NULL,
                ?, ?, 'Disponible', 'payjoy_tc_kardex'
            )
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("iii", $idInventario, $saldo, $idSucursal);
        $stmt->execute();
        $afectadas = (int)$stmt->affected_rows;
        $stmt->close();

        return max(0, $afectadas);
    }
}

if (!function_exists('crearSnapshotInventarioCiclico')) {
    function crearSnapshotInventarioCiclico(mysqli $conn, int $idInventario, int $idSucursal): array
    {
        $resumen = [
            'producto_serializado' => 0,
            'producto_cantidad'    => 0,
            'sim'                  => 0,
            'payjoy_cantidad'      => 0,
            'total'                => 0,
        ];

        $resumen['producto_serializado'] = ic_insert_snapshot_producto_serializado($conn, $idInventario, $idSucursal);
        $resumen['producto_cantidad']    = ic_insert_snapshot_producto_cantidad($conn, $idInventario, $idSucursal);
        $resumen['sim']                  = ic_insert_snapshot_sims($conn, $idInventario, $idSucursal);
        $resumen['payjoy_cantidad']      = ic_insert_snapshot_payjoy($conn, $idInventario, $idSucursal);

        $resumen['total'] =
            $resumen['producto_serializado'] +
            $resumen['producto_cantidad'] +
            $resumen['sim'] +
            $resumen['payjoy_cantidad'];

        return $resumen;
    }
}

/**
 * Flujo principal
 */
if (ic_snapshot_existe($conn, $idInventario)) {
    // Si ya existe snapshot, solo nos aseguramos del estatus
    $sql = "
        UPDATE inventarios_ciclicos
        SET estatus = 'EnCaptura',
            iniciado_por = COALESCE(iniciado_por, ?),
            fecha_inicio = COALESCE(fecha_inicio, NOW())
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idUsuario, $idInventario);
    $stmt->execute();
    $stmt->close();

    registrarBitacoraInventarioCiclico(
        $idInventario,
        'reingreso_captura',
        'Se reingresó a la captura del inventario cíclico. El snapshot ya existía.',
        null,
        $idUsuario
    );

    header("Location: inventario_ciclico_captura.php?id=" . $idInventario);
    exit();
}

$conn->begin_transaction();

try {
    $sql = "
        UPDATE inventarios_ciclicos
        SET estatus = 'EnCaptura',
            iniciado_por = ?,
            fecha_inicio = NOW()
        WHERE id = ?
          AND estatus IN ('Programado', 'BloqueoPrevio')
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('No se pudo preparar el inicio del inventario.');
    }

    $stmt->bind_param("ii", $idUsuario, $idInventario);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('No se pudo iniciar el inventario. Verifica el estatus actual.');
    }
    $stmt->close();

    $resumenSnapshot = crearSnapshotInventarioCiclico($conn, $idInventario, (int)$inventario['id_sucursal']);

    $okBitacora = registrarBitacoraInventarioCiclico(
        $idInventario,
        'inicio_inventario',
        'Se inició el inventario cíclico y se generó el snapshot inicial.',
        [
            'id_sucursal' => (int)$inventario['id_sucursal'],
            'snapshot'    => $resumenSnapshot,
        ],
        $idUsuario
    );

    if (!$okBitacora) {
        throw new Exception('No se pudo registrar la bitácora de inicio.');
    }

    $conn->commit();

    header("Location: inventario_ciclico_captura.php?id=" . $idInventario . "&ok=1");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    die("Error al iniciar inventario cíclico: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}