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

        $conteos = [
            'entidades' => 1,
            'municipios' => count($municipioIdPorClave),
            'secciones' => $resumenSecciones['insertadas'],
            'secciones_invalidas' => $resumenSecciones['invalidas'],
        ];

        // La lista nominal (padrón) es opcional: si el estado trae un lista_nominal.csv
        // junto a los GeoJSON, poblamos secciones.lista_nominal con el dato real.
        $rutaListaNominal = "{$directorio}/lista_nominal.csv";

        if (is_file($rutaListaNominal)) {
            $resumenPadron = $this->cargarListaNominal($rutaListaNominal, $municipioIdPorClave);
            $conteos['lista_nominal_actualizadas'] = $resumenPadron['actualizadas'];
            $conteos['lista_nominal_sin_match'] = $resumenPadron['sin_match'];
        }

        $this->reportar($conteos);
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
     * Puebla secciones.lista_nominal desde un CSV del padrón. Detecta las columnas
     * por encabezado (tolerante a mayúsculas/acentos): número de sección, lista
     * nominal y, opcionalmente, clave de municipio. Casa por número (y municipio
     * si viene). No inserta secciones nuevas: solo actualiza las ya cargadas.
     *
     * @param  array<int, int>  $municipioIdPorClave
     * @return array{actualizadas: int, sin_match: int}
     */
    private function cargarListaNominal(string $ruta, array $municipioIdPorClave): array
    {
        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            throw new \RuntimeException("No se pudo leer el archivo: {$ruta}");
        }

        try {
            $encabezado = fgetcsv($manejador, null, ',', '"', '');

            if ($encabezado === false || $encabezado === null) {
                return ['actualizadas' => 0, 'sin_match' => 0];
            }

            $columnas = array_map(fn ($valor) => $this->normalizar((string) $valor), $encabezado);
            $indiceSeccion = $this->buscarColumna($columnas, ['seccion', 'seccion_id', 'clave_seccion', 'clave', 'numero']);
            $indiceLista = $this->buscarColumna($columnas, ['lista_nominal', 'listado_nominal', 'listanominal', 'listado', 'lista_nominal_2024', 'padron', 'lista']);
            $indiceMunicipio = $this->buscarColumna($columnas, ['municipio', 'municipio_id', 'clave_municipio', 'mun']);

            if ($indiceSeccion === null || $indiceLista === null) {
                throw new \RuntimeException(
                    "El CSV {$ruta} no tiene columnas reconocibles de sección y/o lista nominal."
                );
            }

            $actualizadas = 0;
            $sinMatch = 0;

            while (($fila = fgetcsv($manejador, null, ',', '"', '')) !== false) {
                $numero = (int) preg_replace('/\D/', '', (string) ($fila[$indiceSeccion] ?? ''));
                $listaNominal = (int) preg_replace('/\D/', '', (string) ($fila[$indiceLista] ?? ''));

                if ($numero === 0) {
                    continue;
                }

                $query = DB::table('secciones')->where('numero', $numero);

                // La columna de municipio puede traer la CLAVE numérica o el NOMBRE.
                // Solo filtramos cuando es una clave conocida; si es un nombre (texto),
                // casamos por número (la cartografía carga un municipio a la vez).
                if ($indiceMunicipio !== null) {
                    $claveMunicipio = (int) preg_replace('/\D/', '', (string) ($fila[$indiceMunicipio] ?? ''));

                    if ($claveMunicipio > 0) {
                        $municipioId = $municipioIdPorClave[$claveMunicipio] ?? null;

                        if ($municipioId === null) {
                            $sinMatch++;

                            continue;
                        }

                        $query->where('municipio_id', $municipioId);
                    }
                }

                $afectadas = $query->update(['lista_nominal' => $listaNominal, 'updated_at' => now()]);

                if ($afectadas > 0) {
                    $actualizadas += $afectadas;
                } else {
                    $sinMatch++;
                }
            }

            return ['actualizadas' => $actualizadas, 'sin_match' => $sinMatch];
        } finally {
            fclose($manejador);
        }
    }

    /**
     * Normaliza un encabezado de columna: minúsculas, sin acentos ni espacios.
     */
    private function normalizar(string $valor): string
    {
        $valor = str_replace("\u{FEFF}", '', $valor);
        $valor = mb_strtolower(trim($valor));
        $valor = strtr($valor, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/[\s\-]+/', '_', $valor);
    }

    /**
     * @param  array<int, string>  $columnas
     * @param  array<int, string>  $candidatos
     */
    private function buscarColumna(array $columnas, array $candidatos): ?int
    {
        foreach ($candidatos as $candidato) {
            $indice = array_search($candidato, $columnas, true);

            if ($indice !== false) {
                return (int) $indice;
            }
        }

        return null;
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
