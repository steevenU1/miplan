<?php
// guard_inventario_ciclico.php
// Guard reutilizable para bloquear operaciones durante inventario cíclico.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/inventario_ciclico_helpers.php';

if (!function_exists('icc_roles_admin_like')) {
    function icc_roles_admin_like(): array
    {
        return ['Admin', 'Administrador', 'Sistemas', 'Logistica', 'Auditor', 'GerenteZona'];
    }
}

if (!function_exists('icc_usuario_es_admin_like')) {
    function icc_usuario_es_admin_like(?string $rol = null): bool
    {
        $rol = $rol ?? ($_SESSION['rol'] ?? '');
        return in_array((string)$rol, icc_roles_admin_like(), true);
    }
}

if (!function_exists('icc_obtener_id_sucursal_contexto')) {
    function icc_obtener_id_sucursal_contexto(?int $idSucursal = null): int
    {
        if ($idSucursal !== null && $idSucursal > 0) {
            return $idSucursal;
        }

        return (int)($_SESSION['id_sucursal'] ?? 0);
    }
}

if (!function_exists('icc_operaciones_config')) {
    function icc_operaciones_config(): array
    {
        return [
            // Inventario visible de sucursal
            'inventario_ver' => [
                'requiere_previo'    => true,
                'requiere_operativo' => true,
                'label'              => 'visualización de inventario',
            ],

            // Operación comercial / sensible
            'venta_crear' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'registro de ventas',
            ],
            'traspaso_crear' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'generación de traspasos',
            ],
            'traspaso_recibir' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'recepción de traspasos',
            ],
            'corte_crear' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'cortes / cierres operativos',
            ],
            'cobro_crear' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'cobros operativos',
            ],
            'movimiento_operativo' => [
                'requiere_previo'    => false,
                'requiere_operativo' => true,
                'label'              => 'movimientos operativos',
            ],
        ];
    }
}

if (!function_exists('icc_describir_bloqueo')) {
    function icc_describir_bloqueo(string $tipo, string $labelOperacion = 'esta operación'): array
    {
        if ($tipo === 'previo') {
            return [
                'titulo'  => 'Inventario bloqueado temporalmente',
                'mensaje' => "La sucursal tiene un inventario cíclico programado. Por control preventivo, la {$labelOperacion} está bloqueada desde un día antes y permanecerá restringida hasta que el inventario sea cerrado.",
            ];
        }

        return [
            'titulo'  => 'Operación bloqueada por inventario cíclico',
            'mensaje' => "La sucursal tiene un inventario cíclico activo y aún no ha sido cerrado/revisado. Por seguridad operativa, la {$labelOperacion} no está disponible en este momento.",
        ];
    }
}

if (!function_exists('icc_evaluar_bloqueo_operacion')) {
    /**
     * Evalúa si una operación está bloqueada para una sucursal.
     *
     * @param string $operacion  inventario_ver|venta_crear|traspaso_crear|traspaso_recibir|corte_crear|cobro_crear|movimiento_operativo
     * @param array  $opts
     *   - id_sucursal (int|null)
     *   - permitir_admin_like (bool) -> solo para vistas de consulta/control
     *   - rol (string|null)
     *
     * @return array
     */
    function icc_evaluar_bloqueo_operacion(string $operacion, array $opts = []): array
    {
        $cfgAll = icc_operaciones_config();
        $cfg    = $cfgAll[$operacion] ?? null;

        if (!$cfg) {
            return [
                'ok' => false,
                'bloqueado' => false,
                'error' => 'Operación de inventario cíclico no reconocida.',
            ];
        }

        $idSucursal        = icc_obtener_id_sucursal_contexto($opts['id_sucursal'] ?? null);
        $rol               = $opts['rol'] ?? ($_SESSION['rol'] ?? '');
        $permitirAdminLike = !empty($opts['permitir_admin_like']);

        if ($idSucursal <= 0) {
            return [
                'ok' => false,
                'bloqueado' => false,
                'error' => 'No fue posible determinar la sucursal para validar el bloqueo de inventario cíclico.',
            ];
        }

        // Excepción controlada solo para vistas administrativas de consulta
        if ($permitirAdminLike && icc_usuario_es_admin_like($rol)) {
            return [
                'ok' => true,
                'bloqueado' => false,
                'tipo_bloqueo' => null,
                'operacion' => $operacion,
                'id_sucursal' => $idSucursal,
                'permitido_por_admin_like' => true,
            ];
        }

        $labelOperacion = $cfg['label'] ?? 'operación';

        // 1) Bloqueo previo
        if (!empty($cfg['requiere_previo']) && function_exists('tieneBloqueoPrevioInventarioCiclico')) {
            $hayPrevio = (bool)tieneBloqueoPrevioInventarioCiclico($idSucursal);
            if ($hayPrevio) {
                $desc = icc_describir_bloqueo('previo', $labelOperacion);
                return [
                    'ok' => true,
                    'bloqueado' => true,
                    'tipo_bloqueo' => 'previo',
                    'operacion' => $operacion,
                    'id_sucursal' => $idSucursal,
                    'titulo' => $desc['titulo'],
                    'mensaje' => $desc['mensaje'],
                ];
            }
        }

        // 2) Bloqueo operativo
        if (!empty($cfg['requiere_operativo']) && function_exists('tieneBloqueoOperativoInventarioCiclico')) {
            $hayOperativo = (bool)tieneBloqueoOperativoInventarioCiclico($idSucursal);
            if ($hayOperativo) {
                $desc = icc_describir_bloqueo('operativo', $labelOperacion);

                $inventario = null;
                if (function_exists('obtenerInventarioCiclicoActivoHoy')) {
                    $inventario = obtenerInventarioCiclicoActivoHoy($idSucursal);
                }

                return [
                    'ok' => true,
                    'bloqueado' => true,
                    'tipo_bloqueo' => 'operativo',
                    'operacion' => $operacion,
                    'id_sucursal' => $idSucursal,
                    'titulo' => $desc['titulo'],
                    'mensaje' => $desc['mensaje'],
                    'inventario' => $inventario,
                ];
            }
        }

        return [
            'ok' => true,
            'bloqueado' => false,
            'tipo_bloqueo' => null,
            'operacion' => $operacion,
            'id_sucursal' => $idSucursal,
        ];
    }
}

if (!function_exists('icc_render_alerta_bloqueo_html')) {
    function icc_render_alerta_bloqueo_html(array $eval, string $extra = ''): void
    {
        $titulo  = htmlspecialchars($eval['titulo'] ?? 'Operación bloqueada', ENT_QUOTES, 'UTF-8');
        $mensaje = htmlspecialchars($eval['mensaje'] ?? 'La operación no está disponible por inventario cíclico.', ENT_QUOTES, 'UTF-8');
        $tipo    = (string)($eval['tipo_bloqueo'] ?? '');
        $inventario = $eval['inventario'] ?? null;

        $subtitulo = $tipo === 'previo'
            ? 'Bloqueo preventivo por inventario próximo'
            : 'Bloqueo operativo por inventario en curso';

        $fechaProgramada = '';
        if (is_array($inventario)) {
            $fechaProgramada =
                $inventario['fecha_programada'] ??
                $inventario['fecha_inventario'] ??
                $inventario['fecha'] ??
                '';
        }

        ?>
        <!DOCTYPE html>
        <html lang="es" data-bs-theme="light">
        <head>
            <meta charset="UTF-8">
            <title>Bloqueo por Inventario Cíclico</title>
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
            <style>
                :root{
                    --bg:#f6f7fb;
                    --surface:#ffffff;
                    --text:#101828;
                    --muted:#667085;
                    --warning-bg:#fff7e8;
                    --warning-bd:#f7d9a7;
                    --warning-tx:#9a6700;
                    --danger-bg:#fff1f2;
                    --danger-bd:#fecdd3;
                    --danger-tx:#be123c;
                }
                body{
                    background:var(--bg);
                    color:var(--text);
                }
                .icc-page{
                    min-height:calc(100vh - 80px);
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    padding:24px 0 40px;
                }
                .icc-card{
                    background:var(--surface);
                    border:1px solid rgba(16,24,40,.06);
                    border-radius:24px;
                    box-shadow:0 18px 45px rgba(16,24,40,.08);
                    overflow:hidden;
                }
                .icc-head{
                    padding:24px 24px 18px;
                    border-bottom:1px solid rgba(16,24,40,.06);
                    background:linear-gradient(180deg,#ffffff 0%,#fafbff 100%);
                }
                .icc-badge{
                    display:inline-flex;
                    align-items:center;
                    gap:.45rem;
                    padding:.4rem .8rem;
                    border-radius:999px;
                    font-size:.9rem;
                    font-weight:700;
                }
                .icc-badge-previo{
                    background:var(--warning-bg);
                    border:1px solid var(--warning-bd);
                    color:var(--warning-tx);
                }
                .icc-badge-operativo{
                    background:var(--danger-bg);
                    border:1px solid var(--danger-bd);
                    color:var(--danger-tx);
                }
                .icc-title{
                    font-weight:800;
                    letter-spacing:.2px;
                    margin:14px 0 6px;
                    font-size:1.55rem;
                }
                .icc-subtitle{
                    color:var(--muted);
                    margin:0;
                    font-size:.98rem;
                }
                .icc-body{
                    padding:24px;
                }
                .icc-icon-wrap{
                    width:74px;
                    height:74px;
                    border-radius:20px;
                    display:grid;
                    place-items:center;
                    flex:0 0 74px;
                    font-size:2rem;
                }
                .icc-icon-previo{
                    background:var(--warning-bg);
                    color:var(--warning-tx);
                    border:1px solid var(--warning-bd);
                }
                .icc-icon-operativo{
                    background:var(--danger-bg);
                    color:var(--danger-tx);
                    border:1px solid var(--danger-bd);
                }
                .icc-message{
                    font-size:1.02rem;
                    line-height:1.65;
                    margin:0;
                }
                .icc-meta{
                    margin-top:18px;
                    background:#f8fafc;
                    border:1px solid #e5e7eb;
                    border-radius:16px;
                    padding:14px 16px;
                }
                .icc-meta .label{
                    color:var(--muted);
                    font-size:.88rem;
                    margin-bottom:4px;
                }
                .icc-meta .value{
                    font-weight:700;
                }
                .icc-actions{
                    margin-top:22px;
                    display:flex;
                    flex-wrap:wrap;
                    gap:10px;
                }
                .icc-note{
                    margin-top:18px;
                    color:var(--muted);
                    font-size:.93rem;
                }
            </style>
        </head>
        <body>
        <?php
        $navbarPath = __DIR__ . '/navbar.php';
        if (is_file($navbarPath)) {
            include $navbarPath;
        }
        ?>

        <div class="container icc-page">
            <div class="icc-card w-100" style="max-width: 880px;">
                <div class="icc-head">
                    <span class="icc-badge <?= $tipo === 'previo' ? 'icc-badge-previo' : 'icc-badge-operativo' ?>">
                        <i class="bi <?= $tipo === 'previo' ? 'bi-calendar-event' : 'bi-lock-fill' ?>"></i>
                        <?= htmlspecialchars($subtitulo, ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <h1 class="icc-title"><?= $titulo ?></h1>
                    <p class="icc-subtitle">
                        La sucursal tiene una restricción activa relacionada con inventario cíclico.
                    </p>
                </div>

                <div class="icc-body">
                    <div class="d-flex gap-3 align-items-start flex-column flex-md-row">
                        <div class="icc-icon-wrap <?= $tipo === 'previo' ? 'icc-icon-previo' : 'icc-icon-operativo' ?>">
                            <i class="bi <?= $tipo === 'previo' ? 'bi-shield-exclamation' : 'bi-exclamation-octagon' ?>"></i>
                        </div>

                        <div class="flex-grow-1">
                            <p class="icc-message"><?= $mensaje ?></p>

                            <?php if ($fechaProgramada !== ''): ?>
                                <div class="icc-meta">
                                    <div class="label">Fecha relacionada con el inventario</div>
                                    <div class="value"><?= htmlspecialchars((string)$fechaProgramada, ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($extra)): ?>
                                <div class="icc-note"><?= $extra ?></div>
                            <?php else: ?>
                                <div class="icc-note">
                                    Cuando el inventario cíclico sea cerrado o revisado, la operación volverá a estar disponible.
                                </div>
                            <?php endif; ?>

                            <div class="icc-actions">
                                <a href="javascript:history.back()" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </a>
                                <a href="dashboard_unificado.php" class="btn btn-outline-primary">
                                    <i class="bi bi-house-door"></i> Ir al Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </body>
        </html>
        <?php
    }
}

if (!function_exists('icc_guard_html')) {
    function icc_guard_html(string $operacion, array $opts = []): void
    {
        $eval = icc_evaluar_bloqueo_operacion($operacion, $opts);

        if (!empty($eval['error'])) {
            http_response_code(500);
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>Error</title>
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body style="background:#f6f7fb;">
                <?php
                $navbarPath = __DIR__ . '/navbar.php';
                if (is_file($navbarPath)) {
                    include $navbarPath;
                }
                ?>
                <div class="container py-4">
                    <div class="alert alert-danger shadow-sm">
                        <strong>Error:</strong>
                        <?= htmlspecialchars($eval['error'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }

        if (!empty($eval['bloqueado'])) {
            http_response_code(423);
            icc_render_alerta_bloqueo_html($eval);
            exit;
        }
    }
}

if (!function_exists('icc_guard_json')) {
    /**
     * Corta la ejecución devolviendo JSON para endpoints / fetch / ajax.
     */
    function icc_guard_json(string $operacion, array $opts = []): void
    {
        $eval = icc_evaluar_bloqueo_operacion($operacion, $opts);

        header('Content-Type: application/json; charset=utf-8');

        if (!empty($eval['error'])) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'bloqueado' => false,
                'error' => $eval['error'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!empty($eval['bloqueado'])) {
            http_response_code(423);
            echo json_encode([
                'ok' => false,
                'bloqueado' => true,
                'tipo_bloqueo' => $eval['tipo_bloqueo'] ?? null,
                'titulo' => $eval['titulo'] ?? 'Operación bloqueada',
                'mensaje' => $eval['mensaje'] ?? 'Operación bloqueada por inventario cíclico.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}