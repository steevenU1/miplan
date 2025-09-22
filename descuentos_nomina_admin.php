<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin','RH'])) {
  header("Location: 403.php"); exit();
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

function obtenerSemanaPorIndice($offset = 0) {
  $hoy = new DateTime('now', new DateTimeZone('America/Mexico_City'));
  $diaSemana = (int)$hoy->format('N'); // 1=Lun...7=Dom
  $dif = $diaSemana - 2; if ($dif < 0) $dif += 7;
  $inicio = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->modify("-$dif days")->setTime(0,0,0);
  if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
  $fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);
  return [$inicio, $fin];
}

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj, $finObj) = obtenerSemanaPorIndice($semana);
$iniISO = $iniObj->format('Y-m-d');
$finISO = $finObj->format('Y-m-d');
$idUsuarioFocus = isset($_GET['id_usuario']) ? (int)$_GET['id_usuario'] : 0;

$msg = "";

/* Crear descuento */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
  $idU = (int)($_POST['id_usuario'] ?? 0);
  $concepto = trim($_POST['concepto'] ?? '');
  $monto = (float)($_POST['monto'] ?? 0);
  if ($idU > 0 && $concepto !== '' && $monto > 0) {
    $stmt = $conn->prepare("INSERT INTO descuentos_nomina (id_usuario, semana_inicio, semana_fin, concepto, monto, creado_por) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssdi", $idU, $iniISO, $finISO, $concepto, $monto, $_SESSION['id_usuario']);
    if ($stmt->execute()) $msg = "Descuento registrado.";
    else $msg = "Error: ".$conn->error;
  } else {
    $msg = "Completa usuario, concepto y monto (> 0).";
  }
}

/* Eliminar descuento */
if (isset($_GET['del']) && ctype_digit($_GET['del'])) {
  $del = (int)$_GET['del'];
  $conn->query("DELETE FROM descuentos_nomina WHERE id={$del} LIMIT 1");
  $msg = "Descuento eliminado.";
}

/* Usuarios (mismos filtros de nómina) */
$subdistCol = null;
foreach (['subtipo_sucursal','subtipo','sub_tipo','tipo_subsucursal'] as $c) {
  $rs = $conn->query("SHOW COLUMNS FROM sucursales LIKE '$c'");
  if ($rs && $rs->num_rows > 0) { $subdistCol = $c; break; }
}
$where = "s.tipo_sucursal <> 'Almacen'";
if ($subdistCol) $where .= " AND (s.`$subdistCol` IS NULL OR s.`$subdistCol` <> 'Subdistribuidor')";

$sqlUsuarios = "
  SELECT u.id, u.nombre, u.rol, s.nombre AS sucursal
  FROM usuarios u
  INNER JOIN sucursales s ON s.id=u.id_sucursal
  WHERE $where
  ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$usuarios = $conn->query($sqlUsuarios);

/* Descuentos existentes de la semana */
$sqlDesc = "
  SELECT d.id, d.id_usuario, u.nombre, s.nombre AS sucursal, d.concepto, d.monto, d.creado_en
  FROM descuentos_nomina d
  JOIN usuarios u ON u.id=d.id_usuario
  JOIN sucursales s ON s.id=u.id_sucursal
  WHERE d.semana_inicio='{$iniISO}' AND d.semana_fin='{$finISO}'
  ORDER BY s.nombre, u.nombre, d.creado_en DESC
";
$descuentos = $conn->query($sqlDesc);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Descuentos de Nómina (Semana)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f7f7fb; }
    .card-soft{ background:#fff; border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-0">Descuentos de Nómina</h4>
      <div class="text-muted small">
        Semana del <strong><?= $iniObj->format('d/m/Y') ?></strong> al <strong><?= $finObj->format('d/m/Y') ?></strong>
      </div>
    </div>
    <form class="d-flex align-items-center gap-2" method="get">
      <input type="hidden" name="id_usuario" value="<?= $idUsuarioFocus ?>">
      <label class="form-label mb-0 small text-muted">Semana</label>
      <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php for ($i=0; $i<8; $i++):
            list($ini, $fin) = obtenerSemanaPorIndice($i);
            $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
        ?>
          <option value="<?= $i ?>" <?= $i==$semana?'selected':'' ?>><?= $texto ?></option>
        <?php endfor; ?>
      </select>
      <a class="btn btn-outline-secondary btn-sm" href="reporte_nomina.php?semana=<?= $semana ?>">
        <i class="bi bi-arrow-left"></i> Volver al reporte
      </a>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card-soft p-3">
        <h6 class="mb-3">Agregar descuento</h6>
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="crear">
          <div class="col-12">
            <label class="form-label small text-muted">Colaborador</label>
            <select name="id_usuario" class="form-select form-select-sm" required>
              <option value="">Selecciona...</option>
              <?php while($u = $usuarios->fetch_assoc()): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $idUsuarioFocus==(int)$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars("{$u['sucursal']} — {$u['nombre']} ({$u['rol']})", ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small text-muted">Concepto</label>
            <input type="text" name="concepto" class="form-control form-control-sm" maxlength="150" placeholder="Ej. Faltante de caja" required>
          </div>
          <div class="col-6">
            <label class="form-label small text-muted">Monto</label>
            <input type="number" step="0.01" min="0.01" name="monto" class="form-control form-control-sm" placeholder="0.00" required>
          </div>
          <div class="col-6 d-flex align-items-end justify-content-end">
            <button class="btn btn-primary btn-sm">
              <i class="bi bi-plus-circle me-1"></i> Guardar
            </button>
          </div>
        </form>
        <div class="text-muted small mt-2">
          * El descuento se aplicará solo a esta semana operativa (mar→lun).
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="card-soft p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Descuentos registrados</h6>
          <form class="d-flex gap-2" method="get">
            <input type="hidden" name="semana" value="<?= $semana ?>">
            <input name="q" class="form-control form-control-sm" placeholder="Buscar colaborador, sucursal, concepto...">
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Colaborador</th>
                <th>Sucursal</th>
                <th>Concepto</th>
                <th class="text-end">Monto</th>
                <th class="text-nowrap">Creado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $q = isset($_GET['q']) ? trim($_GET['q']) : '';
              $rows = [];
              while($d = $descuentos->fetch_assoc()){ $rows[] = $d; }
              if ($q !== '') {
                $qLower = mb_strtolower($q,'UTF-8');
                $rows = array_values(array_filter($rows, function($r) use ($qLower){
                  $txt = mb_strtolower(($r['nombre'].' '.$r['sucursal'].' '.$r['concepto']), 'UTF-8');
                  return strpos($txt, $qLower) !== false;
                }));
              }
              $totalDesc = 0;
              foreach($rows as $d):
                $totalDesc += (float)$d['monto'];
              ?>
              <tr>
                <td><?= htmlspecialchars($d['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['sucursal'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['concepto'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end">-$<?= number_format((float)$d['monto'],2) ?></td>
                <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($d['creado_en'])) ?></td>
                <td class="text-end">
                  <a class="btn btn-outline-danger btn-sm" 
                     href="?semana=<?= $semana ?>&del=<?= (int)$d['id'] ?><?= $idUsuarioFocus?('&id_usuario='.$idUsuarioFocus):'' ?>"
                     onclick="return confirm('¿Eliminar este descuento?');">
                    <i class="bi bi-trash"></i>
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <td colspan="3" class="text-end"><strong>Total Descuentos</strong></td>
                <td class="text-end"><strong>-$<?= number_format($totalDesc,2) ?></strong></td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
