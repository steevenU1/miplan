<?php
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

if (!function_exists('xv')) {
    function xv($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('fmt_excel_fecha')) {
    function fmt_excel_fecha(?string $fecha): string
    {
        if (!$fecha) return '';
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y H:i', $ts) : (string)$fecha;
    }
}

if (!function_exists('fmt_excel_fecha_corta')) {
    function fmt_excel_fecha_corta(?string $fecha): string
    {
        if (!$fecha) return '';
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y', $ts) : (string)$fecha;
    }
}

if (!function_exists('pick_first_excel')) {
    function pick_first_excel(array $row, array $keys, $default = '')
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                return $row[$k];
            }
        }
        return $default;
    }
}

if (!function_exists('es_serializado_excel')) {
    function es_serializado_excel(array $row): bool
    {
        $identificador = trim((string)pick_first_excel($row, ['identificador'], ''));
        return $identificador !== '';
    }
}

if (!function_exists('desc_row_excel')) {
    function desc_row_excel(array $row): string
    {
        return (string)pick_first_excel($row, ['descripcion_snapshot', 'descripcion', 'codigo'], '');
    }
}

if (!function_exists('id_row_excel')) {
    function id_row_excel(array $row): string
    {
        return (string)pick_first_excel($row, ['identificador'], '');
    }
}

if (!function_exists('sec_row_excel')) {
    function sec_row_excel(array $row): string
    {
        return (string)pick_first_excel($row, ['seccion'], '');
    }
}

if (!function_exists('cant_sistema_excel')) {
    function cant_sistema_excel(array $row): int
    {
        return (int)pick_first_excel($row, ['cantidad_esperada', 'cantidad_snapshot', 'cantidad_sistema'], 0);
    }
}

if (!function_exists('cant_contada_excel')) {
    function cant_contada_excel(array $row): int
    {
        return (int)pick_first_excel($row, ['cantidad', 'cantidad_contada'], 0);
    }
}

if (!function_exists('dif_excel')) {
    function dif_excel(array $row): int
    {
        if (array_key_exists('diferencia', $row) && $row['diferencia'] !== null && $row['diferencia'] !== '') {
            return (int)$row['diferencia'];
        }
        return cant_contada_excel($row) - cant_sistema_excel($row);
    }
}

if (!function_exists('xml_cell')) {
    function xml_cell($value, string $type = 'String', ?string $style = null): string
    {
        $styleAttr = $style ? ' ss:StyleID="' . xv($style) . '"' : '';
        return '<Cell' . $styleAttr . '><Data ss:Type="' . xv($type) . '">' . xv($value) . '</Data></Cell>';
    }
}

if (!function_exists('xml_row')) {
    function xml_row(array $cells): string
    {
        return '<Row>' . implode('', $cells) . '</Row>';
    }
}

if (!function_exists('xml_sheet')) {
    function xml_sheet(string $name, array $rows): string
    {
        return '<Worksheet ss:Name="' . xv($name) . '"><Table>' . implode('', $rows) . '</Table></Worksheet>';
    }
}

$idInventario = (int)($_GET['id'] ?? 0);
if ($idInventario <= 0) {
    die('Inventario inválido.');
}

$acta = icc_obtener_acta_auditoria($idInventario);
if (empty($acta['ok']) || empty($acta['inventario'])) {
    die(xv($acta['msg'] ?? 'No se pudo cargar el acta.'));
}

$inventario     = $acta['inventario'];
$resumen        = $acta['resumen'] ?? [];
$serializados   = $acta['serializados'] ?? [];
$noSerializados = $acta['no_serializados'] ?? [];
$firmas         = $acta['firmas'] ?? [];
$bitacora       = $acta['bitacora'] ?? [];

if (!$esAdminLike && (int)($inventario['id_sucursal'] ?? 0) !== $idSucursal) {
    http_response_code(403);
    die('No puedes exportar el acta de otra sucursal.');
}

$estatusPermitidos = ['EnConciliacion', 'Cerrado', 'Revisado'];
if (!in_array((string)($inventario['estatus'] ?? ''), $estatusPermitidos, true)) {
    die('Esta auditoría aún no se encuentra en una etapa válida para exportar.');
}

$fechaCierre = pick_first_excel($inventario, ['fecha_cierre'], null);
$fechaInicio = pick_first_excel($inventario, ['fecha_inicio', 'created_at', 'fecha_creacion'], null);

$folio = 'IC-' . str_pad((string)(int)$inventario['id'], 6, '0', STR_PAD_LEFT);

$firmadoDigitalmente = (int)($firmas['firmado_digitalmente'] ?? 0) === 1 ? 'Sí' : 'No';
$tokenFirma = (string)($firmas['token_firma'] ?? '');
$auditorNombre = (string)($firmas['auditor_nombre'] ?? '');
$gerenteNombre = (string)($firmas['gerente_nombre'] ?? '');
$fechaFirma = (string)($firmas['fecha_firma'] ?? '');
$leyendaFirma = (string)($firmas['leyenda_firma'] ?? 'Firmado digitalmente con contraseña');

/* =========================================================
   HOJA 1: RESUMEN
   ========================================================= */
$rowsResumen = [];

$rowsResumen[] = xml_row([
    xml_cell('Acta de Auditoría Interna', 'String', 'sTitle')
]);

$rowsResumen[] = xml_row([
    xml_cell('', 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Folio', 'String', 'sHeader'),
    xml_cell($folio, 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('ID Inventario', 'String', 'sHeader'),
    xml_cell((int)$inventario['id'], 'Number')
]);

$rowsResumen[] = xml_row([
    xml_cell('Sucursal', 'String', 'sHeader'),
    xml_cell((string)($inventario['sucursal_nombre'] ?? ''), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Fecha programada', 'String', 'sHeader'),
    xml_cell(fmt_excel_fecha_corta($inventario['fecha_programada'] ?? null), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Estatus', 'String', 'sHeader'),
    xml_cell((string)($inventario['estatus'] ?? ''), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Creado por', 'String', 'sHeader'),
    xml_cell((string)($inventario['creado_por_nombre'] ?? ''), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Iniciado por', 'String', 'sHeader'),
    xml_cell((string)($inventario['iniciado_por_nombre'] ?? ''), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Fecha inicio', 'String', 'sHeader'),
    xml_cell(fmt_excel_fecha($fechaInicio), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Fecha cierre', 'String', 'sHeader'),
    xml_cell(fmt_excel_fecha($fechaCierre), 'String')
]);

$rowsResumen[] = xml_row([
    xml_cell('Observaciones', 'String', 'sHeader'),
    xml_cell((string)($inventario['observaciones'] ?? ''), 'String')
]);

$rowsResumen[] = xml_row([xml_cell('', 'String')]);

$rowsResumen[] = xml_row([
    xml_cell('Resumen general', 'String', 'sSubTitle')
]);

$rowsResumen[] = xml_row([
    xml_cell('Indicador', 'String', 'sHeader'),
    xml_cell('Valor', 'String', 'sHeader')
]);

$resumenMap = [
    'Snapshot total' => (int)($resumen['snapshot_total'] ?? 0),
    'Capturas' => (int)($resumen['capturas_total'] ?? 0),
    'Correctos' => (int)($resumen['correctos'] ?? 0),
    'Faltantes' => (int)($resumen['faltantes'] ?? 0),
    'Sobrantes' => (int)($resumen['sobrantes'] ?? 0),
    'Existe en otra sucursal' => (int)($resumen['otra_sucursal'] ?? 0),
    'Existe pero no disponible' => (int)($resumen['no_disponible'] ?? 0),
    'Diferencias por cantidad' => (int)($resumen['diferencia_cantidad'] ?? 0),
];

foreach ($resumenMap as $label => $value) {
    $rowsResumen[] = xml_row([
        xml_cell($label, 'String'),
        xml_cell($value, 'Number')
    ]);
}

$rowsResumen[] = xml_row([xml_cell('', 'String')]);

$rowsResumen[] = xml_row([
    xml_cell('Firmas digitales', 'String', 'sSubTitle')
]);

$rowsResumen[] = xml_row([
    xml_cell('Campo', 'String', 'sHeader'),
    xml_cell('Valor', 'String', 'sHeader')
]);

$firmaMap = [
    'Firmado digitalmente' => $firmadoDigitalmente,
    'Auditor' => $auditorNombre,
    'Gerente / Responsable' => $gerenteNombre,
    'Fecha de firma' => fmt_excel_fecha($fechaFirma ?: null),
    'Token de firma' => $tokenFirma,
    'Leyenda' => $leyendaFirma,
];

foreach ($firmaMap as $label => $value) {
    $rowsResumen[] = xml_row([
        xml_cell($label, 'String'),
        xml_cell($value, 'String')
    ]);
}

if (!empty($bitacora)) {
    $rowsResumen[] = xml_row([xml_cell('', 'String')]);
    $rowsResumen[] = xml_row([
        xml_cell('Bitácora reciente', 'String', 'sSubTitle')
    ]);
    $rowsResumen[] = xml_row([
        xml_cell('Fecha', 'String', 'sHeader'),
        xml_cell('Usuario', 'String', 'sHeader'),
        xml_cell('Evento', 'String', 'sHeader'),
        xml_cell('Descripción', 'String', 'sHeader')
    ]);

    foreach ($bitacora as $b) {
        $rowsResumen[] = xml_row([
            xml_cell(fmt_excel_fecha(pick_first_excel($b, ['created_at', 'fecha_creacion', 'fecha'], null)), 'String'),
            xml_cell((string)pick_first_excel($b, ['usuario_nombre'], ''), 'String'),
            xml_cell((string)pick_first_excel($b, ['tipo_evento'], ''), 'String'),
            xml_cell((string)pick_first_excel($b, ['descripcion'], ''), 'String'),
        ]);
    }
}

/* =========================================================
   HOJA 2: SERIALIZADOS
   ========================================================= */
$rowsSerializados = [];
$rowsSerializados[] = xml_row([
    xml_cell('Clasificación', 'String', 'sHeader'),
    xml_cell('Sección', 'String', 'sHeader'),
    xml_cell('Descripción', 'String', 'sHeader'),
    xml_cell('Identificador', 'String', 'sHeader'),
    xml_cell('Cantidad sistema', 'String', 'sHeader'),
    xml_cell('Cantidad contada', 'String', 'sHeader'),
    xml_cell('Diferencia', 'String', 'sHeader'),
    xml_cell('Resultado', 'String', 'sHeader'),
    xml_cell('Sucursal sistema', 'String', 'sHeader'),
    xml_cell('Estatus sistema', 'String', 'sHeader'),
    xml_cell('Observación', 'String', 'sHeader')
]);

$agregarSerializados = function(array $rows, string $clasificacion, string $resultadoDefault = '') use (&$rowsSerializados) {
    foreach ($rows as $r) {
        if (!es_serializado_excel($r)) {
            continue;
        }

        $cantidadSistema = cant_sistema_excel($r);
        $cantidadContada = cant_contada_excel($r);

        if ($clasificacion === 'Faltante' && $cantidadContada === 0) {
            $cantidadContada = 0;
        }

        $diferencia = $cantidadContada - $cantidadSistema;

        $rowsSerializados[] = xml_row([
            xml_cell($clasificacion, 'String'),
            xml_cell(sec_row_excel($r), 'String'),
            xml_cell(desc_row_excel($r), 'String'),
            xml_cell(id_row_excel($r), 'String'),
            xml_cell($cantidadSistema, 'Number'),
            xml_cell($cantidadContada, 'Number'),
            xml_cell($diferencia, 'Number'),
            xml_cell((string)pick_first_excel($r, ['resultado_validacion'], $resultadoDefault), 'String'),
            xml_cell((string)pick_first_excel($r, ['id_sucursal_sistema', 'sucursal_sistema_nombre'], ''), 'String'),
            xml_cell((string)pick_first_excel($r, ['estatus_sistema'], ''), 'String'),
            xml_cell((string)pick_first_excel($r, ['observacion_sistema'], ''), 'String'),
        ]);
    }
};

$agregarSerializados($serializados['correctos'] ?? [], 'Correcto', 'ok');
$agregarSerializados($serializados['faltantes'] ?? [], 'Faltante', 'faltante');
$agregarSerializados($serializados['sobrantes'] ?? [], 'Sobrante', 'no_existe_en_sistema');
$agregarSerializados($serializados['otra_sucursal'] ?? [], 'Otra sucursal', 'existe_en_otra_sucursal');
$agregarSerializados($serializados['no_disponible'] ?? [], 'No disponible', 'existe_pero_no_disponible');

if (count($rowsSerializados) === 1) {
    $rowsSerializados[] = xml_row([
        xml_cell('Sin registros', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
    ]);
}

/* =========================================================
   HOJA 3: NO SERIALIZADOS
   ========================================================= */
$rowsNoSerializados = [];
$rowsNoSerializados[] = xml_row([
    xml_cell('Clasificación', 'String', 'sHeader'),
    xml_cell('Sección', 'String', 'sHeader'),
    xml_cell('Descripción', 'String', 'sHeader'),
    xml_cell('Cantidad sistema', 'String', 'sHeader'),
    xml_cell('Cantidad contada', 'String', 'sHeader'),
    xml_cell('Diferencia', 'String', 'sHeader'),
    xml_cell('Resultado', 'String', 'sHeader'),
    xml_cell('Estatus sistema', 'String', 'sHeader'),
    xml_cell('Observación', 'String', 'sHeader')
]);

foreach (($noSerializados['diferencias'] ?? []) as $r) {
    if (es_serializado_excel($r)) {
        continue;
    }

    $rowsNoSerializados[] = xml_row([
        xml_cell('Diferencia cantidad', 'String'),
        xml_cell(sec_row_excel($r), 'String'),
        xml_cell(desc_row_excel($r), 'String'),
        xml_cell(cant_sistema_excel($r), 'Number'),
        xml_cell(cant_contada_excel($r), 'Number'),
        xml_cell(dif_excel($r), 'Number'),
        xml_cell((string)pick_first_excel($r, ['resultado_validacion'], 'diferencia_cantidad'), 'String'),
        xml_cell((string)pick_first_excel($r, ['estatus_sistema'], ''), 'String'),
        xml_cell((string)pick_first_excel($r, ['observacion_sistema'], ''), 'String'),
    ]);
}

foreach (($serializados['faltantes'] ?? []) as $r) {
    if (es_serializado_excel($r)) {
        continue;
    }

    $cantidadSistema = cant_sistema_excel($r);

    $rowsNoSerializados[] = xml_row([
        xml_cell('Faltante', 'String'),
        xml_cell(sec_row_excel($r), 'String'),
        xml_cell(desc_row_excel($r), 'String'),
        xml_cell($cantidadSistema, 'Number'),
        xml_cell(0, 'Number'),
        xml_cell(0 - $cantidadSistema, 'Number'),
        xml_cell('faltante', 'String'),
        xml_cell((string)pick_first_excel($r, ['estatus_sistema'], ''), 'String'),
        xml_cell((string)pick_first_excel($r, ['observacion_sistema'], ''), 'String'),
    ]);
}

if (count($rowsNoSerializados) === 1) {
    $rowsNoSerializados[] = xml_row([
        xml_cell('Sin registros', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
        xml_cell('', 'String'),
    ]);
}

/* =========================================================
   SALIDA XML EXCEL
   ========================================================= */
// Limpiar texto para nombre de archivo
function limpiar_nombre_archivo($texto) {
    $texto = trim($texto);
    $texto = preg_replace('/[^\w\s-]/u', '', $texto); // quita caracteres raros
    $texto = preg_replace('/\s+/', '_', $texto); // espacios → _
    return $texto;
}

$sucursalNombre = limpiar_nombre_archivo($inventario['sucursal_nombre'] ?? 'Sucursal');
$fechaNombre    = date('d-m-Y', strtotime($inventario['fecha_programada'] ?? date('Y-m-d')));

$filename = "Inventario_Ciclico_{$sucursalNombre}_{$fechaNombre}.xls";

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
    <Styles>
        <Style ss:ID="Default" ss:Name="Normal">
            <Alignment ss:Vertical="Center"/>
            <Borders/>
            <Font ss:FontName="Calibri" ss:Size="11"/>
            <Interior/>
            <NumberFormat/>
            <Protection/>
        </Style>
        <Style ss:ID="sTitle">
            <Font ss:Bold="1" ss:Size="16"/>
        </Style>
        <Style ss:ID="sSubTitle">
            <Font ss:Bold="1" ss:Size="12"/>
        </Style>
        <Style ss:ID="sHeader">
            <Font ss:Bold="1"/>
            <Interior ss:Color="#DCE6F1" ss:Pattern="Solid"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
                <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
                <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
            </Borders>
        </Style>
    </Styles>

    <?= xml_sheet('Resumen', $rowsResumen) . "\n" ?>
    <?= xml_sheet('Serializados', $rowsSerializados) . "\n" ?>
    <?= xml_sheet('No_serializados', $rowsNoSerializados) . "\n" ?>
</Workbook>