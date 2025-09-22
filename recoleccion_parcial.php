<?php
if(!isset($conn)) { include 'db.php'; }
if(!isset($zona)) {
    session_start();
    $idGerente = $_SESSION['id_usuario'];
    $sqlZona = "
        SELECT DISTINCT s.zona
        FROM sucursales s
        INNER JOIN usuarios u ON u.id_sucursal = s.id
        WHERE u.id = ?
    ";
    $stmtZona = $conn->prepare($sqlZona);
    $stmtZona->bind_param("i", $idGerente);
    $stmtZona->execute();
    $zona = $stmtZona->get_result()->fetch_assoc()['zona'] ?? '';
    $stmtZona->close();
}

$saldos = obtenerSaldos($conn, $zona);
$historial = obtenerHistorial($conn, $zona);
?>

<!-- Tabla de saldos -->
<h4 class="mt-4">💰 Saldos de Comisiones por Sucursal</h4>
<table id="tabla-saldos" class="table table-bordered table-sm">
    <thead class="table-dark">
        <tr>
            <th>Sucursal</th>
            <th>Total Comisiones</th>
            <th>Total Entregado</th>
            <th>Saldo Pendiente</th>
            <th>Acción</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($saldos as $s): ?>
        <tr>
            <td><?= $s['sucursal'] ?></td>
            <td>$<?= number_format($s['total_comisiones'],2) ?></td>
            <td>$<?= number_format($s['total_entregado'],2) ?></td>
            <td class="<?= $s['saldo_pendiente'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                $<?= number_format($s['saldo_pendiente'],2) ?>
            </td>
            <td>
                <?php if ($s['saldo_pendiente'] > 0): ?>
                    <button class="btn btn-primary btn-sm recolectar-todo" 
                            data-sucursal="<?= $s['id_sucursal'] ?>" 
                            data-monto="<?= $s['saldo_pendiente'] ?>">
                        ♻ Recolectar Todo
                    </button>
                <?php else: ?>
                    ✅
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Formulario -->
<h4 class="mt-4">📥 Registrar Entrega de Comisiones</h4>
<form id="form-entrega" class="card p-3 shadow">
    <div class="mb-3">
        <label class="form-label">Sucursal</label>
        <select name="id_sucursal" class="form-select" required>
            <option value="">-- Selecciona Sucursal --</option>
            <?php foreach ($saldos as $s): ?>
                <?php if ($s['saldo_pendiente'] > 0): ?>
                    <option value="<?= $s['id_sucursal'] ?>">
                        <?= $s['sucursal'] ?> - Pendiente $<?= number_format($s['saldo_pendiente'],2) ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">Monto Entregado</label>
        <input type="number" step="0.01" name="monto_entregado" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">Observaciones</label>
        <textarea name="observaciones" class="form-control"></textarea>
    </div>
    <button type="submit" class="btn btn-success w-100">💾 Registrar Entrega</button>
</form>

<!-- Historial -->
<h4 class="mt-4">📜 Historial de Entregas</h4>
<table id="tabla-historial" class="table table-bordered table-sm">
    <thead class="table-dark">
        <tr>
            <th>ID</th>
            <th>Sucursal</th>
            <th>Monto Entregado</th>
            <th>Fecha</th>
            <th>Observaciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($historial as $h): ?>
        <tr>
            <td><?= $h['id'] ?></td>
            <td><?= $h['sucursal'] ?></td>
            <td>$<?= number_format($h['monto_entregado'],2) ?></td>
            <td><?= $h['fecha_entrega'] ?></td>
            <td><?= htmlspecialchars($h['observaciones'], ENT_QUOTES) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
