<?php
// inventario_sims_resumen.php — Central 2.0 (FINAL con edición ICCID/DN solo Admin)
// - Filtro por caja robusto: detecta columna (caja_id | id_caja | caja), usa TRIM, soporta VARCHAR/INT.
// - Export CSV toma los valores actuales del formulario (no exige "Aplicar") y respeta caja/sucursal/rol.
// - Si el select de sucursal está disabled (vista "Por sucursal"), se envía por <input hidden> para no vaciar el export.
// - Admin: Global o por sucursal; otros roles: solo su sucursal.
// - Filtros: operador, tipo_plan, q (ICCID/DN), caja.
// - UI: KPIs + cards por sucursal + tabla detalle por sucursal.
// - Contadores por 'Bait','AT&T','Virgin','Unefon','Telcel','Movistar'.
// - En cards por sucursal, si el conteo de un operador es 0, badge rojo claro.
// - En detalle por sucursal se puede editar ICCID/DN de una SIM disponible mediante modal (solo Admin).

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

/* ===== Const ===== */
$OPERADORES = ['Bait','AT&T','Virgin','Unefon','Telcel','Movistar'];

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function selectOptions(array $opts, $sel){
  $html=''; foreach($opts as $val=>$txt){
    $selAttr = ((string)$val === (string)$sel) ? ' selected' : '';
    $html.='<option value="'.h($val).'"'.$selAttr.'>'.h($txt).'</option>';
  } return $html;
}
function isAdmin($rol){ return in_array($rol, ['Admin'], true); }

/** Detecta la columna real usada para “caja” en inventario_sims. */
function detectarColCaja(mysqli $conn): string {
  foreach (['caja_id','id_caja','caja'] as $col) {
    $colEsc = $conn->real_escape_string($col);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inventario_sims'
              AND COLUMN_NAME = '$colEsc' LIMIT 1";
    $q = $conn->query($sql);
    if ($q && $q->fetch_row()){ return $col; }
  }
  return 'caja_id'; // fallback
}
/** Condición de “caja no vacía” (ignora NULL, '' y '0'). */
function condCajaNoVacia(string $expr): string {
  return "NULLIF(TRIM($expr),'') IS NOT NULL AND TRIM($expr) <> '0'";
}
/** Orden sugerida (queda para otros usos) */
function orderCaja(string $expr): string {
  return "CAST(TRIM($expr) AS UNSIGNED), TRIM($expr)";
}

/* ============================================================
   POST: Edición de SIM (ICCID / DN) — SOLO ADMIN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar_sim') {

  $idSim       = (int)($_POST['id_sim'] ?? 0);
  $nuevoIccid  = trim((string)($_POST['nuevo_iccid'] ?? ''));
  $nuevoDn     = trim((string)($_POST['nuevo_dn'] ?? ''));
  $returnQuery = ltrim((string)($_POST['return_query'] ?? ''), '?');

  $ok = false;

  // Candado: solo Admin puede editar
  if (!isAdmin($ROL)) {
    $redir = 'inventario_sims_resumen.php';
    if ($returnQuery !== '') {
      $redir .= '?'.$returnQuery;
      if (strpos($returnQuery, 'upd=') === false) { $redir .= '&upd=0'; }
    } else {
      $redir .= '?upd=0';
    }
    header("Location: $redir");
    exit;
  }

  if ($idSim > 0 && ($nuevoIccid !== '' || $nuevoDn !== '')) {

    $sql = "UPDATE inventario_sims
            SET iccid = ?, dn = ?
            WHERE id = ? AND estatus = 'Disponible'
            LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
      $st->bind_param('ssi', $nuevoIccid, $nuevoDn, $idSim);
      if ($st->execute() && $st->affected_rows >= 0) {
        $ok = true;
      }
      $st->close();
    }
  }

  // Redirección a la misma vista con flag de resultado
  $redir = 'inventario_sims_resumen.php';
  if ($returnQuery !== '') {
    $redir .= '?'.$returnQuery;
    if (strpos($returnQuery, 'upd=') === false) {
      $redir .= '&upd=' . ($ok ? '1' : '0');
    }
  } else {
    $redir .= '?upd=' . ($ok ? '1' : '0');
  }

  header("Location: $redir");
  exit;
}

/* ===== Mensajes de actualización ===== */
$alertMsg  = '';
$alertType = 'success';
if (isset($_GET['upd'])) {
  if ($_GET['upd'] === '1') {
    $alertMsg  = 'SIM actualizado correctamente.';
    $alertType = 'success';
  } elseif ($_GET['upd'] === '0') {
    $alertMsg  = 'No se pudo actualizar el SIM. Verifica los datos.';
    $alertType = 'danger';
  }
}

/* ===== Parámetros / filtros ===== */
$scope        = isAdmin($ROL) ? ($_GET['scope'] ?? 'global') : 'sucursal';
$selSucursal  = isAdmin($ROL) ? (int)($_GET['sucursal'] ?? 0) : $ID_SUCURSAL; // 0 = todas
$operador     = $_GET['operador']  ?? 'ALL';
$tipoPlan     = $_GET['tipo_plan'] ?? 'ALL';
$q            = trim((string)($_GET['q'] ?? ''));

// caja como string TRIMEADO (soporta VARCHAR o INT con espacios)
$cajaRaw = $_GET['caja'] ?? '0';
$caja    = (string)(is_array($cajaRaw) ? '0' : trim((string)$cajaRaw));
if ($caja === '') $caja = '0';

if (!isAdmin($ROL)) { $scope='sucursal'; $selSucursal=$ID_SUCURSAL; }
if ($scope!=='global') { $scope='sucursal'; }

$CAJA_COL = detectarColCaja($conn);
$CAJA_TR  = "TRIM(i.$CAJA_COL)";
$haySucursalConcreta = ($scope==='sucursal') || ($scope==='global' && $selSucursal>0);

/* ===== WHERE base (solo disponibles) ===== */
$where = ["i.estatus='Disponible'"];
$params=[]; $types='';

if ($operador!=='ALL'){ $where[]="i.operador=?"; $params[]=$operador; $types.='s'; }
if ($tipoPlan!=='ALL'){ $where[]="i.tipo_plan=?"; $params[]=$tipoPlan; $types.='s'; }
if ($q!==''){
  $where[]="(i.iccid LIKE ? OR i.dn LIKE ?)";
  $like="%$q%"; $params[]=$like; $params[]=$like; $types.='ss';
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* ===== Sucursales (Admin) ===== */
$sucursales=[];
if (isAdmin($ROL)) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  $sucursales = $rs->fetch_all(MYSQLI_ASSOC);
}

/* ===== Cajas (si hay sucursal concreta) ===== */
$cajas = [];
if ($haySucursalConcreta) {
  $sqlCajas = "
    SELECT DISTINCT
      TRIM(i.$CAJA_COL)                   AS caja_val,
      CAST(TRIM(i.$CAJA_COL) AS UNSIGNED) AS caja_num
    FROM inventario_sims i
    WHERE i.estatus='Disponible'
      AND i.id_sucursal = ?
      AND ".condCajaNoVacia("i.$CAJA_COL")."
    ORDER BY caja_num, caja_val
  ";
  $st = $conn->prepare($sqlCajas);
  $st->bind_param('i', $selSucursal);
  $st->execute();
  $res = $st->get_result();
  while($r = $res->fetch_assoc()){
    $val = (string)$r['caja_val'];
    $cajas[$val] = $val; // puede ser "1", "01", "A-01", etc.
  }
  $st->close();
}

/* ===== Helper UI: clase de badge por operador ===== */
function badgeOpClass(int $n): string {
  // >0 = badge azul suave; 0 = badge rojo suave
  return $n > 0 ? 'badge-soft' : 'badge-zero';
}

/* ===== KPIs ===== */
function kpisGlobal(mysqli $conn, $whereSql, $params, $types, $selSucursal, $scope, $caja, $haySucursalConcreta, $CAJA_TR){
  $extra=''; $p=$params; $t=$types;
  if ($scope==='sucursal' || ($scope==='global' && $selSucursal>0)) {
    $extra .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($haySucursalConcreta && $caja !== '0') {
      $extra .= " AND $CAJA_TR=?";
      $p[]=$caja; $t.='s';
    }
  }
  $sql="SELECT COUNT(*) total,
               SUM(i.operador='Bait')     bait,
               SUM(i.operador='AT&T')     att,
               SUM(i.operador='Virgin')   virgin,
               SUM(i.operador='Unefon')   unefon,
               SUM(i.operador='Telcel')   telcel,
               SUM(i.operador='Movistar') movistar
        FROM inventario_sims i $whereSql $extra";
  $st=$conn->prepare($sql); if ($p){ $st->bind_param($t, ...$p); } $st->execute();
  $row=$st->get_result()->fetch_assoc() ?: [];
  return [
    'total'    => (int)($row['total']??0),
    'bait'     => (int)($row['bait']??0),
    'att'      => (int)($row['att']??0),
    'virgin'   => (int)($row['virgin']??0),
    'unefon'   => (int)($row['unefon']??0),
    'telcel'   => (int)($row['telcel']??0),
    'movistar' => (int)($row['movistar']??0),
  ];
}
$kpis = kpisGlobal($conn,$whereSql,$params,$types,$selSucursal,$scope,$caja,$haySucursalConcreta,$CAJA_TR);

/* ===== Cards por sucursal (solo global) ===== */
$cards=[];
if ($scope==='global'){
  $extra=''; $p=$params; $t=$types;
  if ($selSucursal>0){
    $extra .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($haySucursalConcreta && $caja !== '0'){
      $extra .= " AND $CAJA_TR=?";
      $p[]=$caja; $t.='s';
    }
  }
  $sql="SELECT s.id id_suc, s.nombre,
               COUNT(i.id)                    disponibles,
               SUM(i.operador='Bait')         bait,
               SUM(i.operador='AT&T')         att,
               SUM(i.operador='Virgin')       virgin,
               SUM(i.operador='Unefon')       unefon,
               SUM(i.operador='Telcel')       telcel,
               SUM(i.operador='Movistar')     movistar
        FROM sucursales s
        LEFT JOIN inventario_sims i ON i.id_sucursal=s.id
        $whereSql $extra
        GROUP BY s.id
        HAVING disponibles > 0
        ORDER BY s.nombre";
  $st=$conn->prepare($sql); if ($p){ $st->bind_param($t, ...$p); } $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){
    foreach (['disponibles','bait','att','virgin','unefon','telcel','movistar'] as $k){
      $row[$k] = (int)($row[$k] ?? 0);
    }
    $cards[]=$row;
  }
}

/* ===== Detalle de una sucursal ===== */
$detalle=[]; $sucursalNombre='';
if ($scope==='sucursal'){
  $st=$conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  $st->bind_param('i', $selSucursal); $st->execute();
  $sucursalNombre = (string)($st->get_result()->fetch_column() ?: '');

  $extra = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
  $p=array_merge($params,[$selSucursal]); $t=$types.'i';
  if ($caja !== '0'){
    $extra .= " AND $CAJA_TR=?";
    $p[]=$caja; $t.='s';
  }
  $sql="SELECT i.id, i.iccid, i.dn, i.operador, i.tipo_plan, i.fecha_ingreso,
               TRIM(i.$CAJA_COL) AS caja_val
        FROM inventario_sims i
        $whereSql $extra
        ORDER BY i.operador, i.tipo_plan, i.iccid";
  $st=$conn->prepare($sql); $st->bind_param($t, ...$p); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $detalle[]=$row; }
}

/* ===== EXPORT CSV (antes de cualquier salida visible) ===== */
if (isset($_GET['export']) && $_GET['export']==='1') {
  $extraWhere=''; $p=$params; $t=$types;

  if ($scope==='sucursal') {
    $extraWhere .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($caja !== '0'){ $extraWhere .= " AND $CAJA_TR=?"; $p[]=$caja; $t.='s'; }

    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn,
                   TRIM(i.$CAJA_COL) AS caja_val, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY i.operador, i.tipo_plan, i.iccid";
  } else {
    if ($selSucursal>0){
      $extraWhere .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
      $p[]=$selSucursal; $t.='i';
      if ($caja !== '0'){ $extraWhere .= " AND $CAJA_TR=?"; $p[]=$caja; $t.='s'; }
    }
    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn,
                   TRIM(i.$CAJA_COL) AS caja_val, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY s.nombre, i.operador, i.tipo_plan, i.iccid";
  }

  if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="inventario_sims_disponibles.csv"');

  $stmt=$conn->prepare($sql);
  if ($p){ $stmt->bind_param($t, ...$p); }
  $stmt->execute();
  $res=$stmt->get_result();

  $out=fopen('php://output','w');
  fputcsv($out, ['Sucursal','Operador','Tipo Plan','ICCID','DN','Caja','Fecha Ingreso']);
  while($r=$res->fetch_assoc()){
    fputcsv($out, [
      $r['sucursal'] ?? '',
      $r['operador'] ?? '',
      $r['tipo_plan'] ?? '',
      $r['iccid'] ?? '',
      $r['dn'] ?? '',
      $r['caja_val'] ?? '',
      $r['fecha_ingreso'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

/* ===== Vista (con navbar) ===== */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventario SIMs — Central 2.0</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .kpi-card{ border:0; border-radius:1rem; box-shadow:0 6px 20px rgba(0,0,0,.08); }
    .kpi-value{ font-size: clamp(1.8rem, 2.5vw, 2.4rem); font-weight:800; line-height:1; }
    .kpi-sub{ opacity:.75; font-weight:600; }
    .suc-card{ border:0; border-radius:1rem; box-shadow:0 4px 16px rgba(0,0,0,.06); transition:.2s transform; }
    .suc-card:hover{ transform: translateY(-3px); }
    .badge-soft{ background:rgba(13,110,253,.1); color:#0d6efd; }
    .badge-zero{ background:rgba(220,53,69,.12); color:#dc3545; border:1px solid rgba(220,53,69,.18); }
    .grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:1rem; }
    .sticky-top-lite{ position: sticky; top: .5rem; z-index: 100; }
    .table thead th{ position:sticky; top:0; background:var(--bs-body-bg); z-index:5; }
    .searchbar{ max-width: 380px; }
    .hint{ font-size:.86rem; color: var(--bs-secondary-color); }
    .badges-ops .badge{ font-weight:600; }
  </style>
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-md-4">

  <!-- Encabezado + Filtros -->
  <div class="sticky-top-lite mb-3">
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-end justify-content-between">
          <div>
            <h2 class="mb-1">Inventario de SIMs <span class="text-secondary">— Disponibles</span></h2>
            <div class="text-secondary small">Central 2.0 · Vista <?= h($scope==='global'?'global':'por sucursal'); ?></div>
          </div>

          <!-- Form principal: el Export usa estos valores actuales -->
          <form id="filtros" class="row g-2 align-items-end" method="get">
            <?php if (isAdmin($ROL)): ?>
              <div class="col-auto">
                <label class="form-label mb-1">Ámbito</label>
                <select name="scope" class="form-select">
                  <option value="global"   <?= $scope==='global'?'selected':''; ?>>Global</option>
                  <option value="sucursal" <?= $scope==='sucursal'?'selected':''; ?>>Por sucursal</option>
                </select>
              </div>
              <div class="col-auto">
                <label class="form-label mb-1">Sucursal</label>
                <select name="sucursal" class="form-select" <?= $scope==='global'?'':'disabled'; ?>>
                  <option value="0">— Todas —</option>
                  <?php foreach($sucursales as $s): ?>
                    <option value="<?= (int)$s['id']; ?>" <?= (int)$s['id']===$selSucursal?'selected':''; ?>>
                      <?= h($s['nombre']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($scope!=='global'): ?>
                  <!-- Clave: si el select está disabled, enviamos el valor por hidden -->
                  <input type="hidden" name="sucursal" value="<?= (int)$selSucursal; ?>">
                <?php endif; ?>
                <?php if ($scope==='global' && $selSucursal===0): ?>
                  <div class="hint">Elige una sucursal para habilitar “Caja”.</div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <input type="hidden" name="scope" value="sucursal">
              <input type="hidden" name="sucursal" value="<?= (int)$selSucursal; ?>">
            <?php endif; ?>

            <!-- Caja -->
            <div class="col-auto">
              <label class="form-label mb-1">Caja</label>
              <select name="caja" class="form-select" <?= $haySucursalConcreta ? '' : 'disabled'; ?>>
                <option value="0">Todas</option>
                <?php if ($haySucursalConcreta && $cajas): ?>
                  <?php foreach($cajas as $val=>$txt): ?>
                    <option value="<?= h($val); ?>" <?= ((string)$val===(string)$caja)?'selected':''; ?>><?= h($txt); ?></option>
                  <?php endforeach; ?>
                <?php elseif ($haySucursalConcreta && !$cajas): ?>
                  <option value="0" selected>(sin cajas registradas)</option>
                <?php else: ?>
                  <option value="0" selected>(selecciona sucursal)</option>
                <?php endif; ?>
              </select>
            </div>

            <div class="col-auto">
              <label class="form-label mb-1">Operador</label>
              <select name="operador" class="form-select">
                <?= selectOptions([
                  'ALL'=>'Todos',
                  'Bait'=>'Bait',
                  'AT&T'=>'AT&T',
                  'Virgin'=>'Virgin',
                  'Unefon'=>'Unefon',
                  'Telcel'=>'Telcel',
                  'Movistar'=>'Movistar'
                ], $operador); ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label mb-1">Tipo plan</label>
              <select name="tipo_plan" class="form-select">
                <?= selectOptions(['ALL'=>'Todos','Prepago'=>'Prepago','Pospago'=>'Pospago'], $tipoPlan); ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label mb-1">Buscar</label>
              <input type="text" class="form-control searchbar" name="q" value="<?= h($q); ?>" placeholder="ICCID o DN…">
            </div>
            <div class="col-auto d-flex gap-2">
              <button class="btn btn-primary" type="submit">Aplicar</button>
              <a class="btn btn-outline-secondary" href="inventario_sims_resumen.php">Limpiar</a>
              <!-- Exporta con los valores actuales del form, sin exigir "Aplicar" -->
              <button class="btn btn-success" type="submit" name="export" value="1">Exportar CSV</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Mensaje de actualización -->
  <?php if ($alertMsg): ?>
    <div class="alert alert-<?= h($alertType); ?> alert-dismissible fade show" role="alert">
      <?= h($alertMsg); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">SIMs disponibles</div>
          <div class="kpi-value"><?= number_format($kpis['total']); ?></div>
          <div class="small text-secondary">Inventario actual según filtros</div>
        </div>
      </div>
    </div>

    <!-- Operadores -->
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">Bait</div>
        <div class="kpi-value"><?= number_format($kpis['bait']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">AT&amp;T</div>
        <div class="kpi-value"><?= number_format($kpis['att']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">Virgin</div>
        <div class="kpi-value"><?= number_format($kpis['virgin']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">Unefon</div>
        <div class="kpi-value"><?= number_format($kpis['unefon']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">Telcel</div>
        <div class="kpi-value"><?= number_format($kpis['telcel']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card kpi-card"><div class="card-body">
        <div class="kpi-sub text-secondary">Movistar</div>
        <div class="kpi-value"><?= number_format($kpis['movistar']); ?></div>
        <div class="small text-secondary">Por operador</div>
      </div></div>
    </div>
  </div>

  <?php if ($scope==='global'): ?>
    <!-- Cards por sucursal -->
    <div class="grid mb-5">
      <?php if (!$cards): ?>
        <div class="text-center text-secondary py-5">No hay SIMs disponibles que coincidan con los filtros.</div>
      <?php else: foreach($cards as $c): ?>
        <div class="card suc-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="mb-0"><?= h($c['nombre']); ?></h5>
              <span class="badge text-bg-light">ID <?= (int)$c['id_suc']; ?></span>
            </div>
            <div class="display-6 fw-bold mb-2"><?= number_format((int)$c['disponibles']); ?></div>
            <div class="d-flex gap-2 mb-3 flex-wrap badges-ops">
              <span class="badge <?= badgeOpClass((int)$c['bait']); ?>">Bait: <?= (int)$c['bait']; ?></span>
              <span class="badge <?= badgeOpClass((int)$c['att']); ?>">AT&amp;T: <?= (int)$c['att']; ?></span>
              <span class="badge <?= badgeOpClass((int)$c['virgin']); ?>">Virgin: <?= (int)$c['virgin']; ?></span>
              <span class="badge <?= badgeOpClass((int)$c['unefon']); ?>">Unefon: <?= (int)$c['unefon']; ?></span>
              <span class="badge <?= badgeOpClass((int)$c['telcel']); ?>">Telcel: <?= (int)$c['telcel']; ?></span>
              <span class="badge <?= badgeOpClass((int)$c['movistar']); ?>">Movistar: <?= (int)$c['movistar']; ?></span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-outline-primary"
                 href="?<?= h(http_build_query(array_merge($_GET, [
                   'scope'=>'sucursal',
                   'sucursal'=>(int)$c['id_suc']
                 ]))); ?>">
                Ver detalle
              </a>
              <!-- Export rápido desde tarjeta -->
              <button class="btn btn-sm btn-outline-success" type="submit" form="filtros" name="export" value="1"
                onclick="(function(f){
                  const sel = f.querySelector('select[name=sucursal]');
                  if (sel) sel.value='<?= (int)$c['id_suc']; ?>';
                  const hid = f.querySelector('input[type=hidden][name=sucursal]'); 
                  if (hid) hid.value='<?= (int)$c['id_suc']; ?>';
                })(document.getElementById('filtros')); ">
                Exportar CSV
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php else: ?>
    <!-- Detalle de sucursal -->
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
          <div>
            <h4 class="mb-0">Sucursal: <?= h($sucursalNombre ?: ('#'.$selSucursal)); ?></h4>
            <div class="text-secondary small">Listado de SIMs disponibles</div>
          </div>
          <?php if (isAdmin($ROL)): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="?<?= h(http_build_query(array_merge($_GET, ['scope'=>'global','sucursal'=>0]))); ?>">
              Volver a Global
            </a>
          <?php endif; ?>
        </div>

        <div class="table-responsive" style="max-height: 70vh;">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th class="text-secondary">#</th>
                <th>ICCID</th>
                <th>DN</th>
                <th>Operador</th>
                <th>Plan</th>
                <th>Caja</th>
                <th>Ingreso</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$detalle): ?>
                <tr><td colspan="8" class="text-center text-secondary">Sin registros</td></tr>
              <?php else:
                $i=1; foreach($detalle as $r): ?>
                <tr>
                  <td class="text-secondary"><?= $i++; ?></td>
                  <td class="fw-semibold"><?= h($r['iccid']); ?></td>
                  <td><?= h($r['dn']); ?></td>
                  <td><?= h($r['operador']); ?></td>
                  <td><?= h($r['tipo_plan']); ?></td>
                  <td><?= h($r['caja_val']); ?></td>
                  <td class="text-nowrap"><?= h($r['fecha_ingreso']); ?></td>
                  <td>
                    <?php if (isAdmin($ROL)): ?>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary btn-edit-sim"
                        data-id="<?= (int)$r['id']; ?>"
                        data-iccid="<?= h($r['iccid']); ?>"
                        data-dn="<?= h($r['dn']); ?>"
                        data-bs-toggle="modal"
                        data-bs-target="#modalEditarSim">
                        Editar
                      </button>
                    <?php else: ?>
                      <span class="text-muted small">N/A</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-success" type="submit" form="filtros" name="export" value="1">Exportar CSV</button>
        </div>
      </div>
    </div>

    <!-- Modal: Editar SIM (solo Admin, pero el candado está en el POST) -->
    <div class="modal fade" id="modalEditarSim" tabindex="-1" aria-labelledby="modalEditarSimLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="modalEditarSimLabel">
              Editar SIM <span class="text-muted small">ID <span class="sim-id-label"></span></span>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="editar_sim">
            <input type="hidden" name="id_sim" value="">
            <input type="hidden" name="return_query" value="">

            <div class="mb-3">
              <label class="form-label">ICCID</label>
              <input type="text" name="nuevo_iccid" class="form-control" autocomplete="off">
              <div class="form-text">Corrige el ICCID si hay un error de captura.</div>
            </div>

            <div class="mb-2">
              <label class="form-label">DN</label>
              <input type="text" name="nuevo_dn" class="form-control" autocomplete="off">
              <div class="form-text">Actualiza el DN si fue reasignado.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>

  <?php endif; ?>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('modalEditarSim');
  if (!modal) return;

  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) return;

    var id    = button.getAttribute('data-id')    || '';
    var iccid = button.getAttribute('data-iccid') || '';
    var dn    = button.getAttribute('data-dn')    || '';

    var idInput     = modal.querySelector('input[name="id_sim"]');
    var iccidInput  = modal.querySelector('input[name="nuevo_iccid"]');
    var dnInput     = modal.querySelector('input[name="nuevo_dn"]');
    var idLabel     = modal.querySelector('.sim-id-label');

    if (idInput) idInput.value = id;
    if (iccidInput) iccidInput.value = iccid;
    if (dnInput) dnInput.value = dn;
    if (idLabel) idLabel.textContent = id;
  });

  // Guardamos la query actual para volver al mismo estado después del POST
  var returnInput = document.querySelector('#modalEditarSim input[name="return_query"]');
  if (returnInput) {
    returnInput.value = window.location.search.replace(/^\?/, '');
  }
});
</script>
</body>
</html>
