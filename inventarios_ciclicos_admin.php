<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';

$ROL        = trim($_SESSION['rol'] ?? '');
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

/**
 * Roles permitidos:
 * - Admin-like: ve todo y puede programar
 * - Gerente / GerenteZona: ve solo su sucursal y puede iniciar/continuar
 */
$rolesAdminLike   = ['Admin', 'Super', 'SuperAdmin', 'Gerente General', 'RH'];
$rolesGerenciales = ['Gerente', 'GerenteZona'];

if (!in_array($ROL, array_merge($rolesAdminLike, $rolesGerenciales), true)) {
    http_response_code(403);
    die('No tienes permisos para acceder a esta vista.');
}

$esAdminLike    = in_array($ROL, $rolesAdminLike, true);
$esGerencial    = in_array($ROL, $rolesGerenciales, true);
$puedeProgramar = $esAdminLike;

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Helpers locales de obtención
 */
if (!function_exists('ic_obtener_sucursales_para_vista')) {
    function ic_obtener_sucursales_para_vista(mysqli $conn, bool $esAdminLike, int $idSucursal): array
    {
        $rows = [];

        if ($esAdminLike) {
            $sql = "
                SELECT id, nombre, zona, tipo_sucursal, subtipo
                FROM sucursales
                WHERE activo = 1
                ORDER BY nombre ASC
            ";
            $rs = $conn->query($sql);
        } else {
            $sql = "
                SELECT id, nombre, zona, tipo_sucursal, subtipo
                FROM sucursales
                WHERE activo = 1
                  AND id = ?
                ORDER BY nombre ASC
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param("i", $idSucursal);
            $stmt->execute();
            $rs = $stmt->get_result();
        }

        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $rows[] = $row;
            }
            $rs->free();
        }

        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        return $rows;
    }
}

if (!function_exists('ic_obtener_inventarios_admin_vista')) {
    function ic_obtener_inventarios_admin_vista(mysqli $conn, array $filtros, bool $esAdminLike, int $idSucursal): array
    {
        $where = [];
        $params = [];
        $types  = '';

        if (!$esAdminLike) {
            $where[] = "ic.id_sucursal = ?";
            $params[] = $idSucursal;
            $types .= 'i';
        } else {
            if (!empty($filtros['id_sucursal'])) {
                $where[] = "ic.id_sucursal = ?";
                $params[] = (int)$filtros['id_sucursal'];
                $types .= 'i';
            }
        }

        if (!empty($filtros['fecha_desde'])) {
            $where[] = "ic.fecha_programada >= ?";
            $params[] = $filtros['fecha_desde'];
            $types .= 's';
        }

        if (!empty($filtros['fecha_hasta'])) {
            $where[] = "ic.fecha_programada <= ?";
            $params[] = $filtros['fecha_hasta'];
            $types .= 's';
        }

        if (!empty($filtros['estatus'])) {
            $where[] = "ic.estatus = ?";
            $params[] = $filtros['estatus'];
            $types .= 's';
        }

        $sql = "
            SELECT
                ic.*,
                s.nombre AS sucursal_nombre,
                uc.nombre AS creado_por_nombre,
                ui.nombre AS iniciado_por_nombre,
                ucie.nombre AS cerrado_por_nombre,
                ur.nombre AS revisado_por_nombre
            FROM inventarios_ciclicos ic
            INNER JOIN sucursales s ON s.id = ic.id_sucursal
            INNER JOIN usuarios uc ON uc.id = ic.creado_por
            LEFT JOIN usuarios ui ON ui.id = ic.iniciado_por
            LEFT JOIN usuarios ucie ON ucie.id = ic.cerrado_por
            LEFT JOIN usuarios ur ON ur.id = ic.revisado_por
        ";

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY ic.fecha_programada DESC, ic.id DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        $stmt->close();

        return $rows;
    }
}

$msgOk  = '';
$msgErr = '';

/**
 * Programación solo para Admin-like
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'programar') {
    if (!$puedeProgramar) {
        $msgErr = 'No tienes permisos para programar inventarios.';
    } else {
        $idsSucursales = $_POST['ids_sucursales'] ?? [];
        $fecha = trim($_POST['fecha_programada'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');

        if (!is_array($idsSucursales) || empty($idsSucursales)) {
            $msgErr = 'Debes seleccionar al menos una sucursal.';
        } elseif (!$fecha) {
            $msgErr = 'Debes seleccionar una fecha.';
        } else {
            $resp = crearInventariosCiclicosMasivo($idsSucursales, $fecha, $observaciones, $idUsuario);

            if (!empty($resp['ok'])) {
                $msgOk = "Se programaron {$resp['total_creados']} inventario(s) correctamente.";

                if (!empty($resp['total_errores'])) {
                    $msgErr = "No se pudieron programar {$resp['total_errores']} sucursal(es) por duplicidad o validación.";
                }
            } else {
                $msgErr = 'No se pudo programar ningún inventario.';
            }
        }
    }
}

/**
 * Cancelación / eliminación lógica
 * - Programado / BloqueoPrevio: se cancelan sin problema
 * - EnCaptura: también se permite cancelar, bajo confirmación en UI
 * - No se permite en EnConciliacion / Cerrado / Revisado / Cancelado
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancelar') {
    if (!$esAdminLike) {
        $msgErr = 'No tienes permisos para cancelar inventarios.';
    } else {
        $idInventario = (int)($_POST['id_inventario'] ?? 0);

        if ($idInventario <= 0) {
            $msgErr = 'Inventario inválido.';
        } else {
            $stmt = $conn->prepare("
                SELECT id, estatus, id_sucursal
                FROM inventarios_ciclicos
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                $msgErr = 'No se pudo preparar la validación del inventario.';
            } else {
                $stmt->bind_param("i", $idInventario);
                $stmt->execute();
                $rs = $stmt->get_result();
                $inv = $rs ? $rs->fetch_assoc() : null;
                if ($rs) {
                    $rs->free();
                }
                $stmt->close();

                if (!$inv) {
                    $msgErr = 'El inventario no existe.';
                } else {
                    $estatusActual = trim((string)($inv['estatus'] ?? ''));

                    $estatusPermitidosCancelar = ['Programado', 'BloqueoPrevio', 'EnCaptura'];

                    if (!in_array($estatusActual, $estatusPermitidosCancelar, true)) {
                        $msgErr = "No se puede cancelar un inventario en estatus '{$estatusActual}'.";
                    } else {
                        $observacionExtra = '';
                        if ($estatusActual === 'EnCaptura') {
                            $observacionExtra = '[Cancelado en captura. Puede haber registros parciales.] ';
                        }

                        $stmtUpd = $conn->prepare("
                            UPDATE inventarios_ciclicos
                            SET estatus = 'Cancelado',
                                observaciones = CONCAT(?, COALESCE(observaciones, ''))
                            WHERE id = ?
                            LIMIT 1
                        ");

                        if (!$stmtUpd) {
                            $msgErr = 'No se pudo preparar la cancelación.';
                        } else {
                            $stmtUpd->bind_param("si", $observacionExtra, $idInventario);

                            if ($stmtUpd->execute()) {
                                $msgOk = "El inventario #{$idInventario} fue cancelado correctamente.";
                            } else {
                                $msgErr = 'No se pudo cancelar el inventario.';
                            }

                            $stmtUpd->close();
                        }
                    }
                }
            }
        }
    }
}

$filtros = [
    'fecha_desde' => trim($_GET['fecha_desde'] ?? ''),
    'fecha_hasta' => trim($_GET['fecha_hasta'] ?? ''),
    'id_sucursal' => (int)($_GET['id_sucursal'] ?? 0),
    'estatus'     => trim($_GET['estatus'] ?? ''),
];

if (!$esAdminLike) {
    $filtros['id_sucursal'] = $idSucursal;
}

$sucursales = ic_obtener_sucursales_para_vista($conn, $esAdminLike, $idSucursal);
$listado    = ic_obtener_inventarios_admin_vista($conn, $filtros, $esAdminLike, $idSucursal);

$estatusDisponibles = ['Programado', 'BloqueoPrevio', 'EnCaptura', 'EnConciliacion', 'Cerrado', 'Revisado', 'Cancelado'];
$archivoActaExiste  = file_exists(__DIR__ . '/acta_auditoria.php');
$archivoExcelExiste = file_exists(__DIR__ . '/export_acta_auditoria_excel.php');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Inventarios Cíclicos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }

        .wrap {
            padding: 20px;
        }

        .card-ui {
            border: none;
            border-radius: 18px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .06);
        }

        .title-chip {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            background: #eaf3ff;
            color: #0a58ca;
            padding: .45rem .8rem;
            border-radius: 999px;
            font-weight: 600;
        }

        .table thead th {
            white-space: nowrap;
            vertical-align: middle;
        }

        .badge-soft {
            font-size: .85rem;
            padding: .45rem .7rem;
            border-radius: 999px;
        }

        .badge-programado {
            background: #e7f1ff;
            color: #0d6efd;
        }

        .badge-bloqueoprevio {
            background: #fff3cd;
            color: #856404;
        }

        .badge-encaptura {
            background: #cff4fc;
            color: #055160;
        }

        .badge-enconciliacion {
            background: #ffe5b4;
            color: #8a5a00;
        }

        .badge-cerrado {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-revisado {
            background: #e2e3e5;
            color: #41464b;
        }

        .badge-cancelado {
            background: #f8d7da;
            color: #842029;
        }

        .sucursal-list {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 14px;
            background: #fff;
            padding: 10px;
        }

        .sucursal-item {
            padding: 8px 10px;
            border-radius: 10px;
        }

        .sucursal-item:hover {
            background: #f8f9fa;
        }

        .acciones-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .form-inline-cancel {
            display: inline-block;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="wrap container-fluid">

        <div class="mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="title-chip">
                    📦 Inventario Cíclico · <?= $esAdminLike ? 'Administración' : 'Sucursal' ?>
                </span>
                <h2 class="mt-3 mb-0">
                    <?= $esAdminLike ? 'Programación y seguimiento' : 'Inventarios de tu sucursal' ?>
                </h2>
            </div>
        </div>

        <?php if ($msgOk): ?>
            <div class="alert alert-success"><?= h($msgOk) ?></div>
        <?php endif; ?>

        <?php if ($msgErr): ?>
            <div class="alert alert-warning"><?= h($msgErr) ?></div>
        <?php endif; ?>

        <div class="row g-4">

            <?php if ($puedeProgramar): ?>
                <div class="col-12 col-xl-4">
                    <div class="card card-ui p-4">
                        <h4 class="mb-3">Programar inventario</h4>

                        <form method="post">
                            <input type="hidden" name="action" value="programar">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Fecha programada</label>
                                <input type="date"
                                    name="fecha_programada"
                                    class="form-control"
                                    min="<?= h(date('Y-m-d')) ?>"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Sucursales</label>
                                <div class="sucursal-list">
                                    <?php foreach ($sucursales as $s): ?>
                                        <label class="d-flex align-items-start gap-2 sucursal-item">
                                            <input type="checkbox" name="ids_sucursales[]" value="<?= (int)$s['id'] ?>" class="form-check-input mt-1">
                                            <span>
                                                <strong><?= h($s['nombre']) ?></strong><br>
                                                <small class="text-muted">
                                                    Zona: <?= h($s['zona'] ?? '-') ?>
                                                    <?php if (!empty($s['tipo_sucursal'])): ?>
                                                        · Tipo: <?= h($s['tipo_sucursal']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($s['subtipo'])): ?>
                                                        · Subtipo: <?= h($s['subtipo']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">Puedes seleccionar una o varias sucursales para el mismo día.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Observaciones</label>
                                <textarea name="observaciones"
                                    class="form-control"
                                    rows="3"
                                    maxlength="255"
                                    placeholder="Opcional"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Programar inventario(s)</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-12 <?= $puedeProgramar ? 'col-xl-8' : 'col-xl-12' ?>">
                <div class="card card-ui p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h4 class="mb-0">Listado de inventarios</h4>
                    </div>

                    <form method="get" class="row g-3 mb-4">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Fecha desde</label>
                            <input type="date" name="fecha_desde" class="form-control" value="<?= h($filtros['fecha_desde']) ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Fecha hasta</label>
                            <input type="date" name="fecha_hasta" class="form-control" value="<?= h($filtros['fecha_hasta']) ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Sucursal</label>
                            <?php if ($esAdminLike): ?>
                                <select name="id_sucursal" class="form-select">
                                    <option value="0">Todas</option>
                                    <?php foreach ($sucursales as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= ((int)$filtros['id_sucursal'] === (int)$s['id']) ? 'selected' : '' ?>>
                                            <?= h($s['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" value="<?= h($sucursales[0]['nombre'] ?? 'Sucursal actual') ?>" disabled>
                                <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursal ?>">
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Estatus</label>
                            <select name="estatus" class="form-select">
                                <option value="">Todos</option>
                                <?php foreach ($estatusDisponibles as $est): ?>
                                    <option value="<?= h($est) ?>" <?= ($filtros['estatus'] === $est) ? 'selected' : '' ?>>
                                        <?= h($est) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-outline-primary">Filtrar</button>
                            <a href="inventarios_ciclicos_admin.php" class="btn btn-outline-secondary">Limpiar</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Sucursal</th>
                                    <th>Estatus</th>
                                    <th>Creado por</th>
                                    <th>Inicio</th>
                                    <th>Cierre</th>
                                    <th>Observaciones</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$listado): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No hay inventarios cíclicos registrados.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($listado as $row): ?>
                                        <?php
                                        $cls = match ($row['estatus']) {
                                            'Programado'     => 'badge-programado',
                                            'BloqueoPrevio'  => 'badge-bloqueoprevio',
                                            'EnCaptura'      => 'badge-encaptura',
                                            'EnConciliacion' => 'badge-enconciliacion',
                                            'Cerrado'        => 'badge-cerrado',
                                            'Revisado'       => 'badge-revisado',
                                            'Cancelado'      => 'badge-cancelado',
                                            default          => 'bg-secondary text-white',
                                        };

                                        $estatus = (string)($row['estatus'] ?? '');
                                        $idRow   = (int)($row['id'] ?? 0);

                                        $puedeCancelar = $esAdminLike && in_array($estatus, ['Programado', 'BloqueoPrevio', 'EnCaptura'], true);

                                        $idSucursalInventario = (int)($row['id_sucursal'] ?? 0);
                                        $puedeCapturarEsteInventario = ($idSucursalInventario === $idSucursal);

                                        if ($estatus === 'EnCaptura') {
                                            $mensajeCancelar = "Este inventario ya está en captura. Si lo cancelas puede quedar evidencia parcial capturada. ¿Deseas continuar?";
                                        } else {
                                            $mensajeCancelar = "¿Seguro que deseas cancelar este inventario?";
                                        }
                                        ?>
                                        <tr>
                                            <td><?= $idRow ?></td>
                                            <td><?= h($row['fecha_programada']) ?></td>
                                            <td><?= h($row['sucursal_nombre']) ?></td>
                                            <td>
                                                <span class="badge-soft <?= h($cls) ?>">
                                                    <?= h($estatus) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= h($row['creado_por_nombre']) ?><br>
                                                <small class="text-muted"><?= h($row['fecha_creacion'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['fecha_inicio'])): ?>
                                                    <small>
                                                        <?= h($row['iniciado_por_nombre'] ?? '') ?><br>
                                                        <?= h($row['fecha_inicio']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin iniciar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['fecha_cierre'])): ?>
                                                    <small>
                                                        <?= h($row['cerrado_por_nombre'] ?? '') ?><br>
                                                        <?= h($row['fecha_cierre']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin cerrar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($row['observaciones'] ?? '') ?></td>
                                            <td>
                                                <div class="acciones-wrap">
                                                    <?php if (in_array($estatus, ['Programado', 'BloqueoPrevio'], true)): ?>
                                                        <a href="iniciar_inventario_ciclico.php?id=<?= $idRow ?>"
                                                            class="btn btn-sm btn-primary">
                                                            Iniciar captura
                                                        </a>
                                                    <?php elseif ($estatus === 'EnCaptura'): ?>
                                                        <a href="inventario_ciclico_captura.php?id=<?= $idRow ?>"
                                                            class="btn btn-sm btn-warning">
                                                            Continuar captura
                                                        </a>
                                                    <?php elseif ($estatus === 'EnConciliacion'): ?>
                                                        <a href="inventario_ciclico_conciliacion.php?id=<?= $idRow ?>"
                                                            class="btn btn-sm btn-info">
                                                            Ver conciliación
                                                        </a>
                                                    <?php elseif (in_array($estatus, ['Cerrado', 'Revisado'], true)): ?>
                                                        <a href="inventario_ciclico_conciliacion.php?id=<?= $idRow ?>"
                                                            class="btn btn-sm btn-outline-info">
                                                            Ver conciliación
                                                        </a>

                                                        <?php if ($archivoActaExiste): ?>
                                                            <a href="acta_auditoria.php?id=<?= $idRow ?>"
                                                                class="btn btn-sm btn-outline-primary">
                                                                Ver acta
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if ($archivoExcelExiste): ?>
                                                            <a href="export_acta_auditoria_excel.php?id=<?= $idRow ?>"
                                                                class="btn btn-sm btn-outline-success">
                                                                Excel
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Sin acciones</span>
                                                    <?php endif; ?>

                                                    <?php if ($puedeCancelar): ?>
                                                        <form method="post" class="form-inline-cancel" onsubmit="return confirm('<?= h($mensajeCancelar) ?>');">
                                                            <input type="hidden" name="action" value="cancelar">
                                                            <input type="hidden" name="id_inventario" value="<?= $idRow ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                Cancelar
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</body>

</html>