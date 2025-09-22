<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

# Includes con fallback
if (file_exists(__DIR__ . '/includes/db.php')) require_once __DIR__ . '/includes/db.php';
else require_once __DIR__ . '/db.php';

$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';

$usuario_id = (int)($_POST['usuario_id'] ?? 0);
if ($usuario_id <= 0) { header("Location: mi_expediente.php?err=Usuario inválido"); exit; }

$puede_editar = in_array($mi_rol, ['Admin','Gerente'], true) || ($usuario_id === $mi_id);
$puede_baja   = in_array($mi_rol, ['Admin','Gerente'], true);
if (!$puede_editar) { header("Location: mi_expediente.php?err=Sin permiso"); exit; }

function norm_date(?string $d): ?string {
  if ($d === null) return null;
  $d = trim($d);
  if ($d === '') return null;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}

# ===== Datos =====
$tel_contacto       = trim($_POST['tel_contacto'] ?? '');
$fecha_nacimiento   = norm_date($_POST['fecha_nacimiento'] ?? null);
$fecha_ingreso      = norm_date($_POST['fecha_ingreso'] ?? null);
$fecha_baja         = $puede_baja ? norm_date($_POST['fecha_baja'] ?? null) : null;
$motivo_baja        = $puede_baja ? (trim($_POST['motivo_baja'] ?? '') ?: null) : null;

$curp   = strtoupper(trim($_POST['curp'] ?? ''));
$nss    = trim($_POST['nss'] ?? '');
$rfc    = strtoupper(trim($_POST['rfc'] ?? ''));
$genero = $_POST['genero'] ?? null;
if ($genero === '' || !in_array($genero, ['M','F','Otro'], true)) { $genero = null; }

$contacto_emergencia = trim($_POST['contacto_emergencia'] ?? '');
$tel_emergencia      = trim($_POST['tel_emergencia'] ?? '');

/* ===== CAMBIO: normalizar CLABE/Tarjeta a solo dígitos antes de validar/guardar ===== */
$clabe_input = $_POST['clabe'] ?? '';
$clabe       = preg_replace('/\D+/', '', (string)$clabe_input); // deja solo dígitos

$banco = trim($_POST['banco'] ?? '');

# ===== Validaciones (si hay valor) =====
$errores = [];

if ($curp !== '') {
  $reCURP = '/^([A-Z])[AEIOUX]([A-Z]{2})(\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM]'
          .'(AS|BC|BS|CC|CS|CH|CL|CM|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)'
          .'([B-DF-HJ-NP-TV-Z]{3})([A-Z0-9])(\d)$/';
  if (!preg_match($reCURP, $curp, $m)) $errores[] = 'CURP no válida.';
  else {
    $dt = DateTime::createFromFormat('ymd', $m[3].$m[4].$m[5]);
    if (!($dt && $dt->format('ymd') === $m[3].$m[4].$m[5])) $errores[] = 'CURP con fecha inválida.';
  }
}

if ($rfc !== '') {
  $reRFC = '/^([A-ZÑ&]{3,4})(\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])([A-Z0-9]{3})$/u';
  if (!preg_match($reRFC, $rfc, $r)) $errores[] = 'RFC no válido.';
  else {
    $dt = DateTime::createFromFormat('ymd', $r[2].$r[3].$r[4]);
    if (!($dt && $dt->format('ymd') === $r[2].$r[3].$r[4])) $errores[] = 'RFC con fecha inválida.';
  }
}

if ($nss !== ''   && !preg_match('/^\d{11}$/', $nss)) $errores[] = 'El NSS debe tener 11 dígitos.';

/* ===== CAMBIO: permitir 16 o 18 dígitos ===== */
if ($clabe !== '' && !preg_match('/^\d{16}(\d{2})?$/', $clabe)) {
  $errores[] = 'La CLABE o Tarjeta debe tener 16 o 18 dígitos.';
}

if ($banco !== '' && strlen($banco) > 80) $errores[] = 'El nombre del banco es muy largo.';

if ($errores) {
  header("Location: mi_expediente.php?err=" . urlencode(implode(' ', $errores)));
  exit;
}

# ===== Upsert =====
$exists = false;
$stmt = $conn->prepare("SELECT id FROM usuarios_expediente WHERE usuario_id=? LIMIT 1");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->fetch_assoc()) $exists = true;
$stmt->close();

if ($exists) {
  $sql = "UPDATE usuarios_expediente SET
            tel_contacto=?,
            fecha_nacimiento = NULLIF(?, ''),
            fecha_ingreso    = NULLIF(?, ''),
            fecha_baja       = NULLIF(?, ''),
            motivo_baja      = NULLIF(?, ''),
            curp=?,
            nss=?,
            rfc=?,
            genero = NULLIF(?, ''),
            contacto_emergencia=?,
            tel_emergencia=?,
            clabe=?,
            banco = NULLIF(?, '')
          WHERE usuario_id=?";
  $stmt = $conn->prepare($sql);
  // 13 's' + 1 'i'
  $stmt->bind_param(
    'sssssssssssssi',
    $tel_contacto,
    $fecha_nacimiento, $fecha_ingreso, $fecha_baja, $motivo_baja,
    $curp, $nss, $rfc, $genero,
    $contacto_emergencia, $tel_emergencia, $clabe,
    $banco,
    $usuario_id
  );
} else {
  $sql = "INSERT INTO usuarios_expediente
          (usuario_id, tel_contacto, fecha_nacimiento, fecha_ingreso, fecha_baja, motivo_baja,
           curp, nss, rfc, genero, contacto_emergencia, tel_emergencia, clabe, banco)
          VALUES (?,
                  ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                  ?, ?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, ''))";
  $stmt = $conn->prepare($sql);
  // 1 'i' + 13 's'
  $stmt->bind_param(
    'isssssssssssss',
    $usuario_id,
    $tel_contacto, $fecha_nacimiento, $fecha_ingreso, $fecha_baja, $motivo_baja,
    $curp, $nss, $rfc, $genero, $contacto_emergencia, $tel_emergencia, $clabe, $banco
  );
}

$stmt->execute();
$stmt->close();

header("Location: mi_expediente.php?ok=1");
exit;
