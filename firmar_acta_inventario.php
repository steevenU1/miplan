<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida.']);
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
    echo json_encode(['ok' => false, 'msg' => 'No tienes permisos para firmar esta acta.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit();
}

$idInventario = (int)($_POST['id_inventario'] ?? 0);
$password     = (string)($_POST['password_firma'] ?? '');

if ($idInventario <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Inventario inválido.']);
    exit();
}

if (trim($password) === '') {
    echo json_encode(['ok' => false, 'msg' => 'Debes capturar tu contraseña.']);
    exit();
}

$inventario = obtenerInventarioCiclicoPorId($idInventario);
if (!$inventario) {
    echo json_encode(['ok' => false, 'msg' => 'No se encontró el inventario.']);
    exit();
}

$rolesAdminLike = ['Admin', 'Super', 'SuperAdmin', 'Gerente General'];
$esAdminLike = in_array($rol, $rolesAdminLike, true);

if (!$esAdminLike && (int)$inventario['id_sucursal'] !== $idSucursal) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'No puedes firmar un inventario de otra sucursal.']);
    exit();
}

if ((string)$inventario['estatus'] !== 'EnConciliacion') {
    echo json_encode(['ok' => false, 'msg' => 'Solo se puede firmar un inventario en conciliación.']);
    exit();
}

if (!function_exists('ic_validar_password_usuario')) {
    function ic_validar_password_usuario(mysqli $conn, int $idUsuario, string $password): bool
    {
        $sql = "SELECT password FROM usuarios WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $idUsuario);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();

        if (!$row) {
            return false;
        }

        $hash = (string)($row['password'] ?? '');

        if ($hash === '') {
            return false;
        }

        // Primero intentamos password_hash / password_verify
        if (password_verify($password, $hash)) {
            return true;
        }

        // Fallback por si el sistema aún guarda texto plano
        return hash_equals($hash, $password);
    }
}

if (!function_exists('ic_generar_token_firma')) {
    function ic_generar_token_firma(): string
    {
        try {
            return strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');
        } catch (Throwable $e) {
            return strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8)) . '-' . date('YmdHis');
        }
    }
}

if (!ic_validar_password_usuario($conn, $idUsuario, $password)) {
    echo json_encode(['ok' => false, 'msg' => 'La contraseña es incorrecta.']);
    exit();
}

if (
    !ic_table_has_column('inventarios_ciclicos', 'firmado_digitalmente') ||
    !ic_table_has_column('inventarios_ciclicos', 'token_firma') ||
    !ic_table_has_column('inventarios_ciclicos', 'fecha_firma') ||
    !ic_table_has_column('inventarios_ciclicos', 'id_usuario_firma')
) {
    echo json_encode(['ok' => false, 'msg' => 'Las columnas de firma digital aún no están disponibles en la base de datos.']);
    exit();
}

$tokenFirma = ic_generar_token_firma();

$conn->begin_transaction();

try {
    $sql = "
        UPDATE inventarios_ciclicos
        SET firmado_digitalmente = 1,
            token_firma = ?,
            fecha_firma = NOW(),
            id_usuario_firma = ?
        WHERE id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('No se pudo preparar la firma.');
    }

    $stmt->bind_param("sii", $tokenFirma, $idUsuario, $idInventario);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new Exception('No se pudo guardar la firma digital.');
    }

    $okBit = registrarBitacoraInventarioCiclico(
        $idInventario,
        'firma_digital',
        'Se aplicó la firma digital del gerente/responsable.',
        [
            'id_usuario_firma' => $idUsuario,
            'token_firma' => $tokenFirma,
            'fecha_firma' => date('Y-m-d H:i:s'),
        ],
        $idUsuario
    );

    if (!$okBit) {
        throw new Exception('No se pudo registrar la bitácora de firma.');
    }

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'Acta firmada correctamente.',
        'token_firma' => $tokenFirma,
    ]);
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage(),
    ]);
    exit();
}