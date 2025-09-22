<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
$mensaje = '';
$errores = [];

// Obtener sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");

// Mantener valores posteados para repoblar el formulario si hay error
$nombre_post      = $_POST['nombre']      ?? '';
$usuario_post     = $_POST['usuario']     ?? '';
$id_sucursal_post = $_POST['id_sucursal'] ?? '';
$rol_post         = $_POST['rol']         ?? '';
$sueldo_post      = $_POST['sueldo']      ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre      = trim($_POST['nombre'] ?? '');
    $usuario     = trim($_POST['usuario'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
    $rol         = $_POST['rol'] ?? '';
    $sueldo      = (float)($_POST['sueldo'] ?? 0);

    // Validaciones básicas
    if ($nombre === '' || $usuario === '' || $password === '' || $id_sucursal <= 0 || $rol === '' || $sueldo <= 0) {
        $errores[] = "Todos los campos son obligatorios y el sueldo debe ser mayor a 0.";
    }

    // Reglas simples de formato de usuario (opcional)
    if ($usuario !== '' && !preg_match('/^[A-Za-z0-9._-]{3,32}$/', $usuario)) {
        $errores[] = "El usuario solo puede contener letras, números, punto, guion y guion bajo (3 a 32 caracteres).";
    }

    if (empty($errores)) {
        // Verificar duplicado case-insensitive
        $sqlDup = "SELECT COUNT(*) FROM usuarios WHERE LOWER(usuario) = LOWER(?)";
        $stmt = $conn->prepare($sqlDup);
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $stmt->bind_result($existe);
        $stmt->fetch();
        $stmt->close();

        if ($existe > 0) {
            $errores[] = "El usuario <b>" . htmlspecialchars($usuario) . "</b> ya existe.";
        } else {
            // Hash de contraseña
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            // Forzar cambio de contraseña en el primer inicio de sesión
            $must_change_password = 1;

            // Insert con sueldo
            $stmt = $conn->prepare("
                INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol, sueldo, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            // tipos: sssisis → nombre(s), usuario(s), password(s), id_sucursal(i), rol(s), sueldo(d), must_change_password(i)
            $stmt->bind_param("sssissi", $nombre, $usuario, $passwordHash, $id_sucursal, $rol, $sueldo, $must_change_password);

            if ($stmt->execute()) {
                $mensaje = "<div class='alert alert-success'>✅ Usuario <b>" . htmlspecialchars($usuario) . "</b> registrado correctamente. Se le pedirá cambiar la contraseña al ingresar.</div>";
                // Limpiar campos del form tras éxito
                $nombre_post = $usuario_post = $id_sucursal_post = $rol_post = $sueldo_post = '';
            } else {
                $errores[] = "Error al registrar el usuario: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
    }

    if (!empty($errores)) {
        $mensaje = "<div class='alert alert-danger'>❌ " . implode("<br>", $errores) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Alta de Usuarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>👤 Alta de Usuarios</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label class="form-label">Nombre Completo</label>
            <input type="text" name="nombre" class="form-control" required
                   value="<?= htmlspecialchars($nombre_post) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="usuario" class="form-control" required
                   placeholder="ej. e.fernandez"
                   value="<?= htmlspecialchars($usuario_post) ?>">
            <div class="form-text">De 3 a 32 caracteres. Permitido: letras, números, punto, guion y guion bajo.</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Sucursal</label>
            <select name="id_sucursal" class="form-select" required>
                <option value="">-- Selecciona sucursal --</option>
                <?php
                $sucursales->data_seek(0);
                while ($s = $sucursales->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>" <?= ($id_sucursal_post == $s['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nombre']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Rol</label>
            <select name="rol" class="form-select" required>
                <option value="">-- Selecciona rol --</option>
                <?php
                $roles = [
                    'Ejecutivo'   => 'Ejecutivo',
                    'Gerente'     => 'Gerente',
                    'Supervisor'  => 'Supervisor',
                    'GerenteZona' => 'Gerente de Zona',
                    'Admin'       => 'Administrador'
                ];
                foreach ($roles as $value => $label):
                ?>
                    <option value="<?= $value ?>" <?= ($rol_post === $value) ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Sueldo (MXN)</label>
            <input type="number" step="0.01" min="0" name="sueldo" class="form-control" required
                   value="<?= htmlspecialchars($sueldo_post) ?>">
        </div>

        <button type="submit" class="btn btn-primary">Registrar Usuario</button>
    </form>
</div>

</body>
</html>
