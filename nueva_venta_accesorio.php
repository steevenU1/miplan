<?php
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__.'/db.php';

$id_usuario          = (int)$_SESSION['id_usuario'];
$id_sucursal_usuario = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usuario      = trim($_SESSION['nombre'] ?? 'Usuario');

$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$mapSuc = []; foreach($sucursales as $s){ $mapSuc[(int)$s['id']] = $s['nombre']; }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nueva Venta de Accesorios</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-light">
<?php include __DIR__.'/navbar.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0"><i class="bi bi-bag"></i> Nueva venta de accesorios</h3>
    <a href="panel.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <form method="POST" action="procesar_venta_accesorio.php" id="formAcc">
    <input type="hidden" name="id_usuario" value="<?=$id_usuario?>">

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Sucursal</label>
            <select class="form-select" name="id_sucursal" id="id_sucursal" required>
              <?php foreach($sucursales as $s): ?>
                <option value="<?=$s['id']?>" <?=$s['id']==$id_sucursal_usuario?'selected':''?>>
                  <?=htmlspecialchars($s['nombre'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Cliente (opcional)</label>
            <input name="nombre_cliente" class="form-control" placeholder="Nombre">
          </div>
          <div class="col-md-4">
            <label class="form-label">Teléfono (opcional)</label>
            <input name="telefono_cliente" class="form-control" placeholder="10 dígitos">
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Accesorios</h6>
          <button type="button" class="btn btn-sm btn-primary" id="btnAdd"><i class="bi bi-plus-circle"></i> Agregar</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="tbl">
            <thead class="table-light">
              <tr>
                <th style="width:40%">Accesorio</th>
                <th style="width:10%">Stock</th>
                <th style="width:15%">Cantidad</th>
                <th style="width:15%">Precio U.</th>
                <th style="width:15%">Subtotal</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
              <tr>
                <th colspan="4" class="text-end">Total</th>
                <th><input class="form-control form-control-sm" id="total" name="total" readonly></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Forma de pago</label>
            <select name="forma_pago" id="forma_pago" class="form-select">
              <option>Efectivo</option>
              <option>Tarjeta</option>
              <option>Mixto</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Pago efectivo</label>
            <input type="number" step="0.01" min="0" class="form-control" name="pago_efectivo" id="pago_efectivo" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">Pago tarjeta</label>
            <input type="number" step="0.01" min="0" class="form-control" name="pago_tarjeta" id="pago_tarjeta" value="0">
          </div>
          <div class="col-12">
            <label class="form-label">Comentarios</label>
            <input class="form-control" name="comentarios" placeholder="Opcional">
          </div>
        </div>
      </div>
      <div class="card-footer bg-white border-0">
        <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> Registrar venta de accesorios</button>
      </div>
    </div>
  </form>
</div>

<script>
  function nuevaFila() {
    const row = `
      <tr>
        <td>
          <select class="form-select sel-acc" name="id_producto[]"></select>
        </td>
        <td><input class="form-control form-control-sm stock" readonly></td>
        <td><input type="number" min="1" value="1" class="form-control form-control-sm qty" name="cantidad[]"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm price" name="precio_unitario[]"></td>
        <td><input class="form-control form-control-sm sub" readonly></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btnDel"><i class="bi bi-x"></i></button></td>
      </tr>`;
    $('#tbl tbody').append(row);
    cargarAccesorios($('.sel-acc').last());
  }

  function cargarAccesorios($select){
    const suc = $('#id_sucursal').val();
    $.post('ajax_accesorios_por_sucursal.php', {id_sucursal: suc}, function(html){
      $select.html(html);
      actualizarFila($select.closest('tr'));
    });
  }

  function actualizarFila($tr){
    const $sel   = $tr.find('.sel-acc option:selected');
    const precio = parseFloat($sel.data('precio') || 0) || 0;
    const stock  = parseInt($sel.data('stock') || 0) || 0;
    $tr.find('.price').val(precio.toFixed(2));
    $tr.find('.stock').val(stock);
    recalcular();
  }

  function recalcular(){
    let total=0;
    $('#tbl tbody tr').each(function(){
      const stock = parseInt($(this).find('.stock').val() || 0);
      let qty  = parseInt($(this).find('.qty').val() || 1);
      if (qty<1) qty=1;
      if (qty>stock) qty=stock; // no permitir rebasar stock en UI
      $(this).find('.qty').val(qty);
      const price = parseFloat($(this).find('.price').val() || 0);
      const sub = qty*price;
      $(this).find('.sub').val(sub.toFixed(2));
      total += sub;
    });
    $('#total').val(total.toFixed(2));
  }

  $(function(){
    $('#id_sucursal').on('change', function(){
      // recargar selects
      $('#tbl tbody').empty();
      nuevaFila();
    });
    $('#btnAdd').on('click', nuevaFila);
    $(document).on('change', '.sel-acc', function(){ actualizarFila($(this).closest('tr')); });
    $(document).on('input', '.qty, .price', function(){ recalcular(); });
    $(document).on('click', '.btnDel', function(){ $(this).closest('tr').remove(); recalcular(); });
    nuevaFila();
  });
</script>
</body>
</html>
