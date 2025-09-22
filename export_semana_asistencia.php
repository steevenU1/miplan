<?php
// export_semana_asistencia.php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit('No autorizado'); }
$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['Admin','GerenteZona'])) { http_response_code(403); exit('No autorizado'); }

require_once 'db.php';

// ===== Helpers semana operativa (Mar→Lun) =====
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/', $iso, $m)) return null;
  $dt = new DateTime(); $dt->setISODate((int)$m[1], (int)$m[2]); // Lunes ISO
  $dt->modify('+1 day'); // Martes
  $dt->setTime(0,0,0);
  return $dt;
}
function currentOpWeekIso(): string {
  $today = new DateTime(); $today->setTime(0,0,0);
  $dow = (int)$today->format('N');
  $offset = ($dow >= 2) ? $dow - 2 : 6 + $dow; // hasta martes reciente
  $tue = (clone $today)->modify("-{$offset} days");
  $mon = (clone $tue)->modify('-1 day');
  return $mon->format('o-\WW');
}

// ===== Parámetros =====
$type        = $_GET['type'] ?? 'asistencias';               // asistencias | incidencias | vacaciones
$scope       = $_GET['scope'] ?? 'week';                      // solo para type=vacaciones: week | pending
$weekIso     = $_GET['week'] ?? currentOpWeekIso();
$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;

$tuesdayStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');
$start = $tuesdayStart->format('Y-m-d');
$end   = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');

// ===== Restricción por zona para GerenteZona =====
$zonaAsignada = null;
if ($rol === 'GerenteZona') {
  $stmt = $conn->prepare("
    SELECT s.zona
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id=? LIMIT 1
  ");
  $stmt->bind_param('i', $_SESSION['id_usuario']);
  $stmt->execute();
  if ($r = $stmt->get_result()->fetch_assoc()) $zonaAsignada = trim($r['zona'] ?? '');
  $stmt->close();
}

// ===== Preparar salida CSV (Excel-friendly) =====
$fname = "export_{$type}_{$start}_{$end}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
$out = fopen('php://output', 'w');
// BOM UTF-8 para Excel
fwrite($out, "\xEF\xBB\xBF");

// ===== ASISTENCIAS =====
if ($type === 'asistencias') {
  if ($rol === 'Admin') {
    $types = 'ss'; $params = [$start, $end];
    $sql = "
      SELECT s.nombre AS Sucursal, u.nombre AS Colaborador,
             a.fecha AS Fecha, a.hora_entrada AS Entrada, a.hora_salida AS Salida,
             a.duracion_minutos AS DuracionMin, a.latitud AS Latitud, a.longitud AS Longitud, a.ip AS IP
      FROM asistencias a
      INNER JOIN usuarios u ON u.id = a.id_usuario
      INNER JOIN sucursales s ON s.id = a.id_sucursal
      WHERE a.fecha BETWEEN ? AND ?
    ";
    if ($sucursal_id>0) { $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
    $sql .= " ORDER BY s.nombre, u.nombre, a.fecha, a.hora_entrada";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  } else {
    $types = 'sss'; $params = [$start, $end, $zonaAsignada];
    $sql = "
      SELECT s.nombre AS Sucursal, u.nombre AS Colaborador,
             a.fecha AS Fecha, a.hora_entrada AS Entrada, a.hora_salida AS Salida,
             a.duracion_minutos AS DuracionMin, a.latitud AS Latitud, a.longitud AS Longitud, a.ip AS IP
      FROM asistencias a
      INNER JOIN usuarios u ON u.id = a.id_usuario
      INNER JOIN sucursales s ON s.id = a.id_sucursal
      WHERE a.fecha BETWEEN ? AND ?
        AND s.zona = ?
        AND s.nombre <> 'Eulalia'
    ";
    if ($sucursal_id>0) { $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
    $sql .= " ORDER BY s.nombre, u.nombre, a.fecha, a.hora_entrada";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  fputcsv($out, ['Sucursal','Colaborador','Fecha','Entrada','Salida','Duración (min)','Latitud','Longitud','IP']);
  while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
      $row['Sucursal'], $row['Colaborador'], $row['Fecha'], $row['Entrada'], $row['Salida'],
      (int)($row['DuracionMin'] ?? 0), $row['Latitud'], $row['Longitud'], $row['IP']
    ]);
  }
  $stmt->close();
  exit;
}

// ===== INCIDENCIAS =====
if ($type === 'incidencias') {
  if ($rol === 'Admin') {
    $types = 'ss'; $params = [$start, $end];
    $sql = "
      SELECT s.nombre AS Sucursal, u.nombre AS Colaborador,
             i.fecha AS Fecha, i.tipo AS Tipo, i.minutos AS Minutos, i.comentario AS Comentario, i.creado_en AS RegistradaEn
      FROM incidencias_asistencia i
      INNER JOIN usuarios u ON u.id = i.id_usuario
      INNER JOIN sucursales s ON s.id = i.id_sucursal
      WHERE i.fecha BETWEEN ? AND ?
    ";
    if ($sucursal_id>0){ $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
    $sql .= " ORDER BY s.nombre, u.nombre, i.fecha DESC, i.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  } else {
    $types = 'sss'; $params = [$start, $end, $zonaAsignada];
    $sql = "
      SELECT s.nombre AS Sucursal, u.nombre AS Colaborador,
             i.fecha AS Fecha, i.tipo AS Tipo, i.minutos AS Minutos, i.comentario AS Comentario, i.creado_en AS RegistradaEn
      FROM incidencias_asistencia i
      INNER JOIN usuarios u ON u.id = i.id_usuario
      INNER JOIN sucursales s ON s.id = i.id_sucursal
      WHERE i.fecha BETWEEN ? AND ?
        AND s.zona = ?
        AND s.nombre <> 'Eulalia'
    ";
    if ($sucursal_id>0){ $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
    $sql .= " ORDER BY s.nombre, u.nombre, i.fecha DESC, i.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  fputcsv($out, ['Sucursal','Colaborador','Fecha','Tipo','Minutos','Comentario','Registrada en']);
  while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
      $row['Sucursal'], $row['Colaborador'], $row['Fecha'], $row['Tipo'],
      ($row['Minutos']!==null?(int)$row['Minutos']:null), $row['Comentario'], $row['RegistradaEn']
    ]);
  }
  $stmt->close();
  exit;
}

// ===== VACACIONES =====
if ($type === 'vacaciones') {
  if ($scope === 'pending') {
    if ($rol === 'Admin') {
      $sql = "
        SELECT u.nombre AS Colaborador, s.nombre AS Sucursal,
               v.fecha_inicio, v.fecha_fin, v.dias, v.motivo,
               v.status_gerente_zona AS StatusGZ, v.status_admin AS StatusAdmin, v.creado_en
        FROM vacaciones_solicitudes v
        INNER JOIN usuarios u ON u.id = v.id_usuario
        INNER JOIN sucursales s ON s.id = v.id_sucursal
        WHERE v.status_gerente_zona='Aprobado' AND v.status_admin='Pendiente'
        ORDER BY v.creado_en ASC
      ";
      $res = $conn->query($sql);
    } else {
      $stmt = $conn->prepare("
        SELECT u.nombre AS Colaborador, s.nombre AS Sucursal,
               v.fecha_inicio, v.fecha_fin, v.dias, v.motivo,
               v.status_gerente_zona AS StatusGZ, v.status_admin AS StatusAdmin, v.creado_en
        FROM vacaciones_solicitudes v
        INNER JOIN usuarios u ON u.id = v.id_usuario
        INNER JOIN sucursales s ON s.id = v.id_sucursal
        WHERE v.status_gerente_zona='Pendiente'
          AND s.zona = ?
          AND s.nombre <> 'Eulalia'
        ORDER BY v.creado_en ASC
      ");
      $stmt->bind_param('s', $zonaAsignada);
      $stmt->execute();
      $res = $stmt->get_result();
    }
    fputcsv($out, ['Colaborador','Sucursal','Inicio','Fin','Días','Motivo','Status G.Zona','Status Admin','Creada en']);
    while ($row = $res->fetch_assoc()) {
      fputcsv($out, [
        $row['Colaborador'], $row['Sucursal'],
        $row['fecha_inicio'], $row['fecha_fin'], (int)$row['dias'],
        $row['motivo'], $row['StatusGZ'], $row['StatusAdmin'], $row['creado_en']
      ]);
    }
    if (isset($stmt)) $stmt->close();
    exit;
  } else { // scope=week
    if ($rol === 'Admin') {
      $types='ss'; $params=[$start,$end];
      $sql = "
        SELECT u.nombre AS Colaborador, s.nombre AS Sucursal,
               v.fecha_inicio, v.fecha_fin, v.dias, v.motivo,
               v.status_gerente_zona AS StatusGZ, v.status_admin AS StatusAdmin
        FROM vacaciones_solicitudes v
        INNER JOIN usuarios u ON u.id = v.id_usuario
        INNER JOIN sucursales s ON s.id = v.id_sucursal
        WHERE NOT (v.fecha_fin < ? OR v.fecha_inicio > ?)
      ";
      if ($sucursal_id>0){ $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
      $sql .= " ORDER BY v.fecha_inicio DESC, v.id DESC";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$params);
    } else {
      $types='sss'; $params=[$start,$end,$zonaAsignada];
      $sql = "
        SELECT u.nombre AS Colaborador, s.nombre AS Sucursal,
               v.fecha_inicio, v.fecha_fin, v.dias, v.motivo,
               v.status_gerente_zona AS StatusGZ, v.status_admin AS StatusAdmin
        FROM vacaciones_solicitudes v
        INNER JOIN usuarios u ON u.id = v.id_usuario
        INNER JOIN sucursales s ON s.id = v.id_sucursal
        WHERE NOT (v.fecha_fin < ? OR v.fecha_inicio > ?)
          AND s.zona = ?
          AND s.nombre <> 'Eulalia'
      ";
      if ($sucursal_id>0){ $sql.=" AND s.id=? "; $types.='i'; $params[]=$sucursal_id; }
      $sql .= " ORDER BY v.fecha_inicio DESC, v.id DESC";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    fputcsv($out, ['Colaborador','Sucursal','Inicio','Fin','Días','Motivo','Status G.Zona','Status Admin']);
    while ($row = $res->fetch_assoc()) {
      fputcsv($out, [
        $row['Colaborador'], $row['Sucursal'],
        $row['fecha_inicio'], $row['fecha_fin'], (int)$row['dias'],
        $row['motivo'], $row['StatusGZ'], $row['StatusAdmin']
      ]);
    }
    $stmt->close();
    exit;
  }
}

// Tipo inválido
http_response_code(400);
echo 'Parámetro "type" inválido.';
