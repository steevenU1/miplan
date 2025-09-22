<?php
session_start();
if(!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin'){
    header("Location: 403.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$mensaje = "";

// 🔹 Procesar formulario de actualización
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $modeloCapacidad  = $_POST['modelo'] ?? '';
    $nuevoPrecioLista = isset($_POST['precio_lista']) && $_POST['precio_lista'] !== '' ? floatval($_POST['precio_lista']) : null;
    $nuevoPrecioCombo = isset($_POST['precio_combo']) && $_POST['precio_combo'] !== '' ? floatval($_POST['precio_combo']) : null;

    $promocionTexto   = trim($_POST['promocion'] ?? '');
    $quitarPromo      = isset($_POST['limpiar_promocion']); // si viene marcado, borraremos la promo (NULL)

    if($modeloCapacidad){
        list($marca, $modelo, $capacidad) = explode('|', $modeloCapacidad);

        // 1) Actualizar precio de lista en productos (para items Disponibles / En tránsito)
        if ($nuevoPrecioLista !== null && $nuevoPrecioLista > 0){
            $sql = "
                UPDATE productos p
                INNER JOIN inventario i ON i.id_producto = p.id
                SET p.precio_lista = ?
                WHERE p.marca = ? AND p.modelo = ? AND (p.capacidad = ? OR IFNULL(p.capacidad,'') = ?)
                  AND TRIM(i.estatus) IN ('Disponible','En tránsito')
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("dssss", $nuevoPrecioLista, $marca, $modelo, $capacidad, $capacidad);
            $stmt->execute();
            $afectados = $stmt->affected_rows;
            $stmt->close();
            $mensaje .= "✅ Precio de lista actualizado a $" . number_format($nuevoPrecioLista,2) . " ({$afectados} registros).<br>";
        }

        // 2) Upsert en precios_combo (precio combo y/o promoción)
        if (
            ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) ||
            ($promocionTexto !== '') ||
            $quitarPromo
        ){
            $precioComboParam = ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) ? $nuevoPrecioCombo : null;
            $promocionParam   = $quitarPromo ? null : ($promocionTexto !== '' ? $promocionTexto : null);

            $sql = "
                INSERT INTO precios_combo (marca, modelo, capacidad, precio_combo, promocion)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    precio_combo = COALESCE(VALUES(precio_combo), precio_combo),
                    promocion    = VALUES(promocion)
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssds", $marca, $modelo, $capacidad, $precioComboParam, $promocionParam);
            $stmt->execute();
            $stmt->close();

            if ($nuevoPrecioCombo !== null && $nuevoPrecioCombo > 0) {
                $mensaje .= "✅ Precio combo actualizado a $" . number_format($nuevoPrecioCombo,2) . ".<br>";
            }
            if ($quitarPromo) {
                $mensaje .= "🧹 Promoción eliminada.<br>";
            } elseif ($promocionTexto !== '') {
                $mensaje .= "✅ Promoción guardada: <i>".htmlspecialchars($promocionTexto)."</i>.<br>";
            }
        }

        if ($mensaje === "") {
            $mensaje = "⚠️ No enviaste cambios: captura un precio o promoción.";
        }

    } else {
        $mensaje = "⚠️ Selecciona un modelo válido.";
    }
}

// 🔹 Obtener modelos únicos de productos con inventario disponible o en tránsito
//    Traemos RAM representativa por grupo para mostrarla en la etiqueta de sugerencia.
$modelosRS = $conn->query("
    SELECT 
        p.marca, 
        p.modelo, 
        IFNULL(p.capacidad,'') AS capacidad,
        MAX(IFNULL(p.ram,'')) AS ram
    FROM productos p
    WHERE p.tipo_producto = 'Equipo'
      AND p.id IN (
            SELECT DISTINCT i.id_producto
            FROM inventario i
            WHERE TRIM(i.estatus) IN ('Disponible','En tránsito')
      )
    GROUP BY p.marca, p.modelo, p.capacidad
    ORDER BY LOWER(p.marca), LOWER(p.modelo), LOWER(p.capacidad)
");

// Armamos un arreglo PHP → JSON con {label, value}
$sugerencias = [];
while($m = $modelosRS->fetch_assoc()){
    $valor = $m['marca'].'|'.$m['modelo'].'|'.$m['capacidad']; // llave interna
    $ramTxt = trim($m['ram'] ?? '');
    $capTxt = trim($m['capacidad'] ?? '');
    $label  = trim($m['marca'].' '.$m['modelo']);
    if ($ramTxt !== '') { $label .= ' · RAM: '.$ramTxt; }
    if ($capTxt !== '') { $label .= ' · Capacidad: '.$capTxt; }
    $sugerencias[] = [
        'label' => $label,
        'value' => $valor
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Precios por Modelo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
    <style>
      .autocomplete-list {
        position: absolute;
        z-index: 1050;
        width: 100%;
        max-height: 260px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        box-shadow: 0 6px 18px rgba(0,0,0,.08);
      }
      .autocomplete-item {
        padding: .5rem .75rem;
        cursor: pointer;
      }
      .autocomplete-item:hover, .autocomplete-item.active {
        background: #f1f5f9;
      }
    </style>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>💰 Actualizar Precios por Modelo</h2>
    <p>Empieza a escribir el <b>modelo</b> y elige una sugerencia (muestra <b>RAM</b> y <b>Capacidad</b>). Afecta equipos <b>Disponibles</b> o <b>En tránsito</b>.</p>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <form id="form-precios" method="POST" class="card p-3 shadow-sm bg-white" style="max-width:680px; position: relative;">
        <!-- Campo visible con autocompletado -->
        <div class="mb-3 position-relative">
            <label class="form-label">Modelo (con RAM y Capacidad)</label>
            <input type="text" id="buscador-modelo" class="form-control" placeholder="Ej. Samsung A15, iPhone 12, Redmi 9..." autocomplete="off">
            <div id="lista-sugerencias" class="autocomplete-list d-none"></div>

            <!-- Campo oculto que envía la llave interna: marca|modelo|capacidad -->
            <input type="hidden" name="modelo" id="modelo-hidden" value="">
            <div class="form-text">Escribe y selecciona una opción de la lista. La llave interna se guarda automáticamente.</div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
              <label class="form-label">Nuevo Precio de Lista ($)</label>
              <input type="number" step="0.01" name="precio_lista" class="form-control" placeholder="Ej. 2500.00">
              <div class="form-text">Déjalo en blanco si no deseas cambiarlo.</div>
          </div>

          <div class="col-md-6 mb-3">
              <label class="form-label">Nuevo Precio Combo ($)</label>
              <input type="number" step="0.01" name="precio_combo" class="form-control" placeholder="Ej. 2199.00">
              <div class="form-text">Déjalo en blanco para conservar el combo actual.</div>
          </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Promoción (texto informativo)</label>
            <input type="text" name="promocion" class="form-control" placeholder="Ej. Descuento $500 en enganche / Incentivo portabilidad">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="limpiar_promocion" id="limpiar_promocion">
                <label class="form-check-label" for="limpiar_promocion">Quitar promoción (dejar en blanco/NULL)</label>
            </div>
            <div class="form-text">Puedes guardar promoción sin cambiar el precio combo. Marca “Quitar promoción” para borrar el texto.</div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Actualizar</button>
            <a href="lista_precios.php" class="btn btn-secondary">Ver Lista</a>
        </div>
    </form>
</div>

<script>
(function(){
  // Sugerencias desde PHP
  const opciones = <?php echo json_encode($sugerencias, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

  const $input   = document.getElementById('buscador-modelo');
  const $hidden  = document.getElementById('modelo-hidden');
  const $lista   = document.getElementById('lista-sugerencias');
  const $form    = document.getElementById('form-precios');

  let cursor = -1; // para navegar con teclado
  let actuales = []; // sugerencias filtradas actuales

  function normaliza(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

  function render(lista){
    $lista.innerHTML = '';
    if (!lista.length) {
      $lista.classList.add('d-none');
      return;
    }
    lista.slice(0, 50).forEach((opt, idx) => {
      const div = document.createElement('div');
      div.className = 'autocomplete-item';
      div.textContent = opt.label;
      div.setAttribute('data-value', opt.value);
      div.addEventListener('mousedown', (e) => { // mousedown para que no se pierda el focus antes del click
        e.preventDefault();
        selecciona(opt);
      });
      $lista.appendChild(div);
    });
    $lista.classList.remove('d-none');
    cursor = -1;
  }

  function filtra(q){
    const nq = normaliza(q);
    if (!nq) { actuales = []; render(actuales); $hidden.value=''; return; }
    actuales = opciones.filter(o => normaliza(o.label).includes(nq));
    render(actuales);
  }

  function selecciona(opt){
    $input.value  = opt.label;
    $hidden.value = opt.value;
    $lista.classList.add('d-none');
  }

  // Eventos de entrada
  $input.addEventListener('input', () => {
    $hidden.value = '';   // limpiar hasta que elija una opción
    filtra($input.value);
  });

  $input.addEventListener('focus', () => {
    if ($input.value.trim() !== '') filtra($input.value);
  });

  document.addEventListener('click', (e) => {
    if (!($lista.contains(e.target) || $input.contains(e.target))) {
      $lista.classList.add('d-none');
    }
  });

  // Navegación con teclado
  $input.addEventListener('keydown', (e) => {
    const items = Array.from($lista.querySelectorAll('.autocomplete-item'));
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      cursor = Math.min(cursor + 1, items.length - 1);
      items.forEach(i => i.classList.remove('active'));
      if (items[cursor]) items[cursor].classList.add('active');
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      cursor = Math.max(cursor - 1, 0);
      items.forEach(i => i.classList.remove('active'));
      if (items[cursor]) items[cursor].classList.add('active');
    } else if (e.key === 'Enter') {
      if (cursor >= 0 && items[cursor]) {
        e.preventDefault();
        const idx = cursor;
        const opt = actuales[idx];
        if (opt) selecciona(opt);
      } else {
        // Si presiona Enter sin seleccionar, intentamos match exacto por etiqueta
        const typed = normaliza($input.value.trim());
        const exact = opciones.find(o => normaliza(o.label) === typed);
        if (exact) selecciona(exact);
      }
    } else if (e.key === 'Escape') {
      $lista.classList.add('d-none');
    }
  });

  // Validación al enviar
  $form.addEventListener('submit', (e) => {
    if (!$hidden.value) {
      e.preventDefault();
      alert('Selecciona un modelo válido de las sugerencias.');
      $input.focus();
    }
  });
})();
</script>

</body>
</html>
