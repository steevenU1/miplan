<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>403 - Acceso Denegado</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light text-center">
    <div class="container mt-5">
        <h1 class="display-3 text-danger">403</h1>
        <h3>Acceso denegado</h3>
        <p>No tienes permisos para acceder a esta sección.</p>
        <a href="dashboard_unificado.php" class="btn btn-primary mt-3">Volver al Dashboard</a>
    </div>
</body>
</html>
