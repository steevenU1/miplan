<?php
// recarga_tiempo_aire.php — Captura de recargas de tiempo aire + cobro en tabla cobros

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'] ?? '', ['Ejecutivo','Gerente','Admin'])) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id_usuario  = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = $_SESSION['rol'] ?? '';
$nombre_usuario = $_SESSION['nombre'] ?? ('Usuario #'.$id_usuario);

// ===============================
// Resolver nombre de sucursal
// ===============================
if (!isset($_SESSION['nombre_sucursal']) || $_SESSION['nombre_sucursal'] === '') {
    if ($id_sucursal > 0) {
        $stmt = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ?");
        $stmt->bind_param('i', $id_sucursal);
        $stmt->execute();
        $stmt->bind_result($ns);
        $stmt->fetch();
        $stmt->close();
        if ($ns) {
            $_SESSION['nombre_sucursal'] = $ns;
        } else {
            $_SESSION['nombre_sucursal'] = 'Sucursal desconocida';
        }
    } else {
        $_SESSION['nombre_sucursal'] = 'Sucursal desconocida';
    }
}
$nombre_sucursal = $_SESSION['nombre_sucursal'];

$msg  = $_GET['msg']  ?? '';
$err  = $_GET['err']  ?? '';
$ok   = isset($_GET['ok']);

$companias = ['Telcel','AT&T','Movistar','Unefon','Bait','Virgin Mobile'];

// ===============================
// Procesamiento POST
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $numero   = trim($_POST['numero_telefonico'] ?? '');
        $compania = trim($_POST['compania'] ?? '');
        $monto    = (float)($_POST['monto'] ?? 0);
        $tipoPago = $_POST['tipo_pago'] ?? 'Efectivo';

        // Validaciones
        if ($numero === '' || !preg_match('/^\d{10}$/', $numero)) {
            throw new Exception('El número telefónico debe tener exactamente 10 dígitos.');
        }
        if ($compania === '' || !in_array($compania, $companias, true)) {
            throw new Exception('Compañía telefónica inválida.');
        }
        if ($monto < 10 || $monto > 500) {
            throw new Exception('El monto debe estar entre 10 y 500 pesos.');
        }
        if (!in_array($tipoPago, ['Efectivo','Tarjeta'], true)) {
            throw new Exception('Tipo de pago inválido.');
        }

        $conn->begin_transaction();

        // ===========================
        // 1) Insertar COBRO
        // ===========================
        $motivo = 'Recarga tiempo aire';

        if ($tipoPago === 'Efectivo') {
            $monto_total    = $monto;
            $monto_efectivo = $monto;
            $monto_tarjeta  = 0;
        } else { // Tarjeta
            $monto_total    = $monto;
            $monto_efectivo = 0;
            $monto_tarjeta  = $monto;
        }

        $comision_especial = 0.00;
        $nombre_cliente    = null;      // opcional
        $telefono_cliente  = $numero;   // usamos el número de recarga
        $ticket_uid        = bin2hex(random_bytes(18)); // 36 chars hex

        $stmt = $conn->prepare("
            INSERT INTO cobros (
                id_usuario, id_sucursal, motivo, tipo_pago,
                monto_total, monto_efectivo, monto_tarjeta,
                comision_especial, nombre_cliente, telefono_cliente,
                ticket_uid, fecha_cobro, corte_generado, id_corte
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, NOW(), 0, NULL
            )
        ");
        $stmt->bind_param(
            'iissddddsss',
            $id_usuario, $id_sucursal, $motivo, $tipoPago,
            $monto_total, $monto_efectivo, $monto_tarjeta,
            $comision_especial, $nombre_cliente, $telefono_cliente,
            $ticket_uid
        );
        $stmt->execute();
        $id_cobro = $stmt->insert_id;
        $stmt->close();

        // ===========================
        // 2) Insertar RECARGA
        // ===========================
        $stmt = $conn->prepare("
            INSERT INTO recargas_tiempo_aire (
                id_usuario, id_sucursal, numero_telefonico,
                compania, monto, id_cobro
            ) VALUES (?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            'iissdi',
            $id_usuario, $id_sucursal, $numero,
            $compania, $monto, $id_cobro
        );
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        // Importante: redirección ANTES de cualquier output
        header("Location: recarga_tiempo_aire.php?ok=1&msg=" . urlencode('Recarga registrada correctamente.'));
        exit();

    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        $err = $e->getMessage();
    }
}

// ===============================
// Hasta aquí NO se ha enviado HTML
// ===============================
?>
<?php require_once __DIR__ . '/navbar.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recarga de tiempo aire</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Recarga de tiempo aire</h3>
        <a href="historial_recargas.php" class="btn btn-outline-primary btn-sm">Historial de recargas</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-sm py-2"><?=h($msg)?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-sm py-2"><?=h($err)?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" autocomplete="off">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Usuario</label>
                        <input type="text" class="form-control"
                               value="<?=h($nombre_usuario)?>"
                               readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sucursal</label>
                        <input type="text" class="form-control"
                               value="<?=h($nombre_sucursal)?>"
                               readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Fecha y hora</label>
                        <input type="text" class="form-control"
                               value="<?=date('Y-m-d H:i:s')?>"
                               readonly>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="compania" class="form-label">Compañía</label>
                        <select name="compania" id="compania" class="form-select" required>
                            <option value="">Selecciona...</option>
                            <?php foreach ($companias as $c): ?>
                                <option value="<?=h($c)?>"><?=h($c)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="numero_telefonico" class="form-label">Número telefónico</label>
                        <input type="tel" name="numero_telefonico" id="numero_telefonico"
                               class="form-control" required
                               pattern="\d{10}" maxlength="10"
                               placeholder="Ej. 5512345678">
                        <div class="form-text">Solo dígitos, exactamente 10 números.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="monto" class="form-label">Monto de recarga</label>
                        <select name="monto" id="monto" class="form-select" required>
                            <option value="">Selecciona...</option>
                            <?php
                            $montos = [10,20,30,50,80,100,150,200,300,500];
                            foreach ($montos as $m):
                            ?>
                                <option value="<?=$m?>">$<?=number_format($m,2)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <label class="form-label d-block">Tipo de pago</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="tipo_pago" id="pago_efectivo" value="Efectivo" checked>
                            <label class="form-check-label" for="pago_efectivo">Efectivo</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="tipo_pago" id="pago_tarjeta" value="Tarjeta">
                            <label class="form-check-label" for="pago_tarjeta">Tarjeta</label>
                        </div>
                        <div class="form-text">Para este flujo no se usa pago mixto.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        Guardar recarga
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
