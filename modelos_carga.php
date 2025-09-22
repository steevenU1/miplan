<?php
// modelos_carga.php — Carga masiva temporal al catálogo de modelos vía CSV
// Requiere rol: Admin o Gerente

session_start();
if (isset($_GET['debug'])) { ini_set('display_errors', 1); error_reporting(E_ALL); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';
include 'navbar.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$permEscritura = in_array($ROL, ['Admin','Gerente']);
if (!$permEscritura) { die("No autorizado."); }

// ==== Helpers ====
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function toNull($s){ $s = trim((string)$s); return $s===''? null : $s; }
function toDecOrNull($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  // "1.234,56", "1,234.56" o "1234.56"
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { $s = str_replace('.','',$s); $s = str_replace(',', '.', $s); }
  else { $s = str_replace(',', '', $s); }
  return is_numeric($s) ? number_format((float)$s, 2, '.', '') : null;
}
function normDateOrNull($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  return null;
}
function slug($s){
  $s = mb_strtolower(trim((string)$s), 'UTF-8');
  $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
  $s = preg_replace('/[^a-z0-9_]+/','_',$s);
  return trim($s,'_');
}

// ==== Config de columnas soportadas ====
$columns = [
  'marca'             => ['required'=>true],
  'modelo'            => ['required'=>true],
  'color'             => ['required'=>false],
  'ram'               => ['required'=>false],
  'capacidad'         => ['required'=>false],
  'codigo_producto'   => ['required'=>false],
  'descripcion'       => ['required'=>false],
  'nombre_comercial'  => ['required'=>false],
  'compania'          => ['required'=>false],
  'financiera'        => ['required'=>false],
  'fecha_lanzamiento' => ['required'=>false, 'type'=>'date'],
  'precio_lista'      => ['required'=>false, 'type'=>'decimal'],
  'tipo_producto'     => ['required'=>false],
  'subtipo'           => ['required'=>false],
  'gama'              => ['required'=>false],
  'ciclo_vida'        => ['required'=>false],
  'abc'               => ['required'=>false],
  'operador'          => ['required'=>false],
  'resurtible'        => ['required'=>false], // "Sí"/"No" o 1/0
  'activo'            => ['required'=>false], // 1/0; si no viene -> 1
];

$report = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accion']) && $_POST['accion']==='cargar' && isset($_FILES['csv'])) {
  $soloValidar  = isset($_POST['solo_validar']);
  $hacerUpsert  = isset($_POST['upsert']);
  $file         = $_FILES['csv'];

  if ($file['error'] !== UPLOAD_ERR_OK) {
    $report = ['ok'=>false,'msg'=>'Error al subir archivo (código '.$file['error'].').'];
  } elseif ($file['size'] > 5*1024*1024) {
    $report = ['ok'=>false,'msg'=>'El archivo excede 5MB.'];
  } else {
    $path = $file['tmp_name'];
    $fh = fopen($path, 'r');
    if (!$fh) {
      $report = ['ok'=>false,'msg'=>'No se pudo leer el CSV.'];
    } else {
      // Detectar BOM UTF-8
      $bom = fread($fh, 3);
      if ($bom !== "\xEF\xBB\xBF") fseek($fh, 0);

      // Leer encabezados
      $headers = fgetcsv($fh, 0, ',');
      if (!$headers) {
        $report = ['ok'=>false,'msg'=>'El CSV no tiene encabezados.'];
      } else {
        $hdrMap = [];
        foreach ($headers as $idx=>$h) $hdrMap[$idx] = slug($h);
        $have = array_values($hdrMap);
        if (!in_array('marca', $have) || !in_array('modelo', $have)) {
          $report = ['ok'=>false,'msg'=>'Encabezados mínimos requeridos: marca, modelo.'];
        } else {
          $rows = [];
          $line = 1;
          while (($row = fgetcsv($fh, 0, ',')) !== false) {
            $line++;
            // sin arrow function para compatibilidad PHP <7.4
            $nonEmpty = array_filter($row, function($x){ return trim((string)$x) !== ''; });
            if (count($nonEmpty)===0) continue;

            $data = array_fill_keys(array_keys($columns), null);
            foreach ($row as $i=>$val) {
              $col = isset($hdrMap[$i]) ? $hdrMap[$i] : null;
              if ($col && array_key_exists($col, $columns)) $data[$col] = trim((string)$val);
            }
            $rows[] = ['line'=>$line, 'data'=>$data];
          }
          fclose($fh);

          if (count($rows)===0) {
            $report = ['ok'=>false,'msg'=>'No se encontraron filas con datos.'];
          } else {
            // Validaciones
            $errores = [];
            $okRows  = [];

            $YES = ['si','sí','1','yes','true'];
            $NO  = ['no','0','false'];
            $TRUEY = ['1','si','sí','true','yes'];

            foreach ($rows as $r) {
              $ln = $r['line']; $d = $r['data'];

              // requeridos
              foreach ($columns as $k=>$meta) {
                if (!empty($meta['required']) && (trim((string)($d[$k] ?? ''))==='')) {
                  $errores[] = "L{$ln}: el campo '$k' es obligatorio.";
                }
              }

              // tipos
              if (isset($d['fecha_lanzamiento']) && $d['fecha_lanzamiento']!=='') {
                $norm = normDateOrNull($d['fecha_lanzamiento']);
                if ($norm===null) $errores[]="L{$ln}: fecha_lanzamiento inválida (usa YYYY-MM-DD).";
                else $d['fecha_lanzamiento'] = $norm;
              }
              if (isset($d['precio_lista']) && $d['precio_lista']!=='') {
                $dec = toDecOrNull($d['precio_lista']);
                if ($dec===null) $errores[]="L{$ln}: precio_lista inválido.";
                else $d['precio_lista'] = $dec;
              }

              // normalizaciones
              if ($d['resurtible']!=='') {
                $val = mb_strtolower((string)$d['resurtible'],'UTF-8');
                if (in_array($val, $YES, true))      $d['resurtible'] = 'Sí';
                elseif (in_array($val, $NO, true))   $d['resurtible'] = 'No';
              } else {
                $d['resurtible'] = null;
              }

              if ($d['activo']==='') {
                $d['activo'] = 1;
              } else {
                $val = mb_strtolower((string)$d['activo'],'UTF-8');
                $d['activo'] = in_array($val, $TRUEY, true) ? 1 : 0;
              }

              $okRows[] = ['line'=>$ln, 'data'=>$d];
            }

            if (!empty($errores)) {
              $report = ['ok'=>false,'msg'=>'Se encontraron errores de validación.','errores'=>$errores];
            } else {
              // INSERT / UPSERT (MariaDB: usar VALUES(col))
              $insertCols = array_keys($columns);
              $placeholders = implode(',', array_fill(0, count($insertCols), '?'));

              $updateSet = [];
              foreach ($insertCols as $c) {
                // evita sobreescribir llaves / índices
                if (in_array($c, ['marca','modelo','color','ram','capacidad','codigo_producto'])) continue;
                $updateSet[] = "$c = VALUES($c)";
              }

              $sqlUpsert = "INSERT INTO catalogo_modelos (".implode(',', $insertCols).")
                            VALUES ($placeholders)
                            ON DUPLICATE KEY UPDATE ".implode(',', $updateSet);

              $sqlInsertOnly = "INSERT INTO catalogo_modelos (".implode(',', $insertCols).")
                                VALUES ($placeholders)";

              $stmt = $conn->prepare($hacerUpsert ? $sqlUpsert : $sqlInsertOnly);
              if (!$stmt) {
                $report = ['ok'=>false,'msg'=>'Error preparando sentencia: '.$conn->error];
              } else {
                $conn->begin_transaction();
                $tot = 0; $ins = 0; $upd = 0; $skp = 0; $errs = [];

                try {
                  foreach ($okRows as $r) {
                    $tot++;
                    $d = $r['data'];

                    // Bind en el orden de $insertCols
                    $vals = [];
                    foreach ($insertCols as $c) {
                      $v = isset($d[$c]) ? $d[$c] : null;
                      if ($c==='precio_lista')         $v = toDecOrNull($v);
                      if ($c==='fecha_lanzamiento')    $v = normDateOrNull($v);
                      if ($c==='descripcion')          $v = toNull($v);
                      if ($c==='nombre_comercial')     $v = toNull($v);
                      if ($c==='compania')             $v = toNull($v);
                      if ($c==='financiera')           $v = toNull($v);
                      if ($c==='tipo_producto')        $v = toNull($v);
                      if ($c==='subtipo')              $v = toNull($v);
                      if ($c==='gama')                 $v = toNull($v);
                      if ($c==='ciclo_vida')           $v = toNull($v);
                      if ($c==='abc')                  $v = toNull($v);
                      if ($c==='operador')             $v = toNull($v);
                      if ($c==='resurtible')           $v = toNull($v);
                      if ($c==='activo')               $v = ($v===null?1:(int)$v);
                      $vals[] = $v;
                    }

                    $types = '';
                    foreach ($insertCols as $c) {
                      if ($c==='precio_lista') $types.='d';
                      elseif ($c==='activo')   $types.='i';
                      else                     $types.='s';
                    }

                    $stmt->bind_param($types, ...$vals);

                    if ($soloValidar) {
                      continue; // no ejecutar
                    }

                    if (!$stmt->execute()) {
                      if ($conn->errno===1062) { $skp++; }
                      else { $errs[] = "L".$r['line'].": Error MySQL ".$conn->errno." - ".$conn->error; }
                    } else {
                      // En MariaDB: affected_rows = 2 cuando hace UPDATE por duplicate
                      if ($hacerUpsert) {
                        if ($conn->affected_rows===1) $ins++;
                        elseif ($conn->affected_rows===2) $upd++;
                        else $ins++;
                      } else {
                        $ins++;
                      }
                    }
                  }

                  if ($soloValidar) {
                    $conn->rollback();
                    $report = ['ok'=>true,'msg'=>'Validación exitosa. No se escribieron datos.','tot'=>$tot,'ins'=>0,'upd'=>0,'skp'=>0,'errores'=>$errs];
                  } else {
                    if (!empty($errs)) {
                      $conn->rollback();
                      $report = ['ok'=>false,'msg'=>'Errores en la ejecución. No se guardaron cambios.','errores'=>$errs];
                    } else {
                      $conn->commit();
                      $report = ['ok'=>true,'msg'=>'Carga completada.','tot'=>$tot,'ins'=>$ins,'upd'=>$upd,'skp'=>$skp];
                    }
                  }

                } catch (Exception $e) {
                  $conn->rollback();
                  $report = ['ok'=>false,'msg'=>'Excepción: '.$e->getMessage()];
                } finally {
                  $stmt->close();
                }
              }
            }
          }
        }
      }
    }
  }
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Carga masiva · Catálogo de Modelos (CSV)</h3>
    <a href="modelos.php" class="btn btn-outline-secondary btn-sm">Volver a Catálogo</a>
  </div>

  <div class="alert alert-warning">
    <strong>Temporal:</strong> esta pantalla es solo para la <em>carga inicial</em>. Asegúrate de que el CSV esté en <strong>UTF-8</strong>.
    <div class="small mt-1">¿Atorado? abre <code>modelos_carga.php?debug=1</code> para ver errores en vivo.</div>
  </div>

  <?php if ($report): ?>
    <div class="alert alert-<?= $report['ok'] ? 'success' : 'danger' ?>">
      <div><?= esc($report['msg']) ?></div>
      <?php if (isset($report['tot'])): ?>
        <div class="mt-2">
          <strong>Total filas:</strong> <?= (int)$report['tot'] ?> |
          <strong>Insertadas:</strong> <?= (int)($report['ins'] ?? 0) ?> |
          <strong>Actualizadas:</strong> <?= (int)($report['upd'] ?? 0) ?> |
          <strong>Saltadas:</strong> <?= (int)($report['skp'] ?? 0) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($report['errores'])): ?>
        <hr>
        <ul class="mb-0">
          <?php foreach ($report['errores'] as $e): ?>
            <li><?= esc($e) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header">Subir CSV</div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="accion" value="cargar">
        <div class="col-12">
          <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
          <div class="form-text">Tamaño máx: 5 MB. Separador: coma. Primera fila: encabezados.</div>
        </div>
        <div class="col-md-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="solo_validar" name="solo_validar">
            <label class="form-check-label" for="solo_validar">Solo validar (no guardar)</label>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="upsert" name="upsert" checked>
            <label class="form-check-label" for="upsert">Actualizar si ya existe (UPSERT)</label>
          </div>
        </div>
        <div class="col-12 text-end">
          <button class="btn btn-primary">Procesar CSV</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm mt-4">
    <div class="card-header">Formato de CSV (encabezados soportados)</div>
    <div class="card-body">
      <p class="mb-2">
        <strong>Requeridos:</strong> <code>marca</code>, <code>modelo</code><br>
        <strong>Opcionales:</strong>
        <code>color</code>, <code>ram</code>, <code>capacidad</code>, <code>codigo_producto</code>,
        <code>descripcion</code>, <code>nombre_comercial</code>, <code>compania</code>, <code>financiera</code>,
        <code>fecha_lanzamiento</code> (YYYY-MM-DD), <code>precio_lista</code>,
        <code>tipo_producto</code>, <code>subtipo</code>, <code>gama</code>, <code>ciclo_vida</code>, <code>abc</code>, <code>operador</code>, <code>resurtible</code> (Sí/No), <code>activo</code> (1/0)
      </p>
      <pre class="bg-light p-3 rounded small mb-0">marca,modelo,color,ram,capacidad,codigo_producto,descripcion,nombre_comercial,compania,financiera,fecha_lanzamiento,precio_lista,tipo_producto,subtipo,gama,ciclo_vida,abc,operador,resurtible,activo
Samsung,SM-A146,Negro,4GB,128GB,A146-N-4-128,"Gama de entrada con 90Hz",Galaxy A14,Telcel,PayJoy,2024-02-10,3999.00,Equipo,Smartphone,Media,Linea,A,Telcel,Sí,1
Apple,MT6T3,Blanco,6GB,128GB,IP14-128,"iPhone 14 6.1""",iPhone 14,,,"15/09/2022",16999.00,Equipo,Smartphone,Alta,Linea,A,,No,1</pre>
    </div>
  </div>
</div>
