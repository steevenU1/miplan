<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

$ROL = $_SESSION['rol'] ?? '';
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

// util: semana martes-lunes (rango actual)
function rangoSemanaActual() {
  $hoy = new DateTime();
  $n = (int)$hoy->format('N'); // 1=lun ... 7=dom
  $dif = $n - 2;               // martes=2
  if ($dif < 0) $dif += 7;
  $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
  $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
  return [$inicio, $fin];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: historial_ventas.php");
  exit();
}

$idVenta   = (int)($_POST['id_venta'] ?? 0);
$tag       = trim($_POST['tag'] ?? '');
$precio    = (float)($_POST['precio_venta'] ?? 0);
$enganche  = (float)($_POST['enganche'] ?? 0);
$forma     = trim($_POST['forma_pago_enganche'] ?? '');
$cliente   = trim($_POST['nombre_cliente'] ?? '');
$telefono  = trim($_POST['telefono_cliente'] ?? '');

// normaliza forma de pago
$formasOK = ['','Efectivo','Tarjeta','Mixto','N/A'];
if (!in_array($forma, $formasOK, true)) $forma = '';

if ($idVenta <= 0 || $precio < 0 || $enganche < 0) {
  header("Location: historial_ventas.php?msg=" . urlencode("Datos inválidos."));
  exit();
}

// Carga dueño + tipo + fecha
$st = $conn->prepare("SELECT id_usuario, tipo_venta, fecha_venta FROM ventas WHERE id=? LIMIT 1");
$st->bind_param("i", $idVenta);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  header("Location: historial_ventas.php?msg=" . urlencode("Venta no encontrada."));
  exit();
}

$idDueno     = (int)$row['id_usuario'];
$tipoVenta   = (string)$row['tipo_venta'];
$fechaVenta  = new DateTime($row['fecha_venta']);

// Permisos: Admin cualquiera; Ejecutivo/Gerente solo propias
$puedeEditar = false;
if ($ROL === 'Admin') {
  $puedeEditar = true;
} elseif (in_array($ROL, ['Ejecutivo','Gerente']) && $idDueno === $idUsuario) {
  $puedeEditar = true;
}
if (!$puedeEditar) {
  header("Location: historial_ventas.php?msg=" . urlencode("No puedes editar esta venta."));
  exit();
}

// Ventana: solo semana actual
list($iniSemana, $finSemana) = rangoSemanaActual();
if ($fechaVenta < $iniSemana || $fechaVenta > $finSemana) {
  header("Location: historial_ventas.php?msg=" . urlencode("Solo puedes editar ventas de la semana actual."));
  exit();
}

// Regla TAG: requerido salvo Contado
if ($tipoVenta !== 'Contado' && $tag === '') {
  header("Location: historial_ventas.php?msg=" . urlencode("El TAG es obligatorio para este tipo de venta."));
  exit();
}

// UPDATE solo campos permitidos
$sql = "UPDATE ventas
        SET tag=?, precio_venta=?, enganche=?, forma_pago_enganche=?,
            nombre_cliente=?, telefono_cliente=?
        WHERE id=?";
$st = $conn->prepare($sql);
$st->bind_param("sddsssi", $tag, $precio, $enganche, $forma, $cliente, $telefono, $idVenta);
$st->execute();
$st->close();

header("Location: historial_ventas.php?msg=" . urlencode("Venta #$idVenta actualizada."));
exit();
