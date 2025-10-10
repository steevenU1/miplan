<?php
// actualizar_precios_csv.php — Actualización masiva por codigo_producto (compatible sin ?? ni ?->)

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'Admin') {
    http_response_code(403);
    exit('Sin permisos');
}

/* =========================
   Configuración
========================= */
define('PREVIEW_LIMIT', 200);
define('MAX_FILE_SIZE_MB', 8);
$REQUIRED_HEADERS = array('codigo_producto','precio_lista');
date_default_timezone_set('America/Mexico_City');

/* =========================
   Helpers
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm_price($raw){
    $s = trim((string)$raw);
    if ($s === '') return null;

    $s = str_replace(array('$',' '), '', $s);

    // Si tiene coma y no punto, asumimos coma decimal
    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        // Quitar separador de miles con coma
        $s = str_replace(',', '', $s);
    }

    if (!is_numeric($s)) return null;
    $v = (float)$s;
    return ($v >= 0) ? $v : null;
}

function detect_delimiter($line){
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    return ($s > $c) ? ';' : ',';
}

function read_csv_assoc($tmpPath, &$errores, $REQUIRED_HEADERS){
    $errores = array();
    $fh = @fopen($tmpPath, 'r');
    if (!$fh){
        $errores[] = 'No se pudo abrir el archivo.';
        return array(array(), array());
    }

    $first = fgets($fh);
    if ($first === false){
        $errores[] = 'Archivo vacío.';
        fclose($fh);
        return array(array(), array());
    }
    // Quitar BOM
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $delim = detect_delimiter($first);
    rewind($fh);

    $headers = array();
    $rows    = array();

    $row = fgetcsv($fh, 0, $delim);
    if ($row === false) {
        $errores[] = 'No se pudo leer encabezados del CSV.';
        fclose($fh);
        return array(array(), array());
    }
    foreach ($row as $hname) {
        $headers[] = strtolower(trim($hname));
    }

    // Validar requeridos
    foreach ($REQUIRED_HEADERS as $req) {
        if (!in_array($req, $headers, true)) {
            $errores[] = 'Falta el encabezado requerido: ' . $req;
        }
    }
    if (!empty($errores)) { fclose($fh); return array(array(), array()); }

    $idxMap = array();
    foreach ($headers as $i => $hname) { $idxMap[$i] = $hname; }

    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $assoc = array();
        foreach ($r as $i => $val) {
            $key = isset($idxMap[$i]) ? $idxMap[$i] : ('col'.$i);
            $assoc[$key] = trim((string)$val);
        }
        $rows[] = $assoc;
    }
    fclose($fh);
    return array($headers, $rows);
}

/* =========================
   Plantilla
========================= */
if (isset($_GET['plantilla'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=plantilla_precios.csv');
    echo "\xEF\xBB\xBF";
    echo "codigo_producto,precio_lista\n";
    echo "IPH15-128-SILVER,17999.00\n";
    echo "S24-256-BLACK,19999\n";
    exit;
}

/* =========================
   Flujo
========================= */
$step = isset($_POST['step']) ? $_POST['step'] : 'form';

$result = array(
    'total'      => 0,
    'prev_rows'  => array(),
    'ok'         => 0,
    'not_found'  => 0,
    'invalid'    => 0,
    'duplicates' => 0,
    'updated'    => array(),
    'skipped'    => array(),
    'errors'     => array()
);

if ($step === 'preview' || $step === 'aplicar') {
    $hasFile = (isset($_FILES['csv']) && isset($_FILES['csv']['error']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK);
    if (!$hasFile) {
        $result['errors'][] = 'Sube un archivo CSV válido.';
        $step = 'form';
    } else {
        $sizeBytes = isset($_FILES['csv']['size']) ? (int)$_FILES['csv']['size'] : 0;
        $sizeMB = $sizeBytes / (1024*1024);
        if ($sizeMB > MAX_FILE_SIZE_MB) {
            $result['errors'][] = 'El archivo excede el tamaño permitido.';
            $step = 'form';
        } else {
            $tmp = $_FILES['csv']['tmp_name'];
            $errs = array();
            list($headers, $rows) = read_csv_assoc($tmp, $errs, $REQUIRED_HEADERS);
            if (!empty($errs)) {
                $result['errors'] = array_merge($result['errors'], $errs);
                $step = 'form';
            } else {
                $result['total'] = count($rows);

                $clean    = array();
                $seenCode = array();

                for ($i=0; $i<count($rows); $i++) {
                    $r = $rows[$i];
                    $line = $i + 2;

                    $codigo = isset($r['codigo_producto']) ? trim($r['codigo_producto']) : '';
                    $precio = isset($r['precio_lista']) ? norm_price($r['precio_lista']) : null;

                    if ($codigo === '' || $precio === null) {
                        $result['invalid']++;
                        $rawPrecio = isset($r['precio_lista']) ? $r['precio_lista'] : '';
                        $result['skipped'][] = 'L'.$line.": inválido (codigo='".$codigo."', precio='".$rawPrecio."')";
                        continue;
                    }

                    if (isset($seenCode[$codigo])) {
                        $result['duplicates']++;
                        $result['skipped'][] = 'L'.$line.": duplicado en archivo para codigo '".$codigo."' (se usa la primera aparición).";
                        continue;
                    }

                    $seenCode[$codigo] = true;
                    $clean[] = array('codigo_producto'=>$codigo, 'precio_lista'=>$precio, 'line'=>$line);
                }

                if ($step === 'preview') {
                    $result['prev_rows'] = array_slice($clean, 0, PREVIEW_LIMIT);
                    $step = 'show_preview';
                } else {
                    // Aplicar
                    $conn->begin_transaction();
                    try {
                        $stmtSel = $conn->prepare("SELECT id, precio_lista FROM productos WHERE codigo_producto = ? LIMIT 1");
                        $stmtUpd = $conn->prepare("UPDATE productos SET precio_lista = ? WHERE id = ?");

                        foreach ($clean as $row) {
                            $codigo = $row['codigo_producto'];
                            $precio = $row['precio_lista'];

                            $stmtSel->bind_param('s', $codigo);
                            $stmtSel->execute();
                            $res = $stmtSel->get_result();
                            $prod = $res ? $res->fetch_assoc() : null;

                            if (!$prod) {
                                $result['not_found']++;
                                $result['skipped'][] = 'L'.$row['line'].": no existe codigo_producto '".$codigo."'";
                                continue;
                            }

                            $idProd = (int)$prod['id'];
                            $stmtUpd->bind_param('di', $precio, $idProd);
                            if ($stmtUpd->execute()) {
                                $result['ok']++;
                                $precioAnt = isset($prod['precio_lista']) ? (float)$prod['precio_lista'] : 0.0;
                                $result['updated'][] = array(
                                    'codigo_producto' => $codigo,
                                    'precio_anterior' => $precioAnt,
                                    'precio_nuevo'    => (float)$precio
                                );
                            } else {
                                $result['invalid']++;
                                $result['skipped'][] = 'L'.$row['line'].": error al actualizar '".$codigo."'";
                            }
                        }

                        $conn->commit();
                        $step = 'done';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $result['errors'][] = 'Se canceló la operación: '.$e->getMessage();
                        $step = 'form';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Actualización masiva de precio lista</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:20px;background:#f8fafc;color:#0f172a}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;max-width:960px;margin:0 auto 24px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    h1{font-size:20px;margin:0 0 12px}
    .muted{color:#475569}
    .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #0ea5e9;background:#0ea5e9;color:#fff;text-decoration:none;cursor:pointer}
    .btn.secondary{background:#fff;color:#0ea5e9}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #e2e8f0;padding:8px;text-align:left;font-size:14px}
    .ok{color:#16a34a}.bad{color:#dc2626}.warn{color:#a16207}
    code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
    ul{margin:6px 0 0 18px}
  </style>
</head>
<body>

<div class="card">
  <h1>Actualización masiva de <em>precio_lista</em> por <em>codigo_producto</em></h1>
  <p class="muted">Sube un CSV con <code>codigo_producto</code> y <code>precio_lista</code>. Descarga la <a href="?plantilla=1">plantilla</a>.</p>
</div>

<?php if ($step === 'form'): ?>
  <?php if (!empty($result['errors'])): ?>
    <div class="card"><p class="bad"><strong>Errores:</strong></p><ul>
      <?php foreach($result['errors'] as $e){ echo '<li>'.h($e).'</li>'; } ?>
    </ul></div>
  <?php endif; ?>
  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="step" value="preview">
      <p><input type="file" name="csv" accept=".csv" required></p>
      <p class="muted">Tamaño máx: <?php echo (int)MAX_FILE_SIZE_MB; ?> MB. Separador: coma o punto y coma.</p>
      <button class="btn" type="submit">Previsualizar</button>
    </form>
  </div>

<?php elseif ($step === 'show_preview'): ?>
  <div class="card">
    <p>Total filas válidas (sin duplicados locales): <strong><?php echo count($result['prev_rows']); ?></strong> (máx <?php echo PREVIEW_LIMIT; ?> mostradas)</p>
    <?php if ($result['invalid'] || $result['duplicates']): ?>
      <p class="warn">Advertencias: inválidas=<?php echo (int)$result['invalid']; ?>, duplicadas=<?php echo (int)$result['duplicates']; ?>.</p>
    <?php endif; ?>
    <div style="max-height:420px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px">
      <table>
        <thead><tr><th>#</th><th>codigo_producto</th><th>precio_lista</th></tr></thead>
        <tbody>
        <?php foreach($result['prev_rows'] as $i=>$r): ?>
          <tr>
            <td><?php echo ($i+1); ?></td>
            <td><?php echo h($r['codigo_producto']); ?></td>
            <td><?php echo number_format((float)$r['precio_lista'],2); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($result['skipped'])): ?>
      <p class="muted">Omitidas/observaciones:</p>
      <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:16px">
      <input type="hidden" name="step" value="aplicar">
      <input type="file" name="csv" accept=".csv" required>
      <button class="btn" type="submit">Aplicar cambios</button>
      <a class="btn secondary" href="actualizar_precios_csv.php">Cancelar</a>
    </form>
  </div>

<?php elseif ($step === 'done'): ?>
  <div class="card">
    <h1>Resultado de la carga</h1>
    <p><strong>Total en archivo:</strong> <?php echo (int)$result['total']; ?></p>
    <p class="ok"><strong>Actualizados:</strong> <?php echo (int)$result['ok']; ?></p>
    <p class="warn"><strong>No encontrados:</strong> <?php echo (int)$result['not_found']; ?></p>
    <p class="warn"><strong>Duplicados en archivo:</strong> <?php echo (int)$result['duplicates']; ?></p>
    <p class="warn"><strong>Inválidos/errores:</strong> <?php echo (int)$result['invalid']; ?></p>

    <?php if (!empty($result['updated'])): ?>
      <h3>Cambios aplicados (primeros <?php echo min(100, count($result['updated'])); ?>)</h3>
      <div style="max-height:420px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px">
        <table>
          <thead><tr><th>#</th><th>codigo_producto</th><th>precio_anterior</th><th>precio_nuevo</th></tr></thead>
          <tbody>
          <?php
          $lim = min(100, count($result['updated']));
          for ($i=0; $i<$lim; $i++){
              $u = $result['updated'][$i];
              echo '<tr>';
              echo '<td>'.($i+1).'</td>';
              echo '<td>'.h($u['codigo_producto']).'</td>';
              echo '<td>'.number_format((float)$u['precio_anterior'],2).'</td>';
              echo '<td><strong>'.number_format((float)$u['precio_nuevo'],2).'</strong></td>';
              echo '</tr>';
          }
          ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($result['skipped'])): ?>
      <h3>Omitidas / Observaciones</h3>
      <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
    <?php endif; ?>

    <p style="margin-top:16px">
      <a class="btn" href="actualizar_precios_csv.php">Nueva carga</a>
    </p>
  </div>
<?php endif; ?>

</body>
</html>
