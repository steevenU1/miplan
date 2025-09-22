<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

// Carga con fallback
if (file_exists(__DIR__ . '/includes/db.php')) require_once __DIR__ . '/includes/db.php';
else require_once __DIR__ . '/db.php';

if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';

$usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : $mi_id;
if ($usuario_id <= 0) $usuario_id = $mi_id;

// Permisos: Admin/Gerente editan a cualquiera; usuario edita su propio expediente
$puede_editar = in_array($mi_rol, ['Admin','Gerente'], true) || ($usuario_id === $mi_id);
// Solo Admin/Gerente pueden dar de baja
$puede_baja   = in_array($mi_rol, ['Admin','Gerente'], true);

// Cargar expediente (si existe)
$stmt = $conn->prepare("SELECT * FROM usuarios_expediente WHERE usuario_id=? LIMIT 1");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
$exp = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Helper para mostrar antigüedad legible
function fmt_antiguedad($meses) {
  if ($meses === null) return '';
  $y = intdiv((int)$meses, 12);
  $m = ((int)$meses) % 12;
  $parts = [];
  if ($y > 0) $parts[] = $y . ' año' . ($y>1?'s':'');
  if ($m > 0) $parts[] = $m . ' mes' . ($m>1?'es':'');
  return $parts ? implode(' y ', $parts) : '0 meses';
}

// Calcular edad por si la columna está NULL pero hay fecha
$edad_calc = '';
if (!empty($exp['fecha_nacimiento'])) {
  $n = new DateTime($exp['fecha_nacimiento']);
  $h = new DateTime();
  $edad_calc = $h->diff($n)->y;
}
$edad = $exp['edad_years'] ?? $edad_calc ?? '';

$ant_texto = isset($exp['antiguedad_meses']) ? fmt_antiguedad($exp['antiguedad_meses']) : '';
$ok = !empty($_GET['ok']);
$err = !empty($_GET['err']) ? $_GET['err'] : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Expediente del usuario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f7fafc}
    .container{max-width:980px;margin:16px auto;padding:0 12px}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    label{display:block;font-weight:600;margin-bottom:4px}
    input,select,textarea{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fff}
    .row{margin-bottom:12px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:14px}
    .actions{display:flex;gap:10px;margin-top:12px}
    .btn{padding:10px 14px;border:1px solid #ccc;border-radius:8px;background:#f9fafb;text-decoration:none;display:inline-block;color:#111}
    .btn-primary{background:#2b6cb0;border-color:#2b6cb0;color:#fff}
    .btn-secondary{background:#4a5568;border-color:#4a5568;color:#fff}
    .muted{opacity:.75;font-size:12px}
    .alert-ok{background:#e6fffa;border:1px solid #b2f5ea;color:#234e52;padding:10px;border-radius:8px;margin-bottom:12px}
    .alert-err{background:#fff5f5;border:1px solid #fed7d7;color:#742a2a;padding:10px;border-radius:8px;margin-bottom:12px}
    @media (max-width:800px){.grid{grid-template-columns:1fr}}
    .readonly{background:#f3f4f6}
    h2{margin:6px 0 12px}
    h3{margin:0 0 10px}
  </style>
</head>
<body>
<div class="container">
  <h2>Expediente del usuario #<?= (int)$usuario_id ?></h2>

  <?php if ($ok): ?><div class="alert-ok">Cambios guardados.</div><?php endif; ?>
  <?php if ($err): ?><div class="alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form action="expediente_guardar.php" method="post" id="frmExp">
    <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">

    <div class="card">
      <h3>Datos de contacto</h3>
      <div class="grid">
        <div class="row">
          <label>Tel. de contacto</label>
          <input type="tel" name="tel_contacto" value="<?= htmlspecialchars($exp['tel_contacto'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Contacto de emergencia</label>
          <input type="text" name="contacto_emergencia" value="<?= htmlspecialchars($exp['contacto_emergencia'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Tel. de emergencia</label>
          <input type="tel" name="tel_emergencia" value="<?= htmlspecialchars($exp['tel_emergencia'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Género</label>
          <select name="genero" <?= $puede_editar?'':'disabled class="readonly"' ?>>
            <?php
              $g = $exp['genero'] ?? '';
              foreach (['M'=>'Masculino','F'=>'Femenino','Otro'=>'Otro'] as $val=>$txt) {
                $sel = ($g===$val)?'selected':''; echo "<option value=\"$val\" $sel>$txt</option>";
              }
            ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Fechas y antigüedad</h3>
      <div class="grid">
        <div class="row">
          <label>Fecha de nacimiento</label>
          <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($exp['fecha_nacimiento'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Edad (años)</label>
          <input type="text" value="<?= htmlspecialchars($edad) ?>" readonly class="readonly">
        </div>
        <div class="row">
          <label>Fecha de ingreso</label>
          <input type="date" name="fecha_ingreso" value="<?= htmlspecialchars($exp['fecha_ingreso'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Antigüedad</label>
          <input type="text" value="<?= htmlspecialchars($ant_texto) ?>" readonly class="readonly">
        </div>
        <div class="row">
          <label>Fecha de baja <?= $puede_baja?'':'(solo Admin/Gerente)' ?></label>
          <input type="date" name="fecha_baja" id="fecha_baja" value="<?= htmlspecialchars($exp['fecha_baja'] ?? '') ?>" <?= $puede_baja?'':'disabled class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Motivo de baja</label>
          <input type="text" name="motivo_baja" id="motivo_baja" value="<?= htmlspecialchars($exp['motivo_baja'] ?? '') ?>" <?= $puede_baja?'':'disabled class="readonly"' ?>>
        </div>
      </div>
      <p class="muted">Si se registra <b>Fecha de baja</b>, el usuario se marcará como inactivo.</p>
    </div>

    <div class="card">
      <h3>Identificación y nómina</h3>
      <div class="grid">
        <div class="row">
          <label>CURP</label>
          <input type="text" name="curp" maxlength="18" style="text-transform:uppercase"
                 value="<?= htmlspecialchars($exp['curp'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>NSS (IMSS)</label>
          <input type="text" name="nss" maxlength="11" pattern="\d{11}" placeholder="11 dígitos"
                 value="<?= htmlspecialchars($exp['nss'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>RFC</label>
          <input type="text" name="rfc" maxlength="13" style="text-transform:uppercase"
                 value="<?= htmlspecialchars($exp['rfc'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>

        <div class="row">
          <label>CLABE</label>
          <input type="text" name="clabe" maxlength="18" pattern="\d{18}" placeholder="18 dígitos"
                 value="<?= htmlspecialchars($exp['clabe'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
        <div class="row">
          <label>Banco (institución)</label>
          <input type="text" name="banco" maxlength="80" placeholder="Ej. BBVA, Santander, Banorte…"
                 value="<?= htmlspecialchars($exp['banco'] ?? '') ?>" <?= $puede_editar?'':'readonly class="readonly"' ?>>
        </div>
      </div>
    </div>

    <div class="actions">
      <?php if ($puede_editar): ?>
        <button class="btn btn-primary" type="submit">Guardar</button>
      <?php endif; ?>
      <a class="btn" href="documentos_historial.php?usuario_id=<?= (int)$usuario_id ?>">Ver documentos</a>
    </div>
    <p class="muted">Campos calculados (Edad/Antigüedad) se actualizan al guardar.</p>
  </form>
</div>

<script>
  // Confirmación de baja
  const fechaBaja = document.getElementById('fecha_baja');
  const motivoBaja = document.getElementById('motivo_baja');
  const puedeBaja = <?= $puede_baja ? 'true' : 'false' ?>;

  if (fechaBaja && puedeBaja) {
    fechaBaja.addEventListener('change', function(){
      if (this.value) {
        const ok = confirm('¿Confirmas dar de baja al usuario? Se marcará como inactivo.');
        if (!ok) { this.value = ''; motivoBaja.value = ''; }
      } else {
        motivoBaja.value = '';
      }
    });
  }
</script>
</body>
</html>
