<?php
// procesar_venta_scooter.php
// Flujo de registro de venta EXCLUSIVO para SCOOTERS

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

// ===== Helpers =====

function redirect_err(string $msg): void {
    $url = 'nueva_venta_scooter.php?err=' . urlencode($msg);
    header("Location: $url");
    exit();
}

function hfname(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $name);
}

// ✅ Helper correcto para bind_param con referencias (PHP 8+)
function bind_params_ref(mysqli_stmt $stmt, string $types, array $values): void {
    $refs = [];
    $refs[] = $types;
    foreach ($values as $k => &$v) {
        $refs[] = &$v;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// ✅ Radar: valida que ? == tipos == vars y da error con contexto
function debug_bind_check(string $sql, string $types, array $values, string $ctx): void {
    $q = substr_count($sql, '?');
    $t = strlen($types);
    $v = count($values);
    if ($q !== $t || $q !== $v) {
        throw new RuntimeException("BIND MISMATCH [$ctx] => ?=$q types=$t vars=$v");
    }
}

/**
 * Sube un archivo (si viene) a /uploads/scooters y devuelve la ruta relativa
 * o cadena vacía si no se subió nada.
 */
function subirArchivoOpcional(string $campo): string {
    if (empty($_FILES[$campo]['name']) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    $file = $_FILES[$campo];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        redirect_err("Error al subir archivo ($campo).");
    }

    // 5 MB máximo
    if ($file['size'] > 5 * 1024 * 1024) {
        redirect_err("El archivo de $campo excede 5 MB.");
    }

    $baseDir = __DIR__ . '/uploads/scooters';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext = $ext ? ('.' . strtolower($ext)) : '';
    $nombreSeguro = hfname(pathinfo($file['name'], PATHINFO_FILENAME));
    $nombreFinal  = $campo . '_' . date('Ymd_His') . '_' . $nombreSeguro . $ext;

    $destinoAbs = $baseDir . '/' . $nombreFinal;
    if (!move_uploaded_file($file['tmp_name'], $destinoAbs)) {
        redirect_err("No se pudo guardar el archivo de $campo.");
    }

    // Guardamos ruta relativa desde /uploads
    return 'scooters/' . $nombreFinal;
}

/**
 * Obtiene información de inventario+producto para los IDs de inventario dados.
 * Devuelve arreglo id_inventario => row.
 */
function obtenerScooters(mysqli $conn, array $idsInv, int $idSucursal): array {
    if (empty($idsInv)) return [];

    // limpiamos y quitamos ceros
    $idsInv = array_values(array_unique(array_filter($idsInv, fn($v) => (int)$v > 0)));
    if (!$idsInv) return [];

    // Detectar columna de tipo
    $colTipo = null;
    $rs = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'");
    if ($rs && $rs->num_rows > 0) {
        $colTipo = 'tipo_producto';
    } else {
        $rs = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo'");
        if ($rs && $rs->num_rows > 0) {
            $colTipo = 'tipo';
        }
    }

    $placeholders = implode(',', array_fill(0, count($idsInv), '?'));

    $sql = "
        SELECT
            i.id          AS id_inventario,
            i.id_producto AS id_producto,
            p.marca,
            p.modelo,
            p.imei1,
            p.imei2,
            p.precio_lista,
            " . ($colTipo ? "LOWER(TRIM(p.`$colTipo`)) AS tipo" : "'' AS tipo") . "
        FROM inventario i
        INNER JOIN productos p ON i.id_producto = p.id
        WHERE i.id_sucursal = ?
          AND TRIM(LOWER(i.estatus)) = 'disponible'
          AND i.id IN ($placeholders)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Error SQL al preparar scooters: ' . $conn->error);
    }

    // bind: primero sucursal, luego todos los ids
    $types  = 'i' . str_repeat('i', count($idsInv));
    $values = array_merge([$idSucursal], $idsInv);

    // ✅ radar bind
    debug_bind_check($sql, $types, $values, 'obtenerScooters');

    bind_params_ref($stmt, $types, $values);

    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['id_inventario']] = $row;
    }
    $stmt->close();

    // Además, validamos que tipo sea scooter (si tenemos la columna)
    foreach ($out as $idInv => $row) {
        $tipo = strtolower(trim((string)($row['tipo'] ?? '')));
        if ($tipo && !in_array($tipo, ['scooter','scooters'], true)) {
            throw new RuntimeException("El inventario $idInv no es Scooter.");
        }
    }

    return $out;
}

// ====== INPUT ======
$tipoVenta   = trim($_POST['tipo_venta']   ?? '');
$idSucursal  = (int)($_POST['id_sucursal'] ?? 0);
$tag         = trim($_POST['tag'] ?? '');
$nombreCli   = trim($_POST['nombre_cliente'] ?? '');
$telCli      = trim($_POST['telefono_cliente'] ?? '');
$precioVenta = (float)($_POST['precio_venta'] ?? 0);
$formaPago   = trim($_POST['forma_pago_enganche'] ?? '');
$enganche    = (float)($_POST['enganche'] ?? 0);
$engancheEf  = (float)($_POST['enganche_efectivo'] ?? 0);
$engancheTj  = (float)($_POST['enganche_tarjeta'] ?? 0);
$plazoSem    = (int)($_POST['plazo_semanas'] ?? 0);
$financiera  = trim($_POST['financiera'] ?? '');
$comentarios = trim($_POST['comentarios'] ?? '');

$ref1Nom = trim($_POST['referencia1_nombre'] ?? '');
$ref1Tel = trim($_POST['referencia1_telefono'] ?? '');
$ref2Nom = trim($_POST['referencia2_nombre'] ?? '');
$ref2Tel = trim($_POST['referencia2_telefono'] ?? '');

$idInv1 = (int)($_POST['equipo1'] ?? 0); // id inventario scooter principal
$idInv2 = (int)($_POST['equipo2'] ?? 0); // opcional (combo)

// ====== Validaciones base (servidor) ======

if ($idSucursal <= 0) {
    redirect_err('Sucursal inválida.');
}
if (!in_array($tipoVenta, ['Contado','Financiamiento','Financiamiento+Combo'], true)) {
    redirect_err('Tipo de venta inválido.');
}
if ($precioVenta <= 0) {
    redirect_err('El precio de venta debe ser mayor a 0.');
}
if (!$idInv1) {
    redirect_err('Debes seleccionar el scooter principal.');
}
if ($formaPago === '') {
    redirect_err('Selecciona la forma de pago.');
}

// Validaciones específicas de Financiamiento
$esFin = ($tipoVenta === 'Financiamiento' || $tipoVenta === 'Financiamiento+Combo');

if ($esFin) {
    if ($nombreCli === '') {
        redirect_err('En financiamiento, el nombre del cliente es obligatorio.');
    }
    if ($telCli === '' || !preg_match('/^\d{10}$/', $telCli)) {
        redirect_err('En financiamiento, el teléfono del cliente es obligatorio y debe tener 10 dígitos.');
    }
    if ($tag === '') {
        redirect_err('En financiamiento, el TAG (ID del crédito) es obligatorio.');
    }
    if ($plazoSem <= 0) {
        redirect_err('En financiamiento, el plazo en semanas debe ser mayor a 0.');
    }
    if ($financiera === '') {
        redirect_err('Selecciona una financiera válida.');
    }
    if ($formaPago === 'Mixto') {
        if ($engancheEf <= 0 && $engancheTj <= 0) {
            redirect_err('En Mixto, al menos un monto de enganche debe ser > 0.');
        }
        if (number_format($enganche, 2, '.', '') !== number_format($engancheEf + $engancheTj, 2, '.', '')) {
            redirect_err('En Mixto, Enganche Efectivo + Tarjeta debe igualar al Enganche.');
        }
    }
    // Referencias
    if ($ref1Nom === '' || !preg_match('/^\d{10}$/', $ref1Tel)) {
        redirect_err('Referencia 1: nombre y teléfono (10 dígitos) son obligatorios en financiamiento.');
    }
    if ($ref2Nom === '' || !preg_match('/^\d{10}$/', $ref2Tel)) {
        redirect_err('Referencia 2: nombre y teléfono (10 dígitos) son obligatorios en financiamiento.');
    }
} else {
    // Contado: si vienen teléfonos de referencias, validar formato
    if ($ref1Tel !== '' && !preg_match('/^\d{10}$/', $ref1Tel)) {
        redirect_err('Referencia 1: el teléfono debe tener 10 dígitos.');
    }
    if ($ref2Tel !== '' && !preg_match('/^\d{10}$/', $ref2Tel)) {
        redirect_err('Referencia 2: el teléfono debe tener 10 dígitos.');
    }
}

// ====== Manejo de archivos ======
$pathIdentificacion = '';
$pathContrato       = '';

if ($esFin) {
    if (empty($_FILES['identificacion']['name'])) {
        redirect_err('En financiamiento, debes subir imagen de identificación.');
    }
    if (empty($_FILES['contrato']['name'])) {
        redirect_err('En financiamiento, debes subir el archivo de contrato.');
    }
}

$pathIdentificacion = subirArchivoOpcional('identificacion');
$pathContrato       = subirArchivoOpcional('contrato');

// ====== Lógica principal: inserción y actualización de inventario ======
try {
    $conn->begin_transaction();

    // 1) Obtener info de scooters por inventario
    $idsInv = [$idInv1];
    if ($idInv2 > 0 && $tipoVenta === 'Financiamiento+Combo') {
        $idsInv[] = $idInv2;
    }
    $infoScooters = obtenerScooters($conn, $idsInv, $idSucursal);

    if (!isset($infoScooters[$idInv1])) {
        throw new RuntimeException('El scooter principal no está disponible o no es scooter.');
    }
    if ($idInv2 > 0 && $tipoVenta === 'Financiamiento+Combo' && !isset($infoScooters[$idInv2])) {
        throw new RuntimeException('El scooter combo no está disponible o no es scooter.');
    }

    // 2) Insertar en ventas_scooter
    $sqlVenta = "
        INSERT INTO ventas_scooter
        (
            tag,
            nombre_cliente,
            telefono_cliente,
            tipo_venta,
            precio_venta,
            fecha_venta,
            id_usuario,
            id_sucursal,
            id_esquema,
            comision,
            comision_gerente,
            enganche,
            forma_pago_enganche,
            enganche_efectivo,
            enganche_tarjeta,
            plazo_semanas,
            financiera,
            comentarios,
            imagen_identificacion,
            referencia1_nombre,
            referencia1_telefono,
            referencia2_nombre,
            referencia2_telefono,
            imagen_contrato,
            comision_especial
        )
        VALUES
        (?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmtVenta = $conn->prepare($sqlVenta);
    if (!$stmtVenta) {
        throw new RuntimeException('Error SQL al preparar venta scooter: ' . $conn->error);
    }

    $idEsquema          = null;
    $comisionTotal      = 0.0;
    $comisionGerente    = 0.0;
    $comisionEspecial   = 0.0;

    // ✅ 24 placeholders => 24 tipos
    $typesVenta = "ssssdiiidddsddissssssssd";  // ✅ 24
    $valsVenta = [
        $tag,
        $nombreCli,
        $telCli,
        $tipoVenta,
        $precioVenta,
        $idUsuario,
        $idSucursal,
        $idEsquema,
        $comisionTotal,
        $comisionGerente,
        $enganche,
        $formaPago,
        $engancheEf,
        $engancheTj,
        $plazoSem,
        $financiera,
        $comentarios,
        $pathIdentificacion,
        $ref1Nom,
        $ref1Tel,
        $ref2Nom,
        $ref2Tel,
        $pathContrato,
        $comisionEspecial
    ];

    // ✅ radar bind
    debug_bind_check($sqlVenta, $typesVenta, $valsVenta, 'insert_venta');

    bind_params_ref($stmtVenta, $typesVenta, $valsVenta);

    $stmtVenta->execute();
    $idVenta = (int)$stmtVenta->insert_id;
    $stmtVenta->close();

    if ($idVenta <= 0) {
        throw new RuntimeException('No se pudo generar la venta de scooter.');
    }

    // 3) Insertar detalle_venta_scooter
    $sqlDet = "
        INSERT INTO detalle_venta_scooter
        (
          id_venta,
          id_producto,
          es_combo,
          imei1,
          precio_unitario,
          comision_regular,
          comision_especial,
          comision
        )
        VALUES (?,?,?,?,?,?,?,?)
    ";
    $stmtDet = $conn->prepare($sqlDet);
    if (!$stmtDet) {
        throw new RuntimeException('Error SQL al preparar detalle scooter: ' . $conn->error);
    }

    // scooter principal
    $row1 = $infoScooters[$idInv1];
    $idProd1  = (int)$row1['id_producto'];
    $imei1_1  = (string)($row1['imei1'] ?? '');
    $precio1  = (float)($row1['precio_lista'] ?? 0);
    $esCombo1 = 0;

    $comReg1  = 0.0;
    $comEsp1  = 0.0;
    $comTot1  = 0.0;

    $stmtDet->bind_param(
        "iiisdddd",
        $idVenta,
        $idProd1,
        $esCombo1,
        $imei1_1,
        $precio1,
        $comReg1,
        $comEsp1,
        $comTot1
    );
    $stmtDet->execute();

    // scooter combo (si aplica)
    if ($idInv2 > 0 && $tipoVenta === 'Financiamiento+Combo' && isset($infoScooters[$idInv2])) {
        $row2 = $infoScooters[$idInv2];
        $idProd2  = (int)$row2['id_producto'];
        $imei1_2  = (string)($row2['imei1'] ?? '');
        $precio2  = (float)($row2['precio_lista'] ?? 0);
        $esCombo2 = 1;

        $comReg2  = 0.0;
        $comEsp2  = 0.0;
        $comTot2  = 0.0;

        $stmtDet->bind_param(
            "iiisdddd",
            $idVenta,
            $idProd2,
            $esCombo2,
            $imei1_2,
            $precio2,
            $comReg2,
            $comEsp2,
            $comTot2
        );
        $stmtDet->execute();
    }

    $stmtDet->close();

    // 4) Actualizar inventario a VENDIDO
    $idsParaVender = [$idInv1];
    if ($idInv2 > 0 && $tipoVenta === 'Financiamiento+Combo') {
        $idsParaVender[] = $idInv2;
    }
    $idsParaVender = array_values(array_unique(array_filter($idsParaVender, fn($v) => (int)$v > 0)));

    if ($idsParaVender) {
        $place = implode(',', array_fill(0, count($idsParaVender), '?'));
        $sqlInv = "UPDATE inventario SET estatus='Vendido' WHERE id IN ($place)";
        $stmtInv = $conn->prepare($sqlInv);
        if (!$stmtInv) {
            throw new RuntimeException('Error SQL al preparar update inventario: ' . $conn->error);
        }

        $typesInv = str_repeat('i', count($idsParaVender));

        // ✅ radar bind
        debug_bind_check($sqlInv, $typesInv, $idsParaVender, 'update_inventario');

        bind_params_ref($stmtInv, $typesInv, $idsParaVender);

        $stmtInv->execute();
        $stmtInv->close();
    }

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    redirect_err('Error al registrar la venta de scooter: ' . $e->getMessage());
}

// Si todo salió bien, regresamos con OK
header("Location: nueva_venta_scooter.php?ok=1");
exit();
