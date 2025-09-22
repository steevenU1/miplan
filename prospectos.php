<?php
// prospectos.php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require 'db.php';

$idUsuario  = (int)$_SESSION['id_usuario'];
$idSucursal = (int)$_SESSION['id_sucursal'];
$rol        = $_SESSION['rol'] ?? 'Ejecutivo';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
$mensaje = '';

function norm_tel($t) {
  $d = preg_replace('/\D+/', '', (string)$t);
  if (strlen($d) > 10) $d = substr($d, -10);
  return $d;
}

function buscarVentaRecientePorTel($conn, $tel10) {
  $sql = "
    SELECT v.nombre_cliente, v.telefono_cliente, v.fecha_venta,
           u.nombre AS ejecutivo, s.nombre AS sucursal
    FROM ventas v
    LEFT JOIN usuarios u   ON u.id = v.id_usuario
    LEFT JOIN sucursales s ON s.id = v.id_sucursal
    WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(v.telefono_cliente,'+',''),' ',''),'-',''), '(', ''), 10) = ?
    ORDER BY v.fecha_venta DESC
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $tel10);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function buscarProspectoPorTel($conn, $tel) {
  $stmt = $conn->prepare("SELECT p.*, u.nombre AS ejecutivo, s.nombre AS sucursal
                          FROM prospectos p
                          LEFT JOIN usuarios u ON u.id = p.id_ejecutivo
                          LEFT JOIN sucursales s ON s.id = p.id_sucursal
                          WHERE p.telefono = ? LIMIT 1");
  $stmt->bind_param("s", $tel);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function add_historial($conn, $prospecto_id, $id_usuario, $tipo, $nota) {
  $stmt = $conn->prepare("INSERT INTO prospectos_historial (prospecto_id, id_usuario, tipo, nota) VALUES (?,?,?,?)");
  $stmt->bind_param("iiss", $prospecto_id, $id_usuario, $tipo, $nota);
  $stmt->execute(); $stmt->close();
}

/* ========== Alta prospecto ========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='crear') {
  if (!hash_equals($csrf, $_POST['csrf']??'')) {
    $mensaje = "<div class='alert alert-danger'>Token inválido. Recarga la página.</div>";
  } else {
    $nombre   = trim($_POST['nombre'] ?? '');
    $telefono = norm_tel($_POST['telefono'] ?? '');
    $origen   = trim($_POST['origen'] ?? '');
    $notas    = trim($_POST['notas'] ?? '');

    if ($nombre==='' || $telefono==='') {
      $mensaje = "<div class='alert alert-warning'>Nombre y teléfono son obligatorios.</div>";
    } else {
      // 1) validar contra ventas
      $venta = buscarVentaRecientePorTel($conn, $telefono);
      if ($venta) {
        $ej = htmlspecialchars($venta['ejecutivo'] ?: 'N/D');
        $su = htmlspecialchars($venta['sucursal'] ?: 'N/D');
        $mensaje = "<div class='alert alert-danger'>❌ El cliente ya existe como venta y es atendido por <b>$ej</b> en <b>$su</b>. No se puede registrar.</div>";
      } else {
        // 2) validar contra prospectos
        $p = buscarProspectoPorTel($conn, $telefono);
        if ($p && (int)$p['id_ejecutivo'] !== $idUsuario && !in_array($p['etapa'], ['Cerrado','Perdido'], true)) {
          $ej = htmlspecialchars($p['ejecutivo'] ?: 'N/D');
          $su = htmlspecialchars($p['sucursal'] ?: 'N/D');
          $mensaje = "<div class='alert alert-danger'>❌ Ya existe un prospecto con ese teléfono y lo atiende <b>$ej</b> en <b>$su</b>.</div>";
        } else {
          // 3) insertar
          try {
            $stmt = $conn->prepare("INSERT INTO prospectos (nombre, telefono, origen, etapa, notas, id_ejecutivo, id_sucursal)
                                    VALUES (?, ?, ?, 'Nuevo', ?, ?, ?)");
            $stmt->bind_param("ssssii", $nombre, $telefono, $origen, $notas, $idUsuario, $idSucursal);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            if ($notas!=='') add_historial($conn, $newId, $idUsuario, 'Otro', $notas);
            $mensaje = "<div class='alert alert-success'>✅ Prospecto creado.</div>";
          } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
              $mensaje = "<div class='alert alert-warning'>⚠️ Ese teléfono ya está registrado.</div>";
            } else {
              $mensaje = "<div class='alert alert-danger'>❌ Error: ".htmlspecialchars($e->getMessage())."</div>";
            }
          }
        }
      }
    }
  }
}

/* ========== Seguimiento ========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='seguimiento') {
  if (hash_equals($csrf, $_POST['csrf']??'')) {
    $pid   = (int)($_POST['prospecto_id'] ?? 0);
    $tipo  = $_POST['tipo'] ?? 'Otro';
    $nota  = trim($_POST['nota'] ?? '');
    $etapa = $_POST['etapa'] ?? null;
    $prox  = $_POST['proxima_accion'] ?? null;

    $stmt = $conn->prepare("SELECT id_ejecutivo FROM prospectos WHERE id=?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $own = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($own && (int)$own['id_ejecutivo'] === $idUsuario) {
      if ($nota!=='') add_historial($conn, $pid, $idUsuario, $tipo, $nota);
      if ($etapa || $prox) {
        $stmt = $conn->prepare("UPDATE prospectos SET etapa = IFNULL(?, etapa), proxima_accion = ? WHERE id=?");
        $et = $etapa ? $etapa : null;
        $stmt->bind_param("ssi", $et, $prox, $pid);
        $stmt->execute(); $stmt->close();
      }
      $mensaje = "<div class='alert alert-success'>📝 Seguimiento guardado.</div>";
    } else {
      $mensaje = "<div class='alert alert-danger'>No puedes actualizar un prospecto que no es tuyo.</div>";
    }
  }
}

/* ========== Mis prospectos ========== */
$stmt = $conn->prepare("
  SELECT p.*,
         (SELECT creado_en FROM prospectos_historial h WHERE h.prospecto_id=p.id ORDER BY h.id DESC LIMIT 1) AS ultima_nota
  FROM prospectos p
  WHERE p.id_ejecutivo = ?
  ORDER BY FIELD(p.etapa,'Nuevo','Contactado','Interesado','Cita','Propuesta','Cerrado','Perdido'),
           p.updated_at DESC
");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$pros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========== Mi CARTERA (desde ventas del propio ejecutivo) ========== */
/* Agrupa por teléfono normalizado (últimos 10), toma la venta más reciente,
   y calcula semanas transcurridas/restantes. Si es 'Contado' o sin plazo (>0), muestra "Contado". */
$carteraBusqueda = trim($_GET['c_q'] ?? '');
$sqlBaseCartera = "
  SELECT t.tel10,
         v2.nombre_cliente,
         v2.telefono_cliente,
         v2.fecha_venta,
         v2.tipo_venta,
         v2.plazo_semanas,
         TIMESTAMPDIFF(WEEK, v2.fecha_venta, NOW()) AS semanas_transcurridas,
         GREATEST(v2.plazo_semanas - TIMESTAMPDIFF(WEEK, v2.fecha_venta, NOW()), 0) AS semanas_restantes
  FROM (
      SELECT RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(v.telefono_cliente,'+',''),' ',''),'-',''), '(', ''), 10) AS tel10,
             MAX(v.fecha_venta) AS last_fecha
      FROM ventas v
      WHERE v.id_usuario = ?
      GROUP BY tel10
  ) t
  INNER JOIN ventas v2
    ON t.tel10 = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(v2.telefono_cliente,'+',''),' ',''),'-',''), '(', ''), 10)
   AND t.last_fecha = v2.fecha_venta
";
$params = [['i', $idUsuario]];
if ($carteraBusqueda !== '') {
  $sqlBaseCartera .= " WHERE (v2.nombre_cliente LIKE ? OR t.tel10 LIKE ?) ";
  $like = "%$carteraBusqueda%";
  $params[] = ['s', $like];
  $params[] = ['s', $like];
}
$sqlBaseCartera .= " ORDER BY v2.fecha_venta DESC LIMIT 300";

$types = ''; $binds = [];
foreach ($params as $p){ $types .= $p[0]; $binds[] = $p[1]; }
$stmt = $conn->prepare($sqlBaseCartera);
$stmt->bind_param($types, ...$binds);
$stmt->execute();
$cartera = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Prospectos</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <h2>📒 Agenda de prospectos</h2>
  <?= $mensaje ?>

  <div class="row g-3">
    <!-- Alta -->
    <div class="col-lg-5">
      <form class="card shadow-sm" method="post" autocomplete="off">
        <div class="card-header bg-dark text-white">Nuevo prospecto</div>
        <div class="card-body">
          <input type="hidden" name="accion" value="crear">
          <input type="hidden" name="csrf" value="<?=$csrf?>">
          <div class="mb-2">
            <label class="form-label">Nombre*</label>
            <input type="text" name="nombre" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Teléfono*</label>
            <input type="tel" name="telefono" class="form-control" placeholder="10 dígitos" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Origen</label>
            <input type="text" name="origen" class="form-control" placeholder="Facebook, Referido, Walk-in...">
          </div>
          <div class="mb-2">
            <label class="form-label">Nota inicial</label>
            <textarea name="notas" class="form-control" rows="2" placeholder="Observaciones iniciales"></textarea>
          </div>
          <div class="text-end">
            <button class="btn btn-primary">Guardar prospecto</button>
          </div>
          <div class="form-text">Se validará contra ventas y prospectos existentes.</div>
        </div>
      </form>
    </div>

    <!-- Lista -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">Mis prospectos</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th><th>Cliente</th><th>Teléfono</th><th>Etapa</th><th>Próxima acción</th><th class="text-end">Seguimiento</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$pros): ?>
                  <tr><td colspan="6" class="text-center py-3">Sin prospectos aún.</td></tr>
                <?php else: foreach ($pros as $p): ?>
                  <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td>
                      <div><b><?= htmlspecialchars($p['nombre']) ?></b></div>
                      <div class="text-muted small"><?= htmlspecialchars($p['origen'] ?: '—') ?></div>
                    </td>
                    <td><?= htmlspecialchars($p['telefono']) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['etapa']) ?></span></td>
                    <td><?= htmlspecialchars($p['proxima_accion'] ?: '—') ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#modalSeg"
                        data-id="<?=$p['id']?>"
                        data-nombre="<?=htmlspecialchars($p['nombre'])?>"
                        data-etapa="<?=$p['etapa']?>"
                      >Agregar seguimiento</button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- CARTERA DE CLIENTES (desde ventas) -->
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>🗂️ Mi cartera (clientes con venta) — últimas 300 coincidencias</span>
          <form class="d-flex" method="get">
            <input type="text" name="c_q" class="form-control form-control-sm me-2" placeholder="Buscar por nombre o teléfono" value="<?= htmlspecialchars($carteraBusqueda) ?>">
            <button class="btn btn-sm btn-outline-secondary">Buscar</button>
          </form>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Cliente</th>
                  <th>Teléfono</th>
                  <th>Última venta</th>
                  <th>Plazo</th>
                  <th>Restan</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$cartera): ?>
                <tr><td colspan="7" class="text-center py-3 text-muted">Sin registros en cartera.</td></tr>
              <?php else: $i=1; foreach ($cartera as $c):
                $tel10 = norm_tel($c['telefono_cliente'] ?? '');
                $wa = $tel10 ? "https://wa.me/52$tel10" : "#";
                $plazo  = (int)($c['plazo_semanas'] ?? 0);
                $restan = (int)($c['semanas_restantes'] ?? 0);
                $tipo   = trim($c['tipo_venta'] ?? '');
                $esContado = ($plazo <= 0) || (strcasecmp($tipo, 'Contado') === 0);
                $mostrarPlazo = !$esContado;
              ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($c['nombre_cliente'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($c['telefono_cliente'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($c['fecha_venta']) ?></td>
                  <td>
                    <?= $mostrarPlazo ? ($plazo . ' sem.') : 'Contado' ?>
                  </td>
                  <td>
                    <?php if ($mostrarPlazo): ?>
                      <span class="badge <?= $restan<=2 ? 'bg-danger' : ($restan<=4 ? 'bg-warning text-dark' : 'bg-success') ?>">
                        <?= $restan ?> sem.
                      </span>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-success" href="<?= $wa ?>" target="_blank">WhatsApp</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <div class="p-2 small text-muted">
            Agrupado por teléfono (últimos 10 dígitos). Se toma la venta más reciente por cliente.
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal seguimiento -->
<div class="modal fade" id="modalSeg" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Seguimiento a <span id="segNombre"></span></h5>
        <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="seguimiento">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="prospecto_id" id="segId">
        <div class="mb-2">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-select">
            <option>Llamada</option>
            <option>WhatsApp</option>
            <option>Visita</option>
            <option>Email</option>
            <option>Otro</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Nota</label>
          <textarea name="nota" class="form-control" rows="3"></textarea>
        </div>
        <div class="row">
          <div class="col-md-7 mb-2">
            <label class="form-label">Actualizar etapa</label>
            <select name="etapa" id="segEtapa" class="form-select">
              <option value="">(Sin cambio)</option>
              <?php foreach (['Nuevo','Contactado','Interesado','Cita','Propuesta','Cerrado','Perdido'] as $e): ?>
                <option value="<?=$e?>"><?=$e?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5 mb-2">
            <label class="form-label">Próxima acción</label>
            <input type="date" name="proxima_accion" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
const modal = document.getElementById('modalSeg');
modal.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('segId').value = b.getAttribute('data-id');
  document.getElementById('segNombre').textContent = b.getAttribute('data-nombre');
  document.getElementById('segEtapa').value = b.getAttribute('data-etapa');
});
</script>
</body>
</html>
