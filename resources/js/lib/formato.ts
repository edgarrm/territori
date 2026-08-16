/** Formato de números es-MX, compartido por Mapa/Sección/Prioridades. */
export function fmt(n: number): string {
    return n.toLocaleString('es-MX');
}

/**
 * Paleta rotativa para series sin color propio (F10: opciones de respuesta de
 * una encuesta, a diferencia de partidos/bloques que sí tienen color
 * institucional). Compartida por Mapa/Sección/Zonas para que la misma opción
 * se vea con el mismo color en las tres vistas.
 */
const PALETA_SERIES = [
    '#2563eb',
    '#16a34a',
    '#f59e0b',
    '#dc2626',
    '#7c3aed',
    '#0d9488',
    '#db2777',
    '#78716c',
] as const;

export function colorPorIndice(indice: number): string {
    return PALETA_SERIES[
        ((indice % PALETA_SERIES.length) + PALETA_SERIES.length) %
            PALETA_SERIES.length
    ];
}

/** Formato de porcentaje a partir de una fracción 0-1 (p.ej. cobertura). */
export function pct(v: number): string {
    return `${Math.round(v * 100)}%`;
}

/**
 * Diferencia de competitividad (bloque propio vs. mejor rival) en la unidad
 * configurada por la campaña: votos absolutos o puntos porcentuales. `null`
 * si el dato correspondiente al modo activo no está disponible.
 */
export function formatoDiferenciaCompetitividad(
    modo: 'votos' | 'porcentaje',
    diferenciaVotos: number | null,
    diferenciaPct: number | null,
): string | null {
    if (modo === 'porcentaje') {
        if (diferenciaPct === null) {
            return null;
        }

        return `${diferenciaPct >= 0 ? '+' : '−'}${Math.abs(diferenciaPct).toFixed(1)} pp`;
    }

    if (diferenciaVotos === null) {
        return null;
    }

    return `${diferenciaVotos >= 0 ? '+' : '−'}${fmt(Math.abs(diferenciaVotos))} votos`;
}
