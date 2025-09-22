<?php
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

/* ========================
   Config / Constantes
======================== */
const GERENTE_COMISION_REGULAR_CAPTURA = 25.0;

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

/** Normaliza texto: minúsculas, sin acentos, sin separadores (mi-fi -> mifi) */
function norm(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8'); else $s = strtolower($s);
  $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  if ($t !== false) $s = strtolower($t);
  return preg_replace('/[^a-z0-9]+/', '', $s);
}

/** Detecta si el producto es MiFi/Módem usando varias columnas */
function esMiFiModem(array $row): bool {
  $candidatos = [];

  // tipo (tipo o tipo_producto)
  $candidatos[] = isset($row['tipo_raw']) ? (string)$row['tipo_raw'] : '';
  // nombre_comercial, subtipo, descripcion, modelo por si ahí viene "MiFi" o "Módem"
  foreach (['nombre_comercial','subtipo','descripcion','modelo'] as $k) {
    if (isset($row[$k])) $candidatos[] = (string)$row[$k];
  }

  $joined = norm(implode(' ', $candidatos));
  // Palabras clave después de normalizar
  $needles = ['modem','mifi','hotspot','router','cpe','pocketwifi'];
  foreach ($needles as $n) {
    if (strpos($joined, $n) !== false) return true;
  }
  return false;
}

/**
 * Comisión regular en CAPTURA (no aplica cuota aquí)
 * Reglas LUGA:
 * - Gerente vendiendo: SIEMPRE 25 por renglón (principal y combo)
 * - Ejecutivo Financiamiento+Combo:
 *     principal → por tramo precio_lista (salvo MiFi/Módem)
 *     combo     → 75 fijo
 * - Ejecutivo Contado o solo Financiamiento:
 *     por tramo precio_lista, salvo MiFi/Módem = 50
 */
function calcularComisionRegularCaptura(
  string $rolVendedor,         // 'Ejecutivo' | 'Gerente' | ...
  string $tipoVenta,           // 'Contado' | 'Financiamiento' | 'Financiamiento+Combo'
  bool   $esCombo,
  float  $precioLista,
  bool   $esMiFi = false
): float {
  // Gerente vendiendo: 25 por renglón en captura (principal y combo)
  if ($rolVendedor === 'Gerente') {
    return GERENTE_COMISION_REGULAR_CAPTURA;
  }

  // Ejecutivo: Financiamiento + Combo
  if ($tipoVenta === 'Financiamiento+Combo') {
    if ($esCombo) return 75.0;             // combo SIEMPRE 75
    if ($esMiFi)  return 50.0;             // principal MiFi/Módem
    return comisionTramoSinCuota($precioLista);
  }

  // Ejecutivo: Contado o solo Financiamiento
  if ($esMiFi)    return 50.0;             // MiFi/Módem
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

/**
 * Registra un renglón de venta (principal o combo)
 * Inserta es_combo si la columna existe.
 * Devuelve la comisión total del renglón (regular + especial).
 */
function venderEquipo(
  mysqli $conn,
  int $id_venta,
  int $id_inventario,
  bool $esCombo,
  string $rolVendedor,   // 'Ejecutivo' | 'Gerente' ...
  string $tipoVenta,     // 'Contado' | 'Financiamiento' | 'Financiamiento+Combo'
  bool $tieneEsCombo     // si la columna detalle_venta.es_combo existe
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
  $esMiFi  = esMiFiModem($row);  // detección robusta

  // === lógica de captura (SIN cuota) ===
  $comReg = calcularComisionRegularCaptura($rolVendedor, $tipoVenta, $esCombo, $precioL, $esMiFi);

  // Fallback defensivo: si es Gerente, fuerzo $25 aunque algo cambiara arriba
  if ($rolVendedor === 'Gerente' && (float)$comReg !== GERENTE_COMISION_REGULAR_CAPTURA) {
    $comReg = GERENTE_COMISION_REGULAR_CAPTURA;
  }

  $comEsp = obtenerComisionEspecial((int)$row['id_producto'], $conn);
  $comTot = (float)$comReg + (float)$comEsp;

  if ($tieneEsCombo) {
    // Con es_combo
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario, comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $esComboInt = $esCombo ? 1 : 0;
    $stmtD->bind_param(
      "iiisdddd",
      $id_venta,
      $row['id_producto'],
      $esComboInt,
      $row['imei1'],
      $precioL,
      $comTot,
      $comReg,
      $comEsp
    );
  } else {
    // Sin es_combo (compatibilidad)
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario, comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iisdddd",
      $id_venta,
      $row['id_producto'],
      $row['imei1'],
      $precioL,
      $comTot,
      $comReg,
      $comEsp
    );
  }

  $stmtD->execute();
  $stmtD->close();

  // inventario -> Vendido
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

$esFin = in_array($tipo_venta, ['Financiamiento','Financiamiento+Combo'], true);
$errores = [];

// Reglas
if (!$tipo_venta)                                 $errores[] = "Selecciona el tipo de venta.";
if ($precio_venta <= 0)                           $errores[] = "El precio de venta debe ser mayor a 0.";
if (!$forma_pago_enganche)                        $errores[] = "Selecciona la forma de pago.";
if ($equipo1 <= 0)                                $errores[] = "Selecciona el equipo principal.";

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
} else {
  // Contado: normaliza campos
  $tag = '';
  $plazo_semanas = 0;
  $financiera = 'N/A';
  $enganche_efectivo = 0;
  $enganche_tarjeta  = 0;
}

// Validar inventarios disponibles en la sucursal seleccionada
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
   2) Insertar Venta (TX)
======================== */
try {
  $conn->begin_transaction();

  $sqlVenta = "INSERT INTO ventas
    (tag, nombre_cliente, telefono_cliente, tipo_venta, precio_venta, id_usuario, id_sucursal, comision,
     enganche, forma_pago_enganche, enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera, comentarios)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

  $stmtVenta = $conn->prepare($sqlVenta);
  $comisionInicial = 0.0;

  // tipos: s s s s d i i d d s d d i s s
  $stmtVenta->bind_param(
    "ssssdiiddsddiss",
    $tag,
    $nombre_cliente,
    $telefono_cliente,
    $tipo_venta,
    $precio_venta,
    $id_usuario,
    $id_sucursal,
    $comisionInicial,
    $enganche,
    $forma_pago_enganche,
    $enganche_efectivo,
    $enganche_tarjeta,
    $plazo_semanas,
    $financiera,
    $comentarios
  );
  $stmtVenta->execute();
  $id_venta = (int)$stmtVenta->insert_id;
  $stmtVenta->close();

  /* ========================
     3) Registrar equipos
     (CAPTURA sin cuota)
  ======================= */
  $tieneEsCombo = columnExists($conn, 'detalle_venta', 'es_combo');

  $totalComision = 0.0;
  // Principal
  $totalComision += venderEquipo($conn, $id_venta, $equipo1, false, $rol_usuario, $tipo_venta, $tieneEsCombo);

  // Combo (si aplica)
  if ($tipo_venta === 'Financiamiento+Combo' && $equipo2) {
    $totalComision += venderEquipo($conn, $id_venta, $equipo2, true, $rol_usuario, $tipo_venta, $tieneEsCombo);
  }

  /* ========================
     4) Actualizar venta
  ======================= */
  $stmtUpd = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
  $stmtUpd->bind_param("di", $totalComision, $id_venta);
  $stmtUpd->execute();
  $stmtUpd->close();

  $conn->commit();

  header("Location: historial_ventas.php?msg=" . urlencode("Venta #$id_venta registrada. Comisión $" . number_format($totalComision,2)));
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  header("Location: nueva_venta.php?err=" . urlencode("Error al registrar la venta: " . $e->getMessage()));
  exit();
}
