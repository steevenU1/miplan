<?php
// top_productos.php — Top 5 con RAM y Almacenamiento
include 'db.php';

$rango = $_GET['rango'] ?? 'historico';

// Sanitizar rango a valores esperados
if (!in_array($rango, ['semana','mes','historico'], true)) {
  $rango = 'historico';
}

$where = "";
if ($rango === 'semana') {
    $inicio = date('Y-m-d', strtotime('last tuesday'));
    $fin    = date('Y-m-d', strtotime('next monday'));
    $where  = "WHERE v.fecha_venta BETWEEN '$inicio' AND '$fin'";
} elseif ($rango === 'mes') {
    $inicio = date('Y-m-01');
    $fin    = date('Y-m-t');
    $where  = "WHERE v.fecha_venta BETWEEN '$inicio' AND '$fin'";
}

// Consulta de top vendidos (incluye RAM y Almacenamiento)
$sql = "
    SELECT
        p.marca,
        p.modelo,
        p.ram,
        p.capacidad AS almacenamiento,
        COUNT(*) AS vendidos
    FROM detalle_venta dv
    INNER JOIN productos p ON p.id = dv.id_producto
    INNER JOIN ventas v    ON v.id = dv.id_venta
    $where
    GROUP BY p.marca, p.modelo, p.ram, p.capacidad
    ORDER BY vendidos DESC
    LIMIT 5
";

$result = $conn->query($sql);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Mostrar tabla
echo '<table class="table table-sm table-bordered mb-0">';
echo '<thead class="table-light"><tr>
        <th>#</th>
        <th>Equipo</th>
        <th>RAM</th>
        <th>Almacenamiento</th>
        <th>Vendidos</th>
      </tr></thead><tbody>';

$i = 1;
if ($result) {
  while ($row = $result->fetch_assoc()) {
      $equipo = trim(($row['marca'] ?? '') . ' ' . ($row['modelo'] ?? ''));
      $ram = $row['ram'] ?? '-';
      $alm = $row['almacenamiento'] ?? '-';
      echo "<tr>
          <td>". $i ."</td>
          <td>". h($equipo) ."</td>
          <td>". h($ram) ."</td>
          <td>". h($alm) ."</td>
          <td><b>". (int)$row['vendidos'] ."</b></td>
      </tr>";
      $i++;
  }
}

if ($i === 1) {
    echo "<tr><td colspan='5' class='text-center text-muted'>No hay datos</td></tr>";
}
echo '</tbody></table>';

