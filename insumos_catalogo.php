<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin'])) {
  header("Location: index.php"); exit();
}
include 'db.php';
include 'navbar.php';

$msg='';

/* =========================
   Helpers
========================= */

function getCats($conn){
  $q = $conn->query("SELECT id, nombre FROM insumos_categorias WHERE activo=1 ORDER BY orden, nombre");
  $arr=[]; while($c=$q->fetch_assoc()) $arr[$c['id']]=$c['nombre']; return $arr;
}

/**
 * Guarda/actualiza límites GLOBALes (sin sucursal/rol/subtipo)
 * Retorna true si guardó sin error.
 */
function upsertLimitesGlobal($conn, int $idInsumo, $maxLinea, $maxMes, $activo = 1){
  // Si ambos van vacíos, no hacemos nada (se permite dejar sin límite)
  $tieneLinea = ($maxLinea !== '' && $maxLinea !== null);
  $tieneMes   = ($maxMes   !== '' && $maxMes   !== null);

  if (!$tieneLinea && !$tieneMes) {
    // No insertar nada si no hay valores; si existe un registro previo, no lo tocamos.
    return true;
  }

  // Normalizar a float (o NULL)
  $valLinea = $tieneLinea ? floatval($maxLinea) : null;
  $valMes   = $tieneMes   ? floatval($maxMes)   : null;

  // ¿Existe ya registro global?
  $sqlSel = "SELECT id FROM insumos_limites
             WHERE id_insumo=? AND id_sucursal IS NULL AND rol IS NULL AND subtipo IS NULL
             LIMIT 1";
  $stmt = $conn->prepare($sqlSel);
  $stmt->bind_param("i", $idInsumo);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if ($row) {
    // UPDATE solo campos provistos, para no sobreescribir con NULLs indeseados
    $sets = [];
    $params = [];
    $types = "";

    if ($tieneLinea) { $sets[] = "max_por_linea=?"; $params[] = $valLinea; $types.="d"; }
    if ($tieneMes)   { $sets[] = "max_por_mes=?";   $params[] = $valMes;   $types.="d"; }
    $sets[] = "activo=?";
    $params[] = (int)$activo; $types.="i";
    $params[] = $row['id'];   $types.="i";

    $sqlUpd = "UPDATE insumos_limites SET ".implode(", ", $sets)." WHERE id=?";
    $stmt2 = $conn->prepare($sqlUpd);
    $stmt2->bind_param($types, ...$params);
    $ok = $stmt2->execute();
    $stmt2->close();
    return $ok;
  } else {
    // INSERT nuevo registro global
    $sqlIns = "INSERT INTO insumos_limites
      (id_insumo, id_sucursal, rol, subtipo, max_por_linea, max_por_mes, activo)
      VALUES (?, NULL, NULL, NULL, ?, ?, ?)";
    $stmt3 = $conn->prepare($sqlIns);
    // Si algún valor no fue provisto, lo dejamos NULL
    // bind_param no acepta NULL para "d", así que usamos set_null condicional
    // Solución: cambiamos a tipos "s" y enviamos NULL como null -> usar ->bind_param requiere real types.
    // Harémoslo con ->bind_param y reemplazamos con floats o NULL via mysqli_stmt::send_long_data? Mejor: preparamos dinámico.
    // Simpler: siempre enviamos número; si venía vacío, usaremos NULL por SQL: CAST(? AS DECIMAL(10,2))
    // Mejor usamos prepared con NULL controlado:
    $linea = $tieneLinea ? $valLinea : null;
    $mes   = $tieneMes   ? $valMes   : null;
    $act   = (int)$activo;

    // Para permitir NULL, usamos "double" pero set a null requiere ->bind_param con "d" falla. Cambiamos a "ssd" tampoco.
    // Truco: usamos "bind_param" con "idd" y luego ->execute; si es null, convertimos a NULL vía $stmt3->send_long_data no aplica.
    // Más simple: construimos SQL con placeholders y usamos "NULLIF(?, '')" con strings.
    $stmt3->close();

    $sqlIns = "INSERT INTO insumos_limites
      (id_insumo, id_sucursal, rol, subtipo, max_por_linea, max_por_mes, activo)
      VALUES (?, NULL, NULL, NULL, ?, ?, ?)";
    $stmt3 = $conn->prepare($sqlIns);
    // Aseguramos valores numéricos (o 0 si vienen vacíos) pero semánticamente preferimos NULL.
    // Si quieres estrictamente NULL cuando no se envía, deja ambos provistos en UI.
    $linea = $tieneLinea ? $valLinea : null;
    $mes   = $tieneMes   ? $valMes   : null;

    // Usaremos "ddd" y pasamos 0.0 cuando sean null para evitar error; luego un UPDATE pone NULL si fue null
    $l = $linea ?? null; 
    $m = $mes ?? null;

    // Para soportar NULL correctamente:
    // Cambiamos a "isdii" no, vamos a usar "bind_param" con tipos "idii"? Necesitamos permitir null.
    // Mysqli no enlaza NULL en tipo "d" limpiamente. Solución: usamos SQL dinámico con valores o NULL literales:

    $stmt3->close();

    $sqlIns2 = "INSERT INTO insumos_limites
      (id_insumo, id_sucursal, rol, subtipo, max_por_linea, max_por_mes, activo)
      VALUES ($idInsumo, NULL, NULL, NULL, "
      . ($tieneLinea ? $valLinea : "NULL") . ", "
      . ($tieneMes   ? $valMes   : "NULL") . ", "
      . $act . ")";
    return $conn->query($sqlIns2);
  }
}

/* =========================
   Datos base
========================= */
$catsArr = getCats($conn);

/* =========================
   POST
========================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  if (isset($_POST['add'])) {
    $nombre = trim($_POST['nombre']);
    $unidad = trim($_POST['unidad']);
    $idcat  = (int)($_POST['id_categoria'] ?? 0);
    $limLinea = $_POST['max_por_linea'] ?? '';
    $limMes   = $_POST['max_por_mes'] ?? '';

    if ($nombre!=='' && $idcat>0) {
      $stmt = $conn->prepare("INSERT INTO insumos_catalogo (nombre,id_categoria,unidad,activo) VALUES (?,?,?,1)");
      $stmt->bind_param("sis",$nombre,$idcat,$unidad);
      if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        // Guardar límites globales si vienen
        upsertLimitesGlobal($conn, (int)$newId, $limLinea, $limMes, 1);
        $msg='Insumo agregado.';
      } else {
        $msg='Error al agregar insumo.';
      }
      $stmt->close();
    } else {
      $msg='Faltan datos.';
    }
  }

  if (isset($_POST['upd'])) {
    $id=(int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $unidad = trim($_POST['unidad'] ?? '');
    $idcat  = (int)($_POST['id_categoria'] ?? 0);
    $activo = isset($_POST['activo']) ? 1 : 0;
    $limLinea = $_POST['max_por_linea'] ?? '';
    $limMes   = $_POST['max_por_mes'] ?? '';

    if ($id>0 && $nombre!=='' && $idcat>0) {
      $stmt = $conn->prepare("UPDATE insumos_catalogo SET nombre=?, unidad=?, id_categoria=?, activo=? WHERE id=?");
      $stmt->bind_param("ssiii",$nombre,$unidad,$idcat,$activo,$id);
      if ($stmt->execute()) {
        // Guardar/actualizar límites globales
        upsertLimitesGlobal($conn, $id, $limLinea, $limMes, 1);
        $msg='Insumo actualizado.';
      } else {
        $msg='Error al actualizar insumo.';
      }
      $stmt->close();
    } else {
      $msg='Faltan datos en actualización.';
    }
  }
}

/* =========================
   Consulta listado con límites GLOBALes
========================= */
$rows = $conn->query("
  SELECT i.*,
         COALESCE(cat.nombre,'Sin categoría') AS categoria,
         l.max_por_linea AS limite_linea,
         l.max_por_mes   AS limite_mes
  FROM insumos_catalogo i
  LEFT JOIN insumos_categorias cat ON cat.id=i.id_categoria
  LEFT JOIN insumos_limites l
         ON l.id_insumo = i.id
        AND l.id_sucursal IS NULL
        AND l.rol IS NULL
        AND l.subtipo IS NULL
  ORDER BY i.activo DESC, cat.orden, categoria, i.nombre
");
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Catálogo de Insumos</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
  <h3>🗂️ Catálogo de Insumos</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="card mb-3">
    <div class="card-header">Agregar nuevo</div>
    <div class="card-body">
      <form class="row g-2" method="post">
        <div class="col-md-4">
          <input name="nombre" class="form-control" placeholder="Nombre" required>
        </div>
        <div class="col-md-2">
          <input name="unidad" class="form-control" value="pz" required>
        </div>
        <div class="col-md-3">
          <select name="id_categoria" class="form-select" required>
            <option value="">Categoría</option>
            <?php foreach($catsArr as $idc=>$nc): ?>
              <option value="<?= $idc ?>"><?= htmlspecialchars($nc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1">
          <input type="number" step="0.01" min="0" name="max_por_linea" class="form-control" placeholder="Línea">
        </div>
        <div class="col-md-1">
          <input type="number" step="0.01" min="0" name="max_por_mes" class="form-control" placeholder="Mes">
        </div>
        <div class="col-md-1">
          <button class="btn btn-success w-100" name="add">Agregar</button>
        </div>
      </form>
      <small class="text-muted d-block mt-1">Los límites capturados aquí se guardan como <b>globales</b> (todas las sucursales/roles).</small>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Listado</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Unidad</th>
              <th>Categoría</th>
              <th class="text-center">Activo</th>
              <th class="text-center">Max/Línea</th>
              <th class="text-center">Max/Mes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php while($r=$rows->fetch_assoc()): ?>
              <tr>
                <form method="post" class="row g-2">
                  <td class="col-md-4">
                    <input name="nombre" class="form-control" value="<?= htmlspecialchars($r['nombre']) ?>" required>
                  </td>
                  <td class="col-md-2">
                    <input name="unidad" class="form-control" value="<?= htmlspecialchars($r['unidad']) ?>" required>
                  </td>
                  <td class="col-md-2">
                    <select name="id_categoria" class="form-select" required>
                      <?php foreach($catsArr as $idc=>$nc): ?>
                        <option value="<?= $idc ?>" <?= ($r['id_categoria']==$idc?'selected':'') ?>><?= htmlspecialchars($nc) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="col-md-1 text-center">
                    <div class="form-check d-inline-block">
                      <input type="checkbox" name="activo" class="form-check-input" id="a<?= (int)$r['id'] ?>" <?= $r['activo']?'checked':'' ?>>
                    </div>
                  </td>
                  <td class="col-md-1">
                    <input type="number" step="0.01" min="0" name="max_por_linea" class="form-control"
                           value="<?= htmlspecialchars($r['limite_linea'] ?? '') ?>" placeholder="Línea">
                  </td>
                  <td class="col-md-1">
                    <input type="number" step="0.01" min="0" name="max_por_mes" class="form-control"
                           value="<?= htmlspecialchars($r['limite_mes'] ?? '') ?>" placeholder="Mes">
                  </td>
                  <td class="col-md-1 text-end">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-primary" name="upd">Guardar</button>
                  </td>
                </form>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <small class="text-muted">Los límites mostrados/guardados aquí son <b>globales</b>. Para límites por sucursal o rol, lo habilitamos en otra vista.</small>
    </div>
  </div>
</div>
</body>
</html>
