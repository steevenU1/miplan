<?php
// iniciar_conteo.php — crea (o reabre) el conteo cíclico semanal y genera el snapshot del inventario
// Además: elimina automáticamente el snapshot de ESTA sucursal de hace N semanas (por defecto, 2)
// Acceso sugerido: Gerente, GerenteZona, Admin

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

// ===== Configuración de roles permitidos =====
$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

// Ajusta los roles que pueden iniciar conteo
$ROLES_PERMITIDOS = ['Gerente','GerenteZona','Admin'];
if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
  header("Location: 403.php"); exit();
}

// ===== Helpers =====
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
/** @var mysqli $conn */
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Semana operativa: Martes (00:00) → Lunes (23:59:59) en America/Mexico_City
 * Devuelve [Y-m-d (martes), Y-m-d (lunes)]
 */
function semana_mar_lun(): array {
  $tz = new DateTimeZone('America/Mexico_City');
  $now = new DateTime('now', $tz);
  $dow = (int)$now->format('N'); // 1=Lun ... 7=Dom
  $diff = $dow - 2;              // martes=2
  if ($diff < 0) $diff += 7;
  $ini = (clone $now)->modify("-$diff days")->setTime(0,0,0);
  $fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini->format('Y-m-d'), $fin->format('Y-m-d')];
}

list($SEM_INI, $SEM_FIN) = semana_mar_lun();

// ===== Parámetro de retención =====
// Mantener K semanas (incluida la actual y la anterior). Al iniciar semana nueva,
// se borra el snapshot de hace K semanas exactas (si está 'Cerrado') SOLO de esta sucursal.
$RETENER_SEMANAS = 2; // <- cambia a 3, 4, etc. si quieres conservar más semanas

try {
  $conn->begin_transaction();

  // 0) PURGA "a prueba de balas" por ID puntual de conteo (solo esta sucursal, semana exacta y cerrado)
  $targetIni = (new DateTime($SEM_INI))->modify("-{$RETENER_SEMANAS} weeks")->format('Y-m-d');

  $getPurge = $conn->prepare("
    SELECT id
    FROM conteos_ciclicos
    WHERE id_sucursal=? AND semana_inicio=? AND estado='Cerrado'
    LIMIT 1
  ");
  $getPurge->bind_param('is', $ID_SUCURSAL, $targetIni);
  $getPurge->execute();
  $purge = $getPurge->get_result()->fetch_assoc();

  if ($purge) {
    $purgeId = (int)$purge['id'];

    // Borrar detalle primero (respetando FK si existe)
    $delDet = $conn->prepare("DELETE FROM conteos_ciclicos_det WHERE id_conteo=?");
    $delDet->bind_param('i', $purgeId);
    $delDet->execute();

    // Luego borrar cabecera
    $delCab = $conn->prepare("DELETE FROM conteos_ciclicos WHERE id=? LIMIT 1");
    $delCab->bind_param('i', $purgeId);
    $delCab->execute();
  }

  // 1) Crear cabecera si no existe (idempotente por UNIQUE uk_sucursal_semana)
  $insCab = $conn->prepare("
    INSERT IGNORE INTO conteos_ciclicos
      (id_sucursal, semana_inicio, semana_fin, estado, creado_por)
    VALUES (?, ?, ?, 'En Proceso', ?)
  ");
  $insCab->bind_param('issi', $ID_SUCURSAL, $SEM_INI, $SEM_FIN, $ID_USUARIO);
  $insCab->execute();

  // 2) Obtener id_conteo y estado actual
  $getCab = $conn->prepare("
    SELECT id, estado
    FROM conteos_ciclicos
    WHERE id_sucursal=? AND semana_inicio=?
    LIMIT 1
  ");
  $getCab->bind_param('is', $ID_SUCURSAL, $SEM_INI);
  $getCab->execute();
  $cab = $getCab->get_result()->fetch_assoc();

  if (!$cab) {
    throw new RuntimeException('No se pudo resolver el conteo de la semana (cabecera).');
  }

  $ID_CONTEO = (int)$cab['id'];
  $ESTADO    = (string)$cab['estado'];

  // Si estaba en Pendiente, pásalo a En Proceso
  if ($ESTADO === 'Pendiente') {
    $upd = $conn->prepare("UPDATE conteos_ciclicos SET estado='En Proceso' WHERE id=? LIMIT 1");
    $upd->bind_param('i', $ID_CONTEO);
    $upd->execute();
  }

  // Si ya está Cerrado, no vamos a reabrir aquí. Mostramos mensaje y salimos con commit.
  if ($ESTADO === 'Cerrado') {
    $conn->commit();
    header("Location: inventario_ciclico.php?id_conteo={$ID_CONTEO}&msg=" . urlencode('El conteo de esta semana ya está cerrado.'));
    exit();
  }

  // 3) Generar snapshot SOLO si aún no existe detalle
  $countDet = $conn->prepare("SELECT COUNT(*) c FROM conteos_ciclicos_det WHERE id_conteo=?");
  $countDet->bind_param('i', $ID_CONTEO);
  $countDet->execute();
  $c = (int)$countDet->get_result()->fetch_assoc()['c'];

  if ($c === 0) {
    // Importante: ajusta estatus incluidos según tu operación
    // Recomendado: Disponible, En tránsito, Apartado
    $insDet = $conn->prepare("
      INSERT INTO conteos_ciclicos_det
        (id_conteo, id_inventario, imei1, imei2, marca, modelo, color, estatus_snapshot, resultado)
      SELECT ?, i.id, p.imei1, p.imei2, p.marca, p.modelo, p.color, i.estatus, 'No Verificable'
      FROM inventario i
      JOIN productos p ON p.id = i.id_producto
      WHERE i.id_sucursal=? AND i.estatus IN ('Disponible','En tránsito','Apartado')
    ");
    $insDet->bind_param('ii', $ID_CONTEO, $ID_SUCURSAL);
    $insDet->execute();
  }

  $conn->commit();

  // Redirigir a la vista de captura
  header("Location: inventario_ciclico.php?id_conteo={$ID_CONTEO}&msg=" . urlencode('Listo. Se limpió la semana vieja y se abrió el conteo.'));
  exit();

} catch (Throwable $e) {
  // Si algo truena, revertimos
  if ($conn->errno) { $conn->rollback(); }
  http_response_code(500);
  ?>
  <!doctype html>
  <html lang="es-MX">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Error al iniciar conteo</title>
    <style>
      body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Inter, Arial, sans-serif; margin:0; padding:2rem; background:#fafafa; color:#222}
      .card{max-width:720px; margin:0 auto; background:#fff; border:1px solid #eee; border-radius:16px; padding:24px; box-shadow:0 2px 12px rgba(0,0,0,0.05)}
      h1{font-size:1.25rem; margin:0 0 1rem}
      pre{white-space:pre-wrap; background:#f5f5f5; padding:12px; border-radius:10px; overflow:auto}
      a.button{display:inline-block; margin-top:1rem; padding:.6rem .9rem; border-radius:10px; border:1px solid #ddd; text-decoration:none; color:#111}
      a.button:hover{background:#f3f3f3}
    </style>
  </head>
  <body>
    <div class="card">
      <h1>Hubo un problema al iniciar el conteo</h1>
      <p><strong>Mensaje:</strong></p>
      <pre><?php echo h($e->getMessage()); ?></pre>
      <a class="button" href="index.php">Volver al inicio</a>
    </div>
  </body>
  </html>
  <?php
  exit();
}
