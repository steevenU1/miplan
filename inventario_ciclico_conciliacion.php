<?php
ob_start();
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol        = trim($_SESSION['rol'] ?? '');

$rolesPermitidos = ['Gerente', 'Admin', 'Super', 'SuperAdmin', 'Gerente General', 'GerenteZona'];
if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    die('No tienes permisos para acceder a esta vista.');
}

$rolesAdminLike = ['Admin', 'Super', 'SuperAdmin', 'Gerente General'];
$esAdminLike = in_array($rol, $rolesAdminLike, true);

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('icc_redirect')) {
    function icc_redirect(string $url): void
    {
        header("Location: {$url}");
        exit();
    }
}

$idInventario = (int)($_GET['id'] ?? $_POST['id_inventario'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$inventario = obtenerInventarioCiclicoPorId($idInventario);
if (!$inventario) {
    die('No se encontró el inventario cíclico.');
}

if (!$esAdminLike && (int)$inventario['id_sucursal'] !== $idSucursal) {
    http_response_code(403);
    die('No puedes ver la conciliación de otra sucursal.');
}

/* 🔒 VALIDACIÓN DE FLUJO */
if (!in_array($inventario['estatus'], ['EnConciliacion', 'Cerrado', 'Revisado'], true)) {
    icc_redirect("inventario_ciclico_captura.php?id=" . $idInventario);
}

/* =========================================================
   Cierre
   ========================================================= */
if (!function_exists('icc_cerrar_inventario')) {
    function icc_cerrar_inventario(mysqli $conn, int $idInventario, int $idUsuario): array
    {
        $conn->begin_transaction();

        try {
            $firmas = function_exists('icc_obtener_firmas_acta')
                ? icc_obtener_firmas_acta($idInventario)
                : [
                    'firmado_digitalmente' => 0,
                    'token_firma' => null,
                    'auditor_nombre' => null,
                    'gerente_nombre' => null,
                ];

            $hayEsquemaFirma = (
                function_exists('ic_table_has_column')
                && (
                    ic_table_has_column('inventarios_ciclicos', 'firmado_digitalmente')
                    || ic_table_has_column('inventarios_ciclicos', 'token_firma')
                    || ic_table_has_column('inventarios_ciclicos', 'id_usuario_firma_auditor')
                    || ic_table_has_column('inventarios_ciclicos', 'id_usuario_firma_gerente')
                )
            );

            if ($hayEsquemaFirma && (int)($firmas['firmado_digitalmente'] ?? 0) !== 1) {
                throw new Exception('No se puede cerrar el inventario porque aún no tiene firma digital aplicada.');
            }

            $sql = "
                UPDATE inventarios_ciclicos
                SET estatus = 'Cerrado',
                    cerrado_por = ?,
                    fecha_cierre = NOW()
                WHERE id = ?
                  AND estatus = 'EnConciliacion'
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('No se pudo preparar el cierre.');
            }

            $stmt->bind_param("ii", $idUsuario, $idInventario);
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                $stmt->close();
                throw new Exception('No se puede cerrar el inventario si no está en conciliación.');
            }
            $stmt->close();

            $okBit = registrarBitacoraInventarioCiclico(
                $idInventario,
                'cierre_inventario',
                'Se cerró el inventario cíclico y quedó lista el acta final.',
                [
                    'token_firma'   => $firmas['token_firma'] ?? null,
                    'responsable'   => $firmas['responsable_nombre'] ?? null,
                    'firmado'       => (int)($firmas['firmado_digitalmente'] ?? 0),
                    'fecha_cierre'  => date('Y-m-d H:i:s'),
                ],
                $idUsuario
            );

            if (!$okBit) {
                throw new Exception('No se pudo registrar la bitácora de cierre.');
            }

            $conn->commit();
            return [
                'ok' => true,
                'msg' => 'Inventario cerrado correctamente.',
                'redirect' => 'acta_auditoria.php?id=' . $idInventario,
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

/* =========================================================
   Procesamiento
   ========================================================= */
$msgOk = '';
$msgErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'cerrar_inventario') {
        $resp = icc_cerrar_inventario($conn, $idInventario, $idUsuario);

        if (!empty($resp['ok'])) {
            $destino = trim((string)($resp['redirect'] ?? ''));
            if ($destino !== '') {
                icc_redirect($destino);
            }

            $msgOk = $resp['msg'];
            $inventario = obtenerInventarioCiclicoPorId($idInventario);
        } else {
            $msgErr = $resp['msg'];
        }
    }
}

/* =========================================================
   Datos de conciliación
   ========================================================= */
$resumen            = function_exists('icc_resumen_general') ? icc_resumen_general($idInventario) : [];
$correctos          = function_exists('icc_correctos') ? icc_correctos($idInventario) : [];
$faltantes          = function_exists('icc_faltantes') ? icc_faltantes($idInventario) : [];
$sobrantes          = function_exists('icc_por_resultado') ? icc_por_resultado($idInventario, 'no_existe_en_sistema') : [];
$otraSucursal       = function_exists('icc_por_resultado') ? icc_por_resultado($idInventario, 'existe_en_otra_sucursal') : [];
$noDisponible       = function_exists('icc_por_resultado') ? icc_por_resultado($idInventario, 'existe_pero_no_disponible') : [];
$diferenciaCantidad = function_exists('icc_por_resultado') ? icc_por_resultado($idInventario, 'diferencia_cantidad') : [];
$firmasActa         = function_exists('icc_obtener_firmas_acta') ? icc_obtener_firmas_acta($idInventario) : [];

$firmadoDigitalmente = (int)($firmasActa['firmado_digitalmente'] ?? 0) === 1;
$tokenFirma          = trim((string)($firmasActa['token_firma'] ?? ''));
$responsableNombre   = trim((string)($firmasActa['responsable_nombre'] ?? ''));
$fechaFirma          = trim((string)($firmasActa['fecha_firma'] ?? ''));

$hayEsquemaFirma = (
    function_exists('ic_table_has_column')
    && (
        ic_table_has_column('inventarios_ciclicos', 'firmado_digitalmente')
        || ic_table_has_column('inventarios_ciclicos', 'token_firma')
        || ic_table_has_column('inventarios_ciclicos', 'id_usuario_firma_auditor')
        || ic_table_has_column('inventarios_ciclicos', 'id_usuario_firma_gerente')
    )
);

$archivoActaExiste  = file_exists(__DIR__ . '/acta_auditoria.php');
$archivoExcelExiste = file_exists(__DIR__ . '/export_acta_auditoria_excel.php');

require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Conciliación Inventario Cíclico</title>
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

        .kpi {
            border-radius: 18px;
            padding: 18px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .05);
            height: 100%;
        }

        .kpi .n {
            font-size: 1.7rem;
            font-weight: 800;
        }

        .sec-title {
            font-weight: 700;
            margin-bottom: 12px;
        }

        .tiny {
            font-size: .9rem;
            color: #6c757d;
        }

        .mono {
            font-family: Consolas, Monaco, monospace;
        }

        .table thead th {
            white-space: nowrap;
            vertical-align: middle;
        }

        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 700;
        }

        .pill-ok {
            background: #eaf7ef;
            color: #146c43;
        }

        .pill-warn {
            background: #fff4db;
            color: #9a6700;
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
    </style>
</head>

<body>
    <div class="wrap container-fluid">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="tiny">Conciliación de inventario cíclico</div>
                <h2 class="mb-1">Sucursal: <?= h($inventario['sucursal_nombre']) ?></h2>
                <div class="tiny">
                    ID #<?= (int)$inventario['id'] ?> · Fecha programada: <?= h($inventario['fecha_programada']) ?> ·
                    Estatus: <strong><?= h($inventario['estatus']) ?></strong>
                </div>
            </div>

            <div class="toolbar-actions">
                <?php if ($inventario['estatus'] === 'EnConciliacion'): ?>
                    <form method="post" class="m-0" onsubmit="return confirm('⚠️ Estás a punto de cerrar el inventario.\n\nYa no podrás modificar la captura.\nSe generará el acta final.\n\n¿Deseas continuar?');">
                        <input type="hidden" name="id_inventario" value="<?= (int)$idInventario ?>">
                        <input type="hidden" name="action" value="cerrar_inventario">
                        <button type="submit" class="btn btn-success">Cerrar inventario</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($inventario['estatus'], ['Cerrado', 'Revisado'], true) && $archivoActaExiste): ?>
                    <a href="acta_auditoria.php?id=<?= (int)$idInventario ?>" class="btn btn-primary">
                        Ver acta final
                    </a>
                <?php endif; ?>

                <?php if (in_array($inventario['estatus'], ['Cerrado', 'Revisado'], true) && $archivoExcelExiste): ?>
                    <a href="export_acta_auditoria_excel.php?id=<?= (int)$idInventario ?>" class="btn btn-success">
                        Exportar Excel
                    </a>
                <?php endif; ?>

                <a href="inventarios_ciclicos_admin.php" class="btn btn-outline-secondary">Volver al listado</a>
            </div>
        </div>

        <?php if ($msgOk): ?>
            <div class="alert alert-success"><?= h($msgOk) ?></div>
        <?php endif; ?>

        <?php if ($msgErr): ?>
            <div class="alert alert-danger"><?= h($msgErr) ?></div>
        <?php endif; ?>

        <?php if ($inventario['estatus'] === 'EnConciliacion'): ?>
            <div class="alert alert-warning">
                🔒 Este inventario está en conciliación.<br>
                Ya no se permite modificar la captura. Solo puedes revisar y cerrar.
            </div>
        <?php endif; ?>

        <?php if ($hayEsquemaFirma): ?>
            <div class="alert <?= $firmadoDigitalmente ? 'alert-success' : 'alert-secondary' ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <strong>Firma digital del acta:</strong>
                        <?php if ($firmadoDigitalmente): ?>
                            <span class="pill pill-ok">Aplicada</span>
                        <?php else: ?>
                            <span class="pill pill-warn">Pendiente</span>
                        <?php endif; ?>
                        <div class="mt-2 small">
                            Responsable: <strong><?= h($responsableNombre ?: 'Pendiente') ?></strong>
                            <?php if ($fechaFirma): ?>
                                · Fecha firma: <strong><?= h($fechaFirma) ?></strong>
                            <?php endif; ?>
                            <?php if ($tokenFirma): ?>
                                · Token: <span class="mono"><?= h($tokenFirma) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$firmadoDigitalmente): ?>
                        <div class="small text-muted">
                            Para un cierre formal, primero debe aplicarse la firma digital.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Snapshot</div>
                    <div class="n"><?= (int)($resumen['snapshot_total'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Capturas</div>
                    <div class="n"><?= (int)($resumen['capturas_total'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Correctos</div>
                    <div class="n"><?= (int)($resumen['correctos'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Faltantes</div>
                    <div class="n"><?= (int)($resumen['faltantes'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Sobrantes</div>
                    <div class="n"><?= (int)($resumen['sobrantes'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <div class="kpi">
                    <div class="tiny">Otra sucursal</div>
                    <div class="n"><?= (int)($resumen['otra_sucursal'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-6">
                <div class="kpi">
                    <div class="tiny">No disponible</div>
                    <div class="n"><?= (int)($resumen['no_disponible'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-6">
                <div class="kpi">
                    <div class="tiny">Diferencias por cantidad</div>
                    <div class="n"><?= (int)($resumen['diferencia_cantidad'] ?? 0) ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Correctos</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Cantidad</th>
                                    <th>Resultado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$correctos): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Sin registros correctos.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($correctos as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td class="mono"><?= h($r['identificador'] ?? '') ?></td>
                                            <td><?= (int)($r['cantidad'] ?? 0) ?></td>
                                            <td><?= h($r['resultado_validacion'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Faltantes</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Cantidad esperada</th>
                                    <th>Estatus sistema</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$faltantes): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Sin faltantes.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($faltantes as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td class="mono"><?= h($r['identificador'] ?? '') ?></td>
                                            <td><?= (int)($r['cantidad_esperada'] ?? 0) ?></td>
                                            <td><?= h($r['estatus_sistema'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Sobrantes / No existe en sistema</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Cantidad</th>
                                    <th>Observación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$sobrantes): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Sin sobrantes.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sobrantes as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td class="mono"><?= h($r['identificador'] ?? '') ?></td>
                                            <td><?= (int)($r['cantidad'] ?? 0) ?></td>
                                            <td><?= h($r['observacion_sistema'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Existe en otra sucursal</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Sucursal en sistema</th>
                                    <th>Estatus sistema</th>
                                    <th>Observación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$otraSucursal): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">Sin incidencias de otra sucursal.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($otraSucursal as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td class="mono"><?= h($r['identificador'] ?? '') ?></td>
                                            <td><?= h((string)($r['id_sucursal_sistema'] ?? '')) ?></td>
                                            <td><?= h($r['estatus_sistema'] ?? '') ?></td>
                                            <td><?= h($r['observacion_sistema'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Existe pero no disponible</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Identificador</th>
                                    <th>Estatus sistema</th>
                                    <th>Observación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$noDisponible): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Sin incidencias de no disponible.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($noDisponible as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td class="mono"><?= h($r['identificador'] ?? '') ?></td>
                                            <td><?= h($r['estatus_sistema'] ?? '') ?></td>
                                            <td><?= h($r['observacion_sistema'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-ui p-4">
                    <h4 class="sec-title">Diferencias por cantidad</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sección</th>
                                    <th>Descripción</th>
                                    <th>Cantidad capturada</th>
                                    <th>Observación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$diferenciaCantidad): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Sin diferencias por cantidad.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($diferenciaCantidad as $r): ?>
                                        <tr>
                                            <td><?= h($r['seccion'] ?? '') ?></td>
                                            <td><?= h(($r['descripcion_snapshot'] ?? '') ?: ($r['codigo'] ?? '')) ?></td>
                                            <td><?= (int)($r['cantidad'] ?? 0) ?></td>
                                            <td><?= h($r['observacion_sistema'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <?php if (in_array($inventario['estatus'], ['Cerrado', 'Revisado'], true)): ?>
            <div class="alert alert-info mt-4">
                ✅ Este inventario ya está cerrado. Desde aquí ya puedes consultar el acta final y exportar el Excel.
            </div>
        <?php endif; ?>

    </div>
</body>

</html>
<?php ob_end_flush(); ?>