<?php
// lista_precios.php — Luga
// KPIs globales para Admin, Top promos solo de la lista, pills legibles (texto negro) y % por promo, RAM antes de Capacidad.

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = trim((string)($_SESSION['rol'] ?? ''));
$puedeEditar = in_array($rol, ['Admin'], true);

// Helpers
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return is_null($n) ? null : number_format((float)$n, 2); }
function n0($v){ return number_format((float)$v,0); }
function n1($v){ return number_format((float)$v,1); }

// Detectar columnas opcionales
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  return $rs && $rs->num_rows > 0;
}
$hasV_Modalidad   = hasColumn($conn, 'ventas', 'modalidad');
$hasDV_PrecioUnit = hasColumn($conn, 'detalle_venta', 'precio_unitario');
$hasP_RAM         = hasColumn($conn, 'productos', 'ram');

// --------- Query agrupada por marca/modelo/capacidad (inventario disponible) ---------
$sql = "
  SELECT 
    p.marca,
    p.modelo,
    COALESCE(p.capacidad, '') AS capacidad," .
    ($hasP_RAM ? " MAX(COALESCE(p.ram,'')) AS ram," : " '' AS ram,") . "
    COUNT(*) AS disponibles_global,
    SUM(CASE WHEN i.id_sucursal = ? THEN 1 ELSE 0 END) AS disponibles_sucursal,
    MAX(p.precio_lista) AS precio_lista,
    MAX(pc.precio_combo) AS precio_combo,
    MAX(pc.promocion)    AS promocion
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  LEFT JOIN precios_combo pc
    ON pc.marca = p.marca AND pc.modelo = p.modelo 
   AND COALESCE(pc.capacidad,'') = COALESCE(p.capacidad,'')
  WHERE i.estatus = 'Disponible'
  GROUP BY p.marca, p.modelo, COALESCE(p.capacidad,'')
  ORDER BY p.marca ASC, p.modelo ASC, capacidad ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $idSucursal);
$stmt->execute();
$res = $stmt->get_result();
$datos = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/** Colores para la promo según texto (para la badge de tabla) */
function promoBadgeClass(string $txt): string {
  $t = mb_strtolower(trim($txt), 'UTF-8');
  if ($t === '') return 'promo-none';
  if (preg_match('/%|desc|descuento/', $t)) return 'promo-green';
  if (preg_match('/combo|kit/', $t))       return 'promo-orange';
  if (preg_match('/gratis|regalo/', $t))   return 'promo-purple';
  if (preg_match('/liquidaci[oó]n|remate/', $t)) return 'promo-red';
  return 'promo-blue';
}

// Opciones filtros (desde inventario mostrado) + set de promos activas en la lista
$marcas = []; $capacidades = []; $promoSet = [];
$withPromoCount = 0;
foreach ($datos as $r){
  $marcas[$r['marca']] = true;
  $capacidades[$r['capacidad'] === '' ? '—' : $r['capacidad']] = true;
  $p = trim((string)$r['promocion']);
  if ($p !== '') { $withPromoCount++; $promoSet[$p] = true; }
}
ksort($marcas, SORT_NATURAL | SORT_FLAG_CASE);
ksort($capacidades, SORT_NATURAL | SORT_FLAG_CASE);

// ===================== KPIs de VENTAS =====================
date_default_timezone_set('America/Mexico_City');
$hoy       = date('Y-m-d');
$inicioMes = date('Y-m-01');
$hace7     = date('Y-m-d', strtotime('-7 days'));

$isAdmin    = preg_match('/^admin(istrador)?$/i', $rol) === 1;
$scopeLabel = $isAdmin ? 'Global' : 'Por sucursal';
$DATE_LOCAL = "DATE(v.fecha_venta)"; // robusto aunque no haya tablas TZ

// Unidades (7 días)
if ($isAdmin) {
  $q7 = $conn->prepare("SELECT COUNT(*) AS unidades
                        FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                        WHERE $DATE_LOCAL >= ?");
  $q7->bind_param('s', $hace7);
} else {
  $q7 = $conn->prepare("SELECT COUNT(*) AS unidades
                        FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                        WHERE v.id_sucursal = ? AND $DATE_LOCAL >= ?");
  $q7->bind_param('is', $idSucursal, $hace7);
}
$q7->execute();
$ventas7 = (int)($q7->get_result()->fetch_assoc()['unidades'] ?? 0);
$q7->close();

// Unidades (mes)
if ($isAdmin) {
  $qm = $conn->prepare("SELECT COUNT(*) AS unidades
                        FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                        WHERE $DATE_LOCAL BETWEEN ? AND ?");
  $qm->bind_param('ss', $inicioMes, $hoy);
} else {
  $qm = $conn->prepare("SELECT COUNT(*) AS unidades
                        FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                        WHERE v.id_sucursal = ? AND $DATE_LOCAL BETWEEN ? AND ?");
  $qm->bind_param('iss', $idSucursal, $inicioMes, $hoy);
}
$qm->execute();
$ventasMes = (int)($qm->get_result()->fetch_assoc()['unidades'] ?? 0);
$qm->close();

// % Ventas con combo
$pctCombo = null;
if ($hasV_Modalidad) {
  if ($isAdmin) {
    $qc = $conn->prepare("SELECT 
                            SUM(CASE WHEN LOWER(v.modalidad) LIKE '%combo%' THEN 1 ELSE 0 END) AS con_combo,
                            COUNT(*) AS total
                          FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                          WHERE $DATE_LOCAL BETWEEN ? AND ?");
    $qc->bind_param('ss', $inicioMes, $hoy);
  } else {
    $qc = $conn->prepare("SELECT 
                            SUM(CASE WHEN LOWER(v.modalidad) LIKE '%combo%' THEN 1 ELSE 0 END) AS con_combo,
                            COUNT(*) AS total
                          FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                          WHERE v.id_sucursal = ? AND $DATE_LOCAL BETWEEN ? AND ?");
    $qc->bind_param('iss', $idSucursal, $inicioMes, $hoy);
  }
  $qc->execute();
  $rc = $qc->get_result()->fetch_assoc();
  $qc->close();
  $totalL  = (int)($rc['total'] ?? 0);
  $conCombo= (int)($rc['con_combo'] ?? 0);
  $pctCombo= $totalL > 0 ? round(($conCombo / $totalL) * 100, 1) : null;
}

// Ticket promedio
$ticketProm = null;
if ($hasDV_PrecioUnit) {
  if ($isAdmin) {
    $qt = $conn->prepare("SELECT AVG(dv.precio_unitario) AS avg_ticket
                          FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                          WHERE $DATE_LOCAL BETWEEN ? AND ? AND dv.precio_unitario IS NOT NULL");
    $qt->bind_param('ss', $inicioMes, $hoy);
  } else {
    $qt = $conn->prepare("SELECT AVG(dv.precio_unitario) AS avg_ticket
                          FROM detalle_venta dv INNER JOIN ventas v ON v.id = dv.id_venta
                          WHERE v.id_sucursal = ? AND $DATE_LOCAL BETWEEN ? AND ? AND dv.precio_unitario IS NOT NULL");
    $qt->bind_param('iss', $idSucursal, $inicioMes, $hoy);
  }
  $qt->execute();
  $ticketProm = $qt->get_result()->fetch_assoc()['avg_ticket'] ?? null;
  $qt->close();
}

// ---------- Top promos (mes) SOLO entre las activas en la lista ----------
$promoList = array_keys($promoSet); // promos activas visibles
$topPromos = []; // cada item: ['promo'=>..., 'unidades'=>N, 'pct'=>P]
$topPromoName='—'; $topPromoUnits=0; $topPromoPct=null;

if (!empty($promoList)) {
  $escaped = array_map(fn($s) => "'".$conn->real_escape_string($s)."'", $promoList);
  $inList  = implode(',', $escaped);

  if ($isAdmin) {
    $sqlTop = "SELECT pc.promocion AS promo, COUNT(*) AS unidades
               FROM detalle_venta dv
               INNER JOIN ventas v   ON v.id = dv.id_venta
               INNER JOIN productos p ON p.id = dv.id_producto
               LEFT JOIN precios_combo pc
                 ON pc.marca = p.marca
                AND pc.modelo = p.modelo
                AND COALESCE(pc.capacidad,'') = COALESCE(p.capacidad,'')
               WHERE $DATE_LOCAL BETWEEN ? AND ?
                 AND pc.promocion IN ($inList)
               GROUP BY pc.promocion
               ORDER BY unidades DESC
               LIMIT 5";
    $qp = $conn->prepare($sqlTop);
    $qp->bind_param('ss', $inicioMes, $hoy);
  } else {
    $sqlTop = "SELECT pc.promocion AS promo, COUNT(*) AS unidades
               FROM detalle_venta dv
               INNER JOIN ventas v   ON v.id = dv.id_venta
               INNER JOIN productos p ON p.id = dv.id_producto
               LEFT JOIN precios_combo pc
                 ON pc.marca = p.marca
                AND pc.modelo = p.modelo
                AND COALESCE(pc.capacidad,'') = COALESCE(p.capacidad,'')
               WHERE v.id_sucursal = ?
                 AND $DATE_LOCAL BETWEEN ? AND ?
                 AND pc.promocion IN ($inList)
               GROUP BY pc.promocion
               ORDER BY unidades DESC
               LIMIT 5";
    $qp = $conn->prepare($sqlTop);
    $qp->bind_param('iss', $idSucursal, $inicioMes, $hoy);
  }
  $qp->execute();
  $rsp = $qp->get_result();

  // Para calcular % necesitamos el total de unidades de TODAS las promos activas
  $totalPromoUnits = 0;
  $raw = [];
  while($row = $rsp->fetch_assoc()){
    $u = (int)$row['unidades'];
    $raw[] = ['promo'=>$row['promo'], 'unidades'=>$u];
    $totalPromoUnits += $u;
  }
  $qp->close();

  // Armar arreglo final con % (sobre total de promos activas del mes)
  foreach ($raw as $it) {
    $pct = $totalPromoUnits > 0 ? round(($it['unidades'] / $totalPromoUnits) * 100, 1) : 0.0;
    $topPromos[] = ['promo'=>$it['promo'], 'unidades'=>$it['unidades'], 'pct'=>$pct];
  }

  if (!empty($topPromos)) {
    $topPromoName  = $topPromos[0]['promo'];
    $topPromoUnits = $topPromos[0]['unidades'];
    $topPromoPct   = $topPromos[0]['pct'];
  }
}

$ultima = date('Y-m-d H:i');
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <title>Lista de Precios — Luga</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{ background:#F5F7FA; color:#0B1220; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .page-title{ display:flex; align-items:center; gap:.75rem; }
    .page-title .emoji{ font-size:1.6rem; }

    .card-soft{ background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 6px 18px rgba(17,24,39,.06); }
    .filters .form-select, .filters .form-control{ background:#fff; color:#0B1220; border-color:#D1D5DB; }

    .chip{ display:inline-flex; align-items:center; gap:.5rem; background:#E5E7EB; color:#000; padding:.38rem .7rem; border-radius:999px; font-size:.86rem; font-weight:600; border:1px solid #D1D5DB; }

    /* Pills / badges con texto NEGRO */
    .badge-muted{ background:#F3F4F6; color:#000 !important; border:1px solid #E5E7EB; font-weight:600; }
    .pill-ok{ background:#DCFCE7; color:#000 !important; border:1px solid #86EFAC; font-weight:700; }
    .pill-warn{ background:#FEF3C7; color:#000 !important; border:1px solid #FDE68A; font-weight:700; }

    /* Promos por tipo – texto negro */
    .promo-blue   { background:#DBEAFE; color:#000 !important; border:1px solid #BFDBFE; font-weight:600; }
    .promo-green  { background:#BBF7D0; color:#000 !important; border:1px solid #86EFAC; font-weight:600; }
    .promo-orange { background:#FED7AA; color:#000 !important; border:1px solid #FDBA74; font-weight:600; }
    .promo-purple { background:#E9D5FF; color:#000 !important; border:1px solid #D8B4FE; font-weight:600; }
    .promo-red    { background:#FECACA; color:#000 !important; border:1px solid #FCA5A5; font-weight:600; }
    .promo-none   { color:#6B7280 !important; }

    .table thead th{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #D1D5DB; font-weight:700; white-space:nowrap; }
    .table-hover tbody tr:hover{ background:#F9FAFB; }
    .th-sort{ cursor:pointer; white-space:nowrap; }
    .actions .btn{ white-space:nowrap; }

    .table-wrap{ overflow:auto; }

    /* ===== KPIs ===== */
    .kpi{ background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:1rem 1rem; height:100%; position:relative; overflow:hidden; box-shadow:0 6px 18px rgba(17,24,39,.06); }
    .kpi .label{ font-size:.9rem; color:#6b7280; }
    .kpi .value{ font-size:1.5rem; font-weight:800; line-height:1.15; }
    .kpi .hint{ font-size:.85rem; color:#6b7280; }
    .kpi::after{
      content:""; position:absolute; right:-28px; top:-28px; width:120px; height:120px;
      background: radial-gradient(58px 58px at 58px 58px, rgba(13,110,253,.12), transparent 60%);
    }
    .top-promos .badge{ font-size:.9rem; background:#E5E7EB; color:#000 !important; border:1px solid #D1D5DB; }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container py-4">
  <div class="page-header mb-3">
    <div class="page-title">
      <span class="emoji">📋</span>
      <div>
        <h3 class="mb-0">Lista de precios por modelo</h3>
        <div class="text-muted small">Mostrando solo equipos <strong>Disponibles</strong>. Última actualización: <?= esc($ultima) ?></div>
      </div>
    </div>
    <div class="controls-right no-print d-flex gap-2">
      <button id="btnExport" class="btn btn-outline-primary btn-sm"><i class="bi bi-filetype-csv me-1"></i> Exportar CSV</button>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Imprimir</button>
    </div>
  </div>

  <!-- ============== KPIs de ventas / promos (arriba) ============== -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Top promo (mes)</div>
        <div class="value"><?= esc($topPromoName) ?></div>
        <div class="hint"><?= n0($topPromoUnits) ?> u<?= $topPromoPct!==null ? ' · '.n1($topPromoPct).'%' : '' ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Unidades (7 días)</div>
        <div class="value"><?= n0($ventas7) ?></div>
        <div class="hint"><?= esc($scopeLabel) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Unidades (mes)</div>
        <div class="value"><?= n0($ventasMes) ?></div>
        <div class="hint"><?= esc($scopeLabel) ?> · Mes en curso</div>
      </div>
    </div>
    <?php if ($pctCombo !== null): ?>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">% ventas con combo</div>
        <div class="value"><?= n1($pctCombo) ?>%</div>
        <div class="hint"><?= esc($scopeLabel) ?> · Mes en curso</div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($ticketProm !== null): ?>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Ticket promedio</div>
        <div class="value">$<?= money($ticketProm) ?></div>
        <div class="hint"><?= esc($scopeLabel) ?> · Mes en curso</div>
      </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
      <div class="kpi">
        <div class="label">Modelos con promo activa</div>
        <div class="value"><?= n0($withPromoCount) ?></div>
        <div class="hint">De la lista visible</div>
      </div>
    </div>
    <?php if (!empty($topPromos)): ?>
    <div class="col-12 col-md-6">
      <div class="kpi top-promos p-3 card-soft">
        <div class="label mb-1">Top 3 promos (mes, por unidades)</div>
        <?php foreach (array_slice($topPromos, 0, 3) as $tp): ?>
          <span class="badge rounded-pill me-1 mb-1">
            <i class="bi bi-megaphone me-1"></i><?= esc($tp['promo']) ?> · <b><?= n0($tp['unidades']) ?></b> · <?= n1($tp['pct']) ?>%
          </span>
        <?php endforeach; ?>
        <?php if (count($topPromos) > 3): ?>
          <span class="badge rounded-pill me-1 mb-1">+<?= n0(count($topPromos)-3) ?> más</span>
        <?php endif; ?>
        <div class="hint mt-1">Fuente: ventas <?= $isAdmin ? 'globales' : 'de tu sucursal' ?> (mes en curso)</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Filtros y buscador -->
  <div class="card-soft p-3 mb-3">
    <div class="filters row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Marca</label>
        <select id="fMarca" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($marcas) as $m): ?>
            <option value="<?= esc($m) ?>"><?= esc($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Capacidad</label>
        <select id="fCapacidad" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach(array_keys($capacidades) as $c): ?>
            <option value="<?= esc($c) ?>"><?= esc($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label mb-1">Buscar</label>
        <input id="fSearch" type="search" class="form-control form-control-sm" placeholder="Modelo, marca, promo…">
      </div>
      <div class="col-6 col-md-1">
        <div class="form-check form-switch mt-4">
          <input class="form-check-input" type="checkbox" id="onlySucursal">
          <label class="form-check-label small" for="onlySucursal">Solo mi sucursal</label>
        </div>
      </div>
      <div class="col-6 col-md-2">
        <div class="form-check form-switch mt-4">
          <input class="form-check-input" type="checkbox" id="onlyCombo">
          <label class="form-check-label small" for="onlyCombo">Solo con combo</label>
        </div>
      </div>
    </div>
  </div>

  <div class="card-soft p-0">
    <div class="table-wrap">
      <table id="tabla" class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th class="th-sort" data-key="marca">Marca <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="modelo">Modelo <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="ram">RAM <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="capacidad">Capacidad <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="precio_lista_num">Precio lista ($) <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-end th-sort" data-key="precio_combo_num">Precio combo ($) <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="th-sort" data-key="promo">Promoción <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="dispo_global_num">Disp. Global <i class="bi bi-arrow-down-up ms-1"></i></th>
            <th class="text-center th-sort" data-key="dispo_suc_num">En mi sucursal <i class="bi bi-arrow-down-up ms-1"></i></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$datos): ?>
            <tr><td colspan="<?= $puedeEditar? '10':'9' ?>" class="text-center text-muted py-4">
              No hay equipos disponibles para mostrar.
            </td></tr>
          <?php else: foreach($datos as $r):
            $marca = $r['marca'];
            $modelo = $r['modelo'];
            $ram = ($r['ram'] ?? '') === '' ? '—' : $r['ram'];
            $capacidad = $r['capacidad'] === '' ? '—' : $r['capacidad'];
            $pl = money($r['precio_lista']);
            $pc = is_null($r['precio_combo']) ? null : money($r['precio_combo']);
            $promo = trim((string)$r['promocion']);
            $dg = (int)$r['disponibles_global'];
            $ds = (int)$r['disponibles_sucursal'];

            $promoClass = promoBadgeClass($promo);
            $promoTxt = $promo === '' ? '—' : $promo;
            $promoKey = ($promo === '' ? '0|' : '1|') . mb_strtolower($promoTxt,'UTF-8'); // con promo primero
          ?>
          <tr
            data-marca="<?= esc($marca) ?>"
            data-ram="<?= esc($ram) ?>"
            data-capacidad="<?= esc($capacidad) ?>"
            data-haycombo="<?= $pc===null ? '0' : '1' ?>"
            data-dsuc="<?= $ds ?>"
            data-precio_lista_num="<?= $pl !== null ? (float)$r['precio_lista'] : 0 ?>"
            data-precio_combo_num="<?= $pc !== null ? (float)$r['precio_combo'] : 0 ?>"
            data-dispo_global_num="<?= $dg ?>"
            data-dispo_suc_num="<?= $ds ?>"
            data-promo="<?= esc($promoKey) ?>"
          >
            <td><span class="chip"><?= esc($marca) ?></span></td>
            <td class="fw-semibold"><?= esc($modelo) ?></td>
            <td><span class="badge badge-muted rounded-pill"><?= esc($ram) ?></span></td>
            <td><span class="badge badge-muted rounded-pill"><?= esc($capacidad) ?></span></td>
            <td class="text-end"><?= $pl===null ? '<span class="text-muted">—</span>' : '$'.$pl ?></td>
            <td class="text-end"><?= $pc===null ? '<span class="text-muted">—</span>' : '$'.$pc ?></td>
            <td>
              <?php if ($promo===''): ?>
                <span class="promo-none">—</span>
              <?php else: ?>
                <span class="badge rounded-pill <?= esc($promoClass) ?>">
                  <i class="bi bi-megaphone me-1"></i><?= esc($promo) ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="text-center"><span class="badge rounded-pill <?= $dg>0 ? 'pill-ok':'badge-muted' ?>"><?= $dg ?></span></td>
            <td class="text-center"><span class="badge rounded-pill <?= $ds>0 ? 'pill-warn':'badge-muted' ?>"><?= $ds ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3 d-flex flex-wrap gap-2">
    <span class="chip"><i class="bi bi-collection me-1"></i> Modelos: <strong id="statModelos">0</strong></span>
    <span class="chip"><i class="bi bi-box-seam me-1"></i> Total disp. global: <strong id="statGlobal">0</strong></span>
    <span class="chip"><i class="bi bi-shop me-1"></i> Total en mi sucursal: <strong id="statSucursal">0</strong></span>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // ---------- Filtros / búsqueda ----------
  const fMarca = document.getElementById('fMarca');
  const fCapacidad = document.getElementById('fCapacidad');
  const fSearch = document.getElementById('fSearch');
  const onlySucursal = document.getElementById('onlySucursal');
  const onlyCombo = document.getElementById('onlyCombo');
  const tbody = document.querySelector('#tabla tbody');

  function textOf(el){ return (el.textContent || '').toLowerCase(); }

  function applyFilters(){
    const marca = (fMarca.value || '').toLowerCase();
    const cap = (fCapacidad.value || '').toLowerCase();
    const q = (fSearch.value || '').toLowerCase();
    const suc = !!(onlySucursal && onlySucursal.checked);
    const combo = !!(onlyCombo && onlyCombo.checked);

    let modelos=0, sumG=0, sumS=0;

    [...tbody.rows].forEach(tr=>{
      const trMarca = (tr.dataset.marca||'').toLowerCase();
      const trCap = (tr.dataset.capacidad||'').toLowerCase();
      const haycombo = tr.dataset.haycombo === '1';
      const dsuc = parseInt(tr.dataset.dsuc||'0',10);
      const full = textOf(tr);

      let ok = true;
      if (marca && trMarca !== marca) ok=false;
      if (cap && trCap !== cap) ok=false;
      if (suc && dsuc <= 0) ok=false;
      if (combo && !haycombo) ok=false;
      if (q && !full.includes(q)) ok=false;

      tr.style.display = ok ? '' : 'none';
      if (ok){
        modelos++;
        sumG += parseInt(tr.dataset.dispo_global_num||'0',10);
        sumS += parseInt(tr.dataset.dispo_suc_num||'0',10);
      }
    });

    document.getElementById('statModelos').textContent = modelos;
    document.getElementById('statGlobal').textContent = sumG;
    document.getElementById('statSucursal').textContent = sumS;
  }

  [fMarca, fCapacidad, fSearch, onlySucursal, onlyCombo].forEach(el=>{
    if (!el) return;
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

  // ---------- Ordenamiento ----------
  let sortState = { key: null, dir: 1 };
  document.querySelectorAll('.th-sort').forEach(th=>{
    th.addEventListener('click', ()=>{
      const key = th.dataset.key;
      sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
      sortState.key = key;
      sortRows(key, sortState.dir);
      applyFilters(); // conserva filtros
    });
  });

  function sortRows(key, dir){
    const rows = [...tbody.rows];
    rows.sort((a,b)=>{
      if (key === 'promo'){
        const ap = a.dataset.promo || '0|';
        const bp = b.dataset.promo || '0|';
        return ap.localeCompare(bp, 'es', {numeric:true, sensitivity:'base'}) * -dir; // con promo primero
      }
      const va = a.dataset[key] || a.textContent;
      const vb = b.dataset[key] || b.textContent;
      const na = Number(va), nb = Number(vb);
      if (!Number.isNaN(na) && !Number.isNaN(nb)) return (na - nb) * dir;
      return va.localeCompare(vb, 'es', {numeric:true, sensitivity:'base'}) * dir;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }

  // ---------- Export CSV (solo visibles) ----------
  document.getElementById('btnExport').addEventListener('click', ()=>{
    const headers = [];
    document.querySelectorAll('#tabla thead th').forEach(th=>{
      if (!th.classList.contains('no-print')) headers.push(th.innerText.trim());
    });
    const rows = [];
    [...tbody.rows].forEach(tr=>{
      if (tr.style.display === 'none') return;
      const tds = [...tr.cells];
      const vals = [];
      tds.forEach(td=>{
        const isLastActions = td.querySelector('button') !== null;
        if (isLastActions) return;
        vals.push(td.innerText.replace(/\s+/g,' ').trim());
      });
      rows.push(vals);
    });
    const csv = [headers, ...rows].map(r=>r.map(v=>{
      v = v.replace(/"/g,'""'); return `"${v}"`;
    }).join(',')).join('\n');

    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'lista_precios.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // ---------- Inicial: ordenar por Promoción y aplicar filtros ----------
  sortState = { key:'promo', dir:1 };
  sortRows('promo', 1);
  applyFilters();

  // (Opcional) función para abrir modal combo si habilitas edición
  window.openComboModal = window.openComboModal || function(){};
</script>
</body>
</html>
