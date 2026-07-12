/** Formato de números es-MX, compartido por Mapa/Sección/Prioridades. */
export function fmt(n: number): string {
    return n.toLocaleString('es-MX');
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
