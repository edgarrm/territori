/** Formato de números es-MX, compartido por Mapa/Sección/Prioridades. */
export function fmt(n: number): string {
    return n.toLocaleString('es-MX');
}

/** Formato de porcentaje a partir de una fracción 0-1 (p.ej. cobertura). */
export function pct(v: number): string {
    return `${Math.round(v * 100)}%`;
}
