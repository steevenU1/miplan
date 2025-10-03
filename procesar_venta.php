<?php
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

/* ========================
   Config / Constantes
======================== */
const GERENTE_COMISION_REGULAR_CAPTURA = 25.0;

/* === BASE_DIR fijo a la carpeta uploads junto a este archivo === */
function fixed_uploads_base_dir(): string {
  $base = __DIR__ . '/uploads';              // <- AQUÍ anclamos
  if (!is_dir($base)) { @mkdir($base, 0777, true); }
  return $base;
}

/* === Config de uploads (sin adivinar otras ubicaciones) === */
function cfg_uploads(): array {
  return [
    'BASE_DIR' => fixed_uploads_base_dir(),   // absoluto en disco
    'BASE_URL' => '/uploads',                 // URL pública (ajústala si tu app corre en subcarpeta)
    'VENTAS' => [
      'IDENTIFICACIONES_DIR' => 'ventas/identificaciones',
      'CONTRATOS_DIR'        => 'ventas/contratos',
      'THUMBS_DIR'           => 'ventas/thumbs',
      'LOG_FILE'             => 'ventas/upload_debug.log', // opcional
    ],
    'MAX_SIZE_BYTES' => 5 * 1024 * 1024,
    'ALLOW_PDF_IN_CONTRACT' => true,
  ];
}

/* ========================
   Helpers
======================== */

/** Verifica si existe una columna en la tabla dada */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/** Detecta la columna de tipo de producto */
$colTipoProd = columnExists($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';

/** Comisión por tramo SIN cuota (LUGA) para ejecutivos */
function comisionTramoSinCuota(float $precio): float {
  if ($precio >= 1     && $precio <= 3500) return 75.0;
  if ($precio >= 3501  && $precio <= 5500) return 100.0;
  if ($precio >= 5501)                     return 150.0;
  return 0.0;
}

/** Normaliza texto */
function norm(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8'); else $s = strtolower($s);
  $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  if ($t !== false) $s = strtolower($t);
  return preg_replace('/[^a-z0-9]+/', '', $s);
}

/** Detecta si el producto es MiFi/Módem */
function esMiFiModem(array $row): bool {
  $candidatos = [];
  $candidatos[] = isset($row['tipo_raw']) ? (string)$row['tipo_raw'] : '';
  foreach (['nombre_comercial','subtipo','descripcion','modelo'] as $k) {
    if (isset($row[$k])) $candidatos[] = (string)$row[$k];
  }
  $joined = norm(implode(' ', $candidatos));
  foreach (['modem','mifi','hotspot','router','cpe','pocketwifi'] as $n) {
    if (strpos($joined, $n) !== false) return true;
  }
  return false;
}

/** Comisión regular en CAPTURA */
function calcularComisionRegularCaptura(string $rolVendedor, string $tipoVenta, bool $esCombo, float $precioLista, bool $esMiFi = false): float {
  if ($rolVendedor === 'Gerente') return GERENTE_COMISION_REGULAR_CAPTURA;
  if ($tipoVenta === 'Financiamiento+Combo') {
    if ($esCombo) return 75.0;
    if ($esMiFi)  return 50.0;
    return comisionTramoSinCuota($precioLista);
  }
  if ($esMiFi) return 50.0;
  return comisionTramoSinCuota($precioLista);
}

/** Comisión especial por producto según catálogo */
function obtenerComisionEspecial(int $id_producto, mysqli $conn): float {
  $hoy = date('Y-m-d');
  $stmt = $conn->prepare("
    SELECT marca, modelo, capacidad, ".$GLOBALS['colTipoProd']." AS tipo_raw,
           nombre_comercial, subtipo, descripcion
    FROM productos WHERE id=?
  ");
  $stmt->bind_param("i", $id_producto);
  $stmt->execute();
  $prod = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$prod) return 0.0;

  $stmt2 = $conn->prepare("
    SELECT monto
    FROM comisiones_especiales
    WHERE marca=? AND modelo=? AND (capacidad=? OR capacidad='' OR capacidad IS NULL)
      AND fecha_inicio <= ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
      AND activo=1
    ORDER BY fecha_inicio DESC
    LIMIT 1
  ");
  $stmt2->bind_param("sssss", $prod['marca'], $prod['modelo'], $prod['capacidad'], $hoy, $hoy);
  $stmt2->execute();
  $res = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();

  return (float)($res['monto'] ?? 0);
}

/** Verifica inventario disponible en la sucursal seleccionada */
function validarInventario(mysqli $conn, int $id_inv, int $id_sucursal): bool {
  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM inventario
    WHERE id=? AND estatus='Disponible' AND id_sucursal=?
  ");
  $stmt->bind_param("ii", $id_inv, $id_sucursal);
  $stmt->execute();
  $stmt->bind_result($ok);
  $stmt->fetch();
  $stmt->close();
  return (int)$ok > 0;
}

/** Log sencillo de subida (opcional) */
function log_upload(array $cfg, string $line): void {
  $logRel = $cfg['VENTAS']['LOG_FILE'] ?? null;
  if (!$logRel) return;
  $baseDir = rtrim($cfg['BASE_DIR'], '/\\');
  $logAbs  = $baseDir . '/' . trim($logRel, '/\\');
  @mkdir(dirname($logAbs), 0777, true);
  @file_put_contents($logAbs, '['.date('Y-m-d H:i:s')."] $line\n", FILE_APPEND);
}

/**
 * Subir archivo con validaciones y devolver URL pública (o '' si no hay archivo).
 * $tipo: 'identificacion' | 'contrato'
 */
function subirArchivoVenta(string $tipo, int $idVenta, array $file, array $cfgUploads): string {
  if (!filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)) {
    throw new RuntimeException("Subida deshabilitada (php.ini file_uploads=0).");
  }

  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return '';
  if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Error al subir $tipo (código ".$file['error'].").");
  }

  $max = (int)($cfgUploads['MAX_SIZE_BYTES'] ?? (5*1024*1024));
  if ($file['size'] > $max) {
    throw new RuntimeException(ucfirst($tipo).": archivo excede ".round($max/1048576,2)." MB.");
  }

  $mime = '';
  if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
  }
  if (!$mime) {
    $extn = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $map  = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','pdf'=>'application/pdf'];
    $mime = $map[$extn] ?? '';
  }

  if ($tipo === 'identificacion') {
    $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($allow[$mime])) throw new RuntimeException("Identificación: formato no permitido ($mime).");
    $ext = $allow[$mime];
    $subdir = $cfgUploads['VENTAS']['IDENTIFICACIONES_DIR'] ?? 'ventas/identificaciones';
    $nombre = "venta_{$idVenta}.".$ext;
  } else if ($tipo === 'contrato') {
    $allowPdf = (bool)($cfgUploads['ALLOW_PDF_IN_CONTRACT'] ?? true);
    $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','image/gif'=>'gif'];
    if ($mime === 'application/pdf' && !$allowPdf) throw new RuntimeException("Contrato: PDF no permitido.");
    if (!isset($allow[$mime])) throw new RuntimeException("Contrato: formato no permitido ($mime).");
    $ext = $allow[$mime];
    $subdir = $cfgUploads['VENTAS']['CONTRATOS_DIR'] ?? 'ventas/contratos';
    $nombre = "venta_{$idVenta}.".$ext;
  } else {
    throw new RuntimeException("Tipo de archivo inválido.");
  }

  // Directorios (SIEMPRE dentro de __DIR__/uploads)
  $baseDir = rtrim((string)$cfgUploads['BASE_DIR'], '/\\');
  if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);
  if (!is_writable($baseDir)) throw new RuntimeException("Carpeta base no escribible: $baseDir");

  $destDir = $baseDir . '/' . trim($subdir,'/\\');
  if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
  if (!is_writable($destDir)) throw new RuntimeException("Carpeta destino no escribible: $destDir");

  $destAbs = $destDir . '/' . $nombre;
  log_upload($cfgUploads, "[$tipo] tmp={$file['tmp_name']} -> $destAbs (mime=$mime, size={$file['size']})");

  if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    // por si el entorno local falla con move_uploaded_file
    if (!@rename($file['tmp_name'], $destAbs)) {
      throw new RuntimeException("No se pudo guardar el archivo de $tipo en $destAbs.");
    }
  }

  // URL pública
  $baseUrl = rtrim((string)($cfgUploads['BASE_URL'] ?? '/uploads'), '/');
  $rutaRel = trim($subdir,'/') . '/' . $nombre;
  return $baseUrl . '/' . $rutaRel;
}

/**
 * Registra un renglón de venta (principal o combo)
 */
function venderEquipo(
  mysqli $conn,
  int $id_venta,
  int $id_inventario,
  bool $esCombo,
  string $rolVendedor,
  string $tipoVenta,
  bool $tieneEsCombo
): float {

  $col = $GLOBALS['colTipoProd'];

  $sql = "
    SELECT i.id_producto,
           p.imei1,
           p.precio_lista,
           p.`$col` AS tipo_raw,
           p.nombre_comercial,
           p.subtipo,
           p.descripcion,
           p.modelo
    FROM inventario i
    INNER JOIN productos p ON i.id_producto = p.id
    WHERE i.id=? AND i.estatus='Disponible'
    LIMIT 1
  ";
  $stmtProd = $conn->prepare($sql);
  $stmtProd->bind_param("i", $id_inventario);
  $stmtProd->execute();
  $row = $stmtProd->get_result()->fetch_assoc();
  $stmtProd->close();
  if (!$row) { throw new RuntimeException("Equipo $id_inventario no disponible."); }

  $precioL = (float)$row['precio_lista'];
  $esMiFi  = esMiFiModem($row);

  $comReg = calcularComisionRegularCaptura($rolVendedor, $tipoVenta, $esCombo, $precioL, $esMiFi);
  if ($rolVendedor === 'Gerente' && (float)$comReg !== GERENTE_COMISION_REGULAR_CAPTURA) {
    $comReg = GERENTE_COMISION_REGULAR_CAPTURA;
  }

  $comEsp = obtenerComisionEspecial((int)$row['id_producto'], $conn);
  $comTot = (float)$comReg + (float)$comEsp;

  if ($tieneEsCombo) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario, comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $esComboInt = $esCombo ? 1 : 0;
    $stmtD->bind_param("iiisdddd", $id_venta, $row['id_producto'], $esComboInt, $row['imei1'], $precioL, $comTot, $comReg, $comEsp);
  } else {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario, comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param("iisdddd", $id_venta, $row['id_producto'], $row['imei1'], $precioL, $comTot, $comReg, $comEsp);
  }

  $stmtD->execute();
  $stmtD->close();

  $stmtU = $conn->prepare("UPDATE inventario SET estatus='Vendido' WHERE id=?");
  $stmtU->bind_param("i", $id_inventario);
  $stmtU->execute();
  $stmtU->close();

  return $comTot;
}

/* ========================
   1) Recibir + Validar
======================== */
$id_usuario   = (int)($_SESSION['id_usuario']);
$rol_usuario  = (string)($_SESSION['rol'] ?? 'Ejecutivo');
$id_sucursal  = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : (int)$_SESSION['id_sucursal'];

$tag                 = trim($_POST['tag'] ?? '');
$nombre_cliente      = trim($_POST['nombre_cliente'] ?? '');
$telefono_cliente    = trim($_POST['telefono_cliente'] ?? '');
$tipo_venta          = $_POST['tipo_venta'] ?? '';
$equipo1             = (int)($_POST['equipo1'] ?? 0);
$equipo2             = isset($_POST['equipo2']) ? (int)$_POST['equipo2'] : 0;
$precio_venta        = (float)($_POST['precio_venta'] ?? 0);
$enganche            = (float)($_POST['enganche'] ?? 0);
$forma_pago_enganche = $_POST['forma_pago_enganche'] ?? '';
$enganche_efectivo   = (float)($_POST['enganche_efectivo'] ?? 0);
$enganche_tarjeta    = (float)($_POST['enganche_tarjeta'] ?? 0);
$plazo_semanas       = (int)($_POST['plazo_semanas'] ?? 0);
$financiera          = $_POST['financiera'] ?? '';
$comentarios         = trim($_POST['comentarios'] ?? '');

// Referencias
$referencia1_nombre   = trim($_POST['referencia1_nombre'] ?? '');
$referencia1_telefono = trim($_POST['referencia1_telefono'] ?? '');
$referencia2_nombre   = trim($_POST['referencia2_nombre'] ?? '');
$referencia2_telefono = trim($_POST['referencia2_telefono'] ?? '');

// Archivos
$archivoIdent = $_FILES['identificacion'] ?? null;
$archivoCtrto = $_FILES['contrato'] ?? null;

$esFin = in_array($tipo_venta, ['Financiamiento','Financiamiento+Combo'], true);
$errores = [];

// Reglas básicas
if (!$tipo_venta)           $errores[] = "Selecciona el tipo de venta.";
if ($precio_venta <= 0)     $errores[] = "El precio de venta debe ser mayor a 0.";
if (!$forma_pago_enganche)  $errores[] = "Selecciona la forma de pago.";
if ($equipo1 <= 0)          $errores[] = "Selecciona el equipo principal.";

// Reglas para Financiamiento / Combo
if ($esFin) {
  if ($nombre_cliente === '')                     $errores[] = "Nombre del cliente es obligatorio.";
  if ($telefono_cliente === '' || !preg_match('/^\d{10}$/', $telefono_cliente)) $errores[] = "Teléfono del cliente debe tener 10 dígitos.";
  if ($tag === '')                                $errores[] = "TAG (ID del crédito) es obligatorio.";
  if ($enganche < 0)                              $errores[] = "El enganche no puede ser negativo (puede ser 0).";
  if ($plazo_semanas <= 0)                        $errores[] = "El plazo en semanas debe ser mayor a 0.";
  if ($financiera === '')                         $errores[] = "Selecciona una financiera (no puede ser N/A).";

  if ($forma_pago_enganche === 'Mixto') {
    if ($enganche_efectivo <= 0 && $enganche_tarjeta <= 0) $errores[] = "En pago Mixto, al menos uno de los montos debe ser > 0.";
    if (round($enganche_efectivo + $enganche_tarjeta, 2) !== round($enganche, 2)) $errores[] = "Efectivo + Tarjeta debe ser igual al Enganche.";
  }

  // referencias
  if ($referencia1_nombre === '')                          $errores[] = "Referencia 1: nombre obligatorio.";
  if (!preg_match('/^\d{10}$/', $referencia1_telefono))    $errores[] = "Referencia 1: teléfono de 10 dígitos.";
  if ($referencia2_nombre === '')                          $errores[] = "Referencia 2: nombre obligatorio.";
  if (!preg_match('/^\d{10}$/', $referencia2_telefono))    $errores[] = "Referencia 2: teléfono de 10 dígitos.";

  // archivos (existencia)
  if (empty($archivoIdent) || (int)($archivoIdent['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $errores[] = "Sube la imagen de la identificación (obligatoria en financiamiento).";
  }
  if (empty($archivoCtrto) || (int)($archivoCtrto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $errores[] = "Sube el contrato (PDF o imagen) obligatorio en financiamiento.";
  }
} else {
  // Contado
  $tag = '';
  $plazo_semanas = 0;
  $financiera = 'N/A';
  $enganche_efectivo = 0;
  $enganche_tarjeta  = 0;

  if ($referencia1_telefono !== '' && !preg_match('/^\d{10}$/', $referencia1_telefono)) {
    $errores[] = "Referencia 1: teléfono debe tener 10 dígitos.";
  }
  if ($referencia2_telefono !== '' && !preg_match('/^\d{10}$/', $referencia2_telefono)) {
    $errores[] = "Referencia 2: teléfono debe tener 10 dígitos.";
  }
}

// Validar inventarios
if ($equipo1 && !validarInventario($conn, $equipo1, $id_sucursal)) {
  $errores[] = "El equipo principal no está disponible en la sucursal seleccionada.";
}
if ($tipo_venta === 'Financiamiento+Combo') {
  if ($equipo2 <= 0) {
    $errores[] = "Selecciona el equipo combo.";
  } else if (!validarInventario($conn, $equipo2, $id_sucursal)) {
    $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
  }
}

if ($errores) {
  header("Location: nueva_venta.php?err=" . urlencode(implode(' ', $errores)));
  exit();
}

/* ========================
   2) Insertar Venta (TX) con columnas dinámicas
======================== */
$uploadsCfg = cfg_uploads();

try {
  $conn->begin_transaction();

  // ==== Construcción dinámica del INSERT en ventas
  $ventasCols  = [];
  $ventasTypes = '';
  $ventasVals  = [];

  $push = function(string $col, string $type, $val) use (&$ventasCols, &$ventasTypes, &$ventasVals) {
    $ventasCols[] = $col;
    $ventasTypes .= $type;
    $ventasVals[] = $val;
  };

  foreach ([
    'tag'                   => ['s', $tag],
    'nombre_cliente'        => ['s', $nombre_cliente],
    'telefono_cliente'      => ['s', $telefono_cliente],
    'tipo_venta'            => ['s', $tipo_venta],
    'precio_venta'          => ['d', $precio_venta],
    'id_usuario'            => ['i', $id_usuario],
    'id_sucursal'           => ['i', $id_sucursal],
    'comision'              => ['d', 0.0],
    'enganche'              => ['d', $enganche],
    'forma_pago_enganche'   => ['s', $forma_pago_enganche],
    'enganche_efectivo'     => ['d', $enganche_efectivo],
    'enganche_tarjeta'      => ['d', $enganche_tarjeta],
    'plazo_semanas'         => ['i', $plazo_semanas],
    'financiera'            => ['s', $financiera],
    'comentarios'           => ['s', $comentarios],
    'referencia1_nombre'    => ['s', $referencia1_nombre],
    'referencia1_telefono'  => ['s', $referencia1_telefono],
    'referencia2_nombre'    => ['s', $referencia2_nombre],
    'referencia2_telefono'  => ['s', $referencia2_telefono],
    'imagen_identificacion' => ['s', null],
    'imagen_contrato'       => ['s', null],
  ] as $col => [$type, $val]) {
    if (columnExists($conn, 'ventas', $col)) {
      $push($col, $type, $val);
    }
  }

  if (empty($ventasCols)) {
    throw new RuntimeException("No hay columnas disponibles para insertar la venta (revisa la tabla ventas).");
  }

  $marks    = implode(',', array_fill(0, count($ventasCols), '?'));
  $sqlVenta = "INSERT INTO ventas (".implode(',', $ventasCols).") VALUES ($marks)";
  $stmtVenta = $conn->prepare($sqlVenta);
  if (!$stmtVenta) throw new RuntimeException("Error SQL en ventas: ".$conn->error);

  $stmtVenta->bind_param($ventasTypes, ...$ventasVals);
  if (!$stmtVenta->execute()) throw new RuntimeException("No se pudo insertar la venta: ".$stmtVenta->error);
  $id_venta = (int)$stmtVenta->insert_id;
  $stmtVenta->close();

  // === Subida de archivos (si aplica)
  $rutaIdent   = '';
  $rutaCtrto   = '';
  $hasColIdent = columnExists($conn, 'ventas', 'imagen_identificacion');
  $hasColCtrto = columnExists($conn, 'ventas', 'imagen_contrato');

  if (!empty($archivoIdent) && (int)$archivoIdent['error'] !== UPLOAD_ERR_NO_FILE) {
    $rutaIdent = subirArchivoVenta('identificacion', $id_venta, $archivoIdent, $uploadsCfg);
  }
  if (!empty($archivoCtrto) && (int)$archivoCtrto['error'] !== UPLOAD_ERR_NO_FILE) {
    $rutaCtrto = subirArchivoVenta('contrato', $id_venta, $archivoCtrto, $uploadsCfg);
  }

  if ($esFin) {
    if (($hasColIdent && $rutaIdent === '') || ($hasColCtrto && $rutaCtrto === '')) {
      throw new RuntimeException("Faltan archivos obligatorios (identificación/contrato).");
    }
  }

  if (($hasColIdent && $rutaIdent !== '') || ($hasColCtrto && $rutaCtrto !== '')) {
    $set    = [];
    $typesF = '';
    $valsF  = [];
    if ($hasColIdent) { $set[] = "imagen_identificacion=?"; $typesF .= 's'; $valsF[] = $rutaIdent; }
    if ($hasColCtrto) { $set[] = "imagen_contrato=?";       $typesF .= 's'; $valsF[] = $rutaCtrto; }
    $typesF .= 'i'; $valsF[] = $id_venta;

    $stmtFiles = $conn->prepare("UPDATE ventas SET ".implode(',', $set)." WHERE id=?");
    $stmtFiles->bind_param($typesF, ...$valsF);
    $stmtFiles->execute();
    $stmtFiles->close();
  }

  /* ========================
     3) Registrar equipos
  ======================= */
  $tieneEsCombo = columnExists($conn, 'detalle_venta', 'es_combo');
  $totalComision  = 0.0;
  $totalComision += venderEquipo($conn, $id_venta, $equipo1, false, $rol_usuario, $tipo_venta, $tieneEsCombo);

  if ($tipo_venta === 'Financiamiento+Combo' && $equipo2) {
    $totalComision += venderEquipo($conn, $id_venta, $equipo2, true, $rol_usuario, $tipo_venta, $tieneEsCombo);
  }

  /* ========================
     4) Actualizar venta (comisión)
  ======================= */
  if (columnExists($conn, 'ventas', 'comision')) {
    $stmtUpd = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
    $stmtUpd->bind_param("di", $totalComision, $id_venta);
    $stmtUpd->execute();
    $stmtUpd->close();
  }

  $conn->commit();

  header("Location: historial_ventas.php?msg=" . urlencode("Venta #$id_venta registrada. Comisión $" . number_format($totalComision,2)));
  exit();
} catch (Throwable $e) {
  $conn->rollback();

  // Limpieza best-effort
  try {
    $cleanup = function(?string $ruta) use ($uploadsCfg) {
      if (!$ruta) return;
      $path    = preg_replace('#^/+#','', parse_url($ruta, PHP_URL_PATH) ?? '');
      $baseDir = rtrim((string)$uploadsCfg['BASE_DIR'], '/\\');
      $baseUrl = rtrim((string)($uploadsCfg['BASE_URL'] ?? '/uploads'), '/');
      $rel     = ltrim(str_replace($baseUrl, '', $path), '/\\');
      $abs     = $baseDir . '/' . $rel;
      if (is_file($abs)) @unlink($abs);
    };
    if (isset($rutaIdent)) $cleanup($rutaIdent);
    if (isset($rutaCtrto)) $cleanup($rutaCtrto);
  } catch (Throwable $__) {}

  header("Location: nueva_venta.php?err=" . urlencode("Error al registrar la venta: " . $e->getMessage()));
  exit();
}
