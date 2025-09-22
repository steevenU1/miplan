<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$force = isset($_GET['force']); // solo para UI
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd1 = $_POST['pwd1'] ?? '';
    $pwd2 = $_POST['pwd2'] ?? '';

    // Validaciones básicas
    $errores = [];
    if (strlen($pwd1) < 8) $errores[] = "La contraseña debe tener al menos 8 caracteres.";
    if (!preg_match('/[A-Z]/', $pwd1)) $errores[] = "Debe incluir al menos una mayúscula.";
    if (!preg_match('/[a-z]/', $pwd1)) $errores[] = "Debe incluir al menos una minúscula.";
    if (!preg_match('/\d/', $pwd1))     $errores[] = "Debe incluir al menos un número.";
    if ($pwd1 !== $pwd2) $errores[] = "Las contraseñas no coinciden.";

    if (!$errores) {
        $hash = password_hash($pwd1, PASSWORD_DEFAULT);
        $id = intval($_SESSION['id_usuario']);
        $stmt = $conn->prepare("UPDATE usuarios SET password=?, must_change_password=0 WHERE id=?");
        $stmt->bind_param("si", $hash, $id);
        if ($stmt->execute()) {
            $_SESSION['must_change_password'] = 0;
            $mensaje = "<div class='alert alert-success'>✅ Contraseña actualizada. Ya puedes continuar.</div>";
            // Redirigir a dashboard en 1.5s
            header("Refresh:1.5; url=dashboard_unificado.php");
        } else {
            $mensaje = "<div class='alert alert-danger'>❌ Error al guardar: ".htmlspecialchars($stmt->error)."</div>";
        }
        $stmt->close();
    } else {
        $mensaje = "<div class='alert alert-danger'>".implode("<br>", array_map('htmlspecialchars',$errores))."</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cambiar contraseña</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:480px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h4 class="mb-2">Cambiar contraseña</h4>
      <?php if ($force): ?>
        <div class="alert alert-warning">Debes actualizar tu contraseña para continuar.</div>
      <?php endif; ?>
      <?= $mensaje ?>
      <form method="post" autocomplete="off">
        <div class="mb-3">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="pwd1" class="form-control" required>
          <div class="form-text">Mínimo 8 caracteres, con mayúscula, minúscula y número.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirmar nueva contraseña</label>
          <input type="password" name="pwd2" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Guardar</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
