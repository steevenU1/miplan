<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

/* =========================
   Config
========================= */
define('PREVIEW_LIMIT', 200);
define('TMP_DIR', sys_get_temp_dir());
const OPERADORES_VALIDOS = ['Bait','AT&T','Virgin','Unefon','Telcel','Movistar'];
const SUCURSAL_DEFAULT_NOMBRE = 'MP Almacen General'; // default requerido

/* =========================
   Helpers
========================= */
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function columnAllowsNull(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
    $rs  = $conn->query($sql);
    if (!$rs) return false;
    $row = $rs->fetch_assoc();
    return isset($row['IS_NULLABLE']) && strtoupper($row['IS_NULLABLE']) === 'YES';
}

function getSucursalIdPorNombre(mysqli $conn, string $nombre, array &$cache): int {
    $nombre = trim($nombre);
    if ($nombre === '') return 0;
    if (isset($cache[$nombre])) return $cache[$nombre];
    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = 0;
    $res = $stmt->get_result();
    if ($res) {
        $row = $res->fetch_assoc();
        if ($row && isset($row['id'])) $id = (int)$row['id'];
    }
    $stmt->close();
    $cache[$nombre] = $id;
    return $id;
}

function quitarAcentosMayus(string $s): string {
    $s = mb_strtoupper($s, 'UTF-8');
    $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
    return $t !== false ? strtoupper($t) : $s;
}

function normalizarOperadorObligatorio(string $opRaw, /*out*/ &$esValido): string {
    // Obligatorio: si viene vacío => inválido
    $opTrim = trim($opRaw);
    if ($opTrim === '') { $esValido = false; return ''; }

    $op = quitarAcentosMayus($opTrim);
    $sinEspHyf = str_replace([' ', '-', '.', '_'], '', $op);
    $map = [
        'BAIT' => 'Bait',
        'AT&T' => 'AT&T',
        'ATT' => 'AT&T',
        'VIRGIN' => 'Virgin',
        'VIRGINMOBILE' => 'Virgin',
        'UNEFON' => 'Unefon',
        'TELCEL' => 'Telcel',
        'MOVISTAR' => 'Movistar',
    ];
    if (isset($map[$op])) { $esValido = true; return $map[$op]; }
    if (isset($map[$sinEspHyf])) { $esValido = true; return $map[$sinEspHyf]; }
    foreach (OPERADORES_VALIDOS as $val) {
        if (quitarAcentosMayus($val) === $op) { $esValido = true; return $val; }
    }
    $esValido = false;
    return $opTrim; // se muestra tal cual en el reporte como inválido
}

/* --- utilidades CSV --- */
function detect_delimiter($line){
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    return ($s > $c) ? ';' : ',';
}

/** Normaliza agresivamente el nombre de encabezado. */
function norm_header_key(string $raw): string {
    // quita BOM, NBSP y caracteres no imprimibles
    $raw = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], '', $raw);
    $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
    $k = strtolower(trim($raw));
    $k = str_replace([' ', '-', '.', '/'], '_', $k);
    // compacta múltiples guiones bajos
    $k = preg_replace('/_+/', '_', $k);
    return $k;
}

/** Crea el mapa encabezado->índice (case-insensitive), con alias y fallback. */
function buildHeaderMap(array $hdr, /*out*/ &$warnings): array {
    $warnings = [];
    $map = [];

    // construir mapa normalizado
    foreach ($hdr as $i => $raw) {
        $k = norm_header_key((string)$raw);
        $map[$k] = $i;
    }

    // alias comunes
    if (!isset($map['caja_id'])) {
        if (isset($map['id_caja'])) $map['caja_id'] = $map['id_caja'];
        elseif (isset($map['caja'])) $map['caja_id'] = $map['caja'];
    }
    // alias/variantes para iccid
    if (!isset($map['iccid'])) {
        foreach (['icc_id','icc','sim','sim_iccid','no_iccid'] as $alias) {
            if (isset($map[$alias])) { $map['iccid'] = $map[$alias]; break; }
        }
    }

    // fallback de iccid a columna 0 si no existiera
    if (!isset($map['iccid'])) {
        $map['iccid'] = 0;
        $warnings[] = "No encontré encabezado 'iccid'; usaré la primera columna como ICCID.";
    }

    return $map;
}

function getCsvVal(array $row, array $map, string $key): string {
    if (isset($map[$key])) {
        $v = isset($row[$map[$key]]) ? $row[$map[$key]] : '';
    } else {
        $v = '';
    }
    // limpieza final (trim + NBSP)
    return trim(str_replace("\xC2\xA0", ' ', (string)$v));
}

/* =========================
   Descubrimientos iniciales
========================= */
// ID sucursal por defecto
$idDefaultSucursal = 0;
$qDef = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
$tmpName = SUCURSAL_DEFAULT_NOMBRE;
$qDef->bind_param("s", $tmpName);
$qDef->execute();
$rDef = $qDef->get_result();
if ($rDef) { $row = $rDef->fetch_assoc(); if ($row) $idDefaultSucursal = (int)$row['id']; }
$qDef->close();

$dnPermiteNull   = columnAllowsNull($conn, 'inventario_sims', 'dn');
$lotePermiteNull = columnAllowsNull($conn, 'inventario_sims', 'lote');

$sucursalCache = [];

$msg = '';
$previewRows = [];
$contador = ['total'=>0, 'ok'=>0, 'ignoradas'=>0];
$headerWarnings = []; // para mostrar notas sobre encabezados

/* =========================
   PREVIEW
========================= */
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview' && isset($_FILES['archivo_csv'])) {
    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = "❌ Error al subir el archivo.";
    } else {
        $nombreOriginal = $_FILES['archivo_csv']['name'];
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $msg = "Convierte tu Excel a CSV UTF-8 y súbelo de nuevo.";
        } else {
            $tmpName = "sims_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . ".csv";
            $tmpPath = rtrim(TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tmpName;
            if (!move_uploaded_file($_FILES['archivo_csv']['tmp_name'], $tmpPath)) {
                $msg = "❌ No se pudo mover el archivo a temporal.";
            } else {
                $_SESSION['carga_sims_tmp'] = $tmpPath;
                $_SESSION['confirm_token'] = bin2hex(random_bytes(16));

                $fh = fopen($tmpPath, 'r');
                if ($fh) {
                    // detectar delimitador
                    $first = fgets($fh);
                    if ($first === false) {
                        $msg = "❌ Archivo vacío.";
                    } else {
                        $first = str_replace("\xEF\xBB\xBF", '', $first);
                        $delim = detect_delimiter($first);
                        rewind($fh);

                        $fila = 0; $hdrMap = null; $hdrWarn = [];
                        while (($data = fgetcsv($fh, 0, $delim)) !== false) {
                            $fila++;
                            if ($fila === 1) {
                                // limpieza extra de BOM/NBSP en la primera celda
                                if (isset($data[0])) {
                                    $data[0] = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], '', (string)$data[0]);
                                }
                                $hdrMap = buildHeaderMap($data, $hdrWarn);
                                $headerWarnings = $hdrWarn;
                                continue;
                            }

                            $iccid   = getCsvVal($data, $hdrMap, 'iccid');
                            $dn      = getCsvVal($data, $hdrMap, 'dn');
                            $caja    = getCsvVal($data, $hdrMap, 'caja_id');
                            $lote    = getCsvVal($data, $hdrMap, 'lote');
                            $sucNom  = getCsvVal($data, $hdrMap, 'sucursal');
                            $opRaw   = getCsvVal($data, $hdrMap, 'operador');

                            // Reglas: sucursal vacía -> default; operador obligatorio
                            $id_sucursal = ($sucNom === '') ? $idDefaultSucursal : getSucursalIdPorNombre($conn, $sucNom, $sucursalCache);
                            $esValido = false;
                            $operador = normalizarOperadorObligatorio($opRaw, $esValido);

                            $estatus = 'OK'; $motivo = 'Listo para insertar';
                            if ($iccid === '') { $estatus='Ignorada'; $motivo='ICCID vacío'; }
                            elseif ($id_sucursal === 0) { $estatus='Ignorada'; $motivo='Sucursal no encontrada'; }
                            elseif (!$esValido) { $estatus='Ignorada'; $motivo='Operador vacío o inválido'; }
                            else {
                                $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
                                $stmtDup->bind_param("s", $iccid);
                                $stmtDup->execute(); $stmtDup->store_result();
                                if ($stmtDup->num_rows > 0) { $estatus='Ignorada'; $motivo='Duplicado en base'; }
                                $stmtDup->close();
                            }

                            $contador['total']++;
                            if ($estatus === 'OK') $contador['ok']++; else $contador['ignoradas']++;
                            if (count($previewRows) < PREVIEW_LIMIT) {
                                $previewRows[] = [
                                    'iccid'=>$iccid,'dn'=>$dn,'caja'=>$caja,'lote'=>$lote,
                                    'nombre_sucursal'=>($sucNom===''?SUCURSAL_DEFAULT_NOMBRE:$sucNom),
                                    'operador'=>$operador,
                                    'estatus'=>$estatus,'motivo'=>$motivo
                                ];
                            }
                        }
                    }
                    if ($fh) fclose($fh);
                } else {
                    $msg = "❌ No se pudo abrir el archivo.";
                }
            }
        }
    }
}

/* =========================
   INSERTAR
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'insertar') {
    $word = isset($_POST['confirm_word']) ? trim($_POST['confirm_word']) : '';
    $chk  = isset($_POST['confirm_ok']) ? 1 : 0;
    $token_recv = isset($_POST['confirm_token']) ? $_POST['confirm_token'] : '';
    $token_sess = isset($_SESSION['confirm_token']) ? $_SESSION['confirm_token'] : '';
    if ($word !== 'CARGAR' || $chk !== 1 || $token_recv !== $token_sess) {
        echo "❌ Confirmación inválida."; exit;
    }
    $tmpPath = isset($_SESSION['carga_sims_tmp']) ? $_SESSION['carga_sims_tmp'] : '';
    if ($tmpPath === '' || !is_file($tmpPath)) { echo "❌ Archivo temporal no encontrado."; exit; }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_carga_sims.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['iccid','dn','caja','lote','sucursal','operador','estatus_final','motivo']);

    $sqlInsert = "INSERT INTO inventario_sims (iccid,dn,caja_id,lote,id_sucursal,operador,estatus,fecha_ingreso)
                  VALUES (?,?,?,?,?,?,'Disponible',NOW())";
    $stmtInsert = $conn->prepare($sqlInsert);

    $fh = fopen($tmpPath, 'r');
    // detectar delimitador otra vez por si acaso
    $first = fgets($fh);
    $first = str_replace("\xEF\xBB\xBF", '', $first);
    $delim = detect_delimiter($first);
    rewind($fh);

    $fila = 0; $hdrMap = null; $hdrWarn = [];
    while (($data = fgetcsv($fh, 0, $delim)) !== false) {
        $fila++;
        if ($fila === 1) {
            if (isset($data[0])) {
                $data[0] = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], '', (string)$data[0]);
            }
            $hdrMap = buildHeaderMap($data, $hdrWarn);
            continue;
        }

        $iccid   = getCsvVal($data, $hdrMap, 'iccid');
        $dn      = getCsvVal($data, $hdrMap, 'dn');
        $caja    = getCsvVal($data, $hdrMap, 'caja_id');
        $lote    = getCsvVal($data, $hdrMap, 'lote');
        $sucNom  = getCsvVal($data, $hdrMap, 'sucursal');
        $opRaw   = getCsvVal($data, $hdrMap, 'operador');

        $id_sucursal = ($sucNom === '') ? $idDefaultSucursal : getSucursalIdPorNombre($conn, $sucNom, $sucursalCache);
        $esValido = false;
        $operador = normalizarOperadorObligatorio($opRaw, $esValido);

        $estatusFinal='Ignorada'; $motivo='N/A';

        if ($iccid===''){ $motivo='ICCID vacío'; }
        elseif ($id_sucursal===0){ $motivo='Sucursal no encontrada'; }
        elseif (!$esValido){ $motivo='Operador vacío o inválido'; }
        else {
            $stmtDup=$conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
            $stmtDup->bind_param("s",$iccid);
            $stmtDup->execute(); $stmtDup->store_result();

            if($stmtDup->num_rows>0){
                $motivo='Duplicado';
            } else {
                // DN / LOTE opcionales -> NULL si la columna lo permite
                $dnParam   = ($dn === '')   ? ($dnPermiteNull   ? null : '') : $dn;
                $loteParam = ($lote === '') ? ($lotePermiteNull ? null : '') : $lote;

                // Tipos: iccid(s), dn(s), caja(s), lote(s), id_sucursal(i), operador(s)
                $stmtInsert->bind_param("ssssis", $iccid, $dnParam, $caja, $loteParam, $id_sucursal, $operador);

                if($stmtInsert->execute()){
                    $estatusFinal='Insertada'; $motivo='OK';
                } else {
                    $motivo='Error inserción';
                }
            }
            $stmtDup->close();
        }

        fputcsv($out, [$iccid,$dn,$caja,$lote, ($sucNom===''?SUCURSAL_DEFAULT_NOMBRE:$sucNom), $operador,$estatusFinal,$motivo]);
    }
    fclose($fh); fclose($out);
    @unlink($tmpPath);
    unset($_SESSION['carga_sims_tmp'],$_SESSION['confirm_token']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Carga Masiva de SIMs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>
<div style="height:70px"></div> <!-- offset para navbar fixed -->

<div class="container mt-4">
    <h2>Carga Masiva de SIMs</h2>
    <a href="dashboard_unificado.php" class="btn btn-secondary mb-3">← Volver al Dashboard</a>

    <?php if ($idDefaultSucursal === 0): ?>
      <div class="alert alert-warning">
        No encontré la sucursal <b><?= h(SUCURSAL_DEFAULT_NOMBRE) ?></b> en la tabla <code>sucursales</code>.
        Cualquier fila sin sucursal será ignorada.
      </div>
    <?php endif; ?>

    <?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>

    <?php if (!isset($_POST['action']) || (isset($_POST['action']) && $_POST['action'] === '')): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Subir Archivo CSV</h5>
            <p>
               Columnas recomendadas: <b>iccid, dn, caja_id, lote, sucursal, operador</b>.<br>
               <b>dn</b> y <b>lote</b> son opcionales.<br>
               Si <b>sucursal</b> está vacía, se asigna <b><?= h(SUCURSAL_DEFAULT_NOMBRE) ?></b> .<br>
               <b>operador es obligatorio</b>: filas sin operador o no reconocido se ignoran.<br>
               Encabezados equivalentes para caja: <code>caja_id</code>, <code>id_caja</code> o <code>caja</code>.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <input type="file" name="archivo_csv" class="form-control mb-3" accept=".csv" required>
                <button class="btn btn-primary">👀 Vista Previa</button>
            </form>
        </div>
    <?php elseif (isset($_POST['action']) && $_POST['action'] === 'preview'): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Vista Previa</h5>
            <p>Total filas: <b><?= $contador['total'] ?></b> | OK: <b class="text-success"><?= $contador['ok'] ?></b> | Ignoradas: <b class="text-danger"><?= $contador['ignoradas'] ?></b></p>

            <?php if (!empty($headerWarnings)): ?>
              <div class="alert alert-warning">
                <?php foreach($headerWarnings as $w) echo '<div>'.h($w).'</div>'; ?>
              </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                      <tr><th>ICCID</th><th>DN</th><th>Caja</th><th>Lote</th><th>Sucursal</th><th>Operador</th><th>Estatus</th><th>Motivo</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $r): ?>
                        <tr class="<?= ($r['estatus']==='OK')?'':'table-warning' ?>">
                            <td><?= h($r['iccid']) ?></td>
                            <td><?= h($r['dn']) ?></td>
                            <td><?= h($r['caja']) ?></td>
                            <td><?= h($r['lote']) ?></td>
                            <td><?= h($r['nombre_sucursal']) ?></td>
                            <td><?= h($r['operador']) ?></td>
                            <td><?= h($r['estatus']) ?></td>
                            <td><?= h($r['motivo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="POST" class="mt-3">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="confirm_token" value="<?= h(isset($_SESSION['confirm_token']) ? $_SESSION['confirm_token'] : '') ?>">
                <div class="alert alert-warning">Se insertarán hasta <b class="text-success"><?= $contador['ok'] ?></b> registros válidos.</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_ok" name="confirm_ok">
                    <label class="form-check-label" for="confirm_ok">Entiendo y deseo continuar.</label>
                </div>
                <input type="text" class="form-control mb-2" name="confirm_word" placeholder="Escribe CARGAR">
                <button class="btn btn-success" id="btnConfirm" disabled>✅ Confirmar e Insertar</button>
                <a href="carga_masiva_sims.php" class="btn btn-outline-secondary">Cancelar</a>
            </form>
            <script>
            const chk=document.getElementById('confirm_ok'),
                  word=document.querySelector('[name=confirm_word]'),
                  btn=document.getElementById('btnConfirm');
            function toggle(){btn.disabled=!(chk.checked && word.value.trim()==='CARGAR');}
            chk.onchange=toggle; word.oninput=toggle; toggle();
            </script>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
