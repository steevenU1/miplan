<?php
// catalogo_modelos_admin.php — Importación CSV + Alta manual de modelos (estilo claro + navbar)
// Roles permitidos: Admin / Logística
//
// UPSERT FINAL (solo por codigo_producto):
//   - Si codigo_producto existe => UPDATE por código.
//   - Si NO existe => INSERT.
//   - Si falta codigo_producto en la fila => ERROR y se omite.
//
// Reglas CSV (resumen):
// - 'categoria' -> tipo_producto:
//     Accesorios → Accesorio
//     Modem/Router/Tablets → Modem Tables
//     Teléfonos/Celulares → Equipo
//     Scooter → Scooter
//     Chips/SIM → IGNORAR fila.
// - 'subcategoria' -> subtipo
// - 'almacenamiento' -> capacidad
// - fecha_lanzamiento: yyyy-mm-dd o dd/mm/yyyy
// - resurtible: si/sí/yes/1/true → 'Sí'; otros → 'No'

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$rol = $_SESSION['rol'] ?? '';
$rolOk = in_array(mb_strtolower($rol,'UTF-8'), ['admin','logistica','logística']);
if (!$rolOk) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

/* ================= Helpers ================= */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normBoolSiNo($v){
  $v = mb_strtolower(trim((string)$v), 'UTF-8');
  return in_array($v, ['1','si','sí','yes','true','y','s']) ? 'Sí' : 'No';
}

function normNumero($v){
  $t = trim((string)$v);
  if ($t === '') return null;
  if (strpos($t, ',') !== false && strpos($t, '.') !== false){
    $t = str_replace(',', '', $t);       // "3,999.00" -> "3999.00"
  } else {
    $t = str_replace(',', '.', $t);      // "3999,00"  -> "3999.00"
  }
  return is_numeric($t) ? (float)$t : null;
}

function normFecha($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)){ return "{$m[3]}-{$m[2]}-{$m[1]}"; }
  try { $d = new DateTime($s); return $d->format('Y-m-d'); } catch(Exception $e){ return null; }
}

function normCategoria($raw){
  $v = mb_strtolower(trim((string)$raw), 'UTF-8');
  // chips/SIM -> ignorar
  if ($v === 'chip' || $v === 'chips' || $v === 'sim' || $v === 'sims' || strpos($v,'chip')!==false) {
    return ['ignore'=>true,'valor'=>null];
  }
  if (strpos($v,'accesor')!==false) return ['ignore'=>false,'valor'=>'Accesorio'];
  if ($v==='modem' || strpos($v,'módem')!==false || strpos($v,'router')!==false || strpos($v,'tablet')!==false)
    return ['ignore'=>false,'valor'=>'Modem Tables'];
  if (strpos($v,'scooter')!==false)
    return ['ignore'=>false,'valor'=>'Scooter'];
  if ($v==='equipo') return ['ignore'=>false,'valor'=>'Equipo'];
  if (strpos($v,'telef')!==false || strpos($v,'celular')!==false)
    return ['ignore'=>false,'valor'=>'Equipo'];
  return ['ignore'=>false,'valor'=>ucfirst($v)];
}

// Detecta delimitador simple por primera línea
function detectarDelimitador($path){
  $f = fopen($path, 'r'); if (!$f) return ',';
  $line = fgets($f); fclose($f);
  return (substr_count($line,';') > substr_count($line,',')) ? ';' : ',';
}

// Busca por codigo_producto → id|null
function getModeloIdByCodigo(mysqli $conn, $codigo){
  if ($codigo === '') return null;
  $sql = "SELECT id FROM catalogo_modelos WHERE codigo_producto=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('s',$codigo);
  $st->execute(); $st->bind_result($id); $found = null;
  if ($st->fetch()) $found = (int)$id;
  $st->close();
  return $found;
}

/* ============ Alta / edición manual por código ============ */

$resManual = null;

// Tipos permitidos para el formulario manual
$TIPOS_PERMITIDOS = ['Equipo','Modem','Modem Tables','Accesorio','Scooter'];

if (($_POST['accion'] ?? '') === 'crear_manual') {
  $codigo = trim((string)($_POST['codigo_producto'] ?? ''));
  $marca  = trim((string)($_POST['marca'] ?? ''));
  $modelo = trim((string)($_POST['modelo'] ?? ''));
  $color  = trim((string)($_POST['color'] ?? ''));
  $ram    = trim((string)($_POST['ram'] ?? ''));
  $cap    = trim((string)($_POST['capacidad'] ?? ''));
  $tipo   = trim((string)($_POST['tipo_producto'] ?? ''));
  $subt   = trim((string)($_POST['subtipo'] ?? ''));
  $gama   = trim((string)($_POST['gama'] ?? ''));
  $cvida  = trim((string)($_POST['ciclo_vida'] ?? ''));
  $abc    = trim((string)($_POST['abc'] ?? ''));
  $oper   = trim((string)($_POST['operador'] ?? ''));
  $desc   = trim((string)($_POST['descripcion'] ?? ''));
  $ncom   = trim((string)($_POST['nombre_comercial'] ?? ''));
  $compa  = trim((string)($_POST['compania'] ?? ''));
  $fin    = trim((string)($_POST['financiera'] ?? ''));
  $precio = normNumero($_POST['precio_lista'] ?? '');
  $fechaL = normFecha($_POST['fecha_lanzamiento'] ?? '');
  $resu   = normBoolSiNo($_POST['resurtible'] ?? 'Sí');
  $activo = isset($_POST['activo']) ? 1 : 0;

  $errores = [];

  if ($codigo === '') $errores[] = 'El código de producto es obligatorio.';
  if ($marca  === '') $errores[] = 'La marca es obligatoria.';
  if ($modelo === '') $errores[] = 'El modelo es obligatorio.';
  if ($tipo === '' || !in_array($tipo, $TIPOS_PERMITIDOS, true))
    $errores[] = 'Selecciona un tipo de producto válido.';

  if (!empty($errores)) {
    $resManual = ['ok'=>false,'msg'=>implode(' ', $errores)];
  } else {
    try {
      $idExistente = getModeloIdByCodigo($conn, $codigo);

      if ($idExistente) {
        $sql = "UPDATE catalogo_modelos SET
                  marca=?, modelo=?, color=?, ram=?, capacidad=?, codigo_producto=?, descripcion=?, nombre_comercial=?,
                  compania=?, financiera=?, fecha_lanzamiento=?, precio_lista=?, tipo_producto=?, subtipo=?, gama=?,
                  ciclo_vida=?, abc=?, operador=?, resurtible=?, activo=?
                WHERE id=?";
        $st = $conn->prepare($sql);
        $st->bind_param(
          'sssssssssssdsssssssi' . 'i',
          $marca,$modelo,$color,$ram,$cap,$codigo,$desc,$ncom,$compa,$fin,
          $fechaL,$precio,$tipo,$subt,$gama,$cvida,$abc,$oper,$resu,$activo,
          $idExistente
        );
        $st->execute();
        $st->close();
        $resManual = ['ok'=>true,'msg'=>"Modelo actualizado correctamente (código {$codigo})."];
      } else {
        $sql = "INSERT INTO catalogo_modelos
          (marca,modelo,color,ram,capacidad,codigo_producto,descripcion,nombre_comercial,compania,financiera,
           fecha_lanzamiento,precio_lista,tipo_producto,subtipo,gama,ciclo_vida,abc,operador,resurtible,activo)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $st = $conn->prepare($sql);
        $st->bind_param(
          'sssssssssssdsssssssi',
          $marca,$modelo,$color,$ram,$cap,$codigo,$desc,$ncom,$compa,$fin,
          $fechaL,$precio,$tipo,$subt,$gama,$cvida,$abc,$oper,$resu,$activo
        );
        $st->execute();
        $st->close();
        $resManual = ['ok'=>true,'msg'=>"Modelo creado correctamente (código {$codigo})."];
      }
    } catch (Throwable $e) {
      $resManual = ['ok'=>false,'msg'=>'Error en la base de datos: '.$e->getMessage()];
    }
  }
}

/* ============ Descarga de plantilla ============ */
if (isset($_GET['plantilla'])) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="plantilla_catalogo_modelos.csv"');
  $out = fopen('php://output', 'w');
  // BOM para Excel
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out, [
    'codigo_producto','marca','modelo','color','ram','almacenamiento','precio_lista',
    'proveedor','descripcion','nombre_comercial','compania','financiera','fecha_lanzamiento',
    'categoria','subcategoria','gama','ciclo_vida','abc','operador','resurtible','activo'
  ]);
  fputcsv($out, [
    'A146-N-4-128','Samsung','SM-A146','Negro','4GB','128GB','3999.00',
    'MayoristaXYZ','Gama de entrada con 90Hz','Galaxy A14','Telcel','PayJoy','2024-02-10',
    'Telefonos','Smartphone','Media','Linea','A','Telcel','Sí','1'
  ]);
  fclose($out); exit();
}

/* ============ Importación CSV con UPSERT ============ */
$resImport = null;
if (($_POST['accion'] ?? '') === 'import_csv' && isset($_FILES['csv'])) {
  $file = $_FILES['csv'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    $resImport = ['ok'=>false, 'msg'=>'Error al subir archivo (código '.$file['error'].').'];
  } else {
    $tmp   = $file['tmp_name'];
    $delim = detectarDelimitador($tmp);
    $f = fopen($tmp, 'r');
    if (!$f) {
      $resImport = ['ok'=>false, 'msg'=>'No se pudo abrir el archivo.'];
    } else {
      $first = fgets($f);
      if (strpos($first, chr(0xEF).chr(0xBB).chr(0xBF)) === 0) { $first = substr($first, 3); }
      $headers = array_map(function($h){ return mb_strtolower(trim($h),'UTF-8'); }, str_getcsv($first, $delim));
      $alias = [
        'codigo_producto' => ['codigo_producto','codigo','código','sku','code'],
        'marca'           => ['marca','brand'],
        'modelo'          => ['modelo','model'],
        'color'           => ['color'],
        'ram'             => ['ram','memoria_ram','memoria'],
        'capacidad'       => ['capacidad','almacenamiento','storage'],
        'descripcion'     => ['descripcion','descripción','description'],
        'nombre_comercial'=> ['nombre_comercial','nombre','producto'],
        'compania'        => ['compania','compañia','company','carrier'],
        'financiera'      => ['financiera'],
        'fecha_lanzamiento'=>['fecha_lanzamiento','lanzamiento','release_date'],
        'precio_lista'    => ['precio_lista','precio','precio_list','price'],
        'tipo_producto'   => ['tipo_producto','categoria','categoría','type','category'],
        'subtipo'         => ['subtipo','subcategoria','subcategoría','subcategory'],
        'gama'            => ['gama','range','segmento'],
        'ciclo_vida'      => ['ciclo_vida','ciclo de vida','lifecycle','life_cycle'],
        'abc'             => ['abc'],
        'operador'        => ['operador','operadora','carrier_operator'],
        'resurtible'      => ['resurtible','resurtir','replenishable','reorder'],
        'activo'          => ['activo','status','estatus','active']
      ];
      $idx = [];
      foreach ($alias as $canon => $alts){
        foreach ($alts as $a){
          $pos = array_search(mb_strtolower($a,'UTF-8'), $headers);
          if ($pos !== false){ $idx[$canon] = $pos; break; }
        }
      }

      $insertados=0; $actualizados=0; $ignorados=0; $errores=0; $detalles=[];
      // Preparados para INSERT y UPDATE (mismo set de columnas)
      $sqlIns = "INSERT INTO catalogo_modelos
        (marca,modelo,color,ram,capacidad,codigo_producto,descripcion,nombre_comercial,compania,financiera,
         fecha_lanzamiento,precio_lista,tipo_producto,subtipo,gama,ciclo_vida,abc,operador,resurtible,activo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
      $stIns = $conn->prepare($sqlIns);

      $sqlUpd = "UPDATE catalogo_modelos SET
          marca=?, modelo=?, color=?, ram=?, capacidad=?, codigo_producto=?, descripcion=?, nombre_comercial=?, 
          compania=?, financiera=?, fecha_lanzamiento=?, precio_lista=?, tipo_producto=?, subtipo=?, gama=?, 
          ciclo_vida=?, abc=?, operador=?, resurtible=?, activo=?
        WHERE id=?";
      $stUpd = $conn->prepare($sqlUpd);

      // Control simple para evitar doble INSERT del mismo código en el MISMO CSV
      $csvCodesSeen = [];

      $linea=2;
      while (($row = fgetcsv($f, 0, $delim)) !== false) {
        if (count($row)===1 && trim((string)$row[0])===''){ $linea++; continue; }
        $get = function($canon) use ($idx,$row){
          if (!isset($idx[$canon])) return null;
          $v = $row[$idx[$canon]] ?? null;
          return is_string($v) ? trim($v) : $v;
        };

        $cod   = (string)($get('codigo_producto') ?? '');
        if ($cod === '') { $errores++; $detalles[]="L$linea: Falta codigo_producto."; $linea++; continue; }

        $cat = normCategoria($get('tipo_producto'));
        if ($cat['ignore']){ $ignorados++; $detalles[]="L$linea: Categoria CHIP/SIM → ignorada."; $linea++; continue; }
        $tipo  = $cat['valor'];

        $marca  = (string)($get('marca') ?? '');
        $modelo = (string)($get('modelo') ?? '');
        $color  = (string)($get('color') ?? '');
        $ram    = (string)($get('ram') ?? '');
        $cap    = (string)($get('capacidad') ?? ''); // viene de 'almacenamiento'
        $desc   = (string)($get('descripcion') ?? '');
        $ncom   = (string)($get('nombre_comercial') ?? '');
        $compa  = (string)($get('compania') ?? '');
        $fin    = (string)($get('financiera') ?? '');
        $gama   = (string)($get('gama') ?? '');
        $cvida  = (string)($get('ciclo_vida') ?? '');
        $abc    = (string)($get('abc') ?? '');
        $oper   = (string)($get('operador') ?? '');
        $subt   = (string)($get('subtipo') ?? '');

        $precio = normNumero($get('precio_lista'));            // float|null
        $precio = is_null($precio) ? null : (float)$precio;    // permite NULL
        $fechaL = normFecha($get('fecha_lanzamiento'));        // 'Y-m-d'|NULL
        $resu   = normBoolSiNo($get('resurtible') ?? 'Sí');    // 'Sí'/'No'
        $activo = ((int)($get('activo') ?? 1)) ? 1 : 0;        // 0/1

        // ======= UPSERT SOLO POR CÓDIGO =======
        $idPorCodigo = getModeloIdByCodigo($conn, $cod);

        // Evita doble INSERT del mismo código dentro del CSV
        if (!$idPorCodigo) {
          if (isset($csvCodesSeen[$cod])) {
            $errores++; $detalles[] = "L$linea: Código repetido en el CSV ('{$cod}'), omitido para evitar duplicado.";
            $linea++; continue;
          }
          $csvCodesSeen[$cod] = true;
        }

        try{
          if ($idPorCodigo) {
            // UPDATE (20 campos + id)
            $stUpd->bind_param(
              'sssssssssssdsssssssi' . 'i', // 20 valores + id
              $marca,$modelo,$color,$ram,$cap,$cod,$desc,$ncom,$compa,$fin,
              $fechaL,$precio,$tipo,$subt,$gama,$cvida,$abc,$oper,$resu,$activo,
              $idPorCodigo
            );
            $stUpd->execute();
            $actualizados++;
          } else {
            // INSERT (20 valores)
            $stIns->bind_param(
              'sssssssssssdsssssssi',
              $marca,$modelo,$color,$ram,$cap,$cod,$desc,$ncom,$compa,$fin,
              $fechaL,$precio,$tipo,$subt,$gama,$cvida,$abc,$oper,$resu,$activo
            );
            $stIns->execute();
            $insertados++;
          }
        } catch(Throwable $e){
          // Si truena por duplicado de código, intenta UPDATE por código como último recurso
          if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $idDup = getModeloIdByCodigo($conn, $cod);
            if ($idDup) {
              try {
                $stUpd->bind_param(
                  'sssssssssssdsssssssi' . 'i',
                  $marca,$modelo,$color,$ram,$cap,$cod,$desc,$ncom,$compa,$fin,
                  $fechaL,$precio,$tipo,$subt,$gama,$cvida,$abc,$oper,$resu,$activo,
                  $idDup
                );
                $stUpd->execute();
                $actualizados++;
                $detalles[] = "L$linea: Conflicto de duplicado resuelto con UPDATE por código '{$cod}'.";
                $linea++; continue;
              } catch(Throwable $e2){
                $errores++; $detalles[]="L$linea: Error DB tras resolver duplicado: ".$e2->getMessage();
                $linea++; continue;
              }
            }
          }
          $errores++; $detalles[]="L$linea: Error DB: ".$e->getMessage();
        }
        $linea++;
      }
      fclose($f); 
      $stIns && $stIns->close(); 
      $stUpd && $stUpd->close();

      $resImport = [
        'ok'=>true,'msg'=>"Importación finalizada (UPSERT).",
        'insertados'=>$insertados,'actualizados'=>$actualizados,'ignorados'=>$ignorados,'errores'=>$errores,
        'detalles'=>$detalles
      ];
    }
  }
}

/* =================== HTML / UI (CLARO) =================== */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Catálogo de Modelos — Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e5e7eb;
  --brand:#0ea5e9; --ok:#16a34a; --err:#ef4444;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Open Sans','Helvetica Neue',sans-serif;color:var(--text)}
.container{max-width:1000px;margin:22px auto;padding:0 16px}
h1{font-size:24px;margin:0 0 6px}
h2{font-size:18px;margin:0 0 6px}
.sub{color:var(--muted);font-size:14px;margin-bottom:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 6px 18px rgba(0,0,0,.06);margin-bottom:16px}
label{display:block;font-size:12px;color:#334155;margin:2px}
input[type=file],
input[type=text],
input[type=number],
input[type=date],
select,
textarea{width:100%;padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--text);font-size:13px}
input[type=file]:focus,
input[type=text]:focus,
input[type=number]:focus,
input[type=date]:focus,
select:focus,
textarea:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(14,165,233,.15);outline:none}
.row{display:flex;gap:10px;flex-wrap:wrap}
.col-3{flex:1 1 30%}
.col-4{flex:1 1 23%}
.col-6{flex:1 1 48%}
.col-12{flex:1 1 100%}
.btn{display:inline-block;padding:10px 16px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--text);cursor:pointer;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,#38bdf8,#0ea5e9);border-color:#0ea5e9;color:white;font-weight:600}
.btn-outline{background:#fff}
.kpi{display:inline-flex;align-items:center;gap:8px;background:#f8fafc;border:1px dashed var(--line);padding:6px 10px;border-radius:12px;color:#0f172a;font-size:13px}
.alert{padding:12px;border-radius:12px;margin:10px 0;border:1px solid var(--line);background:#fff}
.alert.ok{border-left:4px solid var(--ok)}
.alert.err{border-left:4px solid var(--err)}
ul.detalles{margin:8px 0 0 18px;color:#0f172a;font-size:13px}
small.muted{color:var(--muted)}
.checkbox-inline{display:flex;align-items:center;gap:6px;font-size:13px;margin-top:6px}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container">
  <h1>Catálogo de Modelos</h1>
  <div class="sub">Administra modelos y códigos de producto (incluyendo Scooters) para poder dar de alta inventario.</div>

  <!-- Alta / edición manual -->
  <div class="card">
    <h2>Alta / edición manual por código</h2>
    <p class="sub">Usa este formulario para crear rápidamente un nuevo código (por ejemplo, un Scooter) o actualizar uno existente.</p>

    <?php if ($resManual): ?>
      <div class="alert <?= $resManual['ok'] ? 'ok':'err' ?>">
        <b><?= h($resManual['msg']) ?></b>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="accion" value="crear_manual">

      <div class="row">
        <div class="col-4">
          <label>Código de producto *</label>
          <input type="text" name="codigo_producto" required placeholder="Ej: SCOOT-XIA-350W-NEGRO">
        </div>
        <div class="col-4">
          <label>Marca *</label>
          <input type="text" name="marca" required placeholder="Ej: Xiaomi">
        </div>
        <div class="col-4">
          <label>Modelo *</label>
          <input type="text" name="modelo" required placeholder="Ej: Mi Scooter 3">
        </div>
      </div>

      <div class="row">
        <div class="col-3">
          <label>Color</label>
          <input type="text" name="color" placeholder="Negro">
        </div>
        <div class="col-3">
          <label>RAM</label>
          <input type="text" name="ram" placeholder="(opcional)">
        </div>
        <div class="col-3">
          <label>Capacidad / Especificación</label>
          <input type="text" name="capacidad" placeholder="Ej: 350W / 10.4Ah">
        </div>
        <div class="col-3">
          <label>Precio lista</label>
          <input type="number" step="0.01" name="precio_lista" placeholder="9999.00">
        </div>
      </div>

      <div class="row">
        <div class="col-4">
          <label>Tipo de producto *</label>
          <select name="tipo_producto" required>
            <option value="">Seleccione…</option>
            <option value="Equipo">Equipo</option>
            <option value="Modem">Modem</option>
            <option value="Modem Tables">Modem Tables</option>
            <option value="Accesorio">Accesorio</option>
            <option value="Scooter">Scooter</option>
          </select>
        </div>
        <div class="col-4">
          <label>Subtipo</label>
          <input type="text" name="subtipo" placeholder="Ej: Scooter eléctrico">
        </div>
        <div class="col-4">
          <label>Gama</label>
          <input type="text" name="gama" placeholder="Baja / Media / Alta">
        </div>
      </div>

      <div class="row">
        <div class="col-4">
          <label>Ciclo de vida</label>
          <input type="text" name="ciclo_vida" placeholder="Nuevo / Línea / Fin de vida">
        </div>
        <div class="col-4">
          <label>Clasificación ABC</label>
          <input type="text" name="abc" placeholder="A / B / C">
        </div>
        <div class="col-4">
          <label>Operador</label>
          <input type="text" name="operador" placeholder="(si aplica)">
        </div>
      </div>

      <div class="row">
        <div class="col-4">
          <label>Compañía</label>
          <input type="text" name="compania" placeholder="Telcel / AT&amp;T / etc.">
        </div>
        <div class="col-4">
          <label>Financiera</label>
          <input type="text" name="financiera" placeholder="PayJoy / etc.">
        </div>
        <div class="col-4">
          <label>Fecha lanzamiento</label>
          <input type="date" name="fecha_lanzamiento">
        </div>
      </div>

      <div class="row">
        <div class="col-6">
          <label>Nombre comercial</label>
          <input type="text" name="nombre_comercial" placeholder="Nombre visible al cliente">
        </div>
        <div class="col-6">
          <label>Descripción</label>
          <textarea name="descripcion" rows="2" placeholder="Descripción breve del modelo"></textarea>
        </div>
      </div>

      <div class="row">
        <div class="col-6">
          <label>Resurtible</label>
          <select name="resurtible">
            <option value="Sí">Sí</option>
            <option value="No">No</option>
          </select>
        </div>
        <div class="col-6">
          <label>&nbsp;</label>
          <div class="checkbox-inline">
            <input type="checkbox" id="chk_activo" name="activo" checked>
            <label for="chk_activo" style="margin:0;">Modelo activo</label>
          </div>
        </div>
      </div>

      <div style="margin-top:10px">
        <button class="btn btn-primary" type="submit">Guardar modelo</button>
        <span class="sub">Si el código ya existe, se actualizará; si no, se creará.</span>
      </div>
    </form>
  </div>

  <!-- Importación CSV -->
  <form class="card" method="post" enctype="multipart/form-data">
    <h2>Importar / actualizar vía CSV</h2>
    <input type="hidden" name="accion" value="import_csv">

    <div class="kpi" style="margin-bottom:8px">
      <span><strong>Reglas rápidas</strong></span>
    </div>
    <ul class="detalles">
      <li>Delimitador <code>,</code> o <code>;</code>. UTF-8/UTF-8-BOM. Fechas <code>yyyy-mm-dd</code> o <code>dd/mm/yyyy</code>.</li>
      <li>Números: 3999.00 (también se acepta <code>3,999.00</code>).</li>
      <li>UPSERT SOLO por <b>codigo_producto</b>: si existe, <b>UPDATE</b>; si no, <b>INSERT</b>.</li>
      <li>Categorías tipo “Scooter” en el CSV se mapean al tipo de producto <b>Scooter</b>.</li>
    </ul>

    <div style="margin:12px 0">
      <label>Archivo CSV</label>
      <input type="file" name="csv" accept=".csv" required>
    </div>

    <div class="row" style="margin-top:8px">
      <button class="btn btn-primary" type="submit">Importar</button>
      <a class="btn btn-outline" href="?plantilla=1">Descargar plantilla CSV</a>
    </div>

    <?php if ($resImport): ?>
      <div class="alert <?= $resImport['ok']?'ok':'err' ?>">
        <div><b><?= h($resImport['msg']) ?></b></div>
        <?php if ($resImport['ok']): ?>
          <div class="row" style="gap:8px;margin-top:8px">
            <div class="kpi">Insertados: <?= (int)$resImport['insertados'] ?></div>
            <div class="kpi">Actualizados: <?= (int)$resImport['actualizados'] ?></div>
            <div class="kpi">Ignorados (chips/SIM): <?= (int)$resImport['ignorados'] ?></div>
            <div class="kpi">Errores: <?= (int)$resImport['errores'] ?></div>
          </div>
          <?php if (!empty($resImport['detalles'])): ?>
            <ul class="detalles">
              <?php foreach ($resImport['detalles'] as $d): ?>
                <li><?= h($d) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </form>
</div>

</body>
</html>
