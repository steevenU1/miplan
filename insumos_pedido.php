<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

$ROL        = $_SESSION['rol'] ?? '';
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

/*
  Requiere índice único si quieres evitar líneas duplicadas:
  ALTER TABLE insumos_pedidos_detalle
  ADD UNIQUE KEY uq_pedido_insumo (id_pedido, id_insumo);
*/

/* ===================================================================
   >>>>>>>>>>>>>>>>>>>>  DEV OVERRIDE (PROVISIONAL) <<<<<<<<<<<<<<<<<<
   Cambia SOLO estas 2 líneas durante tus pruebas y luego
   regresa a estos valores por defecto:
     $DEV_FORCE_WINDOW     = null;   // null = normal, true = abrir, false = cerrar
     $DEV_ALLOW_ANY_PERIOD = false;  // false = solo mes siguiente, true = cualquier mes
   =================================================================== */
$DEV_FORCE_WINDOW     = null;   // ← true/false para forzar ventana; null para normal
$DEV_ALLOW_ANY_PERIOD = false;  // ← true permite capturar en cualquier mes
/* =================================================================== */

/* ------------------ Acceso: SOLO Gerente de TIENDA PROPIA ------------------ */
if ($ROL !== 'Gerente') {
  echo "<!doctype html><html><body><div class='container mt-4'><div class='alert alert-danger'>Solo un Gerente puede hacer el pedido de insumos.</div></div></body></html>";
  exit;
}

$stmtSuc = $conn->prepare("SELECT tipo_sucursal, COALESCE(subtipo,'') AS subtipo, nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtSuc->bind_param("i", $idSucursal);
$stmtSuc->execute();
$tipoRow = $stmtSuc->get_result()->fetch_assoc();
$stmtSuc->close();

$tipo      = strtolower($tipoRow['tipo_sucursal'] ?? '');
$subtipo   = strtolower($tipoRow['subtipo'] ?? '');
$sucNombre = $tipoRow['nombre'] ?? '';

$esTiendaPropia = ($tipo === 'tienda' && $subtipo === 'propia');
if (!$esTiendaPropia) {
  echo "<!doctype html><html><body><div class='container mt-4'>
          <div class='alert alert-warning'>
            🚫 Esta sucursal no está habilitada para pedir insumos.<br>
            Requisito: <strong>Tienda</strong> con <strong>subtipo = Propia</strong>.
          </div>
        </div></body></html>";
  exit;
}

/* ------------------ Periodo seleccionado por el usuario ------------------ */
// Siempre iniciar en el mes siguiente por defecto
if (isset($_GET['anio']) && isset($_GET['mes'])) {
    $anio = (int)$_GET['anio'];
    $mes  = (int)$_GET['mes'];
} else {
    $hoy = new DateTime('now', new DateTimeZone('America/Mexico_City'));
    $siguiente = (clone $hoy)->modify('first day of next month');
    $anio = (int)$siguiente->format('Y');
    $mes  = (int)$siguiente->format('n');
}

/* ============================================================
   Ventana: 4 días del mes ANTERIOR al periodo solicitado,
   específicamente los 4 días ANTES de los últimos 2 días del mes.
   Si el mes anterior termina en N, ventana = [N-5, N-2].
   ============================================================ */
function obtenerRangoVentana(int $anio, int $mes): array {
  $tz = new DateTimeZone('America/Mexico_City');
  $periodo = DateTime::createFromFormat('Y-n-j', "$anio-$mes-1", $tz);
  if (!$periodo) return ['inicio'=>null,'fin'=>null];

  // Mes anterior al periodo solicitado
  $mesAnterior = (clone $periodo)->modify('first day of previous month');

  // Último día del mes anterior (N)
  $ultimoDiaMesAnterior = (clone $mesAnterior)->modify('last day of this month');

  // Fin de ventana = N - 2 (excluimos los últimos 2 días: N-1 y N)
  $finVentana = (clone $ultimoDiaMesAnterior)->modify('-2 days');

  // Inicio de ventana = (N - 2) - 3 = N - 5  → 4 días: N-5, N-4, N-3, N-2
  $inicioVentana = (clone $finVentana)->modify('-3 days');

  // Normalizamos horas
  $inicioVentana->setTime(0,0,0);
  $finVentana->setTime(23,59,59);

  return ['inicio' => $inicioVentana, 'fin' => $finVentana];
}

function ventanaAbiertaParaPeriodo(int $anio, int $mes): bool {
  $tz   = new DateTimeZone('America/Mexico_City');
  $hoy  = new DateTime('now', $tz);
  $r = obtenerRangoVentana($anio, $mes);
  if (!$r['inicio'] || !$r['fin']) return false;
  return ($hoy >= $r['inicio'] && $hoy <= $r['fin']);
}

/* ------------------ Ventana (cálculo + periodo permitido) ------------------ */
$tz  = new DateTimeZone('America/Mexico_City');
$hoy = new DateTime('now', $tz);

/* Periodo permitido: SIEMPRE el SIGUIENTE mes al actual */
$periodoPermitido = (clone $hoy)->modify('first day of next month');
$anioPermitido = (int)$periodoPermitido->format('Y');
$mesPermitido  = (int)$periodoPermitido->format('n');

$rango = obtenerRangoVentana($anio, $mes);
$desde = $rango['inicio'] ? $rango['inicio']->format('d/m/Y') : '';
$hasta = $rango['fin']    ? $rango['fin']->format('d/m/Y')    : '';

$ventanaActiva = ventanaAbiertaParaPeriodo($anio, $mes);
$solicitudEsPeriodoPermitido = ($anio === $anioPermitido && $mes === $mesPermitido);

/* Reglas normales (productivas) */
if (!$solicitudEsPeriodoPermitido) { $ventanaActiva = false; }

/* DEV override (provisional) */
if ($DEV_ALLOW_ANY_PERIOD === true) { $solicitudEsPeriodoPermitido = true; }
if ($DEV_FORCE_WINDOW !== null)     { $ventanaActiva = (bool)$DEV_FORCE_WINDOW; }

$ventanaLeyenda      = $ventanaActiva ? "Abierta del $desde al $hasta" : "Estuvo abierta del $desde al $hasta";
$periodoPermitidoTxt = sprintf('%02d/%d', $mesPermitido, $anioPermitido);

/* ============================================================
   Helpers de límites
   ============================================================ */
function normalizarSubtipo(?string $s): ?string {
  if ($s === null || $s === '') return null;
  return ucfirst(strtolower($s)); // 'Propia', 'Subdistribuidor', etc.
}

/**
 * Busca límites priorizando:
 *   1) Regla específica de sucursal (id_sucursal = X)
 *   2) Regla global con id_sucursal = 0
 *   3) Regla global con id_sucursal = NULL
 * Además pondera coincidencia exacta de rol y subtipo (o acepta NULL).
 */
function obtenerLimiteInsumo(mysqli $conn, int $idInsumo, int $idSucursal, string $rol = 'Gerente', ?string $subtipoSucursal = 'Propia'): ?array {
  $subtipoSucursal = normalizarSubtipo($subtipoSucursal);

  $sql = "
    SELECT max_por_linea, max_por_mes
    FROM insumos_limites
    WHERE id_insumo = ?
      AND (rol = ? OR rol IS NULL)
      AND (subtipo IS NULL OR subtipo = ?)
      AND (id_sucursal IS NULL OR id_sucursal = 0 OR id_sucursal = ?)
      AND (max_por_linea IS NOT NULL OR max_por_mes IS NOT NULL)
      AND activo = 1
    ORDER BY
      (id_sucursal = ?) DESC,
      (id_sucursal = 0) DESC,
      (id_sucursal IS NULL) DESC,
      (rol = ?) DESC,
      (subtipo = ?) DESC
    LIMIT 1
  ";

  $st = $conn->prepare($sql);
  // tipos: i s s i i s s  -> "issiiss"
  $st->bind_param(
    "issiiss",
    $idInsumo,         // i
    $rol,              // s
    $subtipoSucursal,  // s
    $idSucursal,       // i (filtro)
    $idSucursal,       // i (ORDER BY prioridad)
    $rol,              // s (ORDER BY)
    $subtipoSucursal   // s (ORDER BY)
  );
  $st->execute();
  $res = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$res) return null;

  return [
    'max_por_linea' => $res['max_por_linea'] !== null ? (float)$res['max_por_linea'] : null,
    'max_por_mes'   => $res['max_por_mes']   !== null ? (float)$res['max_por_mes']   : null,
  ];
}

function sumaCapturadaPedido(mysqli $conn, int $idPedido, int $idInsumo): float {
  $sql = "SELECT COALESCE(SUM(cantidad),0) AS total FROM insumos_pedidos_detalle WHERE id_pedido=? AND id_insumo=?";
  $st = $conn->prepare($sql);
  $st->bind_param('ii', $idPedido, $idInsumo);
  $st->execute();
  $total = (float)($st->get_result()->fetch_assoc()['total'] ?? 0);
  $st->close();
  return $total;
}

/* ------------------ Obtener/crear pedido del periodo ------------------ */
$stmtPed = $conn->prepare("
  SELECT id, estatus FROM insumos_pedidos
  WHERE id_sucursal=? AND anio=? AND mes=?
  LIMIT 1
");
$stmtPed->bind_param("iii", $idSucursal,$anio,$mes);
$stmtPed->execute();
$rowPed = $stmtPed->get_result()->fetch_assoc();
$stmtPed->close();

if (!$rowPed) {
  $stmtIns = $conn->prepare("INSERT INTO insumos_pedidos (id_sucursal, anio, mes) VALUES (?,?,?)");
  $stmtIns->bind_param("iii", $idSucursal,$anio,$mes);
  $stmtIns->execute();
  $stmtIns->close();

  $stmtPed = $conn->prepare("
    SELECT id, estatus FROM insumos_pedidos
    WHERE id_sucursal=? AND anio=? AND mes=?
    LIMIT 1
  ");
  $stmtPed->bind_param("iii", $idSucursal,$anio,$mes);
  $stmtPed->execute();
  $rowPed = $stmtPed->get_result()->fetch_assoc();
  $stmtPed->close();
}

$idPedido = (int)($rowPed['id'] ?? 0);
$estatus  = $rowPed['estatus'] ?? 'Borrador';

$msg = '';
$msgClass = 'success';

/* ============================================================
   POST: agregar / borrar / enviar (solo si Borrador y ventanaActiva)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD']==='POST' && $estatus==='Borrador') {

  // Seguridad adicional
  if (!$solicitudEsPeriodoPermitido || !$ventanaActiva) {
    $msg = 'No puedes editar fuera de la ventana de captura del mes permitido.';
    $msgClass='warning';
    goto END_POST;
  }

  // Agregar/actualizar línea
  if (isset($_POST['add_line'])) {
    $id_insumo = (int)($_POST['id_insumo'] ?? 0);
    $cantidad  = max(0, (float)($_POST['cantidad'] ?? 0));
    $coment    = trim($_POST['comentario'] ?? '');

    if ($id_insumo <= 0 || $cantidad <= 0) {
      $msg = 'Selecciona un insumo y una cantidad válida.';
      $msgClass='danger';
      goto END_POST;
    }

    // límites (usar ROL y subtipo de la sucursal real)
    $lim = obtenerLimiteInsumo($conn, $id_insumo, $idSucursal, $ROL, $tipoRow['subtipo'] ?? 'Propia');
    if ($lim) {
      $maxLinea = $lim['max_por_linea'];
      $maxMes   = $lim['max_por_mes'];

      if ($maxLinea !== null && $cantidad > $maxLinea) {
        $msg = "No puedes agregar más de {$maxLinea} por línea para este insumo.";
        $msgClass='danger';
        goto END_POST;
      }

      $yaCapturado = sumaCapturadaPedido($conn, $idPedido, $id_insumo);
      if ($maxMes !== null && ($yaCapturado + $cantidad) > $maxMes) {
        $disponible = max(0, $maxMes - $yaCapturado);
        $msg = "No puedes exceder {$maxMes} en el mes para este insumo. Disponible: {$disponible}.";
        $msgClass='danger';
        goto END_POST;
      }
    }

    // INSERT ... ON DUPLICATE KEY UPDATE
    $sql = "
      INSERT INTO insumos_pedidos_detalle (id_pedido, id_insumo, cantidad, comentario)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad), comentario=VALUES(comentario)
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('iids', $idPedido, $id_insumo, $cantidad, $coment);
    $st->execute();
    $st->close();

    $msg = 'Línea guardada.';
    $msgClass='success';
  }

  // Eliminar línea
  if (isset($_POST['del_line'])) {
    $id_linea = (int)($_POST['id_linea'] ?? 0);
    if ($id_linea > 0) {
      $st = $conn->prepare("DELETE FROM insumos_pedidos_detalle WHERE id=? AND id_pedido=?");
      $st->bind_param('ii', $id_linea, $idPedido);
      $st->execute();
      $st->close();
      $msg = 'Línea eliminada.';
      $msgClass='success';
    }
  }

  // Enviar pedido
  if (isset($_POST['enviar'])) {
    $st = $conn->prepare("UPDATE insumos_pedidos SET estatus='Enviado', fecha_envio=NOW() WHERE id=?");
    $st->bind_param('i', $idPedido);
    $st->execute();
    $st->close();
    $estatus = 'Enviado';
    $msg = 'Pedido enviado a Admin.';
    $msgClass='success';
  }
}
END_POST:

/* ------------------ Catálogo agrupado por categoría ------------------ */
$catQ = $conn->query("
  SELECT 
    COALESCE(cat.nombre,'Sin categoría') AS cat_nombre,
    COALESCE(cat.orden, 999) AS cat_orden,
    i.id, i.nombre, i.unidad
  FROM insumos_catalogo i
  LEFT JOIN insumos_categorias cat ON cat.id=i.id_categoria
  WHERE i.activo=1 AND (cat.activo=1 OR cat.id IS NULL)
  ORDER BY cat_orden, cat_nombre, i.nombre
");
$catalogoGrouped = [];
while ($r=$catQ->fetch_assoc()) {
  $catalogoGrouped[$r['cat_nombre']][] = $r;
}

/* ------------------ Detalle del pedido ------------------ */
$det = $conn->query("
  SELECT d.id, c.nombre, c.unidad, d.cantidad, d.comentario,
         COALESCE(cat.nombre,'Sin categoría') AS categoria
  FROM insumos_pedidos_detalle d
  INNER JOIN insumos_catalogo c ON c.id=d.id_insumo
  LEFT JOIN insumos_categorias cat ON cat.id=c.id_categoria
  WHERE d.id_pedido=$idPedido
  ORDER BY categoria, c.nombre
");
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Pedido de insumos — <?= htmlspecialchars($sucNombre) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <h3>🧴 Pedido de insumos — <?= htmlspecialchars($sucNombre) ?></h3>

  <p class="mb-1">
    <strong>Periodo:</strong> <?= sprintf('%02d',$mes) ?>/<?= $anio ?> |
    <strong>Estatus:</strong> <?= htmlspecialchars($estatus) ?>
  </p>
  <p class="text-muted mb-1">
    <?= htmlspecialchars($ventanaLeyenda) ?> 
    <?= $ventanaActiva
          ? '<span class="badge bg-success ms-2">Ventana activa</span>'
          : '<span class="badge bg-danger ms-2">Ventana cerrada</span>' ?>
    <span class="ms-2 text-secondary">Periodo permitido por sistema: <strong><?= $periodoPermitidoTxt ?></strong>.</span>
  </p>

  <?php if ($msg): ?>
    <div class="alert alert-<?= $msgClass ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <form class="row g-2 mb-3 align-items-center" method="get">
    <div class="col-auto">
      <label class="form-label mb-0 small text-muted">Mes</label>
      <select name="mes" class="form-select">
        <?php for($m=1;$m<=12;$m++):
              $textoMes = (string)$m;
              // Marcar visualmente el "mes permitido" cuando el año también coincida
              if ($m === $mesPermitido && $anio === $anioPermitido) {
                $textoMes .= " (siguiente)";
              }
        ?>
          <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= $textoMes ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small text-muted">Año</label>
      <select name="anio" class="form-select">
        <?php for($a=date('Y')-1;$a<=date('Y')+1;$a++): 
              $textoAnio = (string)$a;
              if ($a === $anioPermitido && $mes === $mesPermitido) {
                $textoAnio .= " (siguiente)";
              }
        ?>
          <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $textoAnio ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-primary">Cambiar periodo</button>
    </div>
    <div class="col-auto">
      <span class="badge rounded-pill bg-info text-dark">
        Periodo permitido: <?= $periodoPermitidoTxt ?>
      </span>
    </div>
  </form>

  <?php if ($estatus==='Borrador' && $ventanaActiva): ?>
  <div class="card mb-3">
    <div class="card-header">Agregar insumo</div>
    <div class="card-body">
      <form class="row g-2" method="post">
        <div class="col-md-5">
          <select name="id_insumo" class="form-select" required>
            <option value="">-- selecciona insumo --</option>
            <?php foreach($catalogoGrouped as $gname => $items): ?>
              <optgroup label="<?= htmlspecialchars($gname) ?>">
                <?php foreach($items as $it): ?>
                  <option value="<?= (int)$it['id'] ?>">
                    <?= htmlspecialchars($it['nombre']) ?> (<?= htmlspecialchars($it['unidad']) ?>)
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <input type="number" step="0.01" min="0" name="cantidad" class="form-control" placeholder="Cantidad" required>
        </div>
        <div class="col-md-3">
          <input type="text" name="comentario" class="form-control" placeholder="Comentario (opcional)">
        </div>
        <div class="col-md-2">
          <button class="btn btn-success w-100" name="add_line">Agregar</button>
        </div>
      </form>
      <small class="text-muted d-block mt-2">
        * Se aplican límites por línea y por mes definidos en <code>insumos_limites</code>.
      </small>
    </div>
  </div>
  <?php elseif(!$ventanaActiva): ?>
    <div class="alert alert-info">Fuera de ventana de captura para este periodo. Puedes revisar, pero no agregar/editar.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">Detalle del pedido</div>
    <div class="card-body">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Categoría</th>
            <th>Insumo</th>
            <th class="text-end">Cantidad</th>
            <th>Unidad</th>
            <th>Comentario</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($det->num_rows==0): ?>
            <tr><td colspan="6" class="text-muted">Sin líneas.</td></tr>
          <?php else: while($r=$det->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($r['categoria']) ?></td>
              <td><?= htmlspecialchars($r['nombre']) ?></td>
              <td class="text-end"><?= number_format($r['cantidad'],2) ?></td>
              <td><?= htmlspecialchars($r['unidad']) ?></td>
              <td><?= htmlspecialchars($r['comentario']) ?></td>
              <td class="text-end">
                <?php if ($estatus==='Borrador' && $ventanaActiva): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="id_linea" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" name="del_line" onclick="return confirm('¿Eliminar línea?')">Eliminar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; endif; ?>
        </tbody>
      </table>

      <?php if ($estatus==='Borrador' && $ventanaActiva): ?>
        <form method="post">
          <button class="btn btn-primary" name="enviar" onclick="return confirm('¿Enviar pedido a Admin? Ya no podrás editar.');">Enviar a Admin</button>
        </form>
      <?php elseif ($estatus==='Enviado'): ?>
        <div class="alert alert-info mt-3">Tu pedido fue enviado. Espera revisión del Admin.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
