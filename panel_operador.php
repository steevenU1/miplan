<?php
/* panel_operador.php
   - Tablero con accesos rápidos a herramientas de edición/operación
   - Muestra/oculta o deshabilita botones según rol
*/

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once 'db.php';
include 'navbar.php';

$rol        = $_SESSION['rol'] ?? '';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// === Definición de herramientas del panel ===
// Puedes agregar más entradas a este arreglo y listo.
$TOOLS = [
  [
    'key'        => 'editar_cobros',
    'title'      => 'Editar Cobros',
    'desc'       => 'Corrige cobros siempre que no estén ligados a un corte.',
    'href'       => 'cobros_admin_editar.php',
    'icon'       => 'bi-receipt-cutoff',
    'roles'      => ['Admin'], // quiénes pueden entrar
  ],
  [
    'key'        => 'editar_cortes',
    'title'      => 'Editar Cortes de Caja',
    'desc'       => 'Ajusta totales, fechas y elimina cortes liberando sus cobros.',
    'href'       => 'cortes_admin_editar.php',
    'icon'       => 'bi-scissors',
    'roles'      => ['Admin'], // quiénes pueden entrar
  ],
  [
    'key'        => 'horarios_carga_rapida',
    'title'      => 'Horarios Sucursal',
    'desc'       => 'Corrige horarios de sucursales.',
    'href'       => 'horarios_carga_rapida.php',
    'icon'       => 'bi-receipt-cutoff',
    'roles'      => ['Admin'], // quiénes pueden entrar
  ],
  [
    'key'        => 'nuevo_producto',
    'title'      => 'Nuevo Producto',
    'desc'       => 'Ingresa producto a inventario sin ingresar por compras.',
    'href'       => 'nuevo_producto.php',
    'icon'       => 'bi-scissors',
    'roles'      => ['Admin'], // quiénes pueden entrar
  ],
  [
    'key'        => 'asistencias_editar',
    'title'      => 'Editar Asistencias',
    'desc'       => 'Editar asistencias.',
    'href'       => 'asistencias_admin_editar.php',
    'icon'       => 'bi-scissors',
    'roles'      => ['Admin'], // quiénes pueden entrar
  ],

  // ==== Ejemplos para futuro (déjalos como referencia) ====
  // [
  //   'key'   => 'dep_sucursal',
  //   'title' => 'Depósitos de Sucursal',
  //   'desc'  => 'Registra depósitos y adjunta comprobantes.',
  //   'href'  => 'depositos_sucursal.php',
  //   'icon'  => 'bi-bank',
  //   'roles' => ['Admin','Gerente','Ejecutivo'], // ajusta según tu seguridad
  // ],
  // [
  //   'key'   => 'reporte_ventas',
  //   'title' => 'Reporte de Ventas',
  //   'desc'  => 'Descarga reportes diarios/semanales.',
  //   'href'  => 'reporte_ventas.php',
  //   'icon'  => 'bi-graph-up',
  //   'roles' => ['Admin','Gerente'],
  // ],
];

// helper de permiso
function can_use(array $toolRoles, string $userRole): bool {
  return in_array($userRole, $toolRoles, true);
}

?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>Panel de Operador</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{
      --ink:#0f172a; --muted:#6b7280; --brand1:#0ea5e9; --brand2:#22c55e;
    }
    body{background:#f6f7fb;color:var(--ink)}
    .hero{background:linear-gradient(135deg,var(--brand1),var(--brand2));color:#fff;border-radius:18px;padding:18px 20px;margin:16px 0;box-shadow:0 10px 30px rgba(2,6,23,.18)}
    .tool-card{border:1px solid rgba(0,0,0,.06);border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(2,6,23,.06);transition:transform .15s ease, box-shadow .15s ease}
    .tool-card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(2,6,23,.10)}
    .tool-icon{width:44px;height:44px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef2ff;color:#3f51b5}
    .tool-title{margin:0;font-weight:800}
    .tool-desc{color:var(--muted);margin:0}
    .btn-tool{border-radius:10px}
    .disabled-card{opacity:.65}
    .badge-role{background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35)}
  </style>
</head>
<body>
<div class="container py-3">

  <div class="hero d-flex flex-wrap justify-content-between align-items-center">
    <div class="mb-2 mb-md-0">
      <h1 class="h3 m-0">⚙️ Panel de Operador</h1>
      <div class="opacity-75">
        Usuario <strong><?= htmlspecialchars($_SESSION['nombre']) ?></strong>
        · Rol <span class="badge badge-role"><?= htmlspecialchars($rol) ?></span>
        · Sucursal <strong>#<?= (int)$idSucursal ?></strong>
      </div>
    </div>
    <div class="text-end">
      <a class="btn btn-light btn-sm" href="index.php"><i class="bi bi-house-door me-1"></i>Inicio</a>
    </div>
  </div>

  <div class="row g-3">
    <?php foreach ($TOOLS as $t):
      $enabled = can_use($t['roles'], $rol);
      $cardClass = $enabled ? '' : 'disabled-card';
      $btnAttrs = $enabled ? 'href="'.$t['href'].'"' : 'tabindex="-1" aria-disabled="true"';
      $btnClass = $enabled ? 'btn-primary' : 'btn-outline-secondary disabled';
      $tooltip  = $enabled ? '' : 'data-bs-toggle="tooltip" data-bs-title="Sin permiso para tu rol"';
    ?>
    <div class="col-12 col-md-6 col-lg-4">
      <div class="tool-card p-3 h-100 <?= $cardClass ?>">
        <div class="d-flex align-items-start gap-3">
          <div class="tool-icon"><i class="bi <?= htmlspecialchars($t['icon']) ?> fs-5"></i></div>
          <div class="flex-grow-1">
            <h3 class="tool-title"><?= htmlspecialchars($t['title']) ?></h3>
            <p class="tool-desc"><?= htmlspecialchars($t['desc']) ?></p>
            <div class="d-flex gap-2 mt-2">
              <a class="btn btn-sm <?= $btnClass ?> btn-tool" <?= $btnAttrs ?> <?= $tooltip ?>>
                <i class="bi bi-box-arrow-in-right me-1"></i>Abrir
              </a>
              <span class="align-self-center small text-muted">Roles: <?= implode(', ', $t['roles']) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-4">
    <!-- <div class="alert alert-info">
      <i class="bi bi-lightbulb me-1"></i>
      Para agregar una herramienta nueva, solo añade otra entrada al arreglo <code>$TOOLS</code> con
      <code>title</code>, <code>desc</code>, <code>href</code>, <code>icon</code> y <code>roles</code>.
    </div> -->
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  // Activa tooltips para botones deshabilitados
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
</body>
</html>
