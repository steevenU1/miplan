<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit('No autorizado'); }
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=UTF-8');

$marca     = $_GET['marca']     ?? '';
$modelo    = $_GET['modelo']    ?? '';
$capacidad = $_GET['capacidad'] ?? '';
$sucursal  = (int)($_GET['sucursal'] ?? 0);

if ($marca==='' || $modelo==='' || $capacidad==='') {
  echo '<div class="mini">Parámetros incompletos</div>'; exit;
}

$sql = "
  SELECT 
    s.id   AS id_sucursal,
    s.nombre AS sucursal,
    COALESCE(NULLIF(p.color,''),'N/D') AS color,
    COUNT(*) AS piezas
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE p.tipo_producto='Equipo'
    AND i.estatus IN ('Disponible','En tránsito')
    AND p.marca = ?
    AND p.modelo = ?
    AND p.capacidad = ?
    ".($sucursal>0 ? "AND s.id = ?" : "")."
  GROUP BY s.id, s.nombre, color
  ORDER BY s.nombre, color
";
if ($sucursal>0) {
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("sssi", $marca, $modelo, $capacidad, $sucursal);
} else {
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("sss", $marca, $modelo, $capacidad);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalPiezas = 0;
while($r = $res->fetch_assoc()){
  $r['piezas'] = (int)($r['piezas'] ?? 0);
  $rows[] = $r;
  $totalPiezas += $r['piezas'];
}
$stmt->close();
?>
<style>
  .det-head{display:flex;align-items:center;gap:10px;margin:4px 0 8px 0}
  .det-head h4{margin:0;font-size:15px}
  .pill{display:inline-block; padding:2px 6px; border-radius:999px; font-size:12px; background:#eef2ff}

  .det-table{width:100%; border-collapse:collapse; white-space:nowrap}
  .det-table th, .det-table td{border-bottom:1px solid #eee; padding:6px 8px; font-size:13px}
  .det-table th{text-align:left}
  .det-table td:nth-child(3), .det-table th:nth-child(3){ text-align:right } /* piezas a la derecha */
  .sum{background:#fafafa; font-weight:600}
  .mini{font-size:13px;color:#64748b}
</style>

<div class="det-head">
  <h4>Detalle por sucursal y color — <span class="pill"><?=htmlspecialchars("$marca $modelo $capacidad")?></span></h4>
</div>

<?php if (empty($rows)): ?>
  <div class="mini">Sin equipos para este modelo en “Disponible/En tránsito”.</div>
<?php else: ?>
  <table class="det-table">
    <thead>
      <tr>
        <th>Sucursal</th>
        <th>Color</th>
        <th>Piezas</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['sucursal'])?></td>
          <td><?=htmlspecialchars($r['color'])?></td>
          <td><?= (int)$r['piezas'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="sum">
        <td colspan="2">Total</td>
        <td><?= (int)$totalPiezas ?></td>
      </tr>
    </tfoot>
  </table>
<?php endif; ?>
