<?php
// ajax_nomina_override_save.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'db.php';

/** Helpers **/
function clean_num(?string $v, ?float $defaultIfEmpty = null): ?float {
    if ($v === null) return $defaultIfEmpty;
    $v = trim($v);
    if ($v === '') return $defaultIfEmpty;
    // quitar $ , espacios y separadores de miles
    $v = str_replace(['$', ',', ' '], '', $v);
    // cambiar coma decimal por punto si vino así
    $v = str_replace([' '], '', $v); // NBSP
    $v = str_replace([';'], '.', $v);
    $v = str_replace(['.'], '.', $v);
    if ($v === '' || !is_numeric($v)) return $defaultIfEmpty;
    return floatval($v);
}
function clean_str(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return ($v === '') ? null : $v;
}
function clean_date(?string $d): ?string {
    if (!$d) return null;
    $d = substr($d, 0, 10);
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('Y-m-d') : null;
}

try {
    $idUsuario = isset($_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;
    $rolEmpleado = $_POST['rol'] ?? '';
    $semanaInicio = clean_date($_POST['semana_inicio'] ?? null);
    $semanaFin    = clean_date($_POST['semana_fin'] ?? null);

    if ($idUsuario <= 0 || !$semanaInicio || !$semanaFin) {
        throw new Exception('Datos incompletos (usuario/semana).');
    }

    // Overrides comunes
    $sueldo     = clean_num($_POST['sueldo_override']     ?? null, null);
    $equipos    = clean_num($_POST['equipos_override']    ?? null, null);
    $sims       = clean_num($_POST['sims_override']       ?? null, null);
    $pos        = clean_num($_POST['pospago_override']    ?? null, null);

    // Overrides gerente
    $dirg  = clean_num($_POST['ger_dir_override']  ?? null, null);
    $esceq = clean_num($_POST['ger_esc_override']  ?? null, null);
    $prepg = clean_num($_POST['ger_prep_override'] ?? null, null);
    $posg  = clean_num($_POST['ger_pos_override']  ?? null, null);

    // Si NO es gerente, ignorar (guardar NULL)
    if ($rolEmpleado !== 'Gerente') {
        $dirg = $esceq = $prepg = $posg = null;
    }

    $desc      = clean_num($_POST['descuentos_override'] ?? null, null);
    $ajuste    = clean_num($_POST['ajuste_neto_extra']    ?? null, 0.00); // nunca nulo

    $estado    = $_POST['estado'] ?? 'borrador';
    $nota      = clean_str($_POST['nota'] ?? null);

    // Normalizar estado
    $validEstados = ['borrador','por_autorizar','autorizado'];
    if (!in_array($estado, $validEstados, true)) {
        $estado = 'borrador';
    }

    // Fuente según rol de sesión
    $fuente = ($_SESSION['rol'] === 'Admin') ? 'Admin' : 'RH';

    // ¿Ya existe?
    $sqlChk = "SELECT id FROM nomina_overrides_semana 
               WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? LIMIT 1";
    $stmt = $conn->prepare($sqlChk);
    $stmt->bind_param("iss", $idUsuario, $semanaInicio, $semanaFin);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        // UPDATE existente
        $sql = "UPDATE nomina_overrides_semana SET
                    sueldo_override=?,
                    equipos_override=?,
                    sims_override=?,
                    pospago_override=?,
                    ger_dir_override=?,
                    ger_esc_override=?,
                    ger_prep_override=?,
                    ger_pos_override=?,
                    descuentos_override=?,
                    ajuste_neto_extra=?,
                    fuente=?,
                    estado=?,
                    nota=?,
                    actualizado_en=CURRENT_TIMESTAMP
                WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?";
        $stmt = $conn->prepare($sql);
        // 10 decimales + 3 strings + id + 2 fechas
        $stmt->bind_param(
            "ddddddddddsssiss",
            $sueldo,
            $equipos,
            $sims,
            $pos,
            $dirg,
            $esceq,
            $prepg,
            $posg,
            $desc,
            $ajuste,
            $fuente,
            $estado,
            $nota,
            $idUsuario,
            $semanaInicio,
            $semanaFin
        );
        $stmt->execute();
        $stmt->close();
    } else {
        // INSERT nuevo (no especificamos creado_en/actualizado_en: la tabla los pone por defecto)
        $sql = "INSERT INTO nomina_overrides_semana
                   (id_usuario, semana_inicio, semana_fin,
                    sueldo_override, equipos_override, sims_override, pospago_override,
                    ger_dir_override, ger_esc_override, ger_prep_override, ger_pos_override,
                    descuentos_override, ajuste_neto_extra, fuente, estado, nota)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $conn->prepare($sql);
        // tipos: i, s, s, (10 d), s, s, s  => 'iss' + 'dddddddddd' + 'sss'
        $stmt->bind_param(
            "issddddddddddsss",
            $idUsuario,
            $semanaInicio,
            $semanaFin,
            $sueldo,
            $equipos,
            $sims,
            $pos,
            $dirg,
            $esceq,
            $prepg,
            $posg,
            $desc,
            $ajuste,
            $fuente,
            $estado,
            $nota
        );
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['ok' => true]);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar', 'detail' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
