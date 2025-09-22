<?php
// comisiones_especiales.php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin', 'GerenteZona'])) {
  header("Location: 403.php");
  exit();
}
include 'db.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
$mensaje = "";

// Helpers
function validarTraslape($conn, $id, $marca, $modelo, $capacidad, $f1, $f2)
{
  $sql = "SELECT COUNT(*) c
          FROM comisiones_especiales
          WHERE activo=1
            AND marca=? AND modelo=? AND IFNULL(capacidad,'')=IFNULL(?, '')
            AND (
                 (fecha_inicio <= ? AND fecha_fin >= ?) OR
                 (fecha_inicio <= ? AND fecha_fin >= ?) OR
                 (fecha_inicio >= ? AND fecha_fin <= ?)
            )";
  if ($id) $sql .= " AND id<>?";

  $stmt = $conn->prepare($sql);

  if ($id) {
    // 9 's' + 1 'i' = 10 tipos para 10 variables
    $stmt->bind_param(
      "sssssssssi",
      $marca,
      $modelo,
      $capacidad,
      $f1,
      $f1,
      $f2,
      $f2,
      $f1,
      $f2,
      $id
    );
  } else {
    // 9 's' = 9 tipos para 9 variables
    $stmt->bind_param(
      "sssssssss",
      $marca,
      $modelo,
      $capacidad,
      $f1,
      $f1,
      $f2,
      $f2,
      $f1,
      $f2
    );
  }

  $stmt->execute();
  $c = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
  $stmt->close();
  return $c > 0;
}

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $mensaje = "<div class='alert alert-danger'>Token inválido. Recarga la página.</div>";
  } else {
    $accion = $_POST['accion'];

    try {
      if ($accion === 'crear') {
        $monto = (float)$_POST['monto'];
        $f1 = $_POST['fecha_inicio'];
        $f2 = $_POST['fecha_fin'];
        $marca = trim($_POST['marca']);
        $modelo = trim($_POST['modelo']);
        $capacidad = trim($_POST['capacidad'] ?? '');

        if ($monto <= 0 || !$f1 || !$f2 || !$marca || !$modelo) throw new Exception("Completa los campos obligatorios.");
        if ($f2 < $f1) throw new Exception("La fecha fin no puede ser menor que la de inicio.");
        if (validarTraslape($conn, 0, $marca, $modelo, $capacidad, $f1, $f2))
          throw new Exception("Ya existe una comisión activa que traslapa en ese rango para ese equipo.");

        $stmt = $conn->prepare("INSERT INTO comisiones_especiales (monto, fecha_inicio, fecha_fin, activo, marca, modelo, capacidad)
                                VALUES (?,?,?,?,?,?,?)");
        $activo = 1;
        $stmt->bind_param("dssisss", $monto, $f1, $f2, $activo, $marca, $modelo, $capacidad);
        $stmt->execute();
        $stmt->close();
        $mensaje = "<div class='alert alert-success'>✅ Comisión registrada.</div>";
      } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $monto = (float)$_POST['monto'];
        $f1 = $_POST['fecha_inicio'];
        $f2 = $_POST['fecha_fin'];
        $marca = trim($_POST['marca']);
        $modelo = trim($_POST['modelo']);
        $capacidad = trim($_POST['capacidad'] ?? '');

        if ($id <= 0) throw new Exception("ID inválido.");
        if ($monto <= 0 || !$f1 || !$f2 || !$marca || !$modelo) throw new Exception("Completa los campos obligatorios.");
        if ($f2 < $f1) throw new Exception("La fecha fin no puede ser menor que la de inicio.");
        if (validarTraslape($conn, $id, $marca, $modelo, $capacidad, $f1, $f2))
          throw new Exception("Traslapa con otra comisión activa.");

        $stmt = $conn->prepare("UPDATE comisiones_especiales
                                SET monto=?, fecha_inicio=?, fecha_fin=?, marca=?, modelo=?, capacidad=?
                                WHERE id=?");
        $stmt->bind_param("dsssssi", $monto, $f1, $f2, $marca, $modelo, $capacidad, $id);
        $stmt->execute();
        $stmt->close();
        $mensaje = "<div class='alert alert-success'>✅ Comisión actualizada.</div>";
      } elseif ($accion === 'toggle') {
        $id = (int)$_POST['id'];
        $nuevo = (int)$_POST['nuevo'];
        $stmt = $conn->prepare("UPDATE comisiones_especiales SET activo=? WHERE id=?");
        $stmt->bind_param("ii", $nuevo, $id);
        $stmt->execute();
        $stmt->close();
        $mensaje = "<div class='alert alert-info'>Estado cambiado.</div>";
      }
    } catch (Throwable $e) {
      $mensaje = "<div class='alert alert-danger'>❌ " . $e->getMessage() . "</div>";
    }
  }
}

// Filtros
$q = trim($_GET['q'] ?? '');
$soloActivas = isset($_GET['solo']) && $_GET['solo'] === '1';

// Consulta
$sql = "SELECT * FROM comisiones_especiales WHERE 1";
$types = "";
$params = [];
if ($q !== '') {
  $sql .= " AND (marca LIKE CONCAT('%',?,'%') OR modelo LIKE CONCAT('%',?,'%') OR IFNULL(capacidad,'') LIKE CONCAT('%',?,'%'))";
  $types .= "sss";
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
}
if ($soloActivas) {
  $sql .= " AND activo=1";
}
$sql .= " ORDER BY activo DESC, fecha_inicio DESC, marca ASC, modelo ASC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Comisiones especiales</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>

<body class="bg-light">

  <?php include 'navbar.php'; ?>

  <div class="container mt-4">
    <h2>💸 Comisiones especiales por equipo</h2>
    <?= $mensaje ?>

    <div class="row g-3">
      <!-- Alta -->
      <div class="col-lg-5">
        <form class="card shadow-sm" method="post" autocomplete="off">
          <div class="card-header bg-dark text-white">Nueva comisión</div>
          <div class="card-body">
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="mb-2">
              <label class="form-label">Marca*</label>
              <input type="text" name="marca" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Modelo*</label>
              <input type="text" name="modelo" class="form-control" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Capacidad (opcional)</label>
              <input type="text" name="capacidad" class="form-control" placeholder="64GB, 128GB, etc.">
            </div>
            <div class="row">
              <div class="col-md-6 mb-2">
                <label class="form-label">Fecha inicio*</label>
                <input type="date" name="fecha_inicio" class="form-control" required>
              </div>
              <div class="col-md-6 mb-2">
                <label class="form-label">Fecha fin*</label>
                <input type="date" name="fecha_fin" class="form-control" required>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Monto ($)*</label>
              <input type="number" step="0.01" min="0.01" name="monto" class="form-control" required>
            </div>
            <div class="text-end">
              <button class="btn btn-primary">Guardar</button>
            </div>
            <div class="form-text">* Para la misma marca/modelo/capacidad no se permite traslape de fechas activo.</div>
          </div>
        </form>
      </div>

      <!-- Listado -->
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-header bg-primary text-white">
            Comisiones registradas
          </div>
          <div class="card-body">
            <form class="row g-2 mb-2" method="get">
              <div class="col-md-6">
                <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar marca, modelo o capacidad">
              </div>
              <div class="col-md-3">
                <select name="solo" class="form-select" onchange="this.form.submit()">
                  <option value="">Todas</option>
                  <option value="1" <?= $soloActivas ? 'selected' : ''; ?>>Solo activas</option>
                </select>
              </div>
              <div class="col-md-3 d-grid">
                <button class="btn btn-outline-secondary">Filtrar</button>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Equipo</th>
                    <th>Vigencia</th>
                    <th>Monto</th>
                    <th>Activo</th>
                    <th class="text-end">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="6" class="text-center py-3">Sin registros.</td>
                    </tr>
                    <?php else: foreach ($rows as $r): ?>
                      <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td>
                          <div><b><?= htmlspecialchars($r['marca']) ?></b> <?= htmlspecialchars($r['modelo']) ?></div>
                          <div class="text-muted small"><?= htmlspecialchars($r['capacidad'] ?: '—') ?></div>
                        </td>
                        <td>
                          <div><?= htmlspecialchars($r['fecha_inicio']) ?> → <?= htmlspecialchars($r['fecha_fin']) ?></div>
                        </td>
                        <td>$<?= number_format((float)$r['monto'], 2) ?></td>
                        <td>
                          <span class="badge <?= $r['activo'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $r['activo'] ? 'Sí' : 'No' ?>
                          </span>
                        </td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#modalEditar"
                            data-id="<?= $r['id'] ?>"
                            data-monto="<?= $r['monto'] ?>"
                            data-f1="<?= $r['fecha_inicio'] ?>"
                            data-f2="<?= $r['fecha_fin'] ?>"
                            data-marca="<?= htmlspecialchars($r['marca']) ?>"
                            data-modelo="<?= htmlspecialchars($r['modelo']) ?>"
                            data-capacidad="<?= htmlspecialchars($r['capacidad']) ?>">
                            Editar
                          </button>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="nuevo" value="<?= $r['activo'] ? 0 : 1 ?>">
                            <button class="btn btn-sm <?= $r['activo'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                              <?= $r['activo'] ? 'Desactivar' : 'Activar' ?>
                            </button>
                          </form>
                        </td>
                      </tr>
                  <?php endforeach;
                  endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal editar -->
  <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Editar comisión</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="accion" value="editar">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="id" id="e_id">
          <div class="mb-2">
            <label class="form-label">Marca*</label>
            <input type="text" name="marca" id="e_marca" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Modelo*</label>
            <input type="text" name="modelo" id="e_modelo" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Capacidad (opcional)</label>
            <input type="text" name="capacidad" id="e_capacidad" class="form-control">
          </div>
          <div class="row">
            <div class="col-md-6 mb-2">
              <label class="form-label">Fecha inicio*</label>
              <input type="date" name="fecha_inicio" id="e_f1" class="form-control" required>
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Fecha fin*</label>
              <input type="date" name="fecha_fin" id="e_f2" class="form-control" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Monto ($)*</label>
            <input type="number" step="0.01" min="0.01" name="monto" id="e_monto" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>

  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
  <script>
    const modalEditar = document.getElementById('modalEditar');
    modalEditar.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('e_id').value = b.getAttribute('data-id');
      document.getElementById('e_monto').value = b.getAttribute('data-monto');
      document.getElementById('e_f1').value = b.getAttribute('data-f1');
      document.getElementById('e_f2').value = b.getAttribute('data-f2');
      document.getElementById('e_marca').value = b.getAttribute('data-marca');
      document.getElementById('e_modelo').value = b.getAttribute('data-modelo');
      document.getElementById('e_capacidad').value = b.getAttribute('data-capacidad') || '';
    });
  </script>
</body>

</html>