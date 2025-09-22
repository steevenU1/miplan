<?php
// Autodiagnóstico para la vista Asistencia (no modifica datos)
header('Content-Type: text/plain; charset=utf-8');
$info = [];

$info['php_version'] = PHP_VERSION;
$info['extensions']  = [
  'mysqli' => extension_loaded('mysqli'),
  'intl'   => extension_loaded('intl'),
];

ob_start();
try {
  require __DIR__.'/db.php'; // usa tu misma conexión $conn
  $okConn = isset($conn) && @$conn->ping();
  $info['db']['connected'] = (bool)$okConn;
  if ($okConn) {
    // Base seleccionada
    $r = @$conn->query("SELECT DATABASE() AS db")->fetch_assoc();
    $info['db']['database'] = $r ? $r['db'] : null;

    // ¿Permiso para INFORMATION_SCHEMA?
    $q1 = @$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES LIMIT 1");
    $info['db']['can_information_schema'] = (bool)$q1;

    // Comprobaciones de tablas que usa asistencia
    $needTables = ['asistencias','usuarios','sucursales','sucursales_horario','descansos_programados','permisos_solicitudes'];
    foreach ($needTables as $t) {
      $rs = @$conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($t)."'");
      $info['tables'][$t] = $rs && $rs->num_rows > 0;
    }

    // Columnas críticas en asistencias (compatibilidad prod vs dev)
    $cols = ['hora_entrada','hora_salida','duracion_minutos','retardo','retardo_minutos','latitud','longitud','ip','metodo','estatus','latitud_salida','longitud_salida','fecha','id_usuario','id_sucursal'];
    foreach ($cols as $c) {
      $rs = @$conn->query("SHOW COLUMNS FROM asistencias LIKE '".$conn->real_escape_string($c)."'");
      $info['asistencias_columns'][$c] = $rs ? ($rs->num_rows > 0) : false;
    }

    // Prueba de SELECTs iguales a los de asistencia (sin escribir nada)
    $probe = [];
    $probe[] = @$conn->prepare("SELECT id, hora_entrada FROM asistencias WHERE id_usuario=? AND fecha=? AND hora_salida IS NULL LIMIT 1") ? 'ok' : 'fail: asistencias/select abierta';
    $probe[] = @$conn->prepare("SELECT abre,cierra,cerrado FROM sucursales_horario WHERE id_sucursal=? AND dia_semana=? LIMIT 1") ? 'ok' : 'fail: sucursales_horario';
    $info['probes'] = $probe;
  }
} catch (Throwable $e) {
  $info['fatal'] = get_class($e).': '.$e->getMessage();
}
$out = ob_get_clean();
if (strlen($out)) { $info['include_output'] = $out; }

// Dump bonito
echo "== SELFTEST Asistencia ==\n";
print_r($info);
