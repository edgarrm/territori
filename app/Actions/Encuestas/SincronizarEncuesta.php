<?php

namespace App\Actions\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\EncuestaRespuestaAgregada;
use App\Models\Seccion;
use App\Models\Zona;
use App\Support\Encuestas\ResolverFuenteEncuesta;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sincroniza una encuesta de canal pull (F9): resuelve su FuenteEncuesta,
 * sincroniza el catálogo de preguntas (columnas nuevas → incluida=false),
 * resuelve ambito_id por Seccion.numero/Zona.clave (el identificador crudo
 * nunca se persiste) y tabula con TabularRespuesta (F8) en modo recuento
 * completo: borra los agregados de esta encuesta antes de tabular, mismo
 * espíritu idempotente que RecalcularCoberturaSeccion.
 */
class SincronizarEncuesta
{
    public function __construct(
        private readonly ResolverFuenteEncuesta $resolver,
        private readonly TabularRespuesta $tabular,
    ) {}

    /**
     * @return array{procesadas: int, sin_match: int}
     */
    public function handle(Encuesta $encuesta): array
    {
        $fuente = $this->resolver->resolver($encuesta);

        if ($fuente === null) {
            throw new RuntimeException('Este canal no soporta sincronización.');
        }

        // false: descarta las claves del generador para garantizar un list<>
        // reindexado (el tipo declarado de FuenteEncuesta::obtenerRespuestas
        // no promete claves secuenciales).
        $filas = iterator_to_array($fuente->obtenerRespuestas($encuesta), false);

        $preguntasPorCampo = $this->sincronizarCatalogoPreguntas($encuesta, $filas);

        $procesadas = 0;
        $sinMatch = 0;

        DB::transaction(function () use ($encuesta, $filas, $preguntasPorCampo, &$procesadas, &$sinMatch): void {
            EncuestaRespuestaAgregada::query()->where('encuesta_id', $encuesta->id)->delete();

            foreach ($filas as $fila) {
                $ambitoId = $this->resolverAmbito($encuesta, $fila['identificador']);

                if ($ambitoId === null) {
                    $sinMatch++;

                    continue;
                }

                $procesadas++;

                $respuestas = [];

                foreach ($fila['respuestas'] as $campo => $valor) {
                    $pregunta = $preguntasPorCampo[$campo] ?? null;

                    if ($pregunta === null || $valor === null || $valor === '') {
                        continue;
                    }

                    $respuestas[$pregunta->id] = $valor;
                }

                if ($respuestas !== []) {
                    $this->tabular->handle($encuesta, $encuesta->ambito_tipo, $ambitoId, $respuestas);
                }
            }
        });

        $resultado = ['procesadas' => $procesadas, 'sin_match' => $sinMatch];

        $encuesta->update([
            'ultima_sincronizacion' => now(),
            'ultimo_resultado' => $resultado,
            'ultimo_error' => null,
        ]);

        return $resultado;
    }

    /**
     * Crea en encuesta_preguntas las columnas del Sheet que todavía no
     * existían (incluida=false: el coordinador las activa a mano). Nunca
     * borra ni desactiva preguntas que dejaron de aparecer.
     *
     * @param  list<array{identificador: string, respuestas: array<string, mixed>}>  $filas
     * @return array<string, EncuestaPregunta> campo => pregunta
     */
    private function sincronizarCatalogoPreguntas(Encuesta $encuesta, array $filas): array
    {
        $campos = [];

        foreach ($filas as $fila) {
            foreach (array_keys($fila['respuestas']) as $campo) {
                $campos[$campo] = true;
            }
        }

        $existentes = $encuesta->preguntas()->whereNotNull('campo')->get()->keyBy('campo');
        $orden = (int) ($existentes->max('orden') ?? 0);

        foreach (array_keys($campos) as $campo) {
            if ($existentes->has($campo)) {
                continue;
            }

            $orden++;

            $nueva = EncuestaPregunta::create([
                'encuesta_id' => $encuesta->id,
                'campo' => $campo,
                'etiqueta' => $campo,
                'tipo' => 'opcion_unica',
                'opciones' => null,
                'incluida' => false,
                'orden' => $orden,
            ]);

            $existentes->put($campo, $nueva);
        }

        return $existentes->all();
    }

    /**
     * Match por Seccion.numero (dentro del municipio del tenant) o
     * Zona.clave (ya tenant-scoped). El identificador crudo nunca se
     * persiste, solo se usa para resolver el id.
     */
    private function resolverAmbito(Encuesta $encuesta, string $identificador): ?int
    {
        if ($identificador === '') {
            return null;
        }

        if ($encuesta->ambito_tipo === 'zona') {
            return Zona::query()->where('clave', $identificador)->value('id');
        }

        if (! ctype_digit($identificador)) {
            return null;
        }

        $tenant = TenantContext::get();

        if ($tenant === null) {
            return null;
        }

        return Seccion::query()
            ->where('municipio_id', $tenant->municipio_id)
            ->where('numero', (int) $identificador)
            ->value('id');
    }
}
