<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

// ========= Config =========
define('PREVIEW_LIMIT', 200); // filas máximas que se muestran en vista previa
define('TMP_DIR', sys_get_temp_dir()); // en Windows suele ser C:\Windows\Temp

$msg = '';
$previewRows = []; // solo para mostrar hasta PREVIEW_LIMIT
$contador = ['total' => 0, 'ok' => 0, 'ignoradas' => 0];

// ID sucursal Eulalia (almacén)
$idEulalia = 0;
$resEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1");
if ($resEulalia && $rowE = $resEulalia->fetch_assoc()) {
    $idEulalia = (int)$rowE['id'];
}

// Cache de búsqueda de sucursal por nombre para no consultar BD por cada fila
$sucursalCache = [];
function getSucursalIdPorNombre(mysqli $conn, string $nombre, array &$cache): int {
    $nombre = trim($nombre);
    if ($nombre === '') return 0;
    if (isset($cache[$nombre])) return $cache[$nombre];

    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();

    $cache[$nombre] = $id;
    return $id;
}

// Normalización de operador
function normalizarOperador(string $opRaw): array {
    $op = strtoupper(trim($opRaw));
    $opNoSpaces = str_replace(' ', '', $op);
    if ($op === '' || $op === 'BAIT') return ['Bait', true];
    if ($op === 'AT&T' || $opNoSpaces === 'ATT') return ['AT&T', true];
    return [$opRaw, false];
}

// ========= Paso 1: PREVIEW =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview' && isset($_FILES['archivo_csv'])) {

    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = "❌ Error al subir el archivo.";
    } else {
        $nombreOriginal = $_FILES['archivo_csv']['name'];
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $msg = "Convierte tu Excel a <b>CSV UTF-8</b> y súbelo de nuevo.";
        } else {
            // mover a ruta temporal persistente para usarla en 'insertar'
            $tmpName = "sims_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . ".csv";
            $tmpPath = rtrim(TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tmpName;

            if (!move_uploaded_file($_FILES['archivo_csv']['tmp_name'], $tmpPath)) {
                $msg = "❌ No se pudo mover el archivo a temporal.";
            } else {
                // guardamos ruta en sesión para el siguiente paso
                $_SESSION['carga_sims_tmp'] = $tmpPath;

                // token de confirmación (anti reenvío accidental)
                $_SESSION['confirm_token'] = bin2hex(random_bytes(16));

                // abrir y leer para preview (solo primeras PREVIEW_LIMIT filas)
                $fh = fopen($tmpPath, 'r');
                if (!$fh) {
                    $msg = "❌ No se pudo abrir el archivo para vista previa.";
                } else {
                    $fila = 0;
                    while (($data = fgetcsv($fh, 0, ",")) !== false) {
                        $fila++;
                        if ($fila === 1) continue; // encabezado

                        // CSV: iccid, dn, caja_id, sucursal, operador
                        $iccid           = trim($data[0] ?? '');
                        $dn              = trim($data[1] ?? '');
                        $caja            = trim($data[2] ?? '');
                        $nombre_sucursal = trim($data[3] ?? '');
                        $operadorRaw     = trim($data[4] ?? '');

                        // sucursal
                        if ($nombre_sucursal === '') {
                            $id_sucursal     = $idEulalia;
                            $nombre_sucursal = 'Eulalia (por defecto)';
                        } else {
                            $id_sucursal = getSucursalIdPorNombre($conn, $nombre_sucursal, $sucursalCache);
                        }

                        // operador
                        [$operador, $opValido] = normalizarOperador($operadorRaw);

                        // validación
                        $estatus = 'OK';
                        $motivo  = 'Listo para insertar';

                        if ($iccid === '') {
                            $estatus = 'Ignorada'; $motivo = 'ICCID vacío';
                        } elseif ($id_sucursal === 0) {
                            $estatus = 'Ignorada'; $motivo = 'Sucursal no encontrada';
                        } elseif (!$opValido) {
                            $estatus = 'Ignorada'; $motivo = 'Operador inválido (usa Bait o AT&T)';
                        } else {
                            // duplicado
                            $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
                            $stmtDup->bind_param("s", $iccid);
                            $stmtDup->execute();
                            $stmtDup->store_result();
                            if ($stmtDup->num_rows > 0) {
                                $estatus = 'Ignorada'; $motivo = 'Duplicado en base';
                            }
                            $stmtDup->close();
                        }

                        $contador['total']++;
                        if ($estatus === 'OK') $contador['ok']++; else $contador['ignoradas']++;

                        // solo guardamos las primeras N filas para la tabla de vista previa
                        if (count($previewRows) < PREVIEW_LIMIT) {
                            $previewRows[] = [
                                'iccid'    => $iccid,
                                'dn'       => $dn,
                                'caja'     => $caja,
                                'sucursal' => $nombre_sucursal,
                                'operador' => $operador,
                                'estatus'  => $estatus,
                                'motivo'   => $motivo
                            ];
                        }
                    }
                    fclose($fh);
                }
            }
        }
    }
}

// ========= Paso 2: INSERTAR (streaming) + CSV de resultado =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insertar') {
    // Validación de confirmación del usuario
    $word = trim($_POST['confirm_word'] ?? '');
    $chk  = isset($_POST['confirm_ok']) ? 1 : 0;
    $token_recv = $_POST['confirm_token'] ?? '';
    $token_sess = $_SESSION['confirm_token'] ?? '';

    if ($word !== 'CARGAR' || $chk !== 1 || $token_recv === '' || $token_recv !== $token_sess) {
        echo "❌ Confirmación inválida. Marca la casilla y escribe CARGAR para continuar.";
        exit;
    }

    $tmpPath = $_SESSION['carga_sims_tmp'] ?? '';
    if ($tmpPath === '' || !is_file($tmpPath)) {
        echo "❌ No se encontró el archivo temporal. Repite la carga.";
        exit;
    }

    // headers de salida CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_carga_sims.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['iccid', 'dn', 'caja', 'sucursal', 'operador', 'estatus_final', 'motivo']);

    // preparar insert
    $sqlInsert = "INSERT INTO inventario_sims 
        (iccid, dn, caja_id, id_sucursal, operador, estatus, fecha_ingreso)
        VALUES (?, ?, ?, ?, ?, 'Disponible', NOW())";
    $stmtInsert = $conn->prepare($sqlInsert);

    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
        fputcsv($out, ['', '', '', '', '', 'ERROR', 'No se pudo abrir el archivo temporal']);
        fclose($out);
        exit;
    }

    // reusar cache de sucursal
    $sucursalCache = [];
    $fila = 0;

    while (($data = fgetcsv($fh, 0, ",")) !== false) {
        $fila++;
        if ($fila === 1) continue; // encabezado

        $iccid           = trim($data[0] ?? '');
        $dn              = trim($data[1] ?? '');
        $caja            = trim($data[2] ?? '');
        $nombre_sucursal = trim($data[3] ?? '');
        $operadorRaw     = trim($data[4] ?? '');

        // sucursal
        if ($nombre_sucursal === '') {
            $id_sucursal     = $idEulalia;
            $nombre_sucursal = 'Eulalia (por defecto)';
        } else {
            $id_sucursal = getSucursalIdPorNombre($conn, $nombre_sucursal, $sucursalCache);
        }

        // operador
        [$operador, $opValido] = normalizarOperador($operadorRaw);

        // validaciones igual que preview
        $estatusFinal = 'Ignorada';
        $motivo       = 'N/A';

        if ($iccid === '') {
            $motivo = 'ICCID vacío';
        } elseif ($id_sucursal === 0) {
            $motivo = 'Sucursal no encontrada';
        } elseif (!$opValido) {
            $motivo = 'Operador inválido (usa Bait o AT&T)';
        } else {
            // duplicado
            $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
            $stmtDup->bind_param("s", $iccid);
            $stmtDup->execute();
            $stmtDup->store_result();
            if ($stmtDup->num_rows > 0) {
                $motivo = 'Duplicado en base';
            } else {
                // insertar
                $stmtInsert->bind_param("sssis", $iccid, $dn, $caja, $id_sucursal, $operador);
                if ($stmtInsert->execute()) {
                    $estatusFinal = 'Insertada';
                    $motivo = 'OK';
                } else {
                    $motivo = 'Error en inserción';
                }
            }
            $stmtDup->close();
        }

        fputcsv($out, [
            $iccid, $dn, $caja, $nombre_sucursal, $operador, $estatusFinal, $motivo
        ]);
    }

    fclose($fh);
    fclose($out);

    // limpiar archivo temporal y token
    @unlink($tmpPath);
    unset($_SESSION['carga_sims_tmp'], $_SESSION['confirm_token']);
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
<div class="container mt-4">
    <h2>Carga Masiva de SIMs</h2>
    <a href="dashboard_unificado.php" class="btn btn-secondary mb-3">← Volver al Dashboard</a>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <?php if (!isset($_POST['action']) || ($_POST['action'] ?? '') === ''): ?>
        <!-- Subir CSV -->
        <div class="card p-4 shadow-sm bg-white">
            <h5>Subir Archivo CSV</h5>
            <p>
                Columnas (en este orden): <b>iccid, dn, caja_id, sucursal, operador</b>.<br>
                • Si <b>sucursal</b> está vacía, se asigna <b>Eulalia</b>.<br>
                • Si <b>operador</b> está vacío, se usa <b>Bait</b>. (Permitidos: Bait, AT&amp;T)
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <div class="mb-3">
                    <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                </div>
                <button class="btn btn-primary">👀 Vista Previa</button>
            </form>
        </div>

    <?php elseif (($_POST['action'] ?? '') === 'preview'): ?>
        <!-- Vista previa limitada + totales + confirmación -->
        <div class="card p-4 shadow-sm bg-white">
            <h5>Vista Previa</h5>
            <p class="mb-1">
                Total filas detectadas: <b><?= $contador['total'] ?></b> &nbsp; | &nbsp;
                Listas para insertar: <b class="text-success"><?= $contador['ok'] ?></b> &nbsp; | &nbsp;
                Ignoradas: <b class="text-danger"><?= $contador['ignoradas'] ?></b>
            </p>
            <?php if ($contador['total'] > PREVIEW_LIMIT): ?>
                <p class="text-muted">Mostrando solo las primeras <?= PREVIEW_LIMIT ?> filas…</p>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>ICCID</th>
                            <th>DN</th>
                            <th>Caja</th>
                            <th>Sucursal</th>
                            <th>Operador</th>
                            <th>Estatus</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $r): ?>
                        <tr class="<?= ($r['estatus'] === 'OK') ? '' : 'table-warning' ?>">
                            <td><?= htmlspecialchars($r['iccid']) ?></td>
                            <td><?= htmlspecialchars($r['dn']) ?></td>
                            <td><?= htmlspecialchars($r['caja']) ?></td>
                            <td><?= htmlspecialchars($r['sucursal']) ?></td>
                            <td><?= htmlspecialchars($r['operador']) ?></td>
                            <td><?= htmlspecialchars($r['estatus']) ?></td>
                            <td><?= htmlspecialchars($r['motivo']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="mt-3" id="confirmForm">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($_SESSION['confirm_token'] ?? '') ?>">

                <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <div>
                        <b>Confirmación requerida:</b> Se insertarán hasta
                        <b class="text-success"><?= $contador['ok'] ?></b> registros válidos.
                        Esta acción no se puede deshacer desde aquí.
                    </div>
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_ok" name="confirm_ok">
                    <label class="form-check-label" for="confirm_ok">
                        Entiendo y deseo continuar con la carga.
                    </label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Escribe <b>CARGAR</b> para continuar</label>
                    <input type="text" class="form-control" name="confirm_word" id="confirm_word" placeholder="CARGAR">
                </div>

                <button class="btn btn-success" id="btnConfirm" disabled>✅ Confirmar e Insertar (descarga CSV de resultado)</button>
                <a href="carga_masiva_sims.php" class="btn btn-outline-secondary">Cancelar</a>
            </form>
        </div>

        <script>
        (function(){
            const chk = document.getElementById('confirm_ok');
            const word = document.getElementById('confirm_word');
            const btn = document.getElementById('btnConfirm');

            function toggle() {
                btn.disabled = !(chk.checked && word.value.trim() === 'CARGAR');
            }
            chk.addEventListener('change', toggle);
            word.addEventListener('input', toggle);
            toggle();
        })();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
