<?php
/**
 * inventario_ciclico_helpers.php
 * Helpers base para módulo de inventario cíclico - MiPlan
 *
 * Requiere:
 * - db.php con conexión mysqli en $conn
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!function_exists('ic_now')) {
    function ic_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('ic_hoy')) {
    function ic_hoy(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('ic_ayer')) {
    function ic_ayer(): string
    {
        return date('Y-m-d', strtotime('-1 day'));
    }
}

if (!function_exists('ic_manana')) {
    function ic_manana(): string
    {
        return date('Y-m-d', strtotime('+1 day'));
    }
}

if (!function_exists('ic_json_encode')) {
    function ic_json_encode($data): ?string
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($json === false) ? null : $json;
    }
}

if (!function_exists('ic_fetch_one')) {
    function ic_fetch_one(mysqli_stmt $stmt): ?array
    {
        $result = $stmt->get_result();
        if (!$result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }
}

if (!function_exists('ic_fetch_all')) {
    function ic_fetch_all(mysqli_stmt $stmt): array
    {
        $rows = [];
        $result = $stmt->get_result();

        if (!$result) {
            return $rows;
        }

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $result->free();
        return $rows;
    }
}

if (!function_exists('ic_table_has_column')) {
    /**
     * Verifica si una tabla tiene una columna.
     */
    function ic_table_has_column(string $table, string $column): bool
    {
        global $conn;

        $table  = trim($table);
        $column = trim($column);

        if ($table === '' || $column === '') {
            return false;
        }

        $sql = "
            SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = ic_fetch_one($stmt);
        $stmt->close();

        return (int)($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('obtenerInventarioCiclicoActivo')) {
    /**
     * Obtiene el inventario cíclico activo más relevante para una sucursal.
     * Considera Programado, BloqueoPrevio y EnCaptura.
     *
     * @param int $idSucursal
     * @return array|null
     */
    function obtenerInventarioCiclicoActivo(int $idSucursal): ?array
    {
        global $conn;

        $sql = "
            SELECT
                ic.*,
                s.nombre AS sucursal_nombre,
                uc.nombre AS creado_por_nombre,
                ui.nombre AS iniciado_por_nombre,
                ucie.nombre AS cerrado_por_nombre,
                ur.nombre AS revisado_por_nombre
            FROM inventarios_ciclicos ic
            INNER JOIN sucursales s ON s.id = ic.id_sucursal
            INNER JOIN usuarios uc ON uc.id = ic.creado_por
            LEFT JOIN usuarios ui ON ui.id = ic.iniciado_por
            LEFT JOIN usuarios ucie ON ucie.id = ic.cerrado_por
            LEFT JOIN usuarios ur ON ur.id = ic.revisado_por
            WHERE ic.id_sucursal = ?
              AND ic.estatus IN ('Programado', 'BloqueoPrevio', 'EnCaptura')
            ORDER BY
                CASE ic.estatus
                    WHEN 'EnCaptura' THEN 1
                    WHEN 'BloqueoPrevio' THEN 2
                    WHEN 'Programado' THEN 3
                    ELSE 99
                END,
                ic.fecha_programada ASC,
                ic.id ASC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $idSucursal);
        $stmt->execute();

        $row = ic_fetch_one($stmt);
        $stmt->close();

        return $row;
    }
}

if (!function_exists('obtenerInventarioCiclicoPorId')) {
    /**
     * Obtiene una cabecera completa por ID
     *
     * @param int $idInventario
     * @return array|null
     */
    function obtenerInventarioCiclicoPorId(int $idInventario): ?array
    {
        global $conn;

        $sql = "
            SELECT
                ic.*,
                s.nombre AS sucursal_nombre,
                uc.nombre AS creado_por_nombre,
                ui.nombre AS iniciado_por_nombre,
                ucie.nombre AS cerrado_por_nombre,
                ur.nombre AS revisado_por_nombre
            FROM inventarios_ciclicos ic
            INNER JOIN sucursales s ON s.id = ic.id_sucursal
            INNER JOIN usuarios uc ON uc.id = ic.creado_por
            LEFT JOIN usuarios ui ON ui.id = ic.iniciado_por
            LEFT JOIN usuarios ucie ON ucie.id = ic.cerrado_por
            LEFT JOIN usuarios ur ON ur.id = ic.revisado_por
            WHERE ic.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $idInventario);
        $stmt->execute();

        $row = ic_fetch_one($stmt);
        $stmt->close();

        return $row;
    }
}

if (!function_exists('obtenerInventarioCiclicoActivoHoy')) {
    /**
     * Obtiene inventario activo del día para una sucursal.
     *
     * @param int $idSucursal
     * @return array|null
     */
    function obtenerInventarioCiclicoActivoHoy(int $idSucursal): ?array
    {
        global $conn;

        $hoy = ic_hoy();

        $sql = "
            SELECT *
            FROM inventarios_ciclicos
            WHERE id_sucursal = ?
              AND fecha_programada = ?
              AND estatus IN ('Programado', 'BloqueoPrevio', 'EnCaptura')
            ORDER BY
                CASE estatus
                    WHEN 'EnCaptura' THEN 1
                    WHEN 'BloqueoPrevio' THEN 2
                    WHEN 'Programado' THEN 3
                    ELSE 99
                END,
                id ASC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("is", $idSucursal, $hoy);
        $stmt->execute();

        $row = ic_fetch_one($stmt);
        $stmt->close();

        return $row;
    }
}

if (!function_exists('obtenerInventarioCiclicoBloqueoPrevio')) {
    /**
     * Obtiene inventario cuya fecha programada es mañana para una sucursal.
     *
     * @param int $idSucursal
     * @return array|null
     */
    function obtenerInventarioCiclicoBloqueoPrevio(int $idSucursal): ?array
    {
        global $conn;

        $manana = ic_manana();

        $sql = "
            SELECT *
            FROM inventarios_ciclicos
            WHERE id_sucursal = ?
              AND fecha_programada = ?
              AND estatus IN ('Programado', 'BloqueoPrevio')
            ORDER BY id ASC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("is", $idSucursal, $manana);
        $stmt->execute();

        $row = ic_fetch_one($stmt);
        $stmt->close();

        return $row;
    }
}

if (!function_exists('tieneBloqueoPrevioInventarioCiclico')) {
    /**
     * True si la sucursal debe tener bloqueo previo
     *
     * @param int $idSucursal
     * @return bool
     */
    function tieneBloqueoPrevioInventarioCiclico(int $idSucursal): bool
    {
        return obtenerInventarioCiclicoBloqueoPrevio($idSucursal) !== null;
    }
}

if (!function_exists('tieneBloqueoOperativoInventarioCiclico')) {
    /**
     * True si la sucursal tiene bloqueo operativo el día del inventario
     *
     * @param int $idSucursal
     * @return bool
     */
    function tieneBloqueoOperativoInventarioCiclico(int $idSucursal): bool
    {
        $inv = obtenerInventarioCiclicoActivoHoy($idSucursal);

        if (!$inv) {
            return false;
        }

        return in_array($inv['estatus'], ['Programado', 'BloqueoPrevio', 'EnCaptura'], true);
    }
}

if (!function_exists('obtenerSaldoPayJoySucursal')) {
    /**
     * Calcula saldo de tarjetas PayJoy por sucursal:
     * INGRESO suma, SALIDA resta.
     *
     * @param int $idSucursal
     * @return int
     */
    function obtenerSaldoPayJoySucursal(int $idSucursal): int
    {
        global $conn;

        $saldo = 0;

        $sql = "
            SELECT COALESCE(SUM(
                CASE
                    WHEN tipo = 'INGRESO' THEN cantidad
                    ELSE -cantidad
                END
            ), 0) AS saldo
            FROM payjoy_tc_kardex
            WHERE id_sucursal = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $idSucursal);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result && ($row = $result->fetch_assoc())) {
            $saldo = (int)($row['saldo'] ?? 0);
            $result->free();
        }

        $stmt->close();

        return $saldo;
    }
}

if (!function_exists('registrarBitacoraInventarioCiclico')) {
    /**
     * Registra un evento en la bitácora del inventario cíclico.
     *
     * @param int $idInventario
     * @param string $tipoEvento
     * @param string $descripcion
     * @param array|null $datos
     * @param int $idUsuario
     * @return bool
     */
    function registrarBitacoraInventarioCiclico(
        int $idInventario,
        string $tipoEvento,
        string $descripcion,
        ?array $datos,
        int $idUsuario
    ): bool {
        global $conn;

        $datosJson = ic_json_encode($datos);

        $sql = "
            INSERT INTO inventarios_ciclicos_bitacora
            (
                id_inventario_ciclico,
                tipo_evento,
                descripcion,
                datos_json,
                id_usuario
            )
            VALUES (?, ?, ?, ?, ?)
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "isssi",
            $idInventario,
            $tipoEvento,
            $descripcion,
            $datosJson,
            $idUsuario
        );

        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('actualizarEstatusInventarioCiclico')) {
    /**
     * Actualiza estatus del inventario
     *
     * @param int $idInventario
     * @param string $nuevoEstatus
     * @return bool
     */
    function actualizarEstatusInventarioCiclico(int $idInventario, string $nuevoEstatus): bool
    {
        global $conn;

        $sql = "UPDATE inventarios_ciclicos SET estatus = ? WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("si", $nuevoEstatus, $idInventario);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('marcarBloqueoPrevioInventariosProgramados')) {
    /**
     * Marca como BloqueoPrevio los inventarios de mañana que sigan programados.
     * Útil para cron/manual.
     *
     * @return int Cantidad de filas afectadas
     */
    function marcarBloqueoPrevioInventariosProgramados(): int
    {
        global $conn;

        $manana = ic_manana();

        $sql = "
            UPDATE inventarios_ciclicos
            SET estatus = 'BloqueoPrevio'
            WHERE fecha_programada = ?
              AND estatus = 'Programado'
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("s", $manana);
        $stmt->execute();
        $afectadas = $stmt->affected_rows;
        $stmt->close();

        return max(0, (int)$afectadas);
    }
}

if (!function_exists('obtenerResumenBitacoraInventarioCiclico')) {
    /**
     * Obtiene últimos eventos de bitácora
     *
     * @param int $idInventario
     * @param int $limite
     * @return array
     */
    function obtenerResumenBitacoraInventarioCiclico(int $idInventario, int $limite = 50): array
    {
        global $conn;

        $rows = [];
        $limite = max(1, min(500, $limite));

        $sql = "
            SELECT
                b.*,
                u.nombre AS usuario_nombre
            FROM inventarios_ciclicos_bitacora b
            INNER JOIN usuarios u ON u.id = b.id_usuario
            WHERE b.id_inventario_ciclico = ?
            ORDER BY b.id DESC
            LIMIT {$limite}
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $idInventario);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        $stmt->close();

        return $rows;
    }
}

if (!function_exists('existeInventarioCiclicoActivoMismaFecha')) {
    /**
     * Valida si ya existe un inventario activo para la misma sucursal y fecha.
     *
     * @param int $idSucursal
     * @param string $fecha Y-m-d
     * @return bool
     */
    function existeInventarioCiclicoActivoMismaFecha(int $idSucursal, string $fecha): bool
    {
        global $conn;

        $sql = "
            SELECT id
            FROM inventarios_ciclicos
            WHERE id_sucursal = ?
              AND fecha_programada = ?
              AND estatus IN ('Programado', 'BloqueoPrevio', 'EnCaptura')
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("is", $idSucursal, $fecha);
        $stmt->execute();

        $result = $stmt->get_result();
        $existe = ($result && $result->num_rows > 0);

        if ($result) {
            $result->free();
        }
        $stmt->close();

        return $existe;
    }
}

if (!function_exists('crearInventarioCiclico')) {
    /**
     * Crea una programación de inventario cíclico.
     *
     * @param int $idSucursal
     * @param string $fecha Y-m-d
     * @param string|null $observaciones
     * @param int $idUsuario
     * @return array ['ok'=>bool,'id'=>int|null,'msg'=>string]
     */
    function crearInventarioCiclico(int $idSucursal, string $fecha, ?string $observaciones, int $idUsuario): array
    {
        global $conn;

        $fecha = trim($fecha);
        $observaciones = trim((string)$observaciones);

        if ($idSucursal <= 0) {
            return ['ok' => false, 'id' => null, 'msg' => 'Sucursal inválida.'];
        }

        if ($idUsuario <= 0) {
            return ['ok' => false, 'id' => null, 'msg' => 'Usuario inválido.'];
        }

        if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['ok' => false, 'id' => null, 'msg' => 'Fecha inválida.'];
        }

        if (strtotime($fecha) === false) {
            return ['ok' => false, 'id' => null, 'msg' => 'Fecha inválida.'];
        }

        if ($fecha < ic_hoy()) {
            return ['ok' => false, 'id' => null, 'msg' => 'No puedes programar inventarios en fechas pasadas.'];
        }

        if (existeInventarioCiclicoActivoMismaFecha($idSucursal, $fecha)) {
            return ['ok' => false, 'id' => null, 'msg' => 'Ya existe un inventario activo para esa sucursal en esa fecha.'];
        }

        $sql = "
            INSERT INTO inventarios_ciclicos
            (
                id_sucursal,
                fecha_programada,
                estatus,
                observaciones,
                creado_por
            )
            VALUES (?, ?, 'Programado', ?, ?)
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'id' => null, 'msg' => 'No se pudo preparar la inserción.'];
        }

        $stmt->bind_param("issi", $idSucursal, $fecha, $observaciones, $idUsuario);
        $ok = $stmt->execute();

        if (!$ok) {
            $msg = 'No se pudo crear el inventario cíclico.';
            $stmt->close();
            return ['ok' => false, 'id' => null, 'msg' => $msg];
        }

        $idInventario = (int)$conn->insert_id;
        $stmt->close();

        registrarBitacoraInventarioCiclico(
            $idInventario,
            'programacion_creada',
            'Se creó la programación del inventario cíclico.',
            [
                'id_sucursal' => $idSucursal,
                'fecha_programada' => $fecha,
                'observaciones' => $observaciones,
            ],
            $idUsuario
        );

        return ['ok' => true, 'id' => $idInventario, 'msg' => 'Inventario cíclico programado correctamente.'];
    }
}

if (!function_exists('crearInventariosCiclicosMasivo')) {
    /**
     * Programa varias sucursales para la misma fecha.
     *
     * @param array $idsSucursales
     * @param string $fecha
     * @param string|null $observaciones
     * @param int $idUsuario
     * @return array
     */
    function crearInventariosCiclicosMasivo(array $idsSucursales, string $fecha, ?string $observaciones, int $idUsuario): array
    {
        $creados = [];
        $errores = [];

        $idsSucursales = array_values(array_unique(array_map('intval', $idsSucursales)));

        foreach ($idsSucursales as $idSucursal) {
            if ($idSucursal <= 0) {
                continue;
            }

            $resp = crearInventarioCiclico($idSucursal, $fecha, $observaciones, $idUsuario);

            if (!empty($resp['ok'])) {
                $creados[] = [
                    'id_sucursal' => $idSucursal,
                    'id_inventario' => (int)$resp['id'],
                ];
            } else {
                $errores[] = [
                    'id_sucursal' => $idSucursal,
                    'msg' => $resp['msg'] ?? 'Error desconocido',
                ];
            }
        }

        return [
            'ok' => count($creados) > 0,
            'creados' => $creados,
            'errores' => $errores,
            'total_creados' => count($creados),
            'total_errores' => count($errores),
        ];
    }
}

if (!function_exists('obtenerInventariosCiclicosAdmin')) {
    /**
     * Listado administrativo de inventarios cíclicos
     *
     * Filtros soportados:
     * - fecha_desde
     * - fecha_hasta
     * - id_sucursal
     * - estatus
     *
     * @param array $filtros
     * @return array
     */
    function obtenerInventariosCiclicosAdmin(array $filtros = []): array
    {
        global $conn;

        $where = [];
        $params = [];
        $types  = '';

        if (!empty($filtros['fecha_desde'])) {
            $where[] = "ic.fecha_programada >= ?";
            $params[] = $filtros['fecha_desde'];
            $types .= 's';
        }

        if (!empty($filtros['fecha_hasta'])) {
            $where[] = "ic.fecha_programada <= ?";
            $params[] = $filtros['fecha_hasta'];
            $types .= 's';
        }

        if (!empty($filtros['id_sucursal'])) {
            $where[] = "ic.id_sucursal = ?";
            $params[] = (int)$filtros['id_sucursal'];
            $types .= 'i';
        }

        if (!empty($filtros['estatus'])) {
            $where[] = "ic.estatus = ?";
            $params[] = $filtros['estatus'];
            $types .= 's';
        }

        $sql = "
            SELECT
                ic.*,
                s.nombre AS sucursal_nombre,
                uc.nombre AS creado_por_nombre,
                ui.nombre AS iniciado_por_nombre,
                ucie.nombre AS cerrado_por_nombre,
                ur.nombre AS revisado_por_nombre
            FROM inventarios_ciclicos ic
            INNER JOIN sucursales s ON s.id = ic.id_sucursal
            INNER JOIN usuarios uc ON uc.id = ic.creado_por
            LEFT JOIN usuarios ui ON ui.id = ic.iniciado_por
            LEFT JOIN usuarios ucie ON ucie.id = ic.cerrado_por
            LEFT JOIN usuarios ur ON ur.id = ic.revisado_por
        ";

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY ic.fecha_programada DESC, ic.id DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        $stmt->close();

        return $rows;
    }
}

if (!function_exists('obtenerSucursalesActivasInventarioCiclico')) {
    /**
     * Obtiene sucursales activas para programación.
     *
     * @return array
     */
    function obtenerSucursalesActivasInventarioCiclico(): array
    {
        global $conn;

        $sql = "
            SELECT id, nombre, zona, tipo_sucursal, subtipo
            FROM sucursales
            WHERE activo = 1
            ORDER BY nombre ASC
        ";

        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }
}

/* =========================================================
   HELPERS PARA ACTA / CIERRE / EXPORT
   ========================================================= */

if (!function_exists('icc_resumen_general')) {
    /**
     * Resumen general consolidado del inventario.
     */
    function icc_resumen_general(int $idInventario): array
    {
        global $conn;

        $resumen = [
            'snapshot_total' => 0,
            'capturas_total' => 0,
            'correctos' => 0,
            'faltantes' => 0,
            'sobrantes' => 0,
            'otra_sucursal' => 0,
            'no_disponible' => 0,
            'diferencia_cantidad' => 0,
        ];

        $sql1 = "SELECT COUNT(*) AS total FROM inventarios_ciclicos_snapshot WHERE id_inventario_ciclico = ?";
        $st1 = $conn->prepare($sql1);
        if ($st1) {
            $st1->bind_param("i", $idInventario);
            $st1->execute();
            $row1 = ic_fetch_one($st1);
            $st1->close();
            $resumen['snapshot_total'] = (int)($row1['total'] ?? 0);
        }

        $sql2 = "SELECT COUNT(*) AS total FROM inventarios_ciclicos_detalle WHERE id_inventario_ciclico = ?";
        $st2 = $conn->prepare($sql2);
        if ($st2) {
            $st2->bind_param("i", $idInventario);
            $st2->execute();
            $row2 = ic_fetch_one($st2);
            $st2->close();
            $resumen['capturas_total'] = (int)($row2['total'] ?? 0);
        }

        $sql3 = "
            SELECT
                SUM(CASE WHEN resultado_validacion = 'ok' THEN 1 ELSE 0 END) AS correctos,
                SUM(CASE WHEN resultado_validacion = 'no_existe_en_sistema' THEN 1 ELSE 0 END) AS sobrantes,
                SUM(CASE WHEN resultado_validacion = 'existe_en_otra_sucursal' THEN 1 ELSE 0 END) AS otra_sucursal,
                SUM(CASE WHEN resultado_validacion = 'existe_pero_no_disponible' THEN 1 ELSE 0 END) AS no_disponible,
                SUM(CASE WHEN resultado_validacion = 'diferencia_cantidad' THEN 1 ELSE 0 END) AS diferencia_cantidad
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
        ";
        $st3 = $conn->prepare($sql3);
        if ($st3) {
            $st3->bind_param("i", $idInventario);
            $st3->execute();
            $row3 = ic_fetch_one($st3);
            $st3->close();

            $resumen['correctos'] = (int)($row3['correctos'] ?? 0);
            $resumen['sobrantes'] = (int)($row3['sobrantes'] ?? 0);
            $resumen['otra_sucursal'] = (int)($row3['otra_sucursal'] ?? 0);
            $resumen['no_disponible'] = (int)($row3['no_disponible'] ?? 0);
            $resumen['diferencia_cantidad'] = (int)($row3['diferencia_cantidad'] ?? 0);
        }

        $sql4 = "
            SELECT COUNT(*) AS total
            FROM inventarios_ciclicos_snapshot s
            LEFT JOIN inventarios_ciclicos_detalle d
                ON d.id_inventario_ciclico = s.id_inventario_ciclico
               AND (
                    (s.identificador IS NOT NULL AND s.identificador <> '' AND d.identificador = s.identificador)
                    OR
                    (
                        (s.identificador IS NULL OR s.identificador = '')
                        AND s.seccion = d.seccion
                        AND (
                            (s.id_producto IS NOT NULL AND d.id_producto = s.id_producto)
                            OR
                            (s.tipo_item = 'payjoy_cantidad' AND d.tipo_item = 'payjoy_cantidad')
                        )
                    )
               )
            WHERE s.id_inventario_ciclico = ?
              AND d.id IS NULL
        ";
        $st4 = $conn->prepare($sql4);
        if ($st4) {
            $st4->bind_param("i", $idInventario);
            $st4->execute();
            $row4 = ic_fetch_one($st4);
            $st4->close();
            $resumen['faltantes'] = (int)($row4['total'] ?? 0);
        }

        return $resumen;
    }
}

if (!function_exists('icc_correctos')) {
    /**
     * Registros correctos detectados en la conciliación.
     */
    function icc_correctos(int $idInventario): array
    {
        global $conn;

        $sql = "
            SELECT *
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
              AND resultado_validacion = 'ok'
            ORDER BY seccion, descripcion_snapshot, identificador, id
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $rows = ic_fetch_all($stmt);
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('icc_faltantes')) {
    /**
     * Snapshot esperado que no tuvo captura equivalente.
     */
    function icc_faltantes(int $idInventario): array
    {
        global $conn;

        $sql = "
            SELECT
                s.*
            FROM inventarios_ciclicos_snapshot s
            LEFT JOIN inventarios_ciclicos_detalle d
                ON d.id_inventario_ciclico = s.id_inventario_ciclico
               AND (
                    (s.identificador IS NOT NULL AND s.identificador <> '' AND d.identificador = s.identificador)
                    OR
                    (
                        (s.identificador IS NULL OR s.identificador = '')
                        AND s.seccion = d.seccion
                        AND (
                            (s.id_producto IS NOT NULL AND d.id_producto = s.id_producto)
                            OR
                            (s.tipo_item = 'payjoy_cantidad' AND d.tipo_item = 'payjoy_cantidad')
                        )
                    )
               )
            WHERE s.id_inventario_ciclico = ?
              AND d.id IS NULL
            ORDER BY s.seccion, s.descripcion_snapshot, s.identificador, s.id
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $rows = ic_fetch_all($stmt);
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('icc_por_resultado')) {
    /**
     * Obtiene registros por tipo de resultado_validacion.
     */
    function icc_por_resultado(int $idInventario, string $resultado): array
    {
        global $conn;

        $sql = "
            SELECT *
            FROM inventarios_ciclicos_detalle
            WHERE id_inventario_ciclico = ?
              AND resultado_validacion = ?
            ORDER BY seccion, descripcion_snapshot, identificador, id
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("is", $idInventario, $resultado);
        $stmt->execute();
        $rows = ic_fetch_all($stmt);
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('icc_obtener_firmas_acta')) {
    /**
     * Obtiene datos de firma digital simple del inventario (MiPlan).
     * Firma única del gerente / responsable.
     */
    function icc_obtener_firmas_acta(int $idInventario): array
    {
        global $conn;

        $data = [
            'firmado_digitalmente' => 0,
            'token_firma' => null,
            'fecha_firma' => null,
            'id_usuario_firma' => null,
            'responsable_nombre' => null,
            'leyenda_firma' => 'Firmado digitalmente con contraseña',
        ];

        $cols = [
            'firmado_digitalmente',
            'token_firma',
            'fecha_firma',
            'id_usuario_firma',
        ];

        $columnasDisponibles = [];
        foreach ($cols as $col) {
            if (ic_table_has_column('inventarios_ciclicos', $col)) {
                $columnasDisponibles[] = $col;
            }
        }

        if (!$columnasDisponibles) {
            return $data;
        }

        $selects = [];
        foreach ($columnasDisponibles as $col) {
            $selects[] = "ic.`{$col}`";
        }

        $sql = "
            SELECT
                " . implode(", ", $selects) . ",
                u.nombre AS responsable_nombre
            FROM inventarios_ciclicos ic
            LEFT JOIN usuarios u ON u.id = ic.id_usuario_firma
            WHERE ic.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $data;
        }

        $stmt->bind_param("i", $idInventario);
        $stmt->execute();
        $row = ic_fetch_one($stmt);
        $stmt->close();

        if (!$row) {
            return $data;
        }

        foreach ($data as $k => $v) {
            if (array_key_exists($k, $row)) {
                $data[$k] = $row[$k];
            }
        }

        $data['firmado_digitalmente'] = (int)($data['firmado_digitalmente'] ?? 0);
        $data['id_usuario_firma'] = isset($data['id_usuario_firma']) ? (int)$data['id_usuario_firma'] : null;

        return $data;
    }
}

if (!function_exists('icc_obtener_acta_auditoria')) {
    /**
     * Motor único del acta de auditoría / inventario cíclico.
     *
     * Regresa toda la información consolidada para:
     * - acta HTML
     * - PDF
     * - Excel
     */
    function icc_obtener_acta_auditoria(int $idInventario): array
    {
        $inventario = obtenerInventarioCiclicoPorId($idInventario);

        if (!$inventario) {
            return [
                'ok' => false,
                'msg' => 'No se encontró el inventario cíclico.',
                'inventario' => null,
                'resumen' => [],
                'serializados' => [],
                'no_serializados' => [],
                'firmas' => [],
                'bitacora' => [],
            ];
        }

        $resumen = icc_resumen_general($idInventario);

        $serializados = [
            'correctos' => icc_correctos($idInventario),
            'faltantes' => icc_faltantes($idInventario),
            'sobrantes' => icc_por_resultado($idInventario, 'no_existe_en_sistema'),
            'otra_sucursal' => icc_por_resultado($idInventario, 'existe_en_otra_sucursal'),
            'no_disponible' => icc_por_resultado($idInventario, 'existe_pero_no_disponible'),
        ];

        $noSerializados = [
            'diferencias' => icc_por_resultado($idInventario, 'diferencia_cantidad'),
        ];

        $firmas = icc_obtener_firmas_acta($idInventario);
        $bitacora = obtenerResumenBitacoraInventarioCiclico($idInventario, 20);

        return [
            'ok' => true,
            'msg' => 'Acta cargada correctamente.',
            'inventario' => $inventario,
            'resumen' => $resumen,
            'serializados' => $serializados,
            'no_serializados' => $noSerializados,
            'firmas' => $firmas,
            'bitacora' => $bitacora,
        ];
    }
}



