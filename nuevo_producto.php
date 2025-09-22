<?php
// producto_nuevo_individual.php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

$mensaje = "";
$alertCls = "info";

// Traer sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// ---------- Helpers ----------
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function norm($s){ return trim((string)$s); }
function n2($v){ return number_format((float)$v, 2, '.', ''); }

/** Lee valores ENUM desde INFORMATION_SCHEMA para armar selects confiables */
function getEnumOptions(mysqli $conn, string $table, string $column): array {
  $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$res) return [];
  $colType = $res['COLUMN_TYPE']; // ej: enum('A','B','C')
  if (preg_match("/^enum\\((.*)\\)$/i", $colType, $m)) {
    $parts = str_getcsv($m[1], ',', "'");
    return array_map('trim', $parts);
  }
  return [];
}

// Opciones dinámicas desde el esquema real
$optsTipo       = getEnumOptions($conn, 'productos', 'tipo_producto');    // p.ej. ['Equipo','Modem','Accesorio']
$optsGama       = getEnumOptions($conn, 'productos', 'gama');              // según tu enum real
$optsCicloVida  = getEnumOptions($conn, 'productos', 'ciclo_vida');        // p.ej. ['Nuevo','Línea','Fin de vida']
$optsResurtible = getEnumOptions($conn, 'productos', 'resurtible');        // p.ej. ['Sí','No']

// Defaults amigables si por alguna razón no pudo leer el ENUM (no rompe el flujo)
if (!$optsTipo)       $optsTipo       = ['Equipo','Modem','Accesorio'];
if (!$optsCicloVida)  $optsCicloVida  = ['Nuevo','Línea','Fin de vida'];
if (!$optsResurtible) $optsResurtible = ['Sí','No'];

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // A) Datos básicos de producto
  $codigo_producto = norm($_POST['codigo_producto'] ?? '');
  $marca           = norm($_POST['marca'] ?? '');
  $modelo          = norm($_POST['modelo'] ?? '');
  $color           = norm($_POST['color'] ?? '');
  $ram             = norm($_POST['ram'] ?? '');
  $capacidad       = norm($_POST['capacidad'] ?? '');
  $imei1           = norm($_POST['imei1'] ?? '');
  $imei2           = norm($_POST['imei2'] ?? '');
  $costo           = (float)($_POST['costo'] ?? 0);
  $costo_con_iva   = $_POST['costo_con_iva'] !== '' ? (float)$_POST['costo_con_iva'] : 0.0;
  $precio_lista    = (float)($_POST['precio_lista'] ?? 0);
  $descripcion     = norm($_POST['descripcion'] ?? '');
  $nombre_comercial= norm($_POST['nombre_comercial'] ?? '');
  $compania        = norm($_POST['compania'] ?? '');
  $financiera      = norm($_POST['financiera'] ?? '');
  $fecha_lanz      = norm($_POST['fecha_lanzamiento'] ?? ''); // YYYY-MM-DD
  $tipo_producto   = norm($_POST['tipo_producto'] ?? 'Equipo');
  $subtipo         = norm($_POST['subtipo'] ?? '');
  $gama            = norm($_POST['gama'] ?? '');
  $ciclo_vida      = norm($_POST['ciclo_vida'] ?? '');
  $abc             = norm($_POST['abc'] ?? '');
  $operador        = norm($_POST['operador'] ?? '');
  $resurtible      = norm($_POST['resurtible'] ?? '');
  $proveedor       = norm($_POST['proveedor'] ?? '');

  // B) Sucursal destino
  $id_sucursal     = (int)($_POST['id_sucursal'] ?? 0);

  // C) Reglas rápidas
  if (!$codigo_producto) {
    // Genera código si viene vacío: TIPO-MARCA-MODELO-CAPACIDAD-COLOR (limpio)
    $slug = strtoupper(preg_replace('/[^A-Z0-9]+/','',
            str_replace(' ','', $tipo_producto.'-'.$marca.'-'.$modelo.'-'.$capacidad.'-'.$color)));
    $codigo_producto = substr($slug, 0, 50); // cabe en varchar(50)
  }

  if ($costo > 0 && $costo_con_iva <= 0) {
    $costo_con_iva = round($costo * 1.16, 2); // IVA 16%
  }

  // Validaciones obligatorias mínimas
  if (!$marca || !$modelo || !$imei1 || $costo <= 0 || $precio_lista <= 0 || $id_sucursal <= 0) {
    $mensaje  = "⚠️ Completa los campos obligatorios: Marca, Modelo, IMEI1, Costo, Precio lista y Sucursal.";
    $alertCls = "warning";
  } else {
    // IMEI duplicado (en imei1 o imei2)
    $stmt = $conn->prepare("SELECT id FROM productos WHERE imei1=? OR imei2=? LIMIT 1");
    $stmt->bind_param("ss", $imei1, $imei1);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
      $mensaje  = "❌ Ya existe un producto con IMEI $imei1.";
      $alertCls = "danger";
    } else {
      // Insert de producto (mapea a tus columnas reales)
      $sql = "INSERT INTO productos
        (codigo_producto, marca, modelo, color, ram, capacidad, imei1, imei2, costo, costo_con_iva, proveedor,
         precio_lista, descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
         tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

      $stmt = $conn->prepare($sql);
      $stmt->bind_param(
        "ssssssssddsdssssssssssss",
        $codigo_producto, $marca, $modelo, $color, $ram, $capacidad, $imei1, $imei2, $costo, $costo_con_iva, $proveedor,
        $precio_lista, $descripcion, $nombre_comercial, $compania, $financiera, $fecha_lanz,
        $tipo_producto, $subtipo, $gama, $ciclo_vida, $abc, $operador, $resurtible
      );

      if ($stmt->execute()) {
        $id_producto = $stmt->insert_id;
        $stmt->close();

        // Inventario
        $stmt2 = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?,?, 'Disponible')");
        $stmt2->bind_param("ii", $id_producto, $id_sucursal);
        $stmt2->execute();
        $stmt2->close();

        $mensaje  = "✅ Producto {$marca} {$modelo} registrado y cargado a inventario de la sucursal seleccionada.";
        $alertCls = "success";

        // Limpiar POST para no re-enviar
        $_POST = [];
      } else {
        $mensaje  = "❌ Error al registrar el producto: " . esc($conn->error);
        $alertCls = "danger";
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Nuevo Producto Individual</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4" style="max-width: 980px;">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">📦 Registrar Producto Individual</h3>
    <span class="ms-2 badge text-bg-secondary">Carga directa a inventario</span>
  </div>
  <p class="text-muted">Captura completa del producto y selección de sucursal destino. Los IMEIs se validan para evitar duplicados.</p>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?= esc($alertCls) ?> shadow-sm"><?= $mensaje ?></div>
  <?php endif; ?>

  <form method="POST" class="card shadow-sm p-3 p-md-4 bg-white">

    <!-- Identificación -->
    <h5 class="fw-semibold mb-3">Identificación</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Código de producto</label>
        <input type="text" name="codigo_producto" maxlength="50" class="form-control" value="<?= esc($_POST['codigo_producto'] ?? '') ?>" placeholder="Se genera si lo dejas vacío">
      </div>
      <div class="col-md-4">
        <label class="form-label">Marca *</label>
        <input type="text" name="marca" class="form-control" required value="<?= esc($_POST['marca'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Modelo *</label>
        <input type="text" name="modelo" class="form-control" required value="<?= esc($_POST['modelo'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Color</label>
        <input type="text" name="color" class="form-control" value="<?= esc($_POST['color'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">RAM</label>
        <input type="text" name="ram" class="form-control" value="<?= esc($_POST['ram'] ?? '') ?>" placeholder="Ej. 4GB">
      </div>
      <div class="col-md-4">
        <label class="form-label">Capacidad</label>
        <input type="text" name="capacidad" class="form-control" value="<?= esc($_POST['capacidad'] ?? '') ?>" placeholder="Ej. 128GB">
      </div>
    </div>

    <!-- IMEIs -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">IMEI 1 *</label>
        <input type="text" name="imei1" class="form-control" required value="<?= esc($_POST['imei1'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">IMEI 2</label>
        <input type="text" name="imei2" class="form-control" value="<?= esc($_POST['imei2'] ?? '') ?>">
      </div>
    </div>

    <!-- Económicos -->
    <h5 class="fw-semibold mb-3">Económicos</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Costo ($) *</label>
        <input type="number" step="0.01" name="costo" class="form-control" required value="<?= esc($_POST['costo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Costo con IVA ($)</label>
        <input type="number" step="0.01" name="costo_con_iva" class="form-control" value="<?= esc($_POST['costo_con_iva'] ?? '') ?>" placeholder="Se calcula 16% si lo dejas vacío">
      </div>
      <div class="col-md-4">
        <label class="form-label">Precio lista ($) *</label>
        <input type="number" step="0.01" name="precio_lista" class="form-control" required value="<?= esc($_POST['precio_lista'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Proveedor</label>
        <input type="text" name="proveedor" class="form-control" value="<?= esc($_POST['proveedor'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Nombre comercial</label>
        <input type="text" name="nombre_comercial" class="form-control" value="<?= esc($_POST['nombre_comercial'] ?? '') ?>">
      </div>
    </div>

    <!-- Clasificación -->
    <h5 class="fw-semibold mb-3">Clasificación</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Tipo de producto *</label>
        <select name="tipo_producto" class="form-select" required>
          <?php
            $val = $_POST['tipo_producto'] ?? 'Equipo';
            foreach ($optsTipo as $opt) {
              $sel = ($val === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Subtipo</label>
        <input type="text" name="subtipo" class="form-control" value="<?= esc($_POST['subtipo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Gama</label>
        <select name="gama" class="form-select">
          <option value="">—</option>
          <?php
            $val = $_POST['gama'] ?? '';
            foreach ($optsGama as $opt) {
              $sel = ($val === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Ciclo de vida</label>
        <select name="ciclo_vida" class="form-select">
          <option value="">—</option>
          <?php
            $val = $_POST['ciclo_vida'] ?? '';
            foreach ($optsCicloVida as $opt) {
              $sel = ($val === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">ABC</label>
        <input type="text" name="abc" maxlength="1" class="form-control" value="<?= esc($_POST['abc'] ?? '') ?>" placeholder="A/B/C">
      </div>
      <div class="col-md-4">
        <label class="form-label">Resurtible</label>
        <select name="resurtible" class="form-select">
          <option value="">—</option>
          <?php
            $val = $_POST['resurtible'] ?? '';
            foreach ($optsResurtible as $opt) {
              $sel = ($val === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
    </div>

    <!-- Otros -->
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Compañía</label>
        <input type="text" name="compania" class="form-control" value="<?= esc($_POST['compania'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Financiera</label>
        <input type="text" name="financiera" class="form-control" value="<?= esc($_POST['financiera'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Fecha lanzamiento</label>
        <input type="date" name="fecha_lanzamiento" class="form-control" value="<?= esc($_POST['fecha_lanzamiento'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Operador</label>
        <input type="text" name="operador" class="form-control" value="<?= esc($_POST['operador'] ?? '') ?>" placeholder="Solo aplica si se requiere">
      </div>
      <div class="col-md-6">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" rows="2" class="form-control"><?= esc($_POST['descripcion'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- Sucursal destino -->
    <h5 class="fw-semibold mb-3">Inventario destino</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Sucursal *</label>
        <select name="id_sucursal" class="form-select" required>
          <option value="">Seleccione sucursal…</option>
          <?php
          if ($sucursales && $sucursales->num_rows) {
            $val = (int)($_POST['id_sucursal'] ?? 0);
            while($s = $sucursales->fetch_assoc()){
              $sel = ($val === (int)$s['id']) ? 'selected' : '';
              echo '<option value="'.(int)$s['id'].'" '.$sel.'>'.esc($s['nombre']).'</option>';
            }
          }
          ?>
        </select>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <a href="inventario_global.php" class="btn btn-outline-secondary">Volver</a>
      <button class="btn btn-primary">Registrar producto</button>
    </div>
  </form>
</div>
</body>
</html>
