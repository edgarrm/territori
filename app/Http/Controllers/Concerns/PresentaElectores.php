<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Elector;
use App\Models\Membership;
use App\Support\Pii;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Presentación compartida de listas de capturados (electores): paginación con
 * filtros (`q`, `secciones[]`, `modos[]`), forma de fila uniforme y reglas de
 * visibilidad de PII. Es el patrón de la lista global de capturados reutilizado
 * por las vistas por entidad (lotería, evento, red, sección).
 */
trait PresentaElectores
{
    /**
     * Catálogo de tipos de captura (modo_captura) para el filtro de tipo.
     * Espejo de los modos válidos en StoreElectorRequest.
     *
     * @var array<string, string>
     */
    protected const MODOS = [
        'enlace_seccional' => 'Enlace Seccional',
        'loteria' => 'Lotería',
        'evento' => 'Evento',
        'red_ciudadana' => 'Red Ciudadana',
    ];

    /**
     * Catálogo de modos como lista `[{valor, etiqueta}]` para los props de filtro.
     *
     * @return array<int, array{valor: string, etiqueta: string}>
     */
    protected function catalogoModos(): array
    {
        return array_map(
            fn (string $valor, string $etiqueta): array => ['valor' => $valor, 'etiqueta' => $etiqueta],
            array_keys(self::MODOS),
            array_values(self::MODOS),
        );
    }

    /**
     * Pagina un query de electores aplicando los filtros opcionales de la
     * request (`q` nombre ILIKE, `secciones[]`, `modos[]`) y transformando cada
     * fila a la forma estándar de capturados. El caller define el scope base
     * (p. ej. `where('loteria_id', ...)`).
     */
    protected function paginarElectores(Builder $query, Request $request, ?Membership $viewer, int $porPagina = 25): LengthAwarePaginator
    {
        $busqueda = trim((string) $request->query('q', ''));

        $secciones = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('secciones', []),
        )));

        $modos = array_values(array_intersect(
            array_map('strval', (array) $request->query('modos', [])),
            array_keys(self::MODOS),
        ));

        return $query
            ->with(['evento:id,nombre', 'redCiudadana:id,nombre,enlace_membership_id', 'seccion:id,numero'])
            ->when(
                $busqueda !== '',
                fn (Builder $q) => $q->where('nombre', 'ILIKE', '%'.$busqueda.'%'),
            )
            ->when(
                $secciones !== [],
                fn (Builder $q) => $q->whereIn('seccion_id', $secciones),
            )
            ->when(
                $modos !== [],
                fn (Builder $q) => $q->whereIn('modo_captura', $modos),
            )
            ->latest()
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Elector $elector): array => $this->filaElector($elector, $viewer));
    }

    /**
     * Forma estándar de fila de capturado. El teléfono se enmascara salvo que
     * el viewer tenga permiso de ver la PII de ese registro.
     *
     * @return array<string, mixed>
     */
    protected function filaElector(Elector $elector, ?Membership $viewer): array
    {
        return [
            'id' => $elector->id,
            'nombre' => $elector->nombre,
            'seccion_id' => $elector->seccion_id,
            'seccion_numero' => $elector->seccion?->numero,
            'modo_captura' => $elector->modo_captura,
            'origen' => $this->origen($elector),
            'telefono' => $this->puedeVerPii($elector, $viewer)
                ? $elector->telefono
                : Pii::enmascararTelefono($elector->telefono),
            'capturado_en' => $elector->created_at?->toIso8601String(),
        ];
    }

    /**
     * Etiqueta legible de cómo se registró el elector: enlace seccional, lotería,
     * evento (con su nombre) o red ciudadana (con su nombre).
     */
    protected function origen(Elector $elector): string
    {
        return match ($elector->modo_captura) {
            'enlace_seccional' => 'Enlace Seccional',
            'loteria' => 'Lotería',
            'evento' => $elector->evento?->nombre !== null
                ? 'Evento: '.$elector->evento->nombre
                : 'Evento',
            'red_ciudadana' => $elector->redCiudadana?->nombre !== null
                ? 'Red: '.$elector->redCiudadana->nombre
                : 'Red ciudadana',
            default => $elector->modo_captura,
        };
    }

    /**
     * Gestión (coordinador/admin) ve la PII completa de todos; el enlace ve la de
     * todos los registros de sus redes; cualquier otro (brigadista) solo la de los
     * electores que él capturó. Caso contrario → enmascarada.
     */
    protected function puedeVerPii(Elector $elector, ?Membership $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (in_array($viewer->rol, ['coordinador', 'admin'], true)) {
            return true;
        }

        // El enlace ve la PII completa de todos los registros de sus redes,
        // sin importar quién los capturó.
        if ($elector->red_ciudadana_id !== null
            && $elector->redCiudadana?->enlace_membership_id === $viewer->id) {
            return true;
        }

        return $elector->membership_id === $viewer->id;
    }
}
