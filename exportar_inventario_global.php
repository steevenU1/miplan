<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: 403.php");
    exit();
}
$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona'];
if (!in_array($ROL, $ALLOWED, true)) {
    header("Location: 403.php");
    exit();
}

include 'db.php';

/* ===== Filtros (mismos que la vista) ===== */
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';

/* ===== Obtener columnas reales de productos (en orden) ===== */
$cols = [];
$colsRes = $conn->query("SHOW COLUMNS FROM productos");
while ($c = $colsRes->fetch_assoc()) {
    $cols[] = $c['Field'];
}

/* Helpers */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n) { return number_format((float)$n, 2, '.', ''); }
function codigo_fallback_from_row($row) {
    $partes = array_filter([
        $row['tipo_producto'] ?? '',
        $row['marca'] ?? '',
        $row['modelo'] ?? '',
        $row['color'] ?? '',
        $row['capacidad'] ?? ''
    ], fn($x) => $x !== '');
    if (!$partes) return '-';
    $code = strtoupper(implode('-', $partes));
    return preg_replace('/\s+/', '', $code);
}

/* ===== Consulta ===== */
$sql = "
    SELECT
        i.id AS id_inventario,
        s.nombre AS sucursal,
        p.*,  -- ✅ todas las columnas de productos
        COALESCE(p.costo_con_iva, p.costo, 0) AS costo_mostrar,       -- para profit si lo necesitas aparte
        (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit, -- ✅ profit con costo c/IVA
        i.estatus AS estatus_inventario,
        i.fecha_ingreso,
        TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    INNER JOIN sucursales s ON s.id = i.id_sucursal
    WHERE i.estatus IN ('Disponible','En tránsito')
";

$params = [];
$types  = "";

if ($filtroSucursal !== '') {
    $sql .= " AND s.id = ?";
    $params[] = (int)$filtroSucursal;
    $types .= "i";
}
if ($filtroImei !== '') {
    $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
    $like = "%$filtroImei%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}
if ($filtroEstatus !== '') {
    $sql .= " AND i.estatus = ?";
    $params[] = $filtroEstatus;
    $types .= "s";
}
if ($filtroAntiguedad == '<30') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
    $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
    $sql .= " AND p.precio_lista >= ?";
    $params[] = (float)$filtroPrecioMin;
    $types .= "d";
}
if ($filtroPrecioMax !== '') {
    $sql .= " AND p.precio_lista <= ?";
    $params[] = (float)$filtroPrecioMax;
    $types .= "d";
}

$sql .= " ORDER BY s.nombre, i.fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/* ===== Cabeceras: Excel abre HTML como libro ===== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=inventario_global.xls");
header("Pragma: no-cache");
header("Expires: 0");

// BOM para UTF-8
echo "\xEF\xBB\xBF";

/* ===== Render ===== */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";

/* Encabezados */
echo "<tr style='background:#222;color:#fff;font-weight:bold'>";
echo "<td>ID Inventario</td>";
echo "<td>Sucursal</td>";
foreach ($cols as $col) {
    // Etiquetas amigables opcionales (puedes dejarlas iguales)
    if ($col === 'costo_con_iva') {
        echo "<td>Costo c/IVA</td>";
    } else {
        echo "<td>".h($col)."</td>";
    }
}
echo "<td>Profit</td>";
echo "<td>Estatus Inventario</td>";
echo "<td>Fecha Ingreso</td>";
echo "<td>Antigüedad (días)</td>";
echo "</tr>";

/* Filas */
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>".h($row['id_inventario'])."</td>";
    echo "<td>".h($row['sucursal'])."</td>";

    foreach ($cols as $col) {
        $val = $row[$col] ?? '';

        // Ajustes especiales por columna
        if ($col === 'codigo_producto') {
            if ($val === '' || $val === null) {
                $val = codigo_fallback_from_row($row);
            }
            echo "<td>".h($val)."</td>";
            continue;
        }
        if ($col === 'imei1' || $col === 'imei2') {
            // Prefijo ' para evitar truncamiento en Excel
            echo "<td>'".h($val === '' ? '-' : $val)."'</td>";
            continue;
        }
        if (in_array($col, ['costo','costo_con_iva','precio_lista'], true)) {
            echo "<td>".nf($val)."</td>";
            continue;
        }

        echo "<td>".h($val)."</td>";
    }

    // Extra: Profit calculado con costo_con_iva (fallback a costo)
    echo "<td>".nf($row['profit'])."</td>";
    echo "<td>".h($row['estatus_inventario'])."</td>";
    echo "<td>".h($row['fecha_ingreso'])."</td>";
    echo "<td>".h($row['antiguedad_dias'])."</td>";
    echo "</tr>";
}

echo "</table></body></html>";

$stmt->close();
$conn->close();
