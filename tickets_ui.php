<?php
// tickets_ui.php — listado interno para reimpresión
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }
require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

$q = trim($_GET['q'] ?? '');
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$sql = "SELECT c.id, c.ticket_uid, c.fecha_cobro, c.motivo, c.tipo_pago, c.monto_total, c.estado,
               u.nombre AS usuario, s.nombre AS sucursal
        FROM cobros c
        JOIN usuarios u ON u.id=c.id_usuario
        JOIN sucursales s ON s.id=c.id_sucursal
        WHERE c.fecha_cobro >= ? AND c.fecha_cobro < DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$desde, $hasta];
$types  = 'ss';

if ($q !== '') {
  if (ctype_digit($q)) { $sql .= " AND c.id = ?"; $types.='i'; $params[]=(int)$q; }
  else { $sql .= " AND (c.ticket_uid = ? OR c.motivo LIKE CONCAT('%',?,'%'))"; $types.='ss'; $params[]=$q; $params[]=$q; }
}
$sql .= " ORDER BY c.fecha_cobro DESC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tickets (reimpresión)</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
  <h3>Tickets • Reimpresión / Búsqueda</h3>
  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control">
    </div>
    <div class="col-auto">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control">
    </div>
    <div class="col-auto">
      <label class="form-label">Folio / UID / Motivo</label>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Ej. 125 o 78d15...">
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">Buscar</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-striped">
      <thead class="table-light">
        <tr>
          <th>Folio</th><th>Fecha</th><th>Sucursal</th><th>Usuario</th><th>Motivo</th><th>Monto</th><th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['fecha_cobro']) ?></td>
            <td><?= htmlspecialchars($r['sucursal']) ?></td>
            <td><?= htmlspecialchars($r['usuario']) ?></td>
            <td><?= htmlspecialchars($r['motivo']) ?></td>
            <td class="text-end">$<?= number_format((float)$r['monto_total'],2) ?></td>
            <td><?= htmlspecialchars($r['estado']) ?></td>
            <td class="text-end">
              <?php if ($r['ticket_uid']): ?>
                <a class="btn btn-sm btn-outline-primary" href="ticket_cobro.php?uid=<?= urlencode($r['ticket_uid']) ?>" target="_blank">Ver/Imprimir</a>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="ticket_cobro.php?id=<?= (int)$r['id'] ?>" target="_blank">Ver/Imprimir</a>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-secondary" href="ticket_verificar.php?uid=<?= urlencode($r['ticket_uid']) ?>" target="_blank">Verificar</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="text-center text-muted">Sin resultados</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
