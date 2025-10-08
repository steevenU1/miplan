<?php
// ajax_accesorios_por_sucursal.php
// Lista <option> de ACCESORIOS con stock>0 en una sucursal, mostrando productos.descripcion
declare(strict_types=1);

require_once __DIR__.'/db.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$id_sucursal = (int)($_POST['id_sucursal'] ?? $_GET['id_sucursal'] ?? 0);
if ($id_sucursal <= 0) {
  echo '<option value="">Sucursal inválida</option>';
  exit;
}

// helper para acortar textos largos
function short(string $s, int $max = 80): string {
  $s = trim($s);
  if (mb_strlen($s, 'UTF-8') <= $max) return $s;
  return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
}

$sql = "
  SELECT
    p.id               AS id_producto,
    p.marca,
    p.modelo,
    p.color,
    p.descripcion,
    p.precio_lista,
    COALESCE(i.cantidad, 0) AS stock
  FROM productos p
  JOIN inventario i ON i.id_producto = p.id
  WHERE i.id_sucursal = ?
    AND TRIM(LOWER(i.estatus)) = 'disponible'
    AND COALESCE(i.cantidad, 0) > 0
    AND p.tipo_producto = 'Accesorio'
  ORDER BY p.marca, p.descripcion, p.color
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo '<option value="">Error SQL</option>';
  exit;
}
$stmt->bind_param("i", $id_sucursal);
$stmt->execute();
$res = $stmt->get_result();

$out = '<option value="">Seleccione accesorio...</option>';

while ($r = $res->fetch_assoc()) {
  $desc = trim((string)($r['descripcion'] ?? ''));
  if ($desc === '') { // respaldo por si no capturaron descripción
    $desc = (string)($r['modelo'] ?? '');
  }
  $desc = short($desc, 80);

  $color = trim((string)($r['color'] ?? ''));
  $colorTxt = $color !== '' ? ' ('.$color.')' : '';

  // Texto visible en el combo: Marca — Descripción (Color) — Stock — Precio
  $txt = sprintf(
    '%s — %s%s — Stock:%d — $%0.2f',
    (string)$r['marca'],
    $desc,
    $colorTxt,
    (int)$r['stock'],
    (float)$r['precio_lista']
  );

  // data-* para que tu JS tome precio/stock
  $out .= '<option value="'.(int)$r['id_producto'].'"'
       .  ' data-precio="'.htmlspecialchars((string)$r['precio_lista'], ENT_QUOTES, 'UTF-8').'"'
       .  ' data-stock="'.(int)$r['stock'].'">'
       .  htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')
       .  '</option>';
}

if ($res->num_rows === 0) {
  $out = '<option value="">Sin accesorios disponibles</option>';
}

echo $out;
