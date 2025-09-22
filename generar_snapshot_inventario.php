<?php
// generar_snapshot_inventario.php  (sin validación de token)
// - Genera snapshot diario de inventario
// - Auto-evoluciona columnas/índices consultando INFORMATION_SCHEMA (compatible MariaDB)
// - Aplica retención configurable

session_start();
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

// ===== Parámetros =====
$fecha         = $_GET['fecha'] ?? date('Y-m-d');     // día del snapshot
$retencionDias = (int)($_GET['retencion'] ?? 15);
if ($retencionDias < 1) $retencionDias = 15;

// ==== Helpers de migración (sin IF NOT EXISTS) ====
function colExists(mysqli $c, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $c->prepare($sql);
  $st->bind_param('ss', $table, $col);
  $st->execute(); $st->store_result();
  $ok = $st->num_rows > 0; $st->close();
  return $ok;
}
function idxExists(mysqli $c, string $table, string $idx): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1";
  $st = $c->prepare($sql);
  $st->bind_param('ss', $table, $idx);
  $st->execute(); $st->store_result();
  $ok = $st->num_rows > 0; $st->close();
  return $ok;
}
function ensureColumn(mysqli $c, string $table, string $col, string $definition) {
  if (!colExists($c, $table, $col)) {
    if(!$c->query("ALTER TABLE `$table` ADD COLUMN $definition")) {
      throw new Exception("Error agregando columna $col: ".$c->error);
    }
  }
}
function ensureIndex(mysqli $c, string $table, string $name, string $definition) {
  if (!idxExists($c, $table, $name)) {
    if(!$c->query("ALTER TABLE `$table` ADD $definition")) {
      throw new Exception("Error agregando índice $name: ".$c->error);
    }
  }
}

// ===== 1) Crear tabla base si no existe =====
$create = "
CREATE TABLE IF NOT EXISTS inventario_snapshot (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_date DATE NOT NULL,

  id_inventario INT NOT NULL,
  id_producto   INT NULL,
  id_sucursal   INT NOT NULL,

  codigo_producto VARCHAR(50) NULL,
  marca VARCHAR(50) NULL,
  modelo VARCHAR(50) NULL,
  color VARCHAR(30) NULL,
  capacidad VARCHAR(20) NULL,
  imei1 VARCHAR(30) NULL,
  imei2 VARCHAR(30) NULL,

  tipo_producto VARCHAR(20) NULL,
  proveedor VARCHAR(120) NULL,
  costo DECIMAL(10,2) NULL,
  costo_con_iva DECIMAL(10,2) NULL,
  precio_lista DECIMAL(10,2) NULL,
  profit DECIMAL(10,2) NULL,

  estatus VARCHAR(20) NULL,
  fecha_ingreso DATETIME NULL,
  antiguedad_dias INT NULL,

  sucursal_nombre VARCHAR(120) NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if(!$conn->query($create)) { http_response_code(500); exit("Error creando tabla: ".$conn->error); }

// ===== 2) Alinear columnas “nuevas” (no truena si ya existen) =====
try {
  // De productos
  ensureColumn($conn, 'inventario_snapshot', 'ram',               "ram VARCHAR(50) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'subtipo',           "subtipo VARCHAR(50) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'gama',              "gama VARCHAR(50) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'operador',          "operador VARCHAR(50) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'resurtible',        "resurtible VARCHAR(10) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'nombre_comercial',  "nombre_comercial VARCHAR(255) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'compania',          "compania VARCHAR(100) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'financiera',        "financiera VARCHAR(100) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'fecha_lanzamiento', "fecha_lanzamiento DATE NULL");

  // Por si faltan en instalaciones antiguas
  ensureColumn($conn, 'inventario_snapshot', 'id_producto',       "id_producto INT NULL");
  ensureColumn($conn, 'inventario_snapshot', 'codigo_producto',   "codigo_producto VARCHAR(50) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'costo',             "costo DECIMAL(10,2) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'profit',            "profit DECIMAL(10,2) NULL");
  ensureColumn($conn, 'inventario_snapshot', 'sucursal_nombre',   "sucursal_nombre VARCHAR(120) NULL");
} catch (Exception $e) {
  http_response_code(500); exit($e->getMessage());
}

// Índices recomendados
try {
  ensureIndex($conn, 'inventario_snapshot', 'uniq_date_inv', "UNIQUE KEY uniq_date_inv (snapshot_date, id_inventario)");
  ensureIndex($conn, 'inventario_snapshot', 'idx_date',      "KEY idx_date (snapshot_date)");
  ensureIndex($conn, 'inventario_snapshot', 'idx_suc',       "KEY idx_suc (id_sucursal)");
  ensureIndex($conn, 'inventario_snapshot', 'idx_imei1',     "KEY idx_imei1 (imei1)");
  ensureIndex($conn, 'inventario_snapshot', 'idx_codigo',    "KEY idx_codigo (codigo_producto)");
} catch (Exception $e) {
  http_response_code(500); exit($e->getMessage());
}

// ===== 3) Idempotencia: borrar snapshot del día =====
$st = $conn->prepare("DELETE FROM inventario_snapshot WHERE snapshot_date = ?");
$st->bind_param('s', $fecha);
$st->execute(); $st->close();

// ===== 4) Insertar snapshot =====
$sqlInsert = "
INSERT INTO inventario_snapshot (
  snapshot_date, id_inventario, id_sucursal, sucursal_nombre,
  id_producto, codigo_producto,
  marca, modelo, color, ram, capacidad,
  imei1, imei2,
  tipo_producto, subtipo, gama, operador, resurtible,
  nombre_comercial, compania, financiera, fecha_lanzamiento,
  proveedor, costo, costo_con_iva, precio_lista, profit,
  estatus, fecha_ingreso, antiguedad_dias
)
SELECT
  ? AS snapshot_date,
  i.id         AS id_inventario,
  s.id         AS id_sucursal,
  s.nombre     AS sucursal_nombre,

  p.id         AS id_producto,
  p.codigo_producto,

  p.marca, p.modelo, p.color, p.ram, p.capacidad,
  p.imei1, p.imei2,

  p.tipo_producto, p.subtipo, p.gama, p.operador, p.resurtible,
  p.nombre_comercial, p.compania, p.financiera, p.fecha_lanzamiento,

  p.proveedor,
  p.costo,
  p.costo_con_iva,
  p.precio_lista,
  (COALESCE(p.precio_lista,0) - COALESCE(p.costo_con_iva,0)) AS profit,

  i.estatus,
  i.fecha_ingreso,
  DATEDIFF(?, DATE(i.fecha_ingreso)) AS antiguedad_dias
FROM inventario i
JOIN productos  p ON p.id = i.id_producto
JOIN sucursales s ON s.id = i.id_sucursal
";
$st = $conn->prepare($sqlInsert);
$st->bind_param('ss', $fecha, $fecha);
$st->execute();
$insertados = $st->affected_rows;
$st->close();

// ===== 5) Retención =====
$limite = date('Y-m-d', strtotime("-{$retencionDias} days"));
$st = $conn->prepare("DELETE FROM inventario_snapshot WHERE snapshot_date < ?");
$st->bind_param('s', $limite);
$st->execute(); $st->close();

echo "OK: snapshot {$fecha} generado. Filas: {$insertados}. Retención hasta: {$limite}\n";
