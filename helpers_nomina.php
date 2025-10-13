<?php
/* ========================
   Helpers de overrides
======================== */

/**
 * Usa el override si viene numérico; si no, respeta el valor original.
 * Acepta 0.00 como override válido.
 */
function applyOverride($override, float $original): float {
    if ($override === null) return $original;
    if ($override === '')   return $original;
    if (!is_numeric($override)) return $original;
    return (float)$override;
}

/**
 * Obtiene el registro de overrides de la semana.
 * Incluye:
 *  - sueldo/equipos/sims/pospago
 *  - componentes de gerente (dir/esc/prep/pos) y ger_base_override (legacy)
 *  - descuentos y ajuste_neto_extra
 *  - NUEVOS: bono_gerente_override, bono_cordon_override
 *  - metadatos: estado, nota, fuente, creado_en, actualizado_en
 *
 * Devuelve array vacío si no hay registro.
 */
function fetchOverridesSemana(mysqli $conn, int $idUsuario, string $iniISO, string $finISO): array {
    $sql = "
        SELECT
            sueldo_override,
            equipos_override,
            sims_override,
            pospago_override,

            ger_dir_override,
            ger_esc_override,
            ger_prep_override,
            ger_pos_override,
            ger_base_override,

            descuentos_override,
            ajuste_neto_extra,

            -- 🔹 nuevos bonos editables
            bono_gerente_override,
            bono_cordon_override,

            -- metadatos útiles en UI
            estado,
            nota,
            fuente,
            creado_en,
            actualizado_en
        FROM nomina_overrides_semana
        WHERE id_usuario = ? AND semana_inicio = ? AND semana_fin = ?
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("iss", $idUsuario, $iniISO, $finISO);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: [];
}

/**
 * Devuelve los componentes del gerente aplicando overrides.
 *
 * Si hay overrides en dir/esc/prep, se usan tal cual.
 * Si NO los hay pero existe ger_base_override, se reparte proporcionalmente
 * entre (origDir, origEsc, origPrep). El último (prep) ajusta centavos.
 * Siempre respeta ger_pos_override si existe.
 *
 * @return array [$dir, $esc, $prep, $pos, $base] donde $base = dir+esc+prep
 */
function computeGerenteConOverrides(
    float $origDir, float $origEsc, float $origPrep, float $origPos,
    array $ov
): array {
    $hasParts = isset($ov['ger_dir_override']) || isset($ov['ger_esc_override']) || isset($ov['ger_prep_override']);

    if ($hasParts) {
        $dir  = applyOverride($ov['ger_dir_override']  ?? null, $origDir);
        $esc  = applyOverride($ov['ger_esc_override']  ?? null, $origEsc);
        $prep = applyOverride($ov['ger_prep_override'] ?? null, $origPrep);
    } else {
        // Reparto proporcional si solo viene ger_base_override
        $baseOv = $ov['ger_base_override'] ?? null;
        if ($baseOv !== null && $baseOv !== '' && is_numeric($baseOv)) {
            $baseOv = (float)$baseOv;
            $s = $origDir + $origEsc + $origPrep;
            if ($s > 0.0) {
                $dir  = round($baseOv * ($origDir  / $s), 2);
                $esc  = round($baseOv * ($origEsc  / $s), 2);
                // El último lo ajustamos para evitar centavos perdidos por redondeo
                $prep = round($baseOv - $dir - $esc, 2);
            } else {
                // Sin referencia: manda todo a DirG.
                $dir = $baseOv; $esc = 0.0; $prep = 0.0;
            }
        } else {
            $dir = $origDir; $esc = $origEsc; $prep = $origPrep;
        }
    }

    $pos  = applyOverride($ov['ger_pos_override'] ?? null, $origPos);
    $base = $dir + $esc + $prep;

    return [$dir, $esc, $prep, $pos, $base];
}
