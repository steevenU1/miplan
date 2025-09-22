<?php
// carga_masiva_productos.php — Carga masiva al inventario insertando en productos + inventario
// Requiere rol Admin

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php"); exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

// ===== Helpers =====
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function slug($s){
  $s = mb_strtolower(trim((string)$s), 'UTF-8');
  $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
  $s = preg_replace('/[^a-z0-9_]+/','_',$s);
  return trim($s,'_');
}
function onlyDigits($s){ return preg_replace('/\D+/', '', (string)$s); }
function numOrNull($v){
  $v = trim((string)$v);
  if ($v==='') return null;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $v)) { $v = str_replace('.','',$v); $v = str_replace(',', '.', $v); }
  else { $v = str_replace(',', '', $v); }
  return is_numeric($v) ? (float)$v : null;
}
function normDateOrNull($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  $s = str_replace(['.', '-'], '/', $s);
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $s, $m)) {
    $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3]; if ($y<100) $y+=2000;
    return checkdate($mo,$d,$y) ? sprintf('%04d-%02d-%02d',$y,$mo,$d) : null;
  }
  if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $s, $m)) {
    $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3];
    return checkdate($mo,$d,$y) ? sprintf('%04d-%02d-%02d',$y,$mo,$d) : null;
  }
  $ts = strtotime($s); return $ts? date('Y-m-d',$ts) : null;
}

// ===== Configuración de columnas soportadas (map por encabezado) =====
$COLS = [
  'codigo_producto','marca','modelo','color','ram','capacidad',
  'imei1','imei2',
  'costo','costo_con_iva','precio_lista','proveedor',
  'descripcion','nombre_comercial','compania','financiera',
  'fecha_lanzamiento',
  'tipo_producto','subtipo','gama','ciclo_vida','abc','operador','resurtible',
  // inventario
  'fecha_ingreso','sucursal',
];

// ===== Estados UI =====
$msg=''; $alert='info'; $preview=[]; $reportLink=''; $insertadas=0; $ignoradas=0;

// ===== Vista previa =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='preview' && isset($_FILES['archivo'])) {
  $fh = fopen($_FILES['archivo']['tmp_name'],'r');
  if (!$fh) { $msg='❌ No se pudo abrir el CSV.'; $alert='danger'; }
  else {
    $hdr = fgetcsv($fh,0,',');
    if (!$hdr) { $msg='❌ CSV sin encabezados.'; $alert='danger'; }
    else {
      $map = []; // idx -> colname estandarizado
      foreach ($hdr as $i=>$h) { $k = slug($h); $map[$i] = $k; }

      // Necesitamos al menos: codigo_producto, marca, modelo, sucursal, fecha_ingreso, costo, precio_lista, imei1
      $need = ['codigo_producto','marca','modelo','sucursal','fecha_ingreso','costo','precio_lista','imei1'];
      $have = array_values($map);
      $missing = array_diff($need, $have);
      if (!empty($missing)) {
        $msg = '❌ Faltan encabezados requeridos: <code>'.esc(implode(', ',$missing)).'</code>';
        $alert='danger';
      } else {
        // cache sucursales
        $sucMap = [];
        $q = $conn->query("SELECT id,nombre FROM sucursales");
        if ($q) { while($r=$q->fetch_assoc()){ $sucMap[$r['nombre']] = (int)$r['id']; } }

        $YES = ['si','sí','1','yes','true'];
        $NO  = ['no','0','false'];
        $TIPOS = ['equipo'=>'Equipo','modem'=>'Modem','accesorio'=>'Accesorio'];
        $GAMAS = ['ultra baja','baja','media baja','media','media alta','alta','premium'];
        $CICLO = ['nuevo'=>'Nuevo','linea'=>'Linea','fin de vida'=>'Fin de vida'];
        $ABC   = ['a','b','c'];

        $rownum = 1;
        while(($row=fgetcsv($fh,0,','))!==false){
          $rownum++;
          if (count(array_filter($row,fn($x)=>trim((string)$x)!=''))===0) continue;

          // arma registro base
          $r = array_fill_keys($COLS, null);
          foreach ($row as $i=>$val) {
            $col = $map[$i] ?? null;
            if ($col && in_array($col, $COLS, true)) $r[$col] = trim((string)$val);
          }

          // normalizaciones
          $r['imei1'] = onlyDigits($r['imei1']);
          $r['imei2'] = onlyDigits($r['imei2']);
          $r['costo'] = numOrNull($r['costo']);
          $r['costo_con_iva'] = numOrNull($r['costo_con_iva']);
          $r['precio_lista'] = numOrNull($r['precio_lista']);
          if ($r['costo_con_iva']===null && $r['costo']!==null) $r['costo_con_iva'] = $r['costo']; // fallback

          $r['fecha_lanzamiento'] = normDateOrNull($r['fecha_lanzamiento']);
          $r['fecha_ingreso']     = normDateOrNull($r['fecha_ingreso']) ?: date('Y-m-d');

          // clasificaciones
          $tp = mb_strtolower($r['tipo_producto']??'','UTF-8');
          $r['tipo_producto'] = $TIPOS[$tp] ?? 'Equipo';

          $gm = mb_strtolower($r['gama']??'','UTF-8');
          $r['gama'] = in_array($gm,$GAMAS,true) ? ucfirst($gm) : null;

          $cv = mb_strtolower($r['ciclo_vida']??'','UTF-8');
          $r['ciclo_vida'] = $CICLO[$cv] ?? null;

          $ab = mb_strtolower($r['abc']??'','UTF-8');
          $r['abc'] = in_array($ab,$ABC,true) ? strtoupper($ab) : null;

          $rs = mb_strtolower($r['resurtible']??'','UTF-8');
          if ($rs!=='')       $r['resurtible'] = in_array($rs,$YES,true) ? 'Sí' : (in_array($rs,$NO,true) ? 'No' : null);
          else                $r['resurtible'] = null;

          // sucursal
          $idSucursal = $sucMap[$r['sucursal']] ?? null;

          // Validaciones
          $estatus='OK'; $motivo='Listo';
          if ($r['codigo_producto']===''){ $estatus='Ignorada'; $motivo='codigo_producto vacío'; }
          if ($estatus==='OK' && !$idSucursal){ $estatus='Ignorada'; $motivo='Sucursal no encontrada'; }
          if ($estatus==='OK' && !$r['imei1']) { $estatus='Ignorada'; $motivo='IMEI1 vacío'; }
          if ($estatus==='OK' && $r['costo']===null){ $estatus='Ignorada'; $motivo='costo inválido'; }
          if ($estatus==='OK' && $r['precio_lista']===null){ $estatus='Ignorada'; $motivo='precio_lista inválido'; }
          // Duplicados por IMEI
          if ($estatus==='OK'){
            $st=$conn->prepare("SELECT id FROM productos WHERE imei1=? OR imei2=? LIMIT 1");
            $chk = $r['imei2']!=='' ? $r['imei2'] : $r['imei1'];
            $st->bind_param("ss",$r['imei1'],$chk);
            $st->execute(); $st->store_result();
            if($st->num_rows>0){ $estatus='Ignorada'; $motivo='IMEI duplicado'; }
            $st->close();
          }

          $preview[] = [
            'row' => $rownum,
            'data'=> $r,
            'id_sucursal'=>$idSucursal,
            'estatus'=>$estatus,
            'motivo'=>$motivo
          ];
        }
        fclose($fh);
        if (empty($preview)) { $msg='No se encontraron filas con datos.'; $alert='warning'; }
      }
    }
  }
}

// ===== Confirmar e insertar =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='insertar' && isset($_POST['blob'])) {
  $items = json_decode(base64_decode($_POST['blob']), true);

  $dir = __DIR__.'/tmp'; if(!is_dir($dir)) @mkdir($dir,0775,true);
  $fname = 'reporte_carga_prod_'.date('Ymd_His').'.csv';
  $fpath = $dir.'/'.$fname;
  $out = fopen($fpath,'w');
  fputcsv($out, ['codigo_producto','marca','modelo','color','ram','capacidad','imei1','imei2','sucursal','estatus_final','motivo']);

  $conn->begin_transaction();
  try{
    foreach ($items as $it){
      $r = $it['data']; $idSuc = $it['id_sucursal']; $final=$it['estatus']; $why=$it['motivo'];

      if ($final==='OK') {
        // Insert a productos (TODOS los campos disponibles)
        $sql = "INSERT INTO productos (
          codigo_producto, marca, modelo, color, ram, capacidad,
          imei1, imei2, costo, costo_con_iva, proveedor, precio_lista,
          descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
          tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $conn->prepare($sql);
        $st->bind_param(
          "ssssssssddsdssssssssssss",
          $r['codigo_producto'], $r['marca'], $r['modelo'], $r['color'], $r['ram'], $r['capacidad'],
          $r['imei1'], $r['imei2'], $r['costo'], $r['costo_con_iva'], $r['proveedor'], $r['precio_lista'],
          $r['descripcion'], $r['nombre_comercial'], $r['compania'], $r['financiera'], $r['fecha_lanzamiento'],
          $r['tipo_producto'], $r['subtipo'], $r['gama'], $r['ciclo_vida'], $r['abc'], $r['operador'], $r['resurtible']
        );

        if ($st->execute()) {
          $idProd = $st->insert_id;
          $st->close();

          // Inventario
          $sqlI = "INSERT INTO inventario (id_producto, id_sucursal, estatus, fecha_ingreso)
                   VALUES (?, ?, 'Disponible', ?)";
          $sti = $conn->prepare($sqlI);
          $sti->bind_param("iis", $idProd, $idSuc, $r['fecha_ingreso']);
          $sti->execute(); $sti->close();

          $final='Insertada'; $why='OK'; $insertadas++;
        } else {
          $final='Ignorada'; $why='Error al insertar producto'; $ignoradas++;
          $st->close();
        }
      } else {
        $ignoradas++;
      }

      fputcsv($out, [
        $r['codigo_producto'],$r['marca'],$r['modelo'],$r['color'],$r['ram'],$r['capacidad'],
        $r['imei1'],$r['imei2'],$r['sucursal'] ?? '', $final, $why
      ]);
    }
    $conn->commit();
    fclose($out);
    $msg = "✅ Carga completada. <b>$insertadas</b> insertadas, <b>$ignoradas</b> ignoradas.";
    $alert = 'success';
    $reportLink = 'tmp/'.$fname;
  } catch(Throwable $e){
    $conn->rollback();
    if (isset($out) && is_resource($out)) fclose($out);
    $msg = "❌ Error transaccional: ".$e->getMessage();
    $alert = 'danger';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Carga Masiva de Productos</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body{background:#f8fafc}
    .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
  </style>
</head>
<body>
<div class="container my-4">
  <h3>📥 Carga masiva de Productos al Inventario</h3>
  <p class="text-muted">El CSV debe incluir encabezados. Los nombres pueden ir en cualquier orden y sin respetar mayúsculas.</p>

  <?php if($msg): ?>
    <div class="alert alert-<?=esc($alert)?>">
      <?=$msg?>
      <?php if($reportLink): ?>
        <div class="mt-2"><a class="btn btn-success btn-sm" href="<?=esc($reportLink)?>" download>⬇️ Descargar reporte CSV</a></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if(empty($preview) && ($_POST['action']??'')!=='insertar'): ?>
    <div class="card shadow-sm">
      <div class="card-header">Subir CSV</div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="preview">
          <input type="file" name="archivo" accept=".csv,text/csv" class="form-control mb-3" required>
          <button class="btn btn-primary">👀 Vista previa</button>
        </form>
        <div class="mt-3 small">
          Encabezados soportados (orden libre):
          <div class="code mt-1">
            <?=esc(implode(', ',$COLS))?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-header">Vista previa</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="insertar">
          <input type="hidden" name="blob" value='<?=base64_encode(json_encode($preview))?>'>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Código</th>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Color</th>
                  <th>RAM</th>
                  <th>Capacidad</th>
                  <th>IMEI1</th>
                  <th>IMEI2</th>
                  <th>Sucursal</th>
                  <th>Fecha ingreso</th>
                  <th>$ Costo</th>
                  <th>$ Lista</th>
                  <th>Tipo</th>
                  <th>Subtipo</th>
                  <th>Gama</th>
                  <th>Ciclo</th>
                  <th>ABC</th>
                  <th>Resurtible</th>
                  <th>Estatus</th>
                  <th>Motivo</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($preview as $i=>$p): $d=$p['data']; ?>
                  <tr class="<?=$p['estatus']==='OK'?'':'table-warning'?>">
                    <td><?= (int)$p['row'] ?></td>
                    <td><?= esc($d['codigo_producto']) ?></td>
                    <td><?= esc($d['marca']) ?></td>
                    <td><?= esc($d['modelo']) ?></td>
                    <td><?= esc($d['color']) ?></td>
                    <td><?= esc($d['ram']) ?></td>
                    <td><?= esc($d['capacidad']) ?></td>
                    <td><?= esc($d['imei1']) ?></td>
                    <td><?= esc($d['imei2']) ?></td>
                    <td><?= esc($d['sucursal']) ?></td>
                    <td><?= esc($d['fecha_ingreso']) ?></td>
                    <td><?= $d['costo']!==null?number_format((float)$d['costo'],2):'' ?></td>
                    <td><?= $d['precio_lista']!==null?number_format((float)$d['precio_lista'],2):'' ?></td>
                    <td><?= esc($d['tipo_producto']) ?></td>
                    <td><?= esc($d['subtipo']) ?></td>
                    <td><?= esc($d['gama']) ?></td>
                    <td><?= esc($d['ciclo_vida']) ?></td>
                    <td><?= esc($d['abc']) ?></td>
                    <td><?= esc($d['resurtible']) ?></td>
                    <td><?= esc($p['estatus']) ?></td>
                    <td><?= esc($p['motivo']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button class="btn btn-success">✅ Confirmar e insertar</button>
          <a class="btn btn-outline-secondary" href="carga_masiva_productos.php">Cancelar</a>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
