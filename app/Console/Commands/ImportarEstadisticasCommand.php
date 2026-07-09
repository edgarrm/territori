<?php

namespace App\Console\Commands;

use App\Actions\Estadisticas\DesglosarVotosSeccion;
use App\Models\EstadisticaSeccion;
use App\Models\Municipio;
use App\Models\Seccion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('territori:importar-estadisticas {electoral : CSV de resultados 2024 por bloque} {demografico? : CSV de perfil por grupos de edad (Perfil_Secciones)} {--municipio= : Id del municipio; si se omite y hay uno solo, se usa ese}')]
#[Description('Importa a estadisticas_seccion los resultados electorales 2024 y el perfil demográfico por edad (idempotente, casa por número de sección).')]
class ImportarEstadisticasCommand extends Command
{
    private const GRUPOS_EDAD = ['18-29', '30-39', '40-49', '50-59', '60-79', '80+'];

    /** Mismo catálogo que consume F3 para completar opciones en 0 votos. */
    private const COLUMNAS_PARTIDOS = DesglosarVotosSeccion::OPCIONES_BOLETA_2024;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $municipio = $this->resolverMunicipio();

        if ($municipio === null) {
            return self::FAILURE;
        }

        $seccionIdPorNumero = Seccion::where('municipio_id', $municipio->id)
            ->pluck('id', 'numero')
            ->all();

        $resumen = $this->importarElectoral((string) $this->argument('electoral'), $seccionIdPorNumero);
        $this->info("Electoral: {$resumen['importadas']} secciones importadas, {$resumen['sin_match']} sin sección correspondiente.");

        $rutaDemografico = $this->argument('demografico');

        if ($rutaDemografico !== null) {
            $resumen = $this->importarDemografico((string) $rutaDemografico, $seccionIdPorNumero);
            $this->info("Demográfico: {$resumen['importadas']} secciones importadas, {$resumen['sin_match']} sin sección correspondiente.");
        }

        return self::SUCCESS;
    }

    private function resolverMunicipio(): ?Municipio
    {
        $opcion = $this->option('municipio');

        if ($opcion !== null) {
            $municipio = Municipio::find((int) $opcion);

            if ($municipio === null) {
                $this->error("Municipio {$opcion} no existe.");
            }

            return $municipio;
        }

        if (Municipio::count() === 1) {
            return Municipio::first();
        }

        $this->error('Hay varios municipios cargados: indica cuál con --municipio=.');

        return null;
    }

    /**
     * Importa el CSV de resultados 2024 por bloque (una fila por sección).
     *
     * @param  array<int, int>  $seccionIdPorNumero
     * @return array{importadas: int, sin_match: int}
     */
    private function importarElectoral(string $ruta, array $seccionIdPorNumero): array
    {
        $importadas = 0;
        $sinMatch = 0;

        foreach ($this->leerFilas($ruta) as $fila) {
            $seccionId = $seccionIdPorNumero[(int) ($fila['seccion'] ?? 0)] ?? null;

            if ($seccionId === null) {
                $sinMatch++;

                continue;
            }

            $votosPartidos = [];

            foreach (self::COLUMNAS_PARTIDOS as $partido) {
                $votos = (int) ($fila[$this->normalizar($partido)] ?? 0);

                if ($votos > 0) {
                    $votosPartidos[$partido] = $votos;
                }
            }

            EstadisticaSeccion::updateOrCreate(['seccion_id' => $seccionId], [
                'lista_nominal_2024' => (int) ($fila['lista_nominal_2024'] ?? 0),
                'total_votos' => (int) ($fila['total_votos_final'] ?? 0),
                'participacion_pct' => (float) ($fila['participacion_final'] ?? 0),
                'votos_fuerza' => (int) ($fila['votos_fuerza_corazon_pan_pri_prd_pas'] ?? 0),
                'pct_fuerza' => (float) ($fila['porcentaje_fuerza_corazon_pan_pri_prd_pas'] ?? 0),
                'votos_morena_pvem' => (int) ($fila['votos_morena_pvem'] ?? 0),
                'pct_morena_pvem' => (float) ($fila['porcentaje_morena_pvem'] ?? 0),
                'votos_otros' => (int) ($fila['votos_otros_mc_pt_pes_ind'] ?? 0),
                'pct_otros' => (float) ($fila['porcentaje_otros_mc_pt_pes_ind'] ?? 0),
                'ganador_bloque' => ($fila['ganador_por_bloque'] ?? null) ?: null,
                'margen_votos' => (int) ($fila['margen_bloque_votos'] ?? 0),
                'margen_pp' => (float) ($fila['margen_bloque_pp'] ?? 0),
                'ganador_partido' => ($fila['ganador_partido_individual'] ?? null) ?: null,
                'votos_partidos' => $votosPartidos,
            ]);

            $importadas++;
        }

        return ['importadas' => $importadas, 'sin_match' => $sinMatch];
    }

    /**
     * Importa el CSV de perfil demográfico (hoja Perfil_Secciones exportada a
     * CSV). Las proporciones vienen como fracción 0-1: se guardan como 0-100.
     *
     * @param  array<int, int>  $seccionIdPorNumero
     * @return array{importadas: int, sin_match: int}
     */
    private function importarDemografico(string $ruta, array $seccionIdPorNumero): array
    {
        $importadas = 0;
        $sinMatch = 0;

        foreach ($this->leerFilas($ruta) as $fila) {
            $seccionId = $seccionIdPorNumero[(int) ($fila['seccion'] ?? 0)] ?? null;

            if ($seccionId === null) {
                $sinMatch++;

                continue;
            }

            $gruposEdad = [];

            foreach (self::GRUPOS_EDAD as $grupo) {
                $sufijo = $this->normalizar($grupo);

                $gruposEdad[$grupo] = [
                    'ln' => (int) ($fila["ln_{$sufijo}"] ?? 0),
                    'votos' => (int) ($fila["votos_{$sufijo}"] ?? 0),
                    'participacion' => round((float) ($fila["participacion_{$sufijo}"] ?? 0) * 100, 2),
                    'abstencion' => round((float) ($fila["abstencion_{$sufijo}"] ?? 0) * 100, 2),
                    'potencial' => (int) round((float) ($fila["potencial_{$sufijo}"] ?? 0)),
                ];
            }

            EstadisticaSeccion::updateOrCreate(['seccion_id' => $seccionId], [
                'participacion_2024_pct' => round((float) ($fila['participacion_seccion'] ?? 0) * 100, 2),
                'abstencion_2024_pct' => round((float) ($fila['abstencion_seccion'] ?? 0) * 100, 2),
                'indice_oportunidad' => round((float) ($fila['indice_oportunidad_0_100'] ?? 0), 2),
                'nivel_oportunidad' => ($fila['nivel_oportunidad'] ?? null) ?: null,
                'grupo_dominante' => ($fila['grupo_dominante_votos'] ?? null) ?: null,
                'grupo_mayor_abstencion' => ($fila['grupo_mayor_no_voto'] ?? null) ?: null,
                'potencial_movilizacion' => max(0, (int) round((float) ($fila['potencial_movilizacion_vs_municipal'] ?? 0))),
                'tipo_composicion_edad' => ($fila['tipo_composicion_edad'] ?? null) ?: null,
                'universo_operativo' => ($fila['universo_operativo'] ?? null) ?: null,
                'recomendacion' => ($fila['recomendacion'] ?? null) ?: null,
                'grupos_edad' => $gruposEdad,
            ]);

            $importadas++;
        }

        return ['importadas' => $importadas, 'sin_match' => $sinMatch];
    }

    /**
     * Lee un CSV y regresa cada fila como arreglo asociativo con encabezados
     * normalizados (minúsculas, sin acentos/BOM, separadores a guion bajo).
     *
     * @return \Generator<int, array<string, string>>
     */
    private function leerFilas(string $ruta): \Generator
    {
        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            throw new \RuntimeException("No se pudo leer el archivo: {$ruta}");
        }

        try {
            $encabezado = fgetcsv($manejador, null, ',', '"', '');

            if ($encabezado === false || $encabezado === null) {
                return;
            }

            $columnas = array_map(fn ($valor) => $this->normalizar((string) $valor), $encabezado);

            while (($fila = fgetcsv($manejador, null, ',', '"', '')) !== false) {
                $asociativa = [];

                foreach ($columnas as $indice => $columna) {
                    $asociativa[$columna] = trim((string) ($fila[$indice] ?? ''));
                }

                yield $asociativa;
            }
        } finally {
            fclose($manejador);
        }
    }

    /**
     * Normaliza un encabezado: minúsculas, sin BOM/acentos, y todo lo que no
     * sea alfanumérico colapsado a guion bajo (mismo criterio que CartografiaSeeder).
     */
    private function normalizar(string $valor): string
    {
        $valor = str_replace("\u{FEFF}", '', $valor);
        $valor = mb_strtolower(trim($valor));
        $valor = strtr($valor, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        $valor = (string) preg_replace('/[^a-z0-9]+/', '_', $valor);

        return trim($valor, '_');
    }
}
