<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventario_ciclico_helpers.php';
require_once __DIR__ . '/navbar.php';

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
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fmt_fecha')) {
    function fmt_fecha(?string $fecha): string
    {
        if (!$fecha) return '—';
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y H:i', $ts) : (string)$fecha;
    }
}

if (!function_exists('fmt_fecha_corta')) {
    function fmt_fecha_corta(?string $fecha): string
    {
        if (!$fecha) return '—';
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y', $ts) : (string)$fecha;
    }
}

if (!function_exists('pick_first')) {
    function pick_first(array $row, array $keys, $default = '')
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return $default;
    }
}

if (!function_exists('es_serializado_row')) {
    function es_serializado_row(array $row): bool
    {
        $identificador = trim((string)pick_first($row, ['identificador'], ''));
        return $identificador !== '';
    }
}

if (!function_exists('obtener_descripcion_row')) {
    function obtener_descripcion_row(array $row): string
    {
        return (string)pick_first($row, ['descripcion_snapshot', 'descripcion', 'codigo'], '—');
    }
}

if (!function_exists('obtener_identificador_row')) {
    function obtener_identificador_row(array $row): string
    {
        return (string)pick_first($row, ['identificador'], '—');
    }
}

if (!function_exists('obtener_seccion_row')) {
    function obtener_seccion_row(array $row): string
    {
        return (string)pick_first($row, ['seccion'], '—');
    }
}

if (!function_exists('obtener_cantidad_sistema_row')) {
    function obtener_cantidad_sistema_row(array $row): int
    {
        return (int)pick_first($row, ['cantidad_esperada', 'cantidad_snapshot', 'cantidad_sistema'], 0);
    }
}

if (!function_exists('obtener_cantidad_contada_row')) {
    function obtener_cantidad_contada_row(array $row): int
    {
        return (int)pick_first($row, ['cantidad', 'cantidad_contada'], 0);
    }
}

if (!function_exists('obtener_diferencia_row')) {
    function obtener_diferencia_row(array $row): int
    {
        if (array_key_exists('diferencia', $row) && $row['diferencia'] !== null && $row['diferencia'] !== '') {
            return (int)$row['diferencia'];
        }

        $sistema = obtener_cantidad_sistema_row($row);
        $contada = obtener_cantidad_contada_row($row);
        return $contada - $sistema;
    }
}

$idInventario = (int)($_GET['id'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$acta = icc_obtener_acta_auditoria($idInventario);
if (empty($acta['ok']) || empty($acta['inventario'])) {
    die(h($acta['msg'] ?? 'No se pudo cargar el acta.'));
}

$inventario      = $acta['inventario'];
$resumen         = $acta['resumen'] ?? [];
$serializados    = $acta['serializados'] ?? [];
$noSerializados  = $acta['no_serializados'] ?? [];
$firmas          = $acta['firmas'] ?? [];
$bitacora        = $acta['bitacora'] ?? [];

if (!$esAdminLike && (int)($inventario['id_sucursal'] ?? 0) !== $idSucursal) {
    http_response_code(403);
    die('No puedes ver el acta de otra sucursal.');
}

$estatusPermitidos = ['EnConciliacion', 'Cerrado', 'Revisado'];
if (!in_array((string)($inventario['estatus'] ?? ''), $estatusPermitidos, true)) {
    die('Esta auditoría aún no se encuentra en una etapa válida para generar acta.');
}

$logoLocal = __DIR__ . '/assets/logo.png';
$logoSrc = '';
if (is_file($logoLocal)) {
    $mime = 'image/png';
    $raw  = @file_get_contents($logoLocal);
    if ($raw !== false) {
        $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}

$excelHref = 'export_acta_auditoria_excel.php?id=' . (int)$idInventario;
$excelExiste = file_exists(__DIR__ . '/export_acta_auditoria_excel.php');

$serializadosCorrectos     = array_values(array_filter($serializados['correctos'] ?? [], 'es_serializado_row'));
$serializadosFaltantes     = array_values(array_filter($serializados['faltantes'] ?? [], 'es_serializado_row'));
$serializadosSobrantes     = array_values(array_filter($serializados['sobrantes'] ?? [], 'es_serializado_row'));
$serializadosOtraSucursal  = array_values(array_filter($serializados['otra_sucursal'] ?? [], 'es_serializado_row'));
$serializadosNoDisponible  = array_values(array_filter($serializados['no_disponible'] ?? [], 'es_serializado_row'));

$noSerializadosDiferencias = array_values(array_filter($noSerializados['diferencias'] ?? [], static function ($r) {
    return !es_serializado_row($r);
}));

$noSerializadosFaltantes = array_values(array_filter($serializados['faltantes'] ?? [], static function ($r) {
    return !es_serializado_row($r);
}));

$fechaCierre = pick_first($inventario, ['fecha_cierre'], null);
$fechaInicio = pick_first($inventario, ['fecha_inicio', 'created_at', 'fecha_creacion'], null);

$firmadoDigitalmente = (int)($firmas['firmado_digitalmente'] ?? 0) === 1;
$tokenFirma = trim((string)($firmas['token_firma'] ?? ''));
$auditorNombre = trim((string)($firmas['auditor_nombre'] ?? ''));
$gerenteNombre = trim((string)($firmas['gerente_nombre'] ?? ''));
$fechaFirma = trim((string)($firmas['fecha_firma'] ?? ''));
$leyendaFirma = trim((string)($firmas['leyenda_firma'] ?? 'Firmado digitalmente con contraseña'));

$folio = 'IC-' . str_pad((string)(int)$inventario['id'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acta de Auditoría #<?= (int)$inventario['id'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root{
            --bg:#f4f7fb;
            --card:#ffffff;
            --ink:#1f2937;
            --muted:#6b7280;
            --line:#dbe3ef;
            --soft:#eef3f9;
            --ok:#198754;
            --warn:#dc3545;
        }
        body{
            background:var(--bg);
            color:var(--ink);
        }
        .page-wrap{
            padding:24px 16px 48px;
        }
        .doc-shell{
            max-width:1100px;
            margin:0 auto;
        }
        .screen-toolbar{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            justify-content:space-between;
            align-items:center;
            margin-bottom:18px;
        }
        .doc-card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:22px;
            box-shadow:0 10px 30px rgba(15,23,42,.06);
            overflow:hidden;
        }
        .doc-body{
            padding:28px;
        }
        .doc-header{
            display:flex;
            justify-content:space-between;
            gap:20px;
            align-items:flex-start;
            padding-bottom:20px;
            border-bottom:2px solid var(--line);
            margin-bottom:24px;
        }
        .logo-box img{
            max-height:70px;
            max-width:210px;
            object-fit:contain;
        }
        .doc-title{
            font-size:1.65rem;
            font-weight:800;
            margin:0 0 4px;
        }
        .doc-subtitle{
            color:var(--muted);
            font-size:.95rem;
        }
        .folio-badge{
            background:var(--soft);
            border:1px solid var(--line);
            border-radius:14px;
            padding:10px 14px;
            min-width:180px;
            text-align:right;
        }
        .folio-badge .lbl{
            display:block;
            color:var(--muted);
            font-size:.8rem;
            margin-bottom:2px;
        }
        .folio-badge .val{
            font-size:1rem;
            font-weight:800;
        }
        .meta-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
            margin-bottom:24px;
        }
        .meta-card{
            border:1px solid var(--line);
            border-radius:16px;
            background:#fff;
            padding:14px 16px;
        }
        .meta-card .k{
            display:block;
            color:var(--muted);
            font-size:.82rem;
            margin-bottom:4px;
        }
        .meta-card .v{
            font-weight:700;
        }
        .section{
            margin-top:26px;
        }
        .section:first-of-type{
            margin-top:0;
        }
        .section-title{
            font-size:1.12rem;
            font-weight:800;
            margin:0 0 14px;
        }
        .summary-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
        }
        .summary-box{
            border:1px solid var(--line);
            border-radius:18px;
            background:linear-gradient(180deg,#fff,#f9fbfe);
            padding:16px;
            min-height:100px;
        }
        .summary-box .t{
            color:var(--muted);
            font-size:.85rem;
            margin-bottom:8px;
        }
        .summary-box .n{
            font-size:1.8rem;
            font-weight:800;
            line-height:1;
        }
        .block-card{
            border:1px solid var(--line);
            border-radius:18px;
            padding:16px;
            margin-bottom:14px;
            background:#fff;
        }
        .block-title{
            font-weight:800;
            margin-bottom:12px;
        }
        .table-wrap{
            border:1px solid var(--line);
            border-radius:16px;
            overflow:hidden;
            background:#fff;
        }
        .table{
            margin-bottom:0;
        }
        .table thead th{
            background:#f3f6fb;
            white-space:nowrap;
            font-size:.9rem;
        }
        .mono{
            font-family:Consolas, Monaco, monospace;
        }
        .empty{
            padding:18px;
            color:var(--muted);
            text-align:center;
        }
        .signature-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .signature-box{
            border:1px solid var(--line);
            border-radius:18px;
            padding:16px;
            background:#fff;
            min-height:160px;
        }
        .signature-box .title{
            font-weight:800;
            margin-bottom:10px;
        }
        .signature-box .line{
            height:1px;
            background:var(--line);
            margin:20px 0 10px;
        }
        .signature-token{
            margin-top:16px;
            border:1px dashed #9fb2cc;
            border-radius:14px;
            background:#f8fbff;
            padding:12px 14px;
        }
        .signature-token .tt{
            font-size:.8rem;
            color:var(--muted);
        }
        .signature-token .tv{
            font-weight:800;
            letter-spacing:.4px;
        }
        .footer-note{
            margin-top:24px;
            font-size:.9rem;
            color:var(--muted);
        }
        .status-pill{
            display:inline-block;
            padding:4px 10px;
            border-radius:999px;
            font-size:.8rem;
            font-weight:700;
            background:#eef3f9;
            border:1px solid var(--line);
        }
        
        .print-only{display:none;}

        @media (max-width: 992px){
            .summary-grid{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }
            .meta-grid,
            .signature-grid{
                grid-template-columns:1fr;
            }
            .doc-header{
                flex-direction:column;
            }
            .folio-badge{
                text-align:left;
                min-width:auto;
            }
        }

        @media print{
            body{
                background:#fff !important;
            }
            nav,
            .navbar,
            .screen-only{
                display:none !important;
            }
            .print-only{
                display:block !important;
            }
            .page-wrap{
                padding:0 !important;
            }
            .doc-shell{
                max-width:none;
                margin:0;
            }
            .doc-card{
                border:none !important;
                box-shadow:none !important;
                border-radius:0 !important;
            }
            .doc-body{
                padding:0 !important;
            }
            .table-wrap,
            .block-card,
            .meta-card,
            .summary-box,
            .signature-box{
                break-inside:avoid;
                page-break-inside:avoid;
            }
            a[href]:after{
                content:"";
            }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="doc-shell">

        <div class="screen-toolbar screen-only">
            <div>
                <div class="small text-muted">Acta final de auditoría / inventario cíclico</div>
                <h3 class="mb-0">Sucursal: <?= h($inventario['sucursal_nombre'] ?? '—') ?></h3>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>

                <?php if ($excelExiste): ?>
                    <a href="<?= h($excelHref) ?>" class="btn btn-success">Exportar Excel</a>
                <?php endif; ?>

                <a href="inventario_ciclico_conciliar.php?id=<?= (int)$idInventario ?>" class="btn btn-outline-secondary">Volver a conciliación</a>
                <a href="inventarios_ciclicos_admin.php" class="btn btn-outline-dark">Ir al listado</a>
            </div>
        </div>

        <div class="doc-card">
            <div class="doc-body">

                <div class="doc-header">
                    <div class="d-flex align-items-start gap-3">
                        <?php if ($logoSrc): ?>
                            <div class="logo-box">
                                <img src="<?= $logoSrc ?>" alt="Logo">
                            </div>
                        <?php endif; ?>

                        <div>
                            <h1 class="doc-title">Acta de Auditoría Interna</h1>
                            <div class="doc-subtitle">
                                Documento de cierre del inventario cíclico y conciliación final de existencias.
                            </div>
                        </div>
                    </div>

                    <div class="folio-badge">
                        <span class="lbl">Folio</span>
                        <span class="val"><?= h($folio) ?></span>
                        <div class="small text-muted mt-1">Estatus: <span class="status-pill"><?= h($inventario['estatus'] ?? '—') ?></span></div>
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-card">
                        <span class="k">Sucursal</span>
                        <span class="v"><?= h($inventario['sucursal_nombre'] ?? '—') ?></span>
                    </div>
                    <div class="meta-card">
                        <span class="k">Fecha programada</span>
                        <span class="v"><?= h(fmt_fecha_corta($inventario['fecha_programada'] ?? null)) ?></span>
                    </div>
                    <div class="meta-card">
                        <span class="k">Creado por</span>
                        <span class="v"><?= h($inventario['creado_por_nombre'] ?? '—') ?></span>
                    </div>
                    <div class="meta-card">
                        <span class="k">Iniciado por</span>
                        <span class="v"><?= h($inventario['iniciado_por_nombre'] ?? '—') ?></span>
                    </div>
                    <div class="meta-card">
                        <span class="k">Fecha de inicio</span>
                        <span class="v"><?= h(fmt_fecha($fechaInicio)) ?></span>
                    </div>
                    <div class="meta-card">
                        <span class="k">Fecha de cierre</span>
                        <span class="v"><?= h(fmt_fecha($fechaCierre)) ?></span>
                    </div>
                </div>

                <?php if (!empty($inventario['observaciones'])): ?>
                    <div class="section">
                        <div class="block-card">
                            <div class="block-title">Observaciones generales</div>
                            <div><?= nl2br(h($inventario['observaciones'])) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="section">
                    <h2 class="section-title">Resumen general</h2>
                    <div class="summary-grid">
                        <div class="summary-box">
                            <div class="t">Snapshot total</div>
                            <div class="n"><?= (int)($resumen['snapshot_total'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Capturas</div>
                            <div class="n"><?= (int)($resumen['capturas_total'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Correctos</div>
                            <div class="n"><?= (int)($resumen['correctos'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Faltantes</div>
                            <div class="n"><?= (int)($resumen['faltantes'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Sobrantes</div>
                            <div class="n"><?= (int)($resumen['sobrantes'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Existe en otra sucursal</div>
                            <div class="n"><?= (int)($resumen['otra_sucursal'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Existe pero no disponible</div>
                            <div class="n"><?= (int)($resumen['no_disponible'] ?? 0) ?></div>
                        </div>
                        <div class="summary-box">
                            <div class="t">Diferencias por cantidad</div>
                            <div class="n"><?= (int)($resumen['diferencia_cantidad'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Detalle de productos serializados</h2>

                    <div class="block-card">
                        <div class="block-title">Serializados correctos</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Identificador</th>
                                        <th>Cantidad</th>
                                        <th>Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$serializadosCorrectos): ?>
                                    <tr><td colspan="5" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($serializadosCorrectos as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td class="mono"><?= h(obtener_identificador_row($r)) ?></td>
                                            <td><?= (int)obtener_cantidad_contada_row($r) ?></td>
                                            <td><?= h($r['resultado_validacion'] ?? 'ok') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="block-card">
                        <div class="block-title">Serializados faltantes</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Identificador</th>
                                        <th>Cantidad esperada</th>
                                        <th>Estatus sistema</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$serializadosFaltantes): ?>
                                    <tr><td colspan="5" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($serializadosFaltantes as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td class="mono"><?= h(obtener_identificador_row($r)) ?></td>
                                            <td><?= (int)obtener_cantidad_sistema_row($r) ?></td>
                                            <td><?= h(pick_first($r, ['estatus_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="block-card">
                        <div class="block-title">Serializados sobrantes / no existentes en sistema</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Identificador</th>
                                        <th>Cantidad</th>
                                        <th>Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$serializadosSobrantes): ?>
                                    <tr><td colspan="5" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($serializadosSobrantes as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td class="mono"><?= h(obtener_identificador_row($r)) ?></td>
                                            <td><?= (int)obtener_cantidad_contada_row($r) ?></td>
                                            <td><?= h(pick_first($r, ['observacion_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="block-card">
                        <div class="block-title">Serializados que existen en otra sucursal</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Identificador</th>
                                        <th>Sucursal sistema</th>
                                        <th>Estatus sistema</th>
                                        <th>Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$serializadosOtraSucursal): ?>
                                    <tr><td colspan="6" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($serializadosOtraSucursal as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td class="mono"><?= h(obtener_identificador_row($r)) ?></td>
                                            <td><?= h((string)pick_first($r, ['id_sucursal_sistema', 'sucursal_sistema_nombre'], '—')) ?></td>
                                            <td><?= h(pick_first($r, ['estatus_sistema'], '—')) ?></td>
                                            <td><?= h(pick_first($r, ['observacion_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="block-card">
                        <div class="block-title">Serializados existentes pero no disponibles</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Identificador</th>
                                        <th>Estatus sistema</th>
                                        <th>Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$serializadosNoDisponible): ?>
                                    <tr><td colspan="5" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($serializadosNoDisponible as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td class="mono"><?= h(obtener_identificador_row($r)) ?></td>
                                            <td><?= h(pick_first($r, ['estatus_sistema'], '—')) ?></td>
                                            <td><?= h(pick_first($r, ['observacion_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Detalle de productos no serializados</h2>

                    <div class="block-card">
                        <div class="block-title">No serializados con diferencia</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Cantidad sistema</th>
                                        <th>Cantidad contada</th>
                                        <th>Diferencia</th>
                                        <th>Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$noSerializadosDiferencias): ?>
                                    <tr><td colspan="6" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($noSerializadosDiferencias as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td><?= (int)obtener_cantidad_sistema_row($r) ?></td>
                                            <td><?= (int)obtener_cantidad_contada_row($r) ?></td>
                                            <td><?= (int)obtener_diferencia_row($r) ?></td>
                                            <td><?= h(pick_first($r, ['observacion_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="block-card">
                        <div class="block-title">No serializados faltantes</div>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Sección</th>
                                        <th>Descripción</th>
                                        <th>Cantidad sistema</th>
                                        <th>Cantidad contada</th>
                                        <th>Diferencia</th>
                                        <th>Estatus sistema</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$noSerializadosFaltantes): ?>
                                    <tr><td colspan="6" class="empty">Sin registros.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($noSerializadosFaltantes as $r): ?>
                                        <tr>
                                            <td><?= h(obtener_seccion_row($r)) ?></td>
                                            <td><?= h(obtener_descripcion_row($r)) ?></td>
                                            <td><?= (int)obtener_cantidad_sistema_row($r) ?></td>
                                            <td>0</td>
                                            <td><?= 0 - (int)obtener_cantidad_sistema_row($r) ?></td>
                                            <td><?= h(pick_first($r, ['estatus_sistema'], '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Firmas digitales</h2>

                    <div class="signature-grid">
                        <div class="signature-box">
                            <div class="title">Auditor</div>
                            <div><strong>Nombre:</strong> <?= h($auditorNombre ?: 'Pendiente') ?></div>
                            <div><strong>Firma digital:</strong> <?= $firmadoDigitalmente && $auditorNombre ? 'Aplicada' : 'Pendiente' ?></div>
                            <div><strong>Fecha:</strong> <?= h(fmt_fecha($fechaFirma ?: null)) ?></div>
                            <div class="line"></div>
                            <div class="small text-muted"><?= h($leyendaFirma) ?></div>
                        </div>

                        <div class="signature-box">
                            <div class="title">Gerente / Responsable presente</div>
                            <div><strong>Nombre:</strong> <?= h($gerenteNombre ?: 'Pendiente') ?></div>
                            <div><strong>Firma digital:</strong> <?= $firmadoDigitalmente && $gerenteNombre ? 'Aplicada' : 'Pendiente' ?></div>
                            <div><strong>Fecha:</strong> <?= h(fmt_fecha($fechaFirma ?: null)) ?></div>
                            <div class="line"></div>
                            <div class="small text-muted"><?= h($leyendaFirma) ?></div>
                        </div>
                    </div>

                    <div class="signature-token">
                        <div class="tt">Token de firma</div>
                        <div class="tv mono"><?= h($tokenFirma !== '' ? $tokenFirma : 'SIN TOKEN REGISTRADO') ?></div>
                    </div>
                </div>

                <?php if ($bitacora): ?>
                    <div class="section">
                        <h2 class="section-title">Bitácora reciente</h2>
                        <div class="table-wrap">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Evento</th>
                                        <th>Descripción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($bitacora as $b): ?>
                                    <tr>
                                        <td><?= h(fmt_fecha(pick_first($b, ['created_at', 'fecha_creacion', 'fecha'], null))) ?></td>
                                        <td><?= h(pick_first($b, ['usuario_nombre'], '—')) ?></td>
                                        <td><?= h(pick_first($b, ['tipo_evento'], '—')) ?></td>
                                        <td><?= h(pick_first($b, ['descripcion'], '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="footer-note">
                    Este documento constituye el acta final de auditoría interna del inventario cíclico de la sucursal indicada.
                    La información mostrada corresponde al cierre consolidado del proceso y podrá utilizarse para impresión,
                    resguardo digital en PDF y exportación administrativa.
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>