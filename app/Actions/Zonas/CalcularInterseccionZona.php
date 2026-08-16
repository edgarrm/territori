<?php

namespace App\Actions\Zonas;

use App\Models\Zona;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la intersección geométrica de una `Zona` (F6) contra todas las
 * secciones INE, y reemplaza completo `zona_seccion` para esa zona (borra e
 * inserta, nunca acumula). Filtra primero con `ST_Intersects` antes de
 * `ST_Area(ST_Intersection(...))` — computar la intersección exacta de
 * geometrías que ni se tocan es trabajo desperdiciado sobre cientos de
 * secciones.
 *
 * `secciones` es un catálogo global (sin `tenant_id`), así que la
 * intersección no se restringe al municipio del tenant — mismo criterio de
 * F6 de no restringir la geometría de la zona.
 */
class CalcularInterseccionZona
{
    public function handle(Zona $zona): void
    {
        DB::table('zona_seccion')->where('zona_id', $zona->id)->delete();

        // Consulta la BD directo (no `$zona->geom` en memoria): CrearZona y
        // ActualizarZona escriben `geom` con SQL crudo, sin refrescar el
        // atributo del modelo en memoria (ver ValidaGeometriaZona::guardarGeom).
        // Una zona sin geom propia (nivel intermedio de jerarquía, ej.
        // "mundial"/"continente") no tiene nada que intersectar.
        $tieneGeom = DB::table('zonas')->where('id', $zona->id)->whereNotNull('geom')->exists();

        if (! $tieneGeom) {
            return;
        }

        DB::insert(
            'insert into zona_seccion (tenant_id, zona_id, seccion_id, pct_interseccion, calculado_en)
             select ?, z.id, s.id,
                    ST_Area(ST_Intersection(z.geom, s.geom)) / ST_Area(s.geom),
                    now()
             from zonas z
             join secciones s on ST_Intersects(z.geom, s.geom)
             where z.id = ? and s.geom is not null',
            [$zona->tenant_id, $zona->id]
        );
    }
}
