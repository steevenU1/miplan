<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
    header("Location: index.php"); exit();
}
include 'db.php';
include 'navbar.php';

/* ===== semanas mar→lun ===== */
function obtenerSemanaPorIndice($offset = 0){
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now',$tz);
  $dia = (int)$hoy->format('N'); // 1=Mon..7=Sun
  $dif = $dia - 2; if ($dif < 0) $dif += 7; // martes
  $ini = new DateTime('now',$tz); $ini->modify('-'.$dif.' days')->setTime(0,0,0);
  if ($offset > 0) $ini->modify('-'.(7*$offset).' days');
  $fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
  return [$ini,$fin];
}
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj,$finObj) = obtenerSemanaPorIndice($semana);
$iniISO = $iniObj->format('Y-m-d');
$finISO = $finObj->format('Y-m-d');

/* ===== helpers de inputs ===== */
function nvlMoney(?string $s): ?float {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;
  // quita $ , y espacios
  $s = str_replace([',','$',' '],'',$s);
  if ($s === '' || !is_numeric($s)) return null;
  return (float)$s;
}
function cleanEstado(?string $e): string {
  $e = trim((string)$e);
  $valid = ['borrador','por_autorizar','autorizado'];
  return in_array($e,$valid,true) ? $e : 'por_autorizar';
}
function cleanText(?string $t): ?string {
  if ($t===null) return null;
  $t = trim($t);
  return $t === '' ? null : mb_substr($t,0,255,'UTF-8');
}

/* ===== guardado (POST por fila) ===== */
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
  $id_usuario       = (int)($_POST['id_usuario'] ?? 0);

  $sueldo_override  = nvlMoney($_POST['sueldo_override']  ?? null);
  $equipos_override = nvlMoney($_POST['equipos_override'] ?? null);
  $sims_override    = nvlMoney($_POST['sims_override']    ?? null);
  $pospago_override = nvlMoney($_POST['pospago_override'] ?? null);

  // Gerente (pueden venir vacíos para ejecutivos)
  $ger_dir_override  = nvlMoney($_POST['ger_dir_override']  ?? null);
  $ger_esc_override  = nvlMoney($_POST['ger_esc_override']  ?? null);
  $ger_prep_override = nvlMoney($_POST['ger_prep_override'] ?? null);
  $ger_pos_override  = nvlMoney($_POST['ger_pos_override']  ?? null);

  $descuentos_override = nvlMoney($_POST['descuentos_override'] ?? null);
  $ajuste_neto_extra   = nvlMoney($_POST['ajuste_neto_extra']   ?? '0');
  if ($ajuste_neto_extra === null) $ajuste_neto_extra = 0.0;

  $estado = cleanEstado($_POST['estado'] ?? 'por_autorizar');
  $nota   = cleanText($_POST['nota'] ?? null);

  $sql = "INSERT INTO nomina_overrides_semana
            (id_usuario, semana_inicio, semana_fin,
             sueldo_override, equipos_override, sims_override, pospago_override,
             ger_dir_override, ger_esc_override, ger_prep_override, ger_pos_override,
             descuentos_override, ajuste_neto_extra, estado, nota, creado_en, actualizado_en)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
          ON DUPLICATE KEY UPDATE
            sueldo_override=VALUES(sueldo_override),
            equipos_override=VALUES(equipos_override),
            sims_override=VALUES(sims_override),
            pospago_override=VALUES(pospago_override),
            ger_dir_override=VALUES(ger_dir_override),
            ger_esc_override=VALUES(ger_esc_override),
            ger_prep_override=VALUES(ger_prep_override),
            ger_pos_override=VALUES(ger_pos_override),
            descuentos_override=VALUES(descuentos_override),
            ajuste_neto_extra=VALUES(ajuste_neto_extra),
            estado=VALUES(estado),
            nota=VALUES(nota),
            actualizado_en=NOW()";

  $st = $conn->prepare($sql);
  // tipos: i ss dddddddddd ss  => 1 int, 2 strings, 10 doubles, 2 strings
  $st->bind_param(
    "issddddddddddss",
    $id_usuario, $iniISO, $finISO,
    $sueldo_override, $equipos_override, $sims_override, $pospago_override,
    $ger_dir_override, $ger_esc_override, $ger_prep_override, $ger_pos_override,
    $descuentos_override, $ajuste_neto_extra, $estado, $nota
  );

  if ($st->execute()) {
    $msg = "✅ Override guardado.";
  } else {
    $msg = "❌ Error al guardar: ".$conn->error;
  }
}

/* ===== consulta de personal y overrides existentes ===== */
$sql = "SELECT u.id, u.nombre, u.rol, s.nombre AS sucursal
        FROM usuarios u
        INNER JOIN sucursales s ON s.id=u.id_sucursal
        ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre";
$ru = $conn->query($sql);

$ovMap = [];
$st2 = $conn->prepare("SELECT * FROM nomina_overrides_semana WHERE semana_inicio=? AND semana_fin=?");
$st2->bind_param("ss",$iniISO,$finISO);
$st2->execute();
$rs2 = $st2->get_result();
while ($r = $rs2->fetch_assoc()) { $ovMap[(int)$r['id_usuario']] = $r; }

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Overrides de Nómina (RH)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body{background:#f7f7fb}
  .card-soft{background:#fff;border:1px solid #eef2f7;border-radius:1rem;box-shadow:0 6px 18px rgba(16,24,40,.06)}
  .smallmuted{font-size:.85rem;color:#6b7280}
  .w-110{width:110px}
  .w-90{width:90px}
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Overrides de Nómina (RH)</h4>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="smallmuted mb-0">Semana</label>
      <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php for ($i=0;$i<8;$i++):
          list($a,$b)=obtenerSemanaPorIndice($i);
          $t="Del {$a->format('d/m/Y')} al {$b->format('d/m/Y')}";
        ?>
          <option value="<?= $i ?>" <?= $i==$semana?'selected':'' ?>><?= $t ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars($msg,ENT_QUOTES,'UTF-8') ?></div>
  <?php endif; ?>

  <div class="card-soft p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Empleado</th>
            <th>Rol</th>
            <th>Sucursal</th>
            <th class="text-end">Sueldo</th>
            <th class="text-end">Eq.</th>
            <th class="text-end">SIMs</th>
            <th class="text-end">Pos.</th>
            <th class="text-end">DirG.</th>
            <th class="text-end">Esc.Eq.</th>
            <th class="text-end">PrepG.</th>
            <th class="text-end">PosG.</th>
            <th class="text-end">Desc.</th>
            <th class="text-end">Ajuste Neto</th>
            <th>Estado</th>
            <th>Nota</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php while($u=$ru->fetch_assoc()):
            $id = (int)$u['id'];
            $o  = $ovMap[$id] ?? [];
        ?>
          <tr>
            <form method="post">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id_usuario" value="<?= $id ?>">

              <td class="small">
                <div class="fw-semibold"><?= htmlspecialchars($u['nombre']) ?></div>
              </td>
              <td><?= htmlspecialchars($u['rol']) ?></td>
              <td class="small"><?= htmlspecialchars($u['sucursal']) ?></td>

              <td><input name="sueldo_override"  class="form-control form-control-sm text-end w-110" value="<?= isset($o['sueldo_override'])?number_format($o['sueldo_override'],2,'.',''):'' ?>" placeholder="0.00"></td>
              <td><input name="equipos_override" class="form-control form-control-sm text-end w-90"  value="<?= isset($o['equipos_override'])?number_format($o['equipos_override'],2,'.',''):'' ?>"></td>
              <td><input name="sims_override"    class="form-control form-control-sm text-end w-90"  value="<?= isset($o['sims_override'])?number_format($o['sims_override'],2,'.',''):'' ?>"></td>
              <td><input name="pospago_override" class="form-control form-control-sm text-end w-90"  value="<?= isset($o['pospago_override'])?number_format($o['pospago_override'],2,'.',''):'' ?>"></td>

              <!-- Gerente (si el usuario no es Gerente, puedes dejarlos vacíos sin problema) -->
              <td><input name="ger_dir_override"  class="form-control form-control-sm text-end w-90" value="<?= isset($o['ger_dir_override'])?number_format($o['ger_dir_override'],2,'.',''):'' ?>"></td>
              <td><input name="ger_esc_override"  class="form-control form-control-sm text-end w-90" value="<?= isset($o['ger_esc_override'])?number_format($o['ger_esc_override'],2,'.',''):'' ?>"></td>
              <td><input name="ger_prep_override" class="form-control form-control-sm text-end w-90" value="<?= isset($o['ger_prep_override'])?number_format($o['ger_prep_override'],2,'.',''):'' ?>"></td>
              <td><input name="ger_pos_override"  class="form-control form-control-sm text-end w-90" value="<?= isset($o['ger_pos_override'])?number_format($o['ger_pos_override'],2,'.',''):'' ?>"></td>

              <td><input name="descuentos_override" class="form-control form-control-sm text-end w-90" value="<?= isset($o['descuentos_override'])?number_format($o['descuentos_override'],2,'.',''):'' ?>"></td>
              <td><input name="ajuste_neto_extra"   class="form-control form-control-sm text-end w-90" value="<?= isset($o['ajuste_neto_extra'])?number_format($o['ajuste_neto_extra'],2,'.',''):'0.00' ?>"></td>

              <td>
                <select name="estado" class="form-select form-select-sm">
                  <?php $est = $o['estado'] ?? 'por_autorizar'; ?>
                  <option value="borrador"      <?= $est==='borrador'?'selected':'' ?>>borrador</option>
                  <option value="por_autorizar" <?= $est==='por_autorizar'?'selected':'' ?>>por_autorizar</option>
                  <option value="autorizado"    <?= $est==='autorizado'?'selected':'' ?>>autorizado</option>
                </select>
              </td>
              <td><input name="nota" class="form-control form-control-sm" value="<?= htmlspecialchars($o['nota'] ?? '') ?>" placeholder="Comentario (opcional)"></td>

              <td><button class="btn btn-primary btn-sm">Guardar</button></td>
            </form>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="smallmuted mt-3">
    • Deja vacío un campo para no forzarlo (queda en NULL).<br>
    • Para gerentes, usa DirG./Esc.Eq./PrepG./PosG. — si solo llenas el “ajuste neto”, afectará el total final sin tocar desgloses.<br>
    • Estado: “por_autorizar” es el default. 
  </div>

</div>
</body>
</html>
