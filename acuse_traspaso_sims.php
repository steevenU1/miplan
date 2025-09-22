<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(302); header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

$idUsuario        = (int)$_SESSION['id_usuario'];
$idSucursalOrigen = (int)$_SESSION['id_sucursal'];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$idTraspaso = (int)($_GET['id'] ?? 0);
if ($idTraspaso <= 0) { http_response_code(400); echo "ID inválido"; exit(); }

/* ---------------------------------------
   Soporte a distintos nombres de columna de caja
--------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $res && $res->num_rows > 0;
}
$CANDIDATES = ['caja_id','id_caja','caja','id_caja_sello'];
$COL_CAJA = null;
foreach ($CANDIDATES as $c) { if (hasColumn($conn, 'inventario_sims', $c)) { $COL_CAJA = $c; break; } }
if ($COL_CAJA === null) { $COL_CAJA = 'caja_id'; } // fallback

/* ---------------------------------------
   Header del traspaso (acceso limitado al ORIGEN)
--------------------------------------- */
$sqlH = "
  SELECT ts.id, ts.fecha_traspaso, ts.estatus,
         ts.id_sucursal_origen, ts.id_sucursal_destino,
         so.nombre AS suc_origen, sd.nombre AS suc_destino,
         u.nombre  AS usuario_creo
  FROM traspasos_sims ts
  JOIN sucursales so ON so.id = ts.id_sucursal_origen
  JOIN sucursales sd ON sd.id = ts.id_sucursal_destino
  JOIN usuarios   u  ON u.id  = ts.usuario_creo
  WHERE ts.id=? AND ts.id_sucursal_origen = ?
  LIMIT 1
";
$st = $conn->prepare($sqlH);
$st->bind_param("ii", $idTraspaso, $idSucursalOrigen);
$st->execute();
$head = $st->get_result()->fetch_assoc();
$st->close();

if (!$head) { http_response_code(403); echo "Sin acceso o traspaso no existe."; exit(); }

/* ---------------------------------------
   Cajas del traspaso con conteo + rango ICCID
--------------------------------------- */
$sqlC = "
  SELECT i.`$COL_CAJA` AS caja,
         COUNT(*)            AS sims,
         MIN(UPPER(i.iccid)) AS iccid_min,
         MAX(UPPER(i.iccid)) AS iccid_max
  FROM detalle_traspaso_sims d
  JOIN inventario_sims i ON i.id = d.id_sim
  WHERE d.id_traspaso=?
  GROUP BY i.`$COL_CAJA`
  ORDER BY i.`$COL_CAJA`
";
$st = $conn->prepare($sqlC);
$st->bind_param("i", $idTraspaso);
$st->execute();
$cajas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$totalSims = array_sum(array_map(fn($r)=> (int)$r['sims'], $cajas));

// URL del logo (puedes cambiarla a un archivo local, ej. /assets/logo.png)
$LOGO_URL = "https://i.ibb.co/Jwgbnjdv/Captura-de-pantalla-2025-05-29-230425.png";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Acuse Traspaso #<?= (int)$idTraspaso ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#fff; }
    .doc { max-width: 980px; margin: 24px auto; padding: 24px; }
    .doc h1{ font-size: 1.35rem; margin: 0; }
    .muted{ color:#6b7280; }
    .sig-box{ min-height: 80px; }
    .brand{ font-weight: 800; letter-spacing:.2px; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .logo-img{ height: 56px; width: auto; object-fit: contain; image-rendering: -webkit-optimize-contrast; }
    @media print{
      .no-print{ display:none !important; }
      body{ background:#fff; }
      .doc{ box-shadow:none; margin:0; max-width:100%; padding:0; }
      /* Asegura colores e imagen en impresión */
      *{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
  <div class="doc">
    <div class="d-flex justify-content-between align-items-start">
      <div class="d-flex align-items-start gap-3">
        <img src="<?= h($LOGO_URL) ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'">
        <div>
          <div class="brand">📄 Acuse de Traspaso de SIMs</div>
          <h1 class="mt-1">Folio TRS-<?= (int)$head['id'] ?></h1>
          <div class="muted">Generado por: <b><?= h($head['usuario_creo']) ?></b></div>
        </div>
      </div>
      <div class="text-end">
        <div class="muted">Fecha</div>
        <div><b><?= h($head['fecha_traspaso']) ?></b></div>
        <div class="muted">Estatus</div>
        <div><span class="badge bg-secondary"><?= h($head['estatus']) ?></span></div>
        <button class="btn btn-primary btn-sm mt-2 no-print" onclick="window.print()">Imprimir</button>
      </div>
    </div>
    <hr>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="muted">Sucursal origen</div>
        <div class="fw-semibold"><?= h($head['suc_origen']) ?> (ID <?= (int)$head['id_sucursal_origen'] ?>)</div>
      </div>
      <div class="col-md-4">
        <div class="muted">Sucursal destino</div>
        <div class="fw-semibold"><?= h($head['suc_destino']) ?> (ID <?= (int)$head['id_sucursal_destino'] ?>)</div>
      </div>
      <div class="col-md-4">
        <div class="muted">Total SIMs</div>
        <div class="fw-semibold"><?= (int)$totalSims ?></div>
      </div>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th style="min-width:160px;">ID Caja</th>
            <th class="text-end" style="min-width:120px;">SIMs</th>
            <th style="min-width:260px;">ICCID inicial</th>
            <th style="min-width:260px;">ICCID final</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cajas as $r): ?>
            <tr>
              <td><span class="badge text-bg-secondary"><?= h($r['caja']) ?></span></td>
              <td class="text-end"><?= (int)$r['sims'] ?></td>
              <td class="mono"><?= h($r['iccid_min'] ?? '') ?></td>
              <td class="mono"><?= h($r['iccid_max'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$cajas): ?>
            <tr><td colspan="4" class="text-center text-muted">Sin detalle de SIMs para este traspaso.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="table-dark">
            <th>Total</th>
            <th class="text-end"><?= (int)$totalSims ?></th>
            <th colspan="2" class="text-muted">
              Rango mostrado por caja = <span class="mono">mínimo–máximo (lexicográfico)</span>. No asume consecutividad.
            </th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="row mt-4">
      <div class="col-md-6">
        <div class="border rounded p-3 sig-box">
          <div class="muted">Entrega (Origen)</div>
          <div style="height:40px"></div>
          <div class="muted">Nombre y firma</div>
        </div>
      </div>
      <div class="col-md-6 mt-3 mt-md-0">
        <div class="border rounded p-3 sig-box">
          <div class="muted">Recibe (Destino)</div>
          <div style="height:40px"></div>
          <div class="muted">Nombre y firma</div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
