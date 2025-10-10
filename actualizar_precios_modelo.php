<?php
/******************************************************
 * Actualizar precios (individual + masivo)
 * - Form individual por marca|modelo|capacidad
 * - Carga masiva CSV por codigo_producto, precio_lista
 ******************************************************/

session_start();
if (!isset($_SESSION['id_usuario']) || !isset($_SESSION['rol']) || $_SESSION['rol'] != 'Admin') {
    header("Location: 403.php");
    exit();
}

date_default_timezone_set('America/Mexico_City');

/* =====================================================
   DESCARGA PLANTILLA CSV (debe ir ANTES de cualquier output)
===================================================== */
if (isset($_GET['plantilla'])) {
    // Limpia cualquier salida previa (espacios/BOM/eco accidental)
    while (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=plantilla_precios.csv');

    echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel/Windows
    echo "codigo_producto,precio_lista\n";
    echo "MIC-HON-X7B,1500\n";
    echo "IPH15-128-SILVER,17999.00\n";
    exit;
}

include 'db.php';
include 'navbar.php';

$mensaje = "";

/* =========================
   Helpers
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm_price($raw){
    $s = trim((string)$raw);
    if ($s === '') return null;
    // quita $ y espacios
    $s = str_replace(array('$',' '), '', $s);
    // coma decimal si no hay punto
    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        // miles con coma
        $s = str_replace(',', '', $s);
    }
    if (!is_numeric($s)) return null;
    $v = (float)$s;
    return $v >= 0 ? $v : null;
}

function detect_delimiter($line){
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    return ($s > $c) ? ';' : ',';
}

function read_csv_assoc($tmpPath, &$errores, $requiredHeaders){
    $errores = array();
    $fh = @fopen($tmpPath, 'r');
    if (!$fh) { $errores[] = 'No se pudo abrir el archivo.'; return array(array(), array()); }

    $first = fgets($fh);
    if ($first === false) { $errores[] = 'Archivo vacío.'; fclose($fh); return array(array(), array()); }

    // Limpia BOM y NBSP de la primera línea
    $first = str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), '', $first);
    $delim = detect_delimiter($first);
    rewind($fh);

    // Leer encabezados
    $row = fgetcsv($fh, 0, $delim);
    if ($row === false) { $errores[] = 'No se pudieron leer los encabezados.'; fclose($fh); return array(array(), array()); }

    $headers = array();
    foreach ($row as $hname) {
        // normaliza encabezados: minúsculas + trim + sin BOM/NBSP
        $h = strtolower(trim(str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), '', $hname)));
        $headers[] = $h;
    }

    // Validar requeridos
    foreach ($requiredHeaders as $req) {
        if (!in_array($req, $headers, true)) {
            $errores[] = 'Falta encabezado: '.$req;
        }
    }
    if (!empty($errores)) { fclose($fh); return array(array(), array()); }

    // Mapa de índice->nombre
    $idxMap = array();
    foreach ($headers as $i => $hn) { $idxMap[$i] = $hn; }

    // Leer filas
    $rows = array();
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $assoc = array();
        foreach ($r as $i => $val) {
            $key = isset($idxMap[$i]) ? $idxMap[$i] : ('col'.$i);
            // Limpia NBSP en celdas
            $assoc[$key] = trim(str_replace("\xC2\xA0", ' ', (string)$val));
        }
        $rows[] = $assoc;
    }
    fclose($fh);
    return array($headers, $rows);
}

/* ==========================================================
   1) PROCESAR FORMULARIO INDIVIDUAL (por modelo/RAM/capacidad)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'individual') {
    $modeloCapacidad  = isset($_POST['modelo']) ? $_POST['modelo'] : '';
    $nuevoPrecioLista = (isset($_POST['precio_lista']) && $_POST['precio_lista'] !== '') ? (float)$_POST['precio_lista'] : null;
    $nuevoPrecioCombo = (isset($_POST['precio_combo']) && $_POST['precio_combo'] !== '') ? (float)$_POST['precio_combo'] : null;

    $promocionTexto   = isset($_POST['promocion']) ? trim($_POST['promocion']) : '';
    $quitarPromo      = isset($_POST['limpiar_promocion']);

    if ($modeloCapacidad) {
        $parts = explode('|', $modeloCapacidad);
        $marca = isset($parts[0]) ? $parts[0] : '';
        $modelo = isset($parts[1]) ? $parts[1] : '';
        $capacidad = isset($parts[2]) ? $parts[2] : '';

        // 1) Actualizar precio de lista en productos (con inventario Disponible / En tránsito)
        if ($nuevoPrecioLista !== null && $nuevoPrecioLista > 0){
            $sql = "
                UPDATE productos p
                INNER JOIN inventario i ON i.id_producto = p.id
                SET p.precio_lista = ?
                WHERE p.marca = ? AND p.modelo = ? AND (p.capacidad = ? OR IFNULL(p.capacidad,'') = ?)
                  AND TRIM(i.estatus) IN ('Disponible','En tránsito')
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dssss", $nuevoPrecioLista, $marca, $modelo, $capacidad, $capacidad);
            $stmt->execute();
            $afectados = $stmt->affected_rows;
            $stmt->close();
            $mensaje .= "✅ Precio de lista actualizado a $".number_format($nuevoPrecioLista,2)." ({$afectados} registros).<br>";
        }

        // 2) Upsert en precios_combo (precio combo y/o promoción)
        if (($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) || ($promocionTexto !== '') || $quitarPromo){
            $precioComboParam = ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) ? $nuevoPrecioCombo : null;
            $promocionParam   = $quitarPromo ? null : ($promocionTexto !== '' ? $promocionTexto : null);

            $sql = "
                INSERT INTO precios_combo (marca, modelo, capacidad, precio_combo, promocion)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    precio_combo = COALESCE(VALUES(precio_combo), precio_combo),
                    promocion    = VALUES(promocion)
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssds", $marca, $modelo, $capacidad, $precioComboParam, $promocionParam);
            $stmt->execute();
            $stmt->close();

            if ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) { $mensaje .= "✅ Precio combo actualizado a $".number_format($nuevoPrecioCombo,2).".<br>"; }
            if ($quitarPromo) { $mensaje .= "🧹 Promoción eliminada.<br>"; }
            elseif ($promocionTexto !== '') { $mensaje .= "✅ Promoción guardada: <i>".h($promocionTexto)."</i>.<br>"; }
        }

        if ($mensaje === "") $mensaje = "⚠️ No enviaste cambios: captura un precio o promoción.";
    } else {
        $mensaje = "⚠️ Selecciona un modelo válido.";
    }
}

/* ==========================================================
   2) CARGA MASIVA CSV (codigo_producto, precio_lista)
========================================================== */
define('PREVIEW_LIMIT', 200);
define('MAX_FILE_SIZE_MB', 8);
$REQUIRED_HEADERS = array('codigo_producto','precio_lista');

$step = (isset($_POST['accion']) && $_POST['accion']==='masivo' && isset($_POST['step'])) ? $_POST['step'] : 'form';
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

if (isset($_POST['accion']) && $_POST['accion']==='masivo' && ($step==='preview' || $step==='aplicar')) {
    $hasFile = (isset($_FILES['csv']) && isset($_FILES['csv']['error']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK);
    if (!$hasFile) {
        $result['errors'][] = 'Sube un archivo CSV válido.';
        $step = 'form';
    } else {
        $sizeBytes = isset($_FILES['csv']['size']) ? (int)$_FILES['csv']['size'] : 0;
        if (($sizeBytes/(1024*1024)) > MAX_FILE_SIZE_MB) {
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

                $clean = array();
                $seen  = array();

                for ($i=0; $i<count($rows); $i++){
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
                    if (isset($seen[$codigo])) {
                        $result['duplicates']++;
                        $result['skipped'][] = 'L'.$line.": duplicado en archivo para codigo '".$codigo."' (se usa la primera aparición).";
                        continue;
                    }
                    $seen[$codigo] = true;
                    $clean[] = array('codigo_producto'=>$codigo, 'precio_lista'=>$precio, 'line'=>$line);
                }

                if ($step === 'preview') {
                    $result['prev_rows'] = array_slice($clean, 0, PREVIEW_LIMIT);
                    $step = 'show_preview';
                } else {
                    // Aplicar cambios
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

/* =========================
   Catálogo para autocompletar del formulario
========================= */
$modelosRS = $conn->query("
    SELECT 
        p.marca, 
        p.modelo, 
        IFNULL(p.capacidad,'') AS capacidad,
        MAX(IFNULL(p.ram,'')) AS ram
    FROM productos p
    WHERE p.tipo_producto = 'Equipo'
      AND p.id IN (
            SELECT DISTINCT i.id_producto
            FROM inventario i
            WHERE TRIM(i.estatus) IN ('Disponible','En tránsito')
      )
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY LOWER(p.marca), LOWER(p.modelo), LOWER(p.capacidad)
");

$sugerencias = array();
while($m = $modelosRS->fetch_assoc()){
    $valor = $m['marca'].'|'.$m['modelo'].'|'.$m['capacidad'];
    $ramTxt = trim(isset($m['ram']) ? $m['ram'] : '');
    $capTxt = trim(isset($m['capacidad']) ? $m['capacidad'] : '');
    $label  = trim($m['marca'].' '.$m['modelo']);
    if ($ramTxt !== '') { $label .= ' · RAM: '.$ramTxt; }
    if ($capTxt !== '') { $label .= ' · Capacidad: '.$capTxt; }
    $sugerencias[] = array('label'=>$label, 'value'=>$valor);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Precios (individual + masivo)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
    <style>
      .autocomplete-list { position:absolute; z-index:1050; width:100%; max-height:260px; overflow-y:auto; background:#fff; border:1px solid #dee2e6; border-radius:.5rem; box-shadow:0 6px 18px rgba(0,0,0,.08); }
      .autocomplete-item { padding:.5rem .75rem; cursor:pointer; }
      .autocomplete-item:hover, .autocomplete-item.active { background:#f1f5f9; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-4">

    <h2>💰 Actualizar Precios</h2>
    <p>Actualiza por <b>modelo</b> (individual) o sube un <b>CSV</b> con <code>codigo_producto,precio_lista</code> para hacerlo masivo. <a href="?plantilla=1">Descargar plantilla</a>.</p>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <!-- ====== INDIVIDUAL ====== -->
      <div class="col-lg-6">
        <form id="form-precios" method="POST" class="card p-3 shadow-sm bg-white" style="position:relative;">
            <input type="hidden" name="accion" value="individual">
            <h5 class="mb-3">Actualización individual por modelo</h5>

            <div class="mb-3 position-relative">
                <label class="form-label">Modelo (con RAM y Capacidad)</label>
                <input type="text" id="buscador-modelo" class="form-control" placeholder="Ej. Samsung A15, iPhone 12…" autocomplete="off">
                <div id="lista-sugerencias" class="autocomplete-list d-none"></div>
                <input type="hidden" name="modelo" id="modelo-hidden" value="">
                <div class="form-text">Escribe y selecciona una opción de la lista.</div>
            </div>

            <div class="row">
              <div class="col-md-6 mb-3">
                  <label class="form-label">Nuevo Precio de Lista ($)</label>
                  <input type="number" step="0.01" name="precio_lista" class="form-control" placeholder="Ej. 2500.00">
                  <div class="form-text">Déjalo en blanco si no deseas cambiarlo.</div>
              </div>
              <div class="col-md-6 mb-3">
                  <label class="form-label">Nuevo Precio Combo ($)</label>
                  <input type="number" step="0.01" name="precio_combo" class="form-control" placeholder="Ej. 2199.00">
                  <div class="form-text">Déjalo en blanco para conservar el combo actual.</div>
              </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Promoción (texto informativo)</label>
                <input type="text" name="promocion" class="form-control" placeholder="Ej. Descuento $500 en enganche / Incentivo portabilidad">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="limpiar_promocion" id="limpiar_promocion">
                    <label class="form-check-label" for="limpiar_promocion">Quitar promoción (dejar en NULL)</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Actualizar</button>
                <a href="lista_precios.php" class="btn btn-secondary">Ver Lista</a>
            </div>
        </form>
      </div>

      <!-- ====== MASIVO ====== -->
      <div class="col-lg-6">
        <div class="card p-3 shadow-sm bg-white">
          <h5 class="mb-3">Actualización masiva por CSV</h5>

          <?php if (!empty($result['errors']) && $step==='form'): ?>
            <div class="alert alert-danger">
              <ul class="mb-0"><?php foreach($result['errors'] as $e){ echo '<li>'.h($e).'</li>'; } ?></ul>
            </div>
          <?php endif; ?>

          <?php if ($step === 'form'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="accion" value="masivo">
              <input type="hidden" name="step" value="preview">
              <div class="mb-3">
                <label class="form-label">Archivo CSV</label>
                <input type="file" name="csv" class="form-control" accept=".csv" required>
                <div class="form-text">Columnas requeridas: <code>codigo_producto, precio_lista</code>. Acepta coma o punto y coma; soporta <code>$</code>, comas y punto decimal.</div>
              </div>
              <button class="btn btn-outline-primary" type="submit">Previsualizar</button>
            </form>

          <?php elseif ($step === 'show_preview'): ?>
            <p>Total filas válidas: <b><?php echo count($result['prev_rows']); ?></b> (mostrando hasta <?php echo PREVIEW_LIMIT; ?>)</p>
            <?php if ($result['invalid'] || $result['duplicates']): ?>
              <div class="alert alert-warning">
                Inválidas: <?php echo (int)$result['invalid']; ?> · Duplicadas en archivo: <?php echo (int)$result['duplicates']; ?>
              </div>
            <?php endif; ?>

            <div class="table-responsive" style="max-height:320px">
              <table class="table table-sm">
                <thead><tr><th>#</th><th>codigo_producto</th><th>precio_lista</th></tr></thead>
                <tbody>
                <?php foreach($result['prev_rows'] as $i=>$r): ?>
                  <tr>
                    <td><?php echo $i+1; ?></td>
                    <td><?php echo h($r['codigo_producto']); ?></td>
                    <td><?php echo number_format((float)$r['precio_lista'],2); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if (!empty($result['skipped'])): ?>
              <details class="mt-2">
                <summary>Omitidas / Observaciones</summary>
                <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
              </details>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="mt-3">
              <input type="hidden" name="accion" value="masivo">
              <input type="hidden" name="step" value="aplicar">
              <input type="file" name="csv" class="form-control mb-2" accept=".csv" required>
              <button class="btn btn-primary" type="submit">Aplicar cambios</button>
              <a class="btn btn-secondary" href="actualizar_precios_modelo.php">Cancelar</a>
            </form>

          <?php elseif ($step === 'done'): ?>
            <div class="alert alert-success">
              <div><b>Total en archivo:</b> <?php echo (int)$result['total']; ?></div>
              <div><b>Actualizados:</b> <?php echo (int)$result['ok']; ?></div>
              <div><b>No encontrados:</b> <?php echo (int)$result['not_found']; ?></div>
              <div><b>Duplicados:</b> <?php echo (int)$result['duplicates']; ?></div>
              <div><b>Inválidos/errores:</b> <?php echo (int)$result['invalid']; ?></div>
            </div>

            <?php if (!empty($result['updated'])): ?>
              <div class="table-responsive" style="max-height:320px">
                <table class="table table-sm">
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
                      echo '<td><b>'.number_format((float)$u['precio_nuevo'],2).'</b></td>';
                      echo '</tr>';
                  }
                  ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php if (!empty($result['skipped'])): ?>
              <details class="mt-2">
                <summary>Omitidas / Observaciones</summary>
                <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
              </details>
            <?php endif; ?>

            <a class="btn btn-outline-primary mt-2" href="actualizar_precios_modelo.php">Nueva carga</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
</div>

<script>
// === Autocompletado del formulario individual ===
(function(){
  const opciones = <?php echo json_encode($sugerencias, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const $input   = document.getElementById('buscador-modelo');
  const $hidden  = document.getElementById('modelo-hidden');
  const $lista   = document.getElementById('lista-sugerencias');
  const $form    = document.getElementById('form-precios');

  if(!$input) return;

  let cursor = -1, actuales = [];
  function normaliza(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  function render(lista){
    $lista.innerHTML = '';
    if (!lista.length){ $lista.classList.add('d-none'); return; }
    lista.slice(0,50).forEach((opt, idx) => {
      const div = document.createElement('div');
      div.className = 'autocomplete-item';
      div.textContent = opt.label;
      div.dataset.value = opt.value;
      div.addEventListener('mousedown', (e)=>{ e.preventDefault(); selecciona(opt); });
      $lista.appendChild(div);
    });
    $lista.classList.remove('d-none'); cursor = -1;
  }
  function filtra(q){
    const nq = normaliza(q);
    if (!nq){ actuales=[]; render(actuales); $hidden.value=''; return; }
    actuales = opciones.filter(o => normaliza(o.label).includes(nq));
    render(actuales);
  }
  function selecciona(opt){
    $input.value  = opt.label;
    $hidden.value = opt.value;
    $lista.classList.add('d-none');
  }

  $input.addEventListener('input', ()=>{ $hidden.value=''; filtra($input.value); });
  $input.addEventListener('focus', ()=>{ if($input.value.trim()!=='') filtra($input.value); });
  document.addEventListener('click', (e)=>{ if(!($lista.contains(e.target)||$input.contains(e.target))) $lista.classList.add('d-none'); });

  $input.addEventListener('keydown', (e)=>{
    const items = Array.from($lista.querySelectorAll('.autocomplete-item'));
    if(!items.length) return;
    if(e.key==='ArrowDown'){ e.preventDefault(); cursor=Math.min(cursor+1, items.length-1); items.forEach(i=>i.classList.remove('active')); if(items[cursor]) items[cursor].classList.add('active'); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); cursor=Math.max(cursor-1, 0); items.forEach(i=>i.classList.remove('active')); if(items[cursor]) items[cursor].classList.add('active'); }
    else if(e.key==='Enter'){ if(cursor>=0 && items[cursor]){ e.preventDefault(); const opt = actuales[cursor]; if(opt) selecciona(opt); } }
    else if(e.key==='Escape'){ $lista.classList.add('d-none'); }
  });

  $form.addEventListener('submit', (e)=>{
    if(!$hidden.value){ e.preventDefault(); alert('Selecciona un modelo válido de las sugerencias.'); $input.focus(); }
  });
})();
</script>

</body>
</html>
