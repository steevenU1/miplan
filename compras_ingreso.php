<?php
// compras_ingreso.php
// Ingreso de unidades a inventario por renglón.
// Equipos: captura IMEI y crea 1 producto + inventario por pieza.
// Accesorios: al confirmar, crea/ubica el producto y hace UPSERT en inventario (por sucursal),
//             además inserta N filas en compras_detalle_ingresos (IMEI null) para cerrar pendientes.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

/* ===== AJAX IMEI check (solo equipos) ===== */
if (isset($_GET['action']) && $_GET['action'] === 'check_imei') {
  header('Content-Type: application/json; charset=utf-8');
  $imei = preg_replace('/\D+/', '', (string)($_GET['imei'] ?? ''));
  $resp = ['ok'=>false, 'msg'=>'', 'exists'=>false, 'field'=>null];
  if ($imei === '' || !preg_match('/^\d{15}$/', $imei)) { $resp['msg']='Formato inválido: 15 dígitos.'; echo json_encode($resp); exit; }

  $luhn_ok = (function($s){
    $s = preg_replace('/\D+/', '', $s);
    if (strlen($s) !== 15) return false;
    $sum = 0;
    for ($i=0; $i<15; $i++) { $d=(int)$s[$i]; if (($i%2)===1){ $d*=2; if($d>9)$d-=9; } $sum+=$d; }
    return ($sum % 10) === 0;
  })($imei);
  if (!$luhn_ok) { $resp['msg']='IMEI inválido (Luhn).'; echo json_encode($resp); exit; }

  $sql = "SELECT CASE WHEN imei1=? THEN 'imei1' WHEN imei2=? THEN 'imei2' ELSE NULL END campo
          FROM productos WHERE imei1=? OR imei2=? LIMIT 1";
  if ($st=$conn->prepare($sql)) {
    $st->bind_param("ssss",$imei,$imei,$imei,$imei);
    $st->execute(); $res=$st->get_result();
    if ($row=$res->fetch_assoc()) { $resp=['ok'=>true,'exists'=>true,'field'=>$row['campo']?:'desconocido','msg'=>'Duplicado en BD']; echo json_encode($resp); exit; }
    $st->close();
  }
  $resp=['ok'=>true,'exists'=>false,'msg'=>'Disponible.']; echo json_encode($resp); exit;
}

/* ===== Parámetros ===== */
$detalleId = (int)($_GET['detalle'] ?? 0);
$compraId  = (int)($_GET['compra'] ?? 0);
if ($detalleId<=0 || $compraId<=0) die("Parámetros inválidos.");

/* ===== Helpers ===== */
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function parse_money($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/',$s)){ $s=str_replace('.','',$s); $s=str_replace(',','.',$s);}
  else { $s=str_replace(',','',$s); }
  return is_numeric($s) ? round((float)$s,2) : null;
}
if (!function_exists('luhn_ok')) {
  function luhn_ok(string $s): bool {
    $s = preg_replace('/\D+/', '', $s);
    if (strlen($s)!==15) return false;
    $sum=0; for($i=0;$i<15;$i++){ $d=(int)$s[$i]; if(($i%2)===1){$d*=2; if($d>9)$d-=9;} $sum+=$d; }
    return ($sum%10)===0;
  }
}

/* ===== Encabezado y detalle ===== */
$enc = $conn->query("
  SELECT c.*, s.nombre AS sucursal_nombre, p.nombre AS proveedor_nombre
  FROM compras c
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  LEFT JOIN proveedores p ON p.id=c.id_proveedor
  WHERE c.id=$compraId
")->fetch_assoc();

$det = $conn->query("
  SELECT d.*
       , (SELECT COUNT(*) FROM compras_detalle_ingresos x WHERE x.id_detalle=d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id=$detalleId AND d.id_compra=$compraId
")->fetch_assoc();

if (!$enc || !$det) die("Registro no encontrado.");

$pendientes      = max(0, (int)$det['cantidad'] - (int)$det['ingresadas']);
$requiereImei    = (int)$det['requiere_imei'] === 1;
$proveedorCompra = trim((string)($enc['proveedor_nombre'] ?? ''));
if ($proveedorCompra !== '') { $proveedorCompra = mb_substr($proveedorCompra, 0, 120, 'UTF-8'); }

/* ===== Catálogo del modelo ===== */
$cat = [
  'codigo_producto'=>null,'nombre_comercial'=>null,'descripcion'=>null,'compania'=>null,'financiera'=>null,
  'fecha_lanzamiento'=>null,'precio_lista'=>null,'tipo_producto'=>null,'gama'=>null,'ciclo_vida'=>null,
  'abc'=>null,'operador'=>null,'resurtible'=>null,'subtipo'=>null
];
if (!empty($det['id_modelo'])) {
  $stm = $conn->prepare("SELECT codigo_producto,nombre_comercial,descripcion,compania,financiera,
                                fecha_lanzamiento,precio_lista,tipo_producto,gama,ciclo_vida,abc,operador,resurtible,subtipo
                         FROM catalogo_modelos WHERE id=?");
  $stm->bind_param("i", $det['id_modelo']);
  $stm->execute();
  $stm->bind_result(
    $cat['codigo_producto'],$cat['nombre_comercial'],$cat['descripcion'],$cat['compania'],$cat['financiera'],
    $cat['fecha_lanzamiento'],$cat['precio_lista'],$cat['tipo_producto'],$cat['gama'],$cat['ciclo_vida'],
    $cat['abc'],$cat['operador'],$cat['resurtible'],$cat['subtipo']
  );
  $stm->fetch(); $stm->close();
}
$codigoCat = $cat['codigo_producto'] ?? null;

/* ===== Datos del detalle ===== */
$costo       = (float)$det['precio_unitario']; // sin IVA
$ivaPct      = (float)$det['iva_porcentaje'];  // %
$costoConIva = round($costo * (1 + $ivaPct/100), 2);

$marcaDet  = (string)$det['marca'];
$modeloDet = (string)$det['modelo'];
$ramDet    = (string)($det['ram'] ?? '');
$capDet    = (string)$det['capacidad'];
$colorDet  = (string)$det['color'];

/* ===== Sugerencias y subtipo ===== */
function sugerirPrecioLista(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad, float $costoConIva, ?float $precioCat) {
  if ($codigoProd) {
    $qInv = $conn->prepare("
      SELECT p.precio_lista
      FROM inventario i
      INNER JOIN productos p ON p.id = i.id_producto
      WHERE p.codigo_producto = ?
        AND TRIM(i.estatus) IN ('Disponible','En tránsito')
        AND p.precio_lista IS NOT NULL AND p.precio_lista > 0
      ORDER BY p.id DESC
      LIMIT 1
    ");
    $qInv->bind_param("s", $codigoProd);
    $qInv->execute(); $qInv->bind_result($plInv);
    if ($qInv->fetch()) { $qInv->close(); return ['precio'=>(float)$plInv, 'fuente'=>'inventario vigente (mismo código)']; }
    $qInv->close();
  }
  if ($precioCat !== null && $precioCat > 0) { return ['precio'=>(float)$precioCat,'fuente'=>'catálogo de modelos']; }
  $q2 = $conn->prepare("SELECT precio_lista FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND precio_lista IS NOT NULL AND precio_lista>0
                        ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss",$marca,$modelo,$ram,$capacidad);
  $q2->execute(); $q2->bind_result($pl2);
  if ($q2->fetch()) { $q2->close(); return ['precio'=>(float)$pl2,'fuente'=>'último por modelo (RAM/cap)']; }
  $q2->close();
  return ['precio'=>$costoConIva,'fuente'=>'costo + IVA'];
}
function ultimoSubtipo(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad) {
  if ($codigoProd) {
    $q = $conn->prepare("SELECT subtipo FROM productos WHERE codigo_producto=? AND subtipo IS NOT NULL AND subtipo<>'' ORDER BY id DESC LIMIT 1");
    $q->bind_param("s",$codigoProd);
    $q->execute(); $q->bind_result($st);
    if ($q->fetch()) { $q->close(); return ['subtipo'=>$st,'fuente'=>'por código']; }
    $q->close();
  }
  $q2 = $conn->prepare("SELECT subtipo FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND subtipo IS NOT NULL AND subtipo<>'' ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss",$marca,$modelo,$ram,$capacidad);
  $q2->execute(); $q2->bind_result($st2);
  if ($q2->fetch()) { $q2->close(); return ['subtipo'=>$st2,'fuente'=>'por modelo (RAM/cap)']; }
  $q2->close();
  return ['subtipo'=>null,'fuente'=>null];
}

$precioCat = isset($cat['precio_lista']) && $cat['precio_lista'] !== null ? (float)$cat['precio_lista'] : null;
$sugerencia = sugerirPrecioLista($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet, $costoConIva, $precioCat);
$precioSugerido = $sugerencia['precio'];
$fuenteSugerido = $sugerencia['fuente'];
$ultimoST = ultimoSubtipo($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet);
$subtipoForm = $ultimoST['subtipo'] ?? ($cat['subtipo'] ?? null);

/* ===== ¿Es accesorio? ===== */
$tipoProductoCat = $cat['tipo_producto'] ?? null;
$esAccesorio = (!$requiereImei) || (is_string($tipoProductoCat) && mb_strtolower($tipoProductoCat,'UTF-8')==='accesorio');

/* ===== POST ===== */
$errorMsg = "";
$precioListaForm = number_format((float)$precioSugerido, 2, '.', '');
$oldImei1 = []; $oldImei2 = [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if ($esAccesorio) {
    // ACCESORIOS: crear/ubicar producto y actualizar inventario aquí
    $cantidadMarcar = max(0, (int)($_POST['cantidad_marcar'] ?? $pendientes));
    if ($cantidadMarcar > $pendientes) $cantidadMarcar = $pendientes;

    // precio lista (por si debemos crear producto)
    $precioListaCapt = parse_money($_POST['precio_lista'] ?? $precioListaForm);
    if (!$precioListaCapt || $precioListaCapt <= 0) $precioListaCapt = $precioSugerido;

    $conn->begin_transaction();
    try {
      // 1) Buscar producto accesorio por firma lógica
      $pid = 0;
      $qProd = $conn->prepare("
        SELECT id
        FROM productos
        WHERE tipo_producto='Accesorio'
          AND marca=?
          AND modelo=?
          AND IFNULL(color,'')     = IFNULL(?, '')
          AND IFNULL(ram,'')       = IFNULL(?, '')
          AND IFNULL(capacidad,'') = IFNULL(?, '')
        ORDER BY id DESC
        LIMIT 1
      ");
      $qProd->bind_param('sssss',$marcaDet,$modeloDet,$colorDet,$ramDet,$capDet);
      $qProd->execute(); $rp=$qProd->get_result();
      if ($row=$rp->fetch_assoc()) { $pid=(int)$row['id']; }
      $qProd->close();

      // 2) Si no existe, crearlo
      if ($pid===0) {
        $provTxt = $proveedorCompra !== '' ? $proveedorCompra : null;
        $insProd = $conn->prepare("
          INSERT INTO productos
            (codigo_producto, marca, modelo, color, ram, capacidad,
             costo, costo_con_iva, proveedor, precio_lista, descripcion,
             nombre_comercial, compania, financiera, fecha_lanzamiento,
             tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible)
          VALUES
            (?,?,?,?,?,?,
             ?,?,?,?,?,
             ?,?,?,?,
             'Accesorio', ?, ?, ?, ?, ?, ?)
        ");
        $insProd->bind_param(
          'ssssssddsdsssssssssss',
          $codigoCat, $marcaDet, $modeloDet, $colorDet, $ramDet, $capDet,
          $costo, $costoConIva, $provTxt, $precioListaCapt, $cat['descripcion'],
          $cat['nombre_comercial'], $cat['compania'], $cat['financiera'], $cat['fecha_lanzamiento'],
          $subtipoForm, $cat['gama'], $cat['ciclo_vida'], $cat['abc'], $cat['operador'], $cat['resurtible']
        );
        if (!$insProd->execute()) { throw new Exception("Crear producto accesorio: ".$insProd->error); }
        $pid = $insProd->insert_id;
        $insProd->close();
      }

      // 3) UPSERT inventario por sucursal (estatus 'Disponible'): suma cantidad
      $selInv = $conn->prepare("
        SELECT id, cantidad FROM inventario
        WHERE id_producto=? AND id_sucursal=? AND estatus='Disponible'
        LIMIT 1
      ");
      $selInv->bind_param('ii',$pid,$enc['id_sucursal']);
      $selInv->execute(); $resInv=$selInv->get_result();
      if ($rowInv=$resInv->fetch_assoc()) {
        $invId=(int)$rowInv['id'];
        $updInv=$conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");
        $updInv->bind_param('ii',$cantidadMarcar,$invId);
        if (!$updInv->execute()) { throw new Exception("Actualizar inventario: ".$updInv->error); }
        $updInv->close();
      } else {
        $insInv=$conn->prepare("INSERT INTO inventario (id_producto,id_sucursal,estatus,cantidad) VALUES (?,?, 'Disponible', ?)");
        $insInv->bind_param('iii',$pid,$enc['id_sucursal'],$cantidadMarcar);
        if (!$insInv->execute()) { throw new Exception("Insert inventario: ".$insInv->error); }
        $insInv->close();
      }
      $selInv->close();

      // 4) Registrar N ingresos (IMEIs nulos)
      if ($cantidadMarcar > 0) {
        $stmtR = $conn->prepare("INSERT INTO compras_detalle_ingresos (id_detalle, imei1, imei2, id_producto) VALUES (?,?,?,?)");
        for ($i=0; $i<$cantidadMarcar; $i++) {
          $null = null; $stmtR->bind_param("issi",$detalleId,$null,$null,$pid);
          if (!$stmtR->execute()) { throw new Exception("Registrar ingresos: ".$stmtR->error); }
        }
        $stmtR->close();
      }

      $conn->commit();
      header("Location: compras_ver.php?id=".$compraId);
      exit();

    } catch (Throwable $e) {
      $conn->rollback();
      die("Error al ingresar accesorio: ".$e->getMessage());
    }
  }

  /* ===== EQUIPOS (con IMEI) — flujo existente ===== */
  $n = max(0, (int)($_POST['n'] ?? 0));
  if ($n <= 0) { header("Location: compras_ver.php?id=".$compraId); exit(); }
  if ($n > $pendientes) $n = $pendientes;

  $precioListaForm = trim($_POST['precio_lista'] ?? '');
  $precioListaCapturado = parse_money($precioListaForm);
  if ($precioListaCapturado === null || $precioListaCapturado <= 0) {
    $errorMsg = "Precio de lista inválido. Usa números, ejemplo: 3999.00";
  }

  for ($i=0; $i<$n; $i++) {
    $oldImei1[$i] = preg_replace('/\D+/', '', (string)($_POST['imei1'][$i] ?? ''));
    $oldImei2[$i] = preg_replace('/\D+/', '', (string)($_POST['imei2'][$i] ?? ''));
  }

  if ($errorMsg === "") {
    $seen = [];
    $dupsForm = [];
    for ($i=0; $i<$n; $i++) {
      foreach (['imei1','imei2'] as $col) {
        $val = preg_replace('/\D+/', '', (string)($_POST[$col][$i] ?? ''));
        if ($val !== '' && preg_match('/^\d{15}$/', $val)) {
          if (!isset($seen[$val])) $seen[$val] = [];
          $seen[$val][] = $i+1;
        }
      }
    }
    foreach ($seen as $val => $rows) {
      if (count($rows) > 1) { $dupsForm[$val] = $rows; }
    }
    if (!empty($dupsForm)) {
      $msg = "Se detectaron IMEI duplicados en el formulario:\n";
      foreach ($dupsForm as $val => $rows) { $msg .= " - $val repetido en filas ".implode(', ', $rows)."\n"; }
      $errorMsg = nl2br(esc($msg));
    }
  }

  if ($errorMsg === "") {
    for ($i=0; $i<$n && $errorMsg === ""; $i++) {
      foreach ([['col'=>'imei1','label'=>'IMEI1'], ['col'=>'imei2','label'=>'IMEI2']] as $spec) {
        $raw = trim((string)($_POST[$spec['col']][$i] ?? ''));
        $val = preg_replace('/\D+/', '', $raw);
        if ($val === '') continue;
        if (!preg_match('/^\d{15}$/', $val) || !luhn_ok($val)) { $errorMsg = $spec['label']." inválido en la fila ".($i+1)."."; break; }
        $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
        $st->bind_param("ss", $val, $val);
        $st->execute(); $st->bind_result($cdup); $st->fetch(); $st->close();
        if ($cdup > 0) { $errorMsg = $spec['label']." duplicado en BD en la fila ".($i+1).": $val"; break; }
      }
    }
  }

  if ($errorMsg === "") {
    $conn->begin_transaction();
    try {
      for ($i=0; $i<$n; $i++) {
        $imei1 = preg_replace('/\D+/', '', (string)($_POST['imei1'][$i] ?? ''));
        $imei2 = preg_replace('/\D+/', '', (string)($_POST['imei2'][$i] ?? ''));

        if ($requiereImei) {
          if ($imei1 === '' || !preg_match('/^\d{15}$/', $imei1) || !luhn_ok($imei1)) {
            throw new Exception("IMEI1 inválido en la fila ".($i+1).".");
          }
        } else {
          if ($imei1 !== '' && (!preg_match('/^\d{15}$/', $imei1) || !luhn_ok($imei1))) {
            throw new Exception("IMEI1 inválido (si lo capturas deben ser 15 dígitos Luhn) en la fila ".($i+1).".");
          }
          if ($imei1 === '') $imei1 = null;
        }
        if ($imei2 !== '') {
          if (!preg_match('/^\d{15}$/', $imei2) || !luhn_ok($imei2)) { throw new Exception("IMEI2 inválido en la fila ".($i+1)."."); }
        } else { $imei2 = null; }

        // Variables catálogo
        $descripcion      = $cat['descripcion'] ?? null;
        $nombreComercial  = $cat['nombre_comercial'] ?? null;
        $compania         = $cat['compania'] ?? null;
        $financiera       = $cat['financiera'] ?? null;
        $fechaLanzamiento = $cat['fecha_lanzamiento'] ?? null;
        $tipoProducto     = $cat['tipo_producto'] ?? null;
        $gama             = $cat['gama'] ?? null;
        $cicloVida        = $cat['ciclo_vida'] ?? null;
        $abc              = $cat['abc'] ?? null;
        $operador         = $cat['operador'] ?? null;
        $resurtible       = $cat['resurtible'] ?? null;

        // Crear producto (una unidad)
        $stmtP = $conn->prepare("
          INSERT INTO productos (
            codigo_producto, marca, modelo, color, ram, capacidad,
            imei1, imei2, costo, costo_con_iva, proveedor, precio_lista,
            descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
            tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $prov  = ($proveedorCompra !== '') ? $proveedorCompra : null;
        $stmtP->bind_param(
          "ssssssssddsdssssssssssss",
          $codigoCat, $marcaDet, $modeloDet, $colorDet, $ramDet, $capDet,
          $imei1, $imei2, $costo, $costoConIva, $prov, $precioListaCapturado,
          $descripcion, $nombreComercial, $compania, $financiera, $fechaLanzamiento,
          $tipoProducto, $subtipoForm, $gama, $cicloVida, $abc, $operador, $resurtible
        );
        $stmtP->execute(); $idProducto = $stmtP->insert_id; $stmtP->close();

        // Alta a inventario
        $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?, ?, 'Disponible')");
        $stmtI->bind_param("ii", $idProducto, $enc['id_sucursal']);
        $stmtI->execute(); $stmtI->close();

        // Registrar ingreso
        $stmtR = $conn->prepare("INSERT INTO compras_detalle_ingresos (id_detalle, imei1, imei2, id_producto) VALUES (?,?,?,?)");
        $stmtR->bind_param("issi", $detalleId, $imei1, $imei2, $idProducto);
        $stmtR->execute(); $stmtR->close();
      }

      $conn->commit();
      header("Location: compras_ver.php?id=".$compraId);
      exit();
    } catch (Exception $e) {
      $conn->rollback();
      $errorMsg = $e->getMessage();
    }
  }
}

/* ===== HTML ===== */
include 'navbar.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <h4>Ingreso a inventario</h4>
  <p class="text-muted">
    <strong>Factura:</strong> <?= esc($enc['num_factura']) ?> ·
    <strong>Sucursal destino:</strong> <?= esc($enc['sucursal_nombre']) ?><br>
    <strong>Modelo:</strong>
      <?= esc($marcaDet.' '.$modeloDet) ?> ·
      <?= $ramDet!=='' ? '<strong>RAM:</strong> '.esc($ramDet).' · ' : '' ?>
      <strong>Capacidad:</strong> <?= esc($capDet) ?> ·
      <strong>Color:</strong> <?= esc($colorDet) ?> ·
      <strong>Req. IMEI:</strong> <?= $requiereImei ? 'Sí' : 'No' ?><br>
    <strong>Proveedor (compra):</strong> <?= esc($proveedorCompra ?: '—') ?>
  </p>

  <?php if (!empty($cat['codigo_producto']) || !empty($cat['nombre_comercial'])): ?>
    <div class="alert alert-secondary py-2">
      <?php if(!empty($cat['codigo_producto'])): ?><span class="me-3"><strong>Código:</strong> <?= esc($cat['codigo_producto']) ?></span><?php endif; ?>
      <?php if(!empty($cat['nombre_comercial'])): ?><span class="me-3"><strong>Nombre comercial:</strong> <?= esc($cat['nombre_comercial']) ?></span><?php endif; ?>
      <?php if(!empty($cat['compania'])): ?><span class="me-3"><strong>Compañía:</strong> <?= esc($cat['compania']) ?></span><?php endif; ?>
      <?php if(!empty($cat['financiera'])): ?><span class="me-3"><strong>Financiera:</strong> <?= esc($cat['financiera']) ?></span><?php endif; ?>
      <?php if(!empty($cat['tipo_producto'])): ?><span class="me-3"><strong>Tipo:</strong> <?= esc($cat['tipo_producto']) ?></span><?php endif; ?>
      <?php if(!empty($cat['gama'])): ?><span class="me-3"><strong>Gama:</strong> <?= esc($cat['gama']) ?></span><?php endif; ?>
      <?php if(!empty($cat['ciclo_vida'])): ?><span class="me-3"><strong>Ciclo de vida:</strong> <?= esc($cat['ciclo_vida']) ?></span><?php endif; ?>
      <?php if(!empty($cat['abc'])): ?><span class="me-3"><strong>ABC:</strong> <?= esc($cat['abc']) ?></span><?php endif; ?>
      <?php if(!empty($cat['operador'])): ?><span class="me-3"><strong>Operador:</strong> <?= esc($cat['operador']) ?></span><?php endif; ?>
      <?php if(!empty($cat['resurtible'])): ?><span class="me-3"><strong>Resurtible:</strong> <?= esc($cat['resurtible']) ?></span><?php endif; ?>
      <?php if(!empty($cat['subtipo'])): ?><span class="me-3"><strong>Subtipo (catálogo):</strong> <?= esc($cat['subtipo']) ?></span><?php endif; ?>
      <?php if(!empty($cat['fecha_lanzamiento'])): ?><span class="me-3"><strong>Lanzamiento:</strong> <?= esc($cat['fecha_lanzamiento']) ?></span><?php endif; ?>
      <?php if(!empty($cat['descripcion'])): ?><div class="small text-muted mt-1"><?= esc($cat['descripcion']) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p><strong>Cantidad total:</strong> <?= (int)$det['cantidad'] ?> ·
         <strong>Ingresadas:</strong> <?= (int)$det['ingresadas'] ?> ·
         <strong>Pendientes:</strong> <?= $pendientes ?></p>

      <?php if ($pendientes <= 0): ?>
        <div class="alert alert-success">Este renglón ya está completamente ingresado.</div>
      <?php else: ?>

        <?php if ($esAccesorio): ?>
          <div class="alert alert-info">
            Este renglón es <strong>Accesorio</strong> (no requiere IMEI).<br>
            Al confirmar: se <strong>actualiza inventario</strong> en <em><?= esc($enc['sucursal_nombre']) ?></em>
            y se registran los ingresos administrativos.
          </div>

          <form method="POST" autocomplete="off">
            <div class="row g-3 align-items-end mb-3">
              <div class="col-md-4">
                <label class="form-label">Cantidad a ingresar</label>
                <input type="number" class="form-control" name="cantidad_marcar" min="1" max="<?= $pendientes ?>" value="<?= $pendientes ?>">
                <small class="text-muted">Pendientes: <?= $pendientes ?>.</small>
              </div>
              <div class="col-md-4">
                <label class="form-label">Precio de lista (si se crea producto)</label>
                <input type="text" name="precio_lista" class="form-control" inputmode="decimal" value="<?= number_format((float)$precioSugerido,2,'.','') ?>">
                <small class="text-muted">Sugerido: $<?= number_format((float)$precioSugerido,2) ?> (<?= esc($fuenteSugerido) ?>)</small>
              </div>
            </div>
            <div class="text-end">
              <button type="submit" class="btn btn-primary">Confirmar ingreso</button>
              <a href="compras_ver.php?id=<?= (int)$compraId ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </form>

        <?php else: ?>
          <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger"><?= $errorMsg ?></div>
          <?php endif; ?>

          <form id="formIngreso" method="POST" autocomplete="off" novalidate>
            <input type="hidden" name="n" value="<?= $pendientes ?>">

            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label">Subtipo (asignación automática)</label>
                <input type="text" class="form-control" value="<?= esc($subtipoForm ?? '—') ?>" disabled>
              </div>
              <div class="col-md-4">
                <label class="form-label">Precio de lista (por modelo)</label>
                <input type="text" name="precio_lista" class="form-control" inputmode="decimal" value="<?= esc(number_format((float)$precioSugerido,2,'.','')) ?>" required>
                <small class="text-muted">Sugerido: $<?= number_format((float)$precioSugerido,2) ?> (<?= esc($fuenteSugerido) ?>)</small>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th style="min-width:220px">IMEI1 *</th><th style="min-width:220px">IMEI2 (opcional)</th></tr></thead>
                <tbody>
                <?php for ($i=0;$i<$pendientes;$i++): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <input id="imei1-<?= $i ?>" data-index="<?= $i ?>" class="form-control imei-input imei1"
                             name="imei1[]" required inputmode="numeric" minlength="15" maxlength="15"
                             pattern="[0-9]{15}" placeholder="15 dígitos" autocomplete="off"
                             value="<?= esc($oldImei1[$i] ?? '') ?>" <?= $i===0 ? 'autofocus' : '' ?>>
                      <div class="invalid-feedback small">Corrige el IMEI (15 dígitos, Luhn).</div>
                      <div class="form-text text-danger d-none" id="dupmsg-imei1-<?= $i ?>"></div>
                    </td>
                    <td>
                      <input id="imei2-<?= $i ?>" data-index="<?= $i ?>" class="form-control imei-input imei2"
                             name="imei2[]" inputmode="numeric" minlength="15" maxlength="15"
                             pattern="[0-9]{15}" placeholder="15 dígitos (opcional)" autocomplete="off"
                             value="<?= esc($oldImei2[$i] ?? '') ?>">
                      <div class="invalid-feedback small">Corrige el IMEI (15 dígitos, Luhn) o déjalo vacío.</div>
                      <div class="form-text text-danger d-none" id="dupmsg-imei2-<?= $i ?>"></div>
                    </td>
                  </tr>
                <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <div class="text-end">
              <button id="btnSubmit" type="submit" class="btn btn-success">Ingresar a inventario</button>
              <a href="compras_ver.php?id=<?= (int)$compraId ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </form>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$esAccesorio): ?>
<!-- Validación en vivo para IMEI (equipos) -->
<script>
(function(){
  const form = document.getElementById('formIngreso');
  if (!form) return;
  const btn = document.getElementById('btnSubmit');

  form.addEventListener('submit',(e)=>{
    if (form.dataset.busy==='1'){ e.preventDefault(); return; }
    if (form.querySelector('.dup-bad')){ e.preventDefault(); alert('Hay IMEI duplicados.'); return; }
    form.dataset.busy='1'; if(btn){btn.disabled=true; btn.textContent='Ingresando...';}
  }, {capture:true});

  function norm15(el){ const v=el.value.replace(/\D+/g,'').slice(0,15); if(v!==el.value) el.value=v; return v; }
  function luhnOk(s){ s=(s||'').replace(/\D+/g,''); if(s.length!==15) return false; let sum=0; for(let i=0;i<15;i++){ let d=s.charCodeAt(i)-48; if((i%2)===1){ d*=2; if(d>9)d-=9; } sum+=d; } return (sum%10)===0; }
  function debounce(fn,ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms);} }
  function markDup(el,msg,bad=true){ const help=document.getElementById('dupmsg-'+el.id); if(bad){ el.classList.add('is-invalid','dup-bad'); if(help){help.classList.remove('d-none'); help.textContent=msg||'Duplicado.';} } else { el.classList.remove('dup-bad','is-invalid'); if(help){help.classList.add('d-none'); help.textContent='';} } }
  function checkLocal(){
    const inputs=[...document.querySelectorAll('.imei-input')];
    const map=new Map();
    inputs.forEach(el=>{ const v=(el.value||'').replace(/\D+/g,''); if(v.length===15){ if(!map.has(v)) map.set(v,[]); map.get(v).push(el);} });
    inputs.forEach(el=>markDup(el,'',false));
    map.forEach(arr=>{ if(arr.length>1) arr.forEach(el=>markDup(el,'Duplicado en formulario',true)); });
  }
  const checkRemote = debounce(async (el)=>{
    const v=(el.value||'').replace(/\D+/g,''); if(v.length!==15 || !luhnOk(v)) return;
    try{
      const url=`<?= esc(basename(__FILE__)) ?>?action=check_imei&imei=${encodeURIComponent(v)}`;
      const r=await fetch(url,{headers:{'Accept':'application/json'}}); const data=await r.json();
      if(data && data.ok){ if(data.exists) markDup(el,`Duplicado en BD (${data.field})`,true); else markDup(el,'',false); }
    }catch(_){}
  },220);

  document.querySelectorAll('.imei-input').forEach((el)=>{
    el.addEventListener('input',()=>{ const v=norm15(el); if(v.length===15){ if(!luhnOk(v)){ el.classList.add('is-invalid'); } else { el.classList.remove('is-invalid'); checkLocal(); checkRemote(el);} } else { el.classList.remove('is-invalid'); checkLocal(); } });
    el.addEventListener('blur',()=>{ const v=(el.value||'').replace(/\D+/g,''); if(v.length===15 && luhnOk(v)){ checkLocal(); checkRemote(el);} });
  });
})();
</script>
<?php endif; ?>
