<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

include 'db.php';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre        = trim($_POST['nombre'] ?? '');
    $zona          = $_POST['zona'] ?? '';
    $tipo_sucursal = $_POST['tipo_sucursal'] ?? '';
    $subtipo       = $_POST['subtipo'] ?? '';
    $cuota_semanal = isset($_POST['cuota_semanal']) ? (float)$_POST['cuota_semanal'] : 0;

    if ($nombre && $zona && $tipo_sucursal) {

        // Normalizar reglas:
        // - Almacén: cuota = 0 y subtipo = Propia
        if ($tipo_sucursal === 'Almacen') {
            $cuota_semanal = 0;
            $subtipo = 'Propia';
        }

        // Si es Tienda y no se envió subtipo, por defecto Propia
        if ($tipo_sucursal === 'Tienda' && !$subtipo) {
            $subtipo = 'Propia';
        }

        // Insertar
        $stmt = $conn->prepare("
            INSERT INTO sucursales (nombre, zona, cuota_semanal, tipo_sucursal, subtipo)
            VALUES (?,?,?,?,?)
        ");
        // tipos: s (nombre) s (zona) d (cuota) s (tipo) s (subtipo)
        $stmt->bind_param("ssdss", $nombre, $zona, $cuota_semanal, $tipo_sucursal, $subtipo);
        if ($stmt->execute()) {
            $mensaje = "<div class='alert alert-success'>✅ Sucursal <b>".htmlspecialchars($nombre)."</b> registrada correctamente.</div>";
        } else {
            $mensaje = "<div class='alert alert-danger'>❌ Error al guardar: ".htmlspecialchars($stmt->error)."</div>";
        }
        $stmt->close();
    } else {
        $mensaje = "<div class='alert alert-danger'>❌ Debes completar todos los campos obligatorios.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Alta de Sucursales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2>🏢 Alta de Sucursales</h2>
    <?= $mensaje ?>

    <form method="POST" class="card p-4 shadow-sm bg-white">
        <div class="mb-3">
            <label class="form-label">Nombre de la Sucursal</label>
            <input type="text" name="nombre" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Zona</label>
            <select name="zona" class="form-select" required>
                <option value="">-- Selecciona Zona --</option>
                <option value="Zona 1">Zona 1</option>
                <option value="Zona 2">Zona 2</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Tipo de Sucursal</label>
            <select name="tipo_sucursal" id="tipo_sucursal" class="form-select" required>
                <option value="">-- Selecciona Tipo --</option>
                <option value="Tienda">Tienda</option>
                <option value="Almacen">Almacén</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Subtipo</label>
            <select name="subtipo" id="subtipo" class="form-select">
                <option value="Propia">Propia</option>
                <option value="Subdistribuidor">Subdistribuidor</option>
                <option value="Master Admin">Master Admin</option>
            </select>
            <small class="text-muted">Para almacenes, el subtipo se fija en Propia automáticamente.</small>
        </div>

        <div class="mb-3">
            <label class="form-label">Cuota Semanal ($)</label>
            <input type="number" name="cuota_semanal" id="cuota_semanal" class="form-control" value="0" min="0" step="0.01">
            <small class="text-muted">Para almacenes, se asigna 0 automáticamente.</small>
        </div>

        <button type="submit" class="btn btn-primary">Registrar Sucursal</button>
    </form>
</div>

<script>
  const tipo = document.getElementById('tipo_sucursal');
  const subtipo = document.getElementById('subtipo');
  const cuota = document.getElementById('cuota_semanal');

  function aplicarReglas() {
    if (tipo.value === 'Almacen') {
      // Almacén: subtipo Propia y bloqueado; cuota 0 y bloqueada
      subtipo.value = 'Propia';
      subtipo.setAttribute('disabled', 'disabled');
      cuota.value = 0;
      cuota.setAttribute('readonly', 'readonly');
    } else if (tipo.value === 'Tienda') {
      subtipo.removeAttribute('disabled');
      cuota.removeAttribute('readonly');
    } else {
      // Estado inicial
      subtipo.removeAttribute('disabled');
      cuota.removeAttribute('readonly');
    }
  }

  tipo.addEventListener('change', aplicarReglas);
  // Ejecutar al cargar por si el navegador mantiene valores previos
  aplicarReglas();
</script>

</body>
</html>

