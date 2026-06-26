<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CartografiaSeeder extends Seeder
{
    /**
     * Ingiere los GeoJSON 4326 de un estado (entidad.geojson, municipios.geojson,
     * secciones.geojson) y hace upsert a PostGIS. No reproyecta: el GeoJSON debe
     * venir ya en 4326 (preparacion local, etapa 1 del plan).
     */
    public function run(string $directorio): void
    {
        $entidadId = $this->cargarEntidad("{$directorio}/entidad.geojson");
        $municipioIdPorClave = $this->cargarMunicipios("{$directorio}/municipios.geojson", $entidadId);
        $resumenSecciones = $this->cargarSecciones("{$directorio}/secciones.geojson", $municipioIdPorClave);

        $this->reportar([
            'entidades' => 1,
            'municipios' => count($municipioIdPorClave),
            'secciones' => $resumenSecciones['insertadas'],
            'secciones_invalidas' => $resumenSecciones['invalidas'],
        ]);
    }

    private function cargarEntidad(string $ruta): int
    {
        $feature = $this->leerFeatures($ruta)[0];

        DB::statement(
            'insert into entidades (clave, nombre, geom, created_at, updated_at)
             values (?, ?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), now(), now())
             on conflict (clave) do update set
                nombre = excluded.nombre,
                geom = excluded.geom,
                updated_at = now()',
            [
                $feature['properties']['entidad'],
                $feature['properties']['nombre'],
                json_encode($feature['geometry']),
            ]
        );

        return DB::table('entidades')->where('clave', $feature['properties']['entidad'])->value('id');
    }

    /**
     * @return array<int, int> clave del municipio => id
     */
    private function cargarMunicipios(string $ruta, int $entidadId): array
    {
        $municipioIdPorClave = [];

        foreach ($this->leerFeatures($ruta) as $feature) {
            $clave = $feature['properties']['municipio'];

            DB::statement(
                'insert into municipios (entidad_id, clave, nombre, geom, created_at, updated_at)
                 values (?, ?, ?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), now(), now())
                 on conflict (entidad_id, clave) do update set
                    nombre = excluded.nombre,
                    geom = excluded.geom,
                    updated_at = now()',
                [
                    $entidadId,
                    $clave,
                    $feature['properties']['nombre'] ?? null,
                    json_encode($feature['geometry']),
                ]
            );

            $municipioIdPorClave[$clave] = DB::table('municipios')
                ->where('entidad_id', $entidadId)
                ->where('clave', $clave)
                ->value('id');
        }

        return $municipioIdPorClave;
    }

    /**
     * @param  array<int, int>  $municipioIdPorClave
     * @return array{insertadas: int, invalidas: int}
     */
    private function cargarSecciones(string $ruta, array $municipioIdPorClave): array
    {
        $insertadas = 0;
        $invalidas = 0;

        foreach (array_chunk($this->leerFeatures($ruta), 200) as $lote) {
            DB::transaction(function () use ($lote, $municipioIdPorClave, &$insertadas, &$invalidas) {
                foreach ($lote as $feature) {
                    $propiedades = $feature['properties'];
                    $municipioId = $municipioIdPorClave[$propiedades['municipio']] ?? null;

                    if ($municipioId === null) {
                        $invalidas++;

                        continue;
                    }

                    $geometriaValida = DB::selectOne(
                        'select ST_IsValid(ST_GeomFromGeoJSON(?)) as valida',
                        [json_encode($feature['geometry'])]
                    )->valida;

                    if (! $geometriaValida) {
                        $invalidas++;

                        continue;
                    }

                    DB::statement(
                        'insert into secciones (
                            municipio_id, numero, tipo, distrito_federal, distrito_local, geom, created_at, updated_at
                         )
                         values (?, ?, ?, ?, ?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), now(), now())
                         on conflict (municipio_id, numero) do update set
                            tipo = excluded.tipo,
                            distrito_federal = excluded.distrito_federal,
                            distrito_local = excluded.distrito_local,
                            geom = excluded.geom,
                            updated_at = now()',
                        [
                            $municipioId,
                            $propiedades['seccion'],
                            $propiedades['tipo'] ?? null,
                            $propiedades['distrito_f'] ?? null,
                            $propiedades['distrito_l'] ?? null,
                            json_encode($feature['geometry']),
                        ]
                    );

                    $insertadas++;
                }
            });
        }

        return ['insertadas' => $insertadas, 'invalidas' => $invalidas];
    }

    /**
     * @return array<int, array{properties: array<string, mixed>, geometry: array<string, mixed>}>
     */
    private function leerFeatures(string $ruta): array
    {
        $crudo = file_get_contents($ruta);

        if ($crudo === false) {
            throw new \RuntimeException("No se pudo leer el archivo: {$ruta}");
        }

        $contenido = json_decode($crudo, true);

        return $contenido['features'];
    }

    /**
     * @param  array<string, int>  $conteos
     */
    private function reportar(array $conteos): void
    {
        if (! isset($this->command)) {
            return;
        }

        foreach ($conteos as $etiqueta => $valor) {
            $this->command->info("{$etiqueta}: {$valor}");
        }
    }
}
