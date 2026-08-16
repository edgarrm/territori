<?php

namespace App\Actions\Zonas\Concerns;

use App\Models\Zona;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validación y persistencia de la geometría de una Zona: recibe GeoJSON
 * (RFC 7946) crudo, valida SRID 4326 + ST_IsValid, y la escribe con SQL crudo
 * (ST_GeomFromGeoJSON + ST_Multi), mismo patrón que CartografiaSeeder. El
 * cast `MultiPolygon` del modelo solo se usa para lectura: el paquete
 * eloquent-spatial exige que el `type` del GeoJSON coincida exactamente con
 * la clase del cast, y el dibujo/import de un solo polígono llega como
 * "Polygon", no "MultiPolygon".
 */
trait ValidaGeometriaZona
{
    /**
     * @param  array<string, mixed>|null  $geom
     *
     * @throws ValidationException
     */
    private function validarGeom(?array $geom): void
    {
        if ($geom === null) {
            return;
        }

        $this->validarSrid($geom);

        $valida = DB::selectOne(
            'select ST_IsValid(ST_GeomFromGeoJSON(?)) as valida',
            [json_encode($geom)]
        )->valida;

        if (! $valida) {
            throw ValidationException::withMessages([
                'geom' => 'La geometría no es válida.',
            ]);
        }
    }

    /**
     * RFC 7946: un GeoJSON sin `crs` se asume siempre WGS84 (4326). Si trae
     * `crs` explícito (convención legada que suelen incluir GDAL/ogr2ogr),
     * debe apuntar a 4326/CRS84; cualquier otro SRID se rechaza.
     *
     * @param  array<string, mixed>  $geom
     *
     * @throws ValidationException
     */
    private function validarSrid(array $geom): void
    {
        $nombre = $geom['crs']['properties']['name'] ?? null;

        if ($nombre !== null && ! str_contains((string) $nombre, '4326') && ! str_contains((string) $nombre, 'CRS84')) {
            throw ValidationException::withMessages([
                'geom' => 'El GeoJSON debe estar en SRID 4326.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $geom
     */
    private function guardarGeom(Zona $zona, ?array $geom): void
    {
        if ($geom === null) {
            DB::table('zonas')->where('id', $zona->id)->update(['geom' => null]);

            return;
        }

        DB::statement(
            'update zonas set geom = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) where id = ?',
            [json_encode($geom), $zona->id]
        );
    }
}
