<?php
ob_start();
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = trim($_SESSION['rol'] ?? '');

$rolesPermitidos = ['Gerente', 'Admin', 'Super', 'SuperAdmin', 'Gerente General', 'GerenteZona'];
if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    die('No tienes permisos para acceder a esta vista.');
}

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$idInventario = (int)($_GET['id'] ?? $_POST['id_inventario'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$inventario = obtenerInventarioCiclicoPorId($idInventario);
if (!$inventario) {
    die('No se encontró el inventario cíclico.');
}

$rolesAdminLike = ['Admin', 'Super', 'SuperAdmin', 'Gerente General'];
$esAdminLike = in_array($rol, $rolesAdminLike, true);

if (!$esAdminLike && (int)$inventario['id_sucursal'] !== $idSucursal) {
    http_response_code(403);
    die('No puedes capturar un inventario de otra sucursal.');
}

$estatusActual = trim((string)$inventario['estatus']);

if ($estatusActual !== 'EnCaptura') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso no permitido</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body style="background:#f5f7fb;">
        <div class="container py-5">
            <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                <h3 class="mb-3">⚠️ Auditoría no disponible</h3>
                <p class="mb-2">Este inventario ya no está en modo captura.</p>
                <p class="text-muted">Estatus actual: <strong><?= h($estatusActual) ?></strong></p>

                <div class="mt-4 d-flex justify-content-center gap-2 flex-wrap">
                    <a href="inventario_ciclico_conciliacion.php?id=<?= (int)$idInventario ?>" class="btn btn-primary">
                        Ir a conciliación
                    </a>
                    <a href="inventarios_ciclicos_admin.php" class="btn btn-outline-secondary">
                        Volver al listado
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

/* =========================================================
   Helpers locales
   ========================================================= */
if (!function_exists('ic_snapshot_total')) {
    function ic_snapshot_total(mysqli $conn, int $idInventario): int
    {
        $sql = "SELECT COUNT(*) total FROM inventarios_ciclicos_snapshot WHERE id_inventario_ciclico = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('ic_obtener_siguiente_consecutivo')) {
    function ic_obtener_siguiente_consecutivo(mysqli $conn, int $idInventario): int
    {
        $sql = "
            SELECT COALESCE(MAX(consecutivo_captura), 0) + 1 AS siguiente
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        return (int)($row['siguiente'] ?? 1);
    }
}

if (!function_exists('ic_bloquear_deshacer_anterior')) {
    function ic_bloquear_deshacer_anterior(mysqli $conn, int $idInventario): void
    {
        $sql = "
            UPDATE inventarios_ciclicos_detalle
            SET puede_deshacerse = 0
            WHERE id_inventario_ciclico = ?
              AND puede_deshacerse = 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ic_ya_capturado_identificador')) {
    function ic_ya_capturado_identificador(mysqli $conn, int $idInventario, string $identificador): bool
    {
        $sql = "
            SELECT id
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
              AND identificador = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $idInventario, $identificador);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        if ($res) $res->free();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ic_buscar_snapshot_identificador')) {
    function ic_buscar_snapshot_identificador(mysqli $conn, int $idInventario, string $identificador): ?array
    {
        $sql = "
            SELECT *
            FROM inventarios_ciclicos_snapshot
            WHERE id_inventario_ciclico = ?
              AND identificador = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $idInventario, $identificador);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ic_buscar_producto_serializado_sistema')) {
    function ic_buscar_producto_serializado_sistema(mysqli $conn, string $identificador): ?array
    {
        $sql = "
            SELECT
                p.id AS id_producto,
                i.id AS id_inventario,
                i.id_sucursal,
                i.estatus,
                p.codigo_producto,
                COALESCE(
                    NULLIF(p.descripcion, ''),
                    NULLIF(p.nombre_comercial, ''),
                    CONCAT(p.marca, ' ', p.modelo)
                ) AS descripcion_snapshot,
                p.tipo_producto
            FROM productos p
            INNER JOIN inventario i 
                ON i.id_producto = p.id
                AND i.estatus IN ('Disponible','Stock','Activo')
            WHERE p.imei1 = ? OR p.imei2 = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;

        $stmt->bind_param("ss", $identificador, $identificador);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        if ($res) $res->free();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('ic_buscar_sim_sistema')) {
    function ic_buscar_sim_sistema(mysqli $conn, string $identificador): ?array
    {
        $sql = "
            SELECT
                id,
                iccid,
                id_sucursal,
                estatus,
                operador,
                tipo_plan,
                lote
            FROM inventario_sims
            WHERE iccid = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $identificador);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ic_insertar_detalle_captura')) {
    function ic_insertar_detalle_captura(mysqli $conn, array $data): bool
    {
        $sql = "
            INSERT INTO inventarios_ciclicos_detalle
            (
                id_inventario_ciclico,
                tipo_item,
                seccion,
                id_producto,
                id_inventario,
                codigo,
                descripcion_snapshot,
                identificador,
                cantidad,
                resultado_validacion,
                id_sucursal_sistema,
                estatus_sistema,
                observacion_sistema,
                consecutivo_captura,
                puede_deshacerse,
                capturado_por
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "issiisssisiisii",
            $data['id_inventario_ciclico'],
            $data['tipo_item'],
            $data['seccion'],
            $data['id_producto'],
            $data['id_inventario'],
            $data['codigo'],
            $data['descripcion_snapshot'],
            $data['identificador'],
            $data['cantidad'],
            $data['resultado_validacion'],
            $data['id_sucursal_sistema'],
            $data['estatus_sistema'],
            $data['observacion_sistema'],
            $data['consecutivo_captura'],
            $data['capturado_por']
        );

        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('ic_registrar_captura_serial')) {
    function ic_registrar_captura_serial(
        mysqli $conn,
        int $idInventario,
        string $seccion,
        string $identificador,
        int $idUsuario
    ): array {
        $identificador = trim($identificador);

        if ($identificador === '') {
            return ['ok' => false, 'msg' => 'Debes capturar un identificador.'];
        }

        if (ic_ya_capturado_identificador($conn, $idInventario, $identificador)) {
            return ['ok' => false, 'msg' => 'Ese identificador ya fue capturado previamente.'];
        }

        $snapshot = ic_buscar_snapshot_identificador($conn, $idInventario, $identificador);

        $tipoItem = ($seccion === 'sims') ? 'sim' : 'producto_serializado';

        $data = [
            'id_inventario_ciclico' => $idInventario,
            'tipo_item' => $tipoItem,
            'seccion' => $seccion,
            'id_producto' => null,
            'id_inventario' => null,
            'codigo' => null,
            'descripcion_snapshot' => null,
            'identificador' => $identificador,
            'cantidad' => 1,
            'resultado_validacion' => 'no_existe_en_sistema',
            'id_sucursal_sistema' => null,
            'estatus_sistema' => null,
            'observacion_sistema' => null,
            'consecutivo_captura' => ic_obtener_siguiente_consecutivo($conn, $idInventario),
            'capturado_por' => $idUsuario,
        ];

        if ($snapshot) {
            $data['id_producto'] = $snapshot['id_producto'] !== null ? (int)$snapshot['id_producto'] : null;
            $data['id_inventario'] = $snapshot['id_inventario'] !== null ? (int)$snapshot['id_inventario'] : null;
            $data['codigo'] = $snapshot['codigo'];
            $data['descripcion_snapshot'] = $snapshot['descripcion_snapshot'];
            $data['id_sucursal_sistema'] = $snapshot['id_sucursal_sistema'] !== null ? (int)$snapshot['id_sucursal_sistema'] : null;
            $data['estatus_sistema'] = $snapshot['estatus_sistema'];

            if ($snapshot['seccion'] !== $seccion) {
                $data['resultado_validacion'] = 'existe_en_otra_sucursal';
                $data['observacion_sistema'] = 'El identificador existe en snapshot pero en otra sección.';
            } else {
                $data['resultado_validacion'] = 'ok';
                $data['observacion_sistema'] = 'Captura correcta.';
            }
        } else {
            if ($seccion === 'sims') {
                $sim = ic_buscar_sim_sistema($conn, $identificador);

                if ($sim) {
                    $data['descripcion_snapshot'] = 'SIM ' . trim(($sim['operador'] ?? '') . ' ' . ($sim['tipo_plan'] ?? ''));
                    $data['id_sucursal_sistema'] = (int)$sim['id_sucursal'];
                    $data['estatus_sistema'] = (string)$sim['estatus'];

                    if ((int)$sim['id_sucursal'] !== (int)$GLOBALS['inventario']['id_sucursal']) {
                        $data['resultado_validacion'] = 'existe_en_otra_sucursal';
                        $data['observacion_sistema'] = 'La SIM existe en otra sucursal.';
                    } elseif (($sim['estatus'] ?? '') !== 'Disponible') {
                        $data['resultado_validacion'] = 'existe_pero_no_disponible';
                        $data['observacion_sistema'] = 'La SIM existe pero no está disponible.';
                    } else {
                        $data['resultado_validacion'] = 'existe_pero_no_disponible';
                        $data['observacion_sistema'] = 'La SIM existe en sistema pero no entró en el snapshot.';
                    }
                }
            } else {
                $prod = ic_buscar_producto_serializado_sistema($conn, $identificador);

                if ($prod) {
                    $data['id_producto'] = $prod['id_producto'] !== null ? (int)$prod['id_producto'] : null;
                    $data['id_inventario'] = $prod['id_inventario'] !== null ? (int)$prod['id_inventario'] : null;
                    $data['codigo'] = $prod['codigo_producto'];
                    $data['descripcion_snapshot'] = $prod['descripcion_snapshot'];
                    $data['id_sucursal_sistema'] = $prod['id_sucursal'] !== null ? (int)$prod['id_sucursal'] : null;
                    $data['estatus_sistema'] = (string)($prod['estatus'] ?? '');

                    if ((int)($prod['id_sucursal'] ?? 0) !== (int)$GLOBALS['inventario']['id_sucursal']) {
                        $data['resultado_validacion'] = 'existe_en_otra_sucursal';
                        $data['observacion_sistema'] = 'El equipo existe en otra sucursal.';
                    } elseif (($prod['estatus'] ?? '') !== 'Disponible') {
                        $data['resultado_validacion'] = 'existe_pero_no_disponible';
                        $data['observacion_sistema'] = 'El equipo existe pero no está disponible.';
                    } else {
                        $data['resultado_validacion'] = 'existe_pero_no_disponible';
                        $data['observacion_sistema'] = 'El equipo existe en sistema pero no entró en el snapshot.';
                    }
                }
            }
        }

        $conn->begin_transaction();
        try {
            ic_bloquear_deshacer_anterior($conn, $idInventario);

            if (!ic_insertar_detalle_captura($conn, $data)) {
                throw new Exception('No se pudo guardar la captura.');
            }

            registrarBitacoraInventarioCiclico(
                $idInventario,
                'captura_serial',
                'Se registró una captura serializada.',
                [
                    'seccion' => $seccion,
                    'identificador' => $identificador,
                    'resultado' => $data['resultado_validacion'],
                    'observacion' => $data['observacion_sistema'],
                ],
                $idUsuario
            );

            $conn->commit();

            return ['ok' => true, 'msg' => 'Captura registrada: ' . $data['resultado_validacion']];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('ic_buscar_snapshot_cantidad')) {
    function ic_buscar_snapshot_cantidad(mysqli $conn, int $idSnapshot, int $idInventario): ?array
    {
        $sql = "
            SELECT *
            FROM inventarios_ciclicos_snapshot
            WHERE id = ?
              AND id_inventario_ciclico = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $idSnapshot, $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ic_ya_capturada_cantidad_snapshot')) {
    function ic_ya_capturada_cantidad_snapshot(mysqli $conn, int $idInventario, int $idProducto, string $seccion): bool
    {
        $sql = "
            SELECT id
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
              AND id_producto <=> ?
              AND seccion = ?
              AND tipo_item IN ('producto_cantidad', 'payjoy_cantidad')
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $idInventario, $idProducto, $seccion);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        if ($res) $res->free();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('ic_registrar_captura_cantidad')) {
    function ic_registrar_captura_cantidad(
        mysqli $conn,
        int $idInventario,
        int $idSnapshot,
        int $cantidad,
        int $idUsuario
    ): array {
        if ($cantidad < 0) {
            return ['ok' => false, 'msg' => 'La cantidad no puede ser negativa.'];
        }

        $snapshot = ic_buscar_snapshot_cantidad($conn, $idSnapshot, $idInventario);
        if (!$snapshot) {
            return ['ok' => false, 'msg' => 'No se encontró la referencia de captura.'];
        }

        $tipoItem = $snapshot['tipo_item'];
        if (!in_array($tipoItem, ['producto_cantidad', 'payjoy_cantidad'], true)) {
            return ['ok' => false, 'msg' => 'Ese registro no es capturable por cantidad.'];
        }

        $idProducto = $snapshot['id_producto'] !== null ? (int)$snapshot['id_producto'] : 0;
        if (ic_ya_capturada_cantidad_snapshot($conn, $idInventario, $idProducto, $snapshot['seccion'])) {
            return ['ok' => false, 'msg' => 'Esa sección ya fue capturada. Usa deshacer si fue un error inmediato.'];
        }

        $resultado = ((int)$snapshot['cantidad_esperada'] === $cantidad) ? 'ok' : 'diferencia_cantidad';
        $obs = ($resultado === 'ok')
            ? 'Cantidad correcta.'
            : 'Cantidad distinta a la esperada en snapshot.';

        $data = [
            'id_inventario_ciclico' => $idInventario,
            'tipo_item' => $tipoItem,
            'seccion' => $snapshot['seccion'],
            'id_producto' => $snapshot['id_producto'] !== null ? (int)$snapshot['id_producto'] : null,
            'id_inventario' => $snapshot['id_inventario'] !== null ? (int)$snapshot['id_inventario'] : null,
            'codigo' => $snapshot['codigo'],
            'descripcion_snapshot' => $snapshot['descripcion_snapshot'],
            'identificador' => null,
            'cantidad' => $cantidad,
            'resultado_validacion' => $resultado,
            'id_sucursal_sistema' => $snapshot['id_sucursal_sistema'] !== null ? (int)$snapshot['id_sucursal_sistema'] : null,
            'estatus_sistema' => $snapshot['estatus_sistema'],
            'observacion_sistema' => $obs,
            'consecutivo_captura' => ic_obtener_siguiente_consecutivo($conn, $idInventario),
            'capturado_por' => $idUsuario,
        ];

        $conn->begin_transaction();
        try {
            ic_bloquear_deshacer_anterior($conn, $idInventario);

            if (!ic_insertar_detalle_captura($conn, $data)) {
                throw new Exception('No se pudo guardar la captura por cantidad.');
            }

            registrarBitacoraInventarioCiclico(
                $idInventario,
                'captura_cantidad',
                'Se registró una captura por cantidad.',
                [
                    'seccion' => $snapshot['seccion'],
                    'descripcion' => $snapshot['descripcion_snapshot'],
                    'cantidad_capturada' => $cantidad,
                    'cantidad_esperada_snapshot' => (int)$snapshot['cantidad_esperada'],
                    'resultado' => $resultado,
                ],
                $idUsuario
            );

            $conn->commit();

            return ['ok' => true, 'msg' => 'Cantidad registrada correctamente.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('ic_deshacer_ultima_captura')) {
    function ic_deshacer_ultima_captura(mysqli $conn, int $idInventario, int $idUsuario): array
    {
        $sql = "
            SELECT *
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
              AND puede_deshacerse = 1
            ORDER BY consecutivo_captura DESC, id DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();
        $ultima = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();

        if (!$ultima) {
            return ['ok' => false, 'msg' => 'No hay una última captura disponible para deshacer.'];
        }

        $conn->begin_transaction();
        try {
            // Borrar SOLO la última captura permitida
            $del = $conn->prepare("DELETE FROM inventarios_ciclicos_detalle WHERE id = ? LIMIT 1");
            $idDetalle = (int)$ultima['id'];
            $del->bind_param("i", $idDetalle);
            $del->execute();
            $del->close();

            // OJO: NO reactivar ninguna captura anterior
            // Así el deshacer es de una sola vez hasta que exista una nueva captura.

            registrarBitacoraInventarioCiclico(
                $idInventario,
                'deshacer_captura',
                'Se deshizo la última captura.',
                [
                    'id_detalle' => $idDetalle,
                    'seccion' => $ultima['seccion'],
                    'identificador' => $ultima['identificador'],
                    'cantidad' => (int)$ultima['cantidad'],
                ],
                $idUsuario
            );

            $conn->commit();

            return ['ok' => true, 'msg' => 'Última captura deshecha correctamente.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

if (!function_exists('ic_obtener_resumen_secciones')) {
    function ic_obtener_resumen_secciones(mysqli $conn, int $idInventario): array
    {
        $secciones = [
            'telefonia'  => ['capturas' => 0, 'incidencias' => 0],
            'accesorios' => ['capturas' => 0, 'incidencias' => 0],
            'scooter'    => ['capturas' => 0, 'incidencias' => 0],
            'sims'       => ['capturas' => 0, 'incidencias' => 0],
            'payjoy'     => ['capturas' => 0, 'incidencias' => 0],
        ];

        $sql = "
            SELECT
                seccion,
                COUNT(*) AS capturas,
                SUM(CASE WHEN resultado_validacion <> 'ok' THEN 1 ELSE 0 END) AS incidencias
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
            GROUP BY seccion
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $sec = $row['seccion'];
                if (isset($secciones[$sec])) {
                    $secciones[$sec] = [
                        'capturas' => (int)$row['capturas'],
                        'incidencias' => (int)$row['incidencias'],
                    ];
                }
            }
            $res->free();
        }
        $stmt->close();

        return $secciones;
    }
}

if (!function_exists('ic_ultimas_capturas')) {
    function ic_ultimas_capturas(mysqli $conn, int $idInventario, int $limit = 20): array
    {
        $rows = [];
        $limit = max(1, min(100, $limit));

        $sql = "
            SELECT
                d.*,
                u.nombre AS capturado_por_nombre
            FROM inventarios_ciclicos_detalle d
            INNER JOIN usuarios u ON u.id = d.capturado_por
            WHERE d.id_inventario_ciclico = ?
            ORDER BY d.consecutivo_captura DESC, d.id DESC
            LIMIT {$limit}
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('ic_snapshot_capturas_cantidad')) {
    function ic_snapshot_capturas_cantidad(mysqli $conn, int $idInventario, string $tipoItem): array
    {
        $rows = [];

        $sql = "
            SELECT
                s.*,
                p.marca,
                p.modelo,
                p.color,
                p.nombre_comercial,
                p.codigo_producto,
                p.tipo_producto,
                p.subtipo,
                p.descripcion
            FROM inventarios_ciclicos_snapshot s
            LEFT JOIN productos p ON p.id = s.id_producto
            WHERE s.id_inventario_ciclico = ?
              AND s.tipo_item = ?
            ORDER BY
                COALESCE(NULLIF(p.tipo_producto, ''), ''),
                COALESCE(NULLIF(p.subtipo, ''), ''),
                COALESCE(NULLIF(p.marca, ''), ''),
                COALESCE(NULLIF(p.modelo, ''), ''),
                COALESCE(NULLIF(p.color, ''), ''),
                s.descripcion_snapshot ASC,
                s.id ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $idInventario, $tipoItem);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $partes = [];

                // Encabezado bonito por tipo
                if (!empty($row['tipo_producto'])) {
                    $partes[] = trim($row['tipo_producto']);
                }

                if (!empty($row['subtipo'])) {
                    $partes[] = trim($row['subtipo']);
                }

                $detalle = [];
                if (!empty($row['marca'])) $detalle[] = trim($row['marca']);
                if (!empty($row['modelo'])) $detalle[] = trim($row['modelo']);
                if (!empty($row['color'])) $detalle[] = trim($row['color']);

                if (!empty($detalle)) {
                    $partes[] = implode(' ', $detalle);
                }

                $label = trim(implode(' · ', $partes));

                // Fallbacks
                if ($label === '') {
                    $label = trim((string)($row['descripcion_snapshot'] ?? ''));
                }

                if ($label === '' && !empty($row['descripcion'])) {
                    $label = trim($row['descripcion']);
                }

                if ($label === '' && !empty($row['nombre_comercial'])) {
                    $label = trim($row['nombre_comercial']);
                }

                if ($label === '' && !empty($row['codigo_producto'])) {
                    $label = trim($row['codigo_producto']);
                }

                if ($label === '') {
                    $label = 'Artículo #' . (int)$row['id'];
                }

                $row['_label'] = $label;
                $rows[] = $row;
            }
            $res->free();
        }
        $stmt->close();

        return $rows;
    }
}

/* =========================================================
   Estado de formulario para conservar selección
   ========================================================= */
$seccionSerialActual   = trim($_POST['seccion'] ?? 'telefonia');
if (!in_array($seccionSerialActual, ['telefonia', 'scooter', 'sims'], true)) {
    $seccionSerialActual = 'telefonia';
}

$idSnapshotAccesorioActual = (int)($_POST['action'] ?? '') === 'capturar_cantidad'
    ? (int)($_POST['id_snapshot'] ?? 0)
    : 0;

$cantidadActual = ($_POST['action'] ?? '') === 'capturar_cantidad'
    ? trim((string)($_POST['cantidad'] ?? ''))
    : '';

/* =========================================================
   Procesamiento POST
   ========================================================= */
$msgOk = '';
$msgErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'capturar_serial') {
        $seccion = trim($_POST['seccion'] ?? '');
        $identificador = strtoupper(trim($_POST['identificador'] ?? ''));

        $seccionSerialActual = $seccion;

        if (!in_array($seccion, ['telefonia', 'scooter', 'sims'], true)) {
            $msgErr = 'Sección inválida para captura serial.';
        } else {
            $resp = ic_registrar_captura_serial($conn, $idInventario, $seccion, $identificador, $idUsuario);
            if (!empty($resp['ok'])) {
                $msgOk = $resp['msg'];
            } else {
                $msgErr = $resp['msg'];
            }
        }
    }

    if ($action === 'capturar_cantidad') {
        $idSnapshot = (int)($_POST['id_snapshot'] ?? 0);
        $cantidad   = (int)($_POST['cantidad'] ?? -1);

        $idSnapshotAccesorioActual = $idSnapshot;
        $cantidadActual = trim((string)($_POST['cantidad'] ?? ''));

        $resp = ic_registrar_captura_cantidad($conn, $idInventario, $idSnapshot, $cantidad, $idUsuario);
        if (!empty($resp['ok'])) {
            $msgOk = $resp['msg'];
            $idSnapshotAccesorioActual = 0;
            $cantidadActual = '';
        } else {
            $msgErr = $resp['msg'];
        }
    }

    if ($action === 'deshacer_ultima') {
        $resp = ic_deshacer_ultima_captura($conn, $idInventario, $idUsuario);
        if (!empty($resp['ok'])) {
            $msgOk = $resp['msg'];
        } else {
            $msgErr = $resp['msg'];
        }
    }
}

/* =========================================================
   Datos para UI
   ========================================================= */
$totalSnapshot = ic_snapshot_total($conn, $idInventario);
if ($totalSnapshot <= 0) {
    header("Location: iniciar_inventario_ciclico.php?id=" . $idInventario);
    exit();
}

$resumenSecciones = ic_obtener_resumen_secciones($conn, $idInventario);
$ultimasCapturas  = ic_ultimas_capturas($conn, $idInventario, 25);

$accesoriosCantidad = ic_snapshot_capturas_cantidad($conn, $idInventario, 'producto_cantidad');
$payjoyCantidad     = ic_snapshot_capturas_cantidad($conn, $idInventario, 'payjoy_cantidad');

$totalCapturas = 0;
$totalIncidencias = 0;
foreach ($resumenSecciones as $secData) {
    $totalCapturas += (int)$secData['capturas'];
    $totalIncidencias += (int)$secData['incidencias'];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Captura Inventario Cíclico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }

        .wrap {
            padding: 20px;
        }

        .card-ui {
            border: none;
            border-radius: 18px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .06);
        }

        .kpi {
            border-radius: 18px;
            padding: 18px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .05);
            height: 100%;
        }

        .kpi .n {
            font-size: 1.7rem;
            font-weight: 800;
        }

        .sec-title {
            font-weight: 700;
            margin-bottom: 12px;
        }

        .mono {
            font-family: Consolas, Monaco, monospace;
        }

        .tiny {
            font-size: .9rem;
            color: #6c757d;
        }

        .badge-soft {
            border-radius: 999px;
            padding: .45rem .8rem;
            font-size: .85rem;
        }

        .res-ok {
            background: #d1e7dd;
            color: #0f5132;
        }

        .res-bad {
            background: #f8d7da;
            color: #842029;
        }
    </style>
</head>

<body>
    <div class="wrap container-fluid">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="tiny">Inventario cíclico en captura</div>
                <h2 class="mb-1">Sucursal: <?= h($inventario['sucursal_nombre']) ?></h2>
                <div class="tiny">
                    ID #<?= (int)$inventario['id'] ?> · Fecha programada: <?= h($inventario['fecha_programada']) ?> ·
                    Iniciado por: <?= h($inventario['iniciado_por_nombre'] ?? $_SESSION['nombre'] ?? 'Usuario') ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <form method="post" class="m-0">
                    <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                    <input type="hidden" name="action" value="deshacer_ultima">
                    <button type="submit" class="btn btn-outline-warning">Deshacer última captura</button>
                </form>

                <a href="inventarios_ciclicos_admin.php" class="btn btn-outline-secondary">Volver</a>
                <form method="post" action="pasar_a_conciliacion.php" class="m-0"
                    onsubmit="return confirm('Al pasar a conciliación ya no podrás volver a capturar. ¿Deseas continuar?');">
                    <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                    <button type="submit" class="btn btn-danger">Ir a conciliación</button>
                </form>
            </div>
        </div>

        <?php if ($msgOk): ?>
            <div class="alert alert-success"><?= h($msgOk) ?></div>
        <?php endif; ?>

        <?php if ($msgErr): ?>
            <div class="alert alert-danger"><?= h($msgErr) ?></div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Capturas registradas</div>
                    <div class="n"><?= (int)$totalCapturas ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Incidencias</div>
                    <div class="n"><?= (int)$totalIncidencias ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Telefonía</div>
                    <div class="n"><?= (int)$resumenSecciones['telefonia']['capturas'] ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Accesorios</div>
                    <div class="n"><?= (int)$resumenSecciones['accesorios']['capturas'] ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Scooter</div>
                    <div class="n"><?= (int)$resumenSecciones['scooter']['capturas'] ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">SIMs / PayJoy</div>
                    <div class="n"><?= (int)$resumenSecciones['sims']['capturas'] + (int)$resumenSecciones['payjoy']['capturas'] ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <!-- SERIALIZADOS -->
            <div class="col-12 col-xl-4">
                <div class="card card-ui p-4 h-100">
                    <h4 class="sec-title">Captura serializada</h4>
                    <div class="tiny mb-3">Solo capturas. No se muestra inventario esperado.</div>

                    <form method="post" class="mb-3">
                        <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                        <input type="hidden" name="action" value="capturar_serial">

                        <div class="mb-3">
                            <label class="form-label">Sección</label>
                            <select name="seccion" class="form-select" required>
                                <option value="telefonia" <?= $seccionSerialActual === 'telefonia' ? 'selected' : '' ?>>Telefonía</option>
                                <option value="scooter" <?= $seccionSerialActual === 'scooter' ? 'selected' : '' ?>>Scooter</option>
                                <option value="sims" <?= $seccionSerialActual === 'sims' ? 'selected' : '' ?>>SIMs</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">IMEI / Serie / ICCID</label>
                            <input type="text" name="identificador" class="form-control mono" required autofocus>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Registrar captura serial</button>
                    </form>
                </div>
            </div>

            <!-- ACCESORIOS CANTIDAD -->
            <div class="col-12 col-xl-4">
                <div class="card card-ui p-4 h-100">
                    <h4 class="sec-title">Accesorios por cantidad</h4>
                    <div class="tiny mb-3">Captura cantidad física encontrada por artículo.</div>

                    <form method="post">
                        <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                        <input type="hidden" name="action" value="capturar_cantidad">

                        <div class="mb-3">
                            <label class="form-label">Artículo</label>
                            <select name="id_snapshot" class="form-select" required>
                                <option value="">Selecciona…</option>
                                <?php foreach ($accesoriosCantidad as $row): ?>
                                    <option value="<?= (int)$row['id'] ?>" <?= ((int)$idSnapshotAccesorioActual === (int)$row['id']) ? 'selected' : '' ?>>
                                        <?= h($row['_label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cantidad física</label>
                            <input type="number" name="cantidad" min="0" class="form-control" required value="<?= h($cantidadActual) ?>">
                        </div>

                        <button type="submit" class="btn btn-success w-100">Registrar cantidad</button>
                    </form>
                </div>
            </div>

            <!-- PAYJOY -->
            <div class="col-12 col-xl-4">
                <div class="card card-ui p-4 h-100">
                    <h4 class="sec-title">Tarjetas PayJoy</h4>
                    <div class="tiny mb-3">Captura la cantidad física de tarjetas encontradas.</div>

                    <form method="post">
                        <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                        <input type="hidden" name="action" value="capturar_cantidad">

                        <div class="mb-3">
                            <label class="form-label">Concepto</label>
                            <select name="id_snapshot" class="form-select" required>
                                <option value="">Selecciona…</option>
                                <?php foreach ($payjoyCantidad as $row): ?>
                                    <option value="<?= (int)$row['id'] ?>">
                                        <?= h($row['_label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cantidad física</label>
                            <input type="number" name="cantidad" min="0" class="form-control" required>
                        </div>

                        <button type="submit" class="btn btn-dark w-100">Registrar PayJoy</button>
                    </form>
                </div>
            </div>

            <!-- ÚLTIMAS CAPTURAS -->
            <div class="col-12">
                <div class="card card-ui p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="sec-title mb-0">Últimas capturas</h4>
                        <div class="tiny">Solo se muestra lo capturado, no el inventario esperado.</div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Cantidad</th>
                                    <th>Resultado</th>
                                    <th>Observación</th>
                                    <th>Usuario</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$ultimasCapturas): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">Aún no hay capturas registradas.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ultimasCapturas as $row): ?>
                                        <?php $ok = ($row['resultado_validacion'] === 'ok'); ?>
                                        <tr>
                                            <td><?= (int)$row['consecutivo_captura'] ?></td>
                                            <td><?= h($row['seccion']) ?></td>
                                            <td><?= h($row['descripcion_snapshot'] ?: $row['codigo']) ?></td>
                                            <td class="mono"><?= h($row['identificador']) ?></td>
                                            <td><?= (int)$row['cantidad'] ?></td>
                                            <td>
                                                <span class="badge-soft <?= $ok ? 'res-ok' : 'res-bad' ?>">
                                                    <?= h($row['resultado_validacion']) ?>
                                                </span>
                                            </td>
                                            <td><?= h($row['observacion_sistema']) ?></td>
                                            <td><?= h($row['capturado_por_nombre']) ?></td>
                                            <td><?= h($row['fecha_captura']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>

</html>
<?php
ob_end_flush();
?>