<?php

namespace App\Http\Controllers;

use App\Actions\Estadisticas\CalcularCompetitividadSeccion;
use App\Actions\Estadisticas\DesglosarVotosSeccion;
use App\Actions\Metas\DefinirMetaSeccion;
use App\Enums\TipoSeccion;
use App\Http\Controllers\Concerns\PresentaElectores;
use App\Models\Coalicion;
use App\Models\CoberturaSeccion;
use App\Models\Elector;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\MetaSeccion;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MapaController extends Controller
{
    use PresentaElectores;

    private const TOLERANCIA_SIMPLIFICACION = 0.0001;

    public function index(Request $request): Response
    {
        $tenant = TenantContext::get();
        $municipio = $tenant?->municipio;

        return Inertia::render('Mapa', [
            'municipio' => $municipio?->nombre,
            'estado' => $municipio?->entidad?->nombre,
            'totalSecciones' => $municipio ? Seccion::where('municipio_id', $municipio->id)->count() : 0,
            // Sección a enfocar al abrir el mapa (deep-link desde otras vistas).
            'seccionInicial' => $request->integer('seccion') ?: null,
        ]);
    }

    public function cobertura(Request $request): JsonResponse
    {
        $tenant = TenantContext::get();
        $viewer = $this->miMembership();
        $config = $this->configuracionCompetitividad($tenant);

        $filas = DB::table('secciones')
            ->leftJoin('cobertura_seccion', function ($join) use ($tenant) {
                $join->on('secciones.id', '=', 'cobertura_seccion.seccion_id')
                    ->where('cobertura_seccion.tenant_id', '=', $tenant?->id);
            })
            ->leftJoin('estadisticas_seccion', 'secciones.id', '=', 'estadisticas_seccion.seccion_id')
            ->where('secciones.municipio_id', $tenant?->municipio_id)
            // El brigadista solo ve sus zonas asignadas; gestión ve todas.
            ->when(
                $viewer?->esBrigadista(),
                fn ($query) => $query->whereIn('secciones.id', $viewer->secciones()->pluck('secciones.id')),
            )
            ->select([
                'secciones.id as seccion_id',
                'secciones.numero',
                'secciones.tipo',
                'secciones.distrito_local',
                'secciones.distrito_federal',
                'secciones.lista_nominal',
                DB::raw('COALESCE(cobertura_seccion.capturados, 0) as capturados'),
                DB::raw('COALESCE(cobertura_seccion.meta, 0) as meta'),
                DB::raw('COALESCE(cobertura_seccion.cobertura, 0) as cobertura'),
                DB::raw('COALESCE(cobertura_seccion.penetracion, 0) as penetracion'),
                // Estadística pública 2024 (solo escalares chicos; el desglose
                // jsonb vive en el endpoint de resumen para no inflar el GeoJSON).
                'estadisticas_seccion.ganador_bloque',
                'estadisticas_seccion.margen_pp',
                'estadisticas_seccion.pct_fuerza',
                'estadisticas_seccion.pct_morena_pvem',
                'estadisticas_seccion.participacion_pct as participacion_2024',
                'estadisticas_seccion.indice_oportunidad',
                'estadisticas_seccion.nivel_oportunidad',
                'estadisticas_seccion.potencial_movilizacion',
                'estadisticas_seccion.votos_partidos',
                'estadisticas_seccion.total_votos',
                DB::raw('ST_AsGeoJSON(ST_SimplifyPreserveTopology(secciones.geom, '.self::TOLERANCIA_SIMPLIFICACION.')) as geom_json'),
            ])
            ->get();

        $features = $filas->map(function ($fila) use ($config) {
            $competitividad = $this->calcularCompetitividad($fila->votos_partidos, $fila->total_votos, $config);

            return [
                'type' => 'Feature',
                'geometry' => $fila->geom_json ? json_decode($fila->geom_json) : null,
                'properties' => [
                    'seccion_id' => $fila->seccion_id,
                    'numero' => $fila->numero,
                    'tipo' => $fila->tipo,
                    'distrito_local' => $fila->distrito_local,
                    'distrito_federal' => $fila->distrito_federal,
                    'capturados' => (int) $fila->capturados,
                    'meta' => (int) $fila->meta,
                    'cobertura' => (float) $fila->cobertura,
                    'penetracion' => (float) $fila->penetracion,
                    'lista_nominal' => $fila->lista_nominal,
                    'ganador_bloque' => $fila->ganador_bloque,
                    'margen_pp' => $fila->margen_pp !== null ? (float) $fila->margen_pp : null,
                    'pct_fuerza' => $fila->pct_fuerza !== null ? (float) $fila->pct_fuerza : null,
                    'pct_morena_pvem' => $fila->pct_morena_pvem !== null ? (float) $fila->pct_morena_pvem : null,
                    'participacion_2024' => $fila->participacion_2024 !== null ? (float) $fila->participacion_2024 : null,
                    'indice_oportunidad' => $fila->indice_oportunidad !== null ? (float) $fila->indice_oportunidad : null,
                    'nivel_oportunidad' => $fila->nivel_oportunidad,
                    'potencial_movilizacion' => $fila->potencial_movilizacion,
                    'estatus_slug' => $competitividad['estatus_slug'],
                    'estatus_label' => $competitividad['estatus_label'],
                    'estatus_color' => $competitividad['estatus_color'],
                    'diferencia_votos' => $competitividad['diferencia_votos'],
                    'diferencia_pct' => $competitividad['diferencia_pct'],
                    'tipo_seccion' => $this->calcularTipoSeccion($fila->lista_nominal, $config)?->value,
                ],
            ];
        })->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Detalle de sección (Inertia): estadísticas + lista de electores + alta
     * directa. El front pide resumen/electores/aviso a los endpoints JSON.
     */
    public function detalle(Seccion $seccion): Response
    {
        $this->asegurarAccesoSeccion($seccion);

        return Inertia::render('Seccion', [
            'seccion' => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ],
            'modos' => $this->catalogoModos(),
        ]);
    }

    public function resumenSeccion(Seccion $seccion): JsonResponse
    {
        $this->asegurarAccesoSeccion($seccion);

        $tenant = TenantContext::get();

        $cobertura = CoberturaSeccion::where('tenant_id', $tenant?->id)
            ->where('seccion_id', $seccion->id)
            ->first();

        $ultimoRegistro = Elector::query()
            ->where('seccion_id', $seccion->id)
            ->max('created_at');

        $estadistica = $seccion->estadistica;
        $config = $this->configuracionCompetitividad($tenant);
        $competitividad = $this->calcularCompetitividad($estadistica?->votos_partidos, $estadistica?->total_votos, $config);

        return response()->json([
            'numero' => $seccion->numero,
            'tipo' => $seccion->tipo,
            'lista_nominal' => $seccion->lista_nominal,
            'distrito_local' => $seccion->distrito_local,
            'distrito_federal' => $seccion->distrito_federal,
            'capturados' => $cobertura !== null ? $cobertura->capturados : 0,
            'meta' => $cobertura !== null ? $cobertura->meta : 0,
            'cobertura' => $cobertura !== null ? (float) $cobertura->cobertura : 0,
            'penetracion' => $cobertura !== null ? (float) $cobertura->penetracion : 0,
            'brigadistas_activos' => $this->brigadistasActivosEn($seccion, $tenant),
            'ultimo_registro' => $ultimoRegistro,
            'tipo_seccion' => $this->calcularTipoSeccion($seccion->lista_nominal, $config)?->value,
            'electoral_2024' => $estadistica?->ganador_bloque === null ? null : [
                'ganador_bloque' => $estadistica->ganador_bloque,
                'ganador_partido' => $estadistica->ganador_partido,
                'margen_votos' => $estadistica->margen_votos,
                'margen_pp' => (float) $estadistica->margen_pp,
                'total_votos' => $estadistica->total_votos,
                'participacion_pct' => (float) $estadistica->participacion_pct,
                'pct_fuerza' => (float) $estadistica->pct_fuerza,
                'pct_morena_pvem' => (float) $estadistica->pct_morena_pvem,
                'pct_otros' => (float) $estadistica->pct_otros,
                'estatus_slug' => $competitividad['estatus_slug'],
                'estatus_label' => $competitividad['estatus_label'],
                'estatus_color' => $competitividad['estatus_color'],
                'diferencia_votos' => $competitividad['diferencia_votos'],
                'diferencia_pct' => $competitividad['diferencia_pct'],
                'votos_bloque_propio' => $competitividad['votos_bloque_propio'],
                'votos_mejor_rival' => $competitividad['votos_mejor_rival'],
                'bloque_propio' => $competitividad['bloque_propio'],
            ],
            'desglose_2024' => $this->calcularDesglose($estadistica, $config),
            'demografia' => $estadistica?->nivel_oportunidad === null ? null : [
                'indice_oportunidad' => (float) $estadistica->indice_oportunidad,
                'nivel_oportunidad' => $estadistica->nivel_oportunidad,
                'grupo_dominante' => $estadistica->grupo_dominante,
                'grupo_mayor_abstencion' => $estadistica->grupo_mayor_abstencion,
                'potencial_movilizacion' => $estadistica->potencial_movilizacion,
                'tipo_composicion_edad' => $estadistica->tipo_composicion_edad,
                'recomendacion' => $estadistica->recomendacion,
                'grupos_edad' => $estadistica->grupos_edad,
            ],
        ]);
    }

    /**
     * Brigadistas activos asignados (brigadista_seccion) a esta sección.
     * Pivote tenant-scoped por columna (memberships no tiene global scope).
     *
     * @return array<int, array{membership_id: int, nombre: string|null}>
     */
    private function brigadistasActivosEn(Seccion $seccion, ?Tenant $tenant): array
    {
        return DB::table('brigadista_seccion')
            ->join('memberships', 'memberships.id', '=', 'brigadista_seccion.membership_id')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('brigadista_seccion.tenant_id', $tenant?->id)
            ->where('brigadista_seccion.seccion_id', $seccion->id)
            ->where('memberships.rol', 'brigadista')
            ->where('memberships.activo', true)
            ->get(['memberships.id as membership_id', 'users.name as nombre'])
            ->map(fn (object $row): array => [
                'membership_id' => (int) $row->membership_id,
                'nombre' => $row->nombre,
            ])
            ->all();
    }

    /**
     * Ranking de secciones para enfocar esfuerzos: cruza la estadística pública
     * 2024 (competitividad + oportunidad de movilización) con la cobertura del
     * tenant. El brigadista solo ve sus zonas asignadas (mismo criterio que el mapa).
     */
    public function prioridades(): Response
    {
        $tenant = TenantContext::get();
        $viewer = $this->miMembership();
        $config = $this->configuracionCompetitividad($tenant);

        $filas = DB::table('secciones')
            ->leftJoin('cobertura_seccion', function ($join) use ($tenant) {
                $join->on('secciones.id', '=', 'cobertura_seccion.seccion_id')
                    ->where('cobertura_seccion.tenant_id', '=', $tenant?->id);
            })
            ->leftJoin('estadisticas_seccion', 'secciones.id', '=', 'estadisticas_seccion.seccion_id')
            ->where('secciones.municipio_id', $tenant?->municipio_id)
            ->when(
                $viewer?->esBrigadista(),
                fn ($query) => $query->whereIn('secciones.id', $viewer->secciones()->pluck('secciones.id')),
            )
            ->orderBy('secciones.numero')
            ->select([
                'secciones.id as seccion_id',
                'secciones.numero',
                'secciones.lista_nominal',
                DB::raw('COALESCE(cobertura_seccion.capturados, 0) as capturados'),
                DB::raw('COALESCE(cobertura_seccion.meta, 0) as meta'),
                DB::raw('COALESCE(cobertura_seccion.cobertura, 0) as cobertura'),
                'estadisticas_seccion.ganador_bloque',
                'estadisticas_seccion.margen_pp',
                'estadisticas_seccion.indice_oportunidad',
                'estadisticas_seccion.nivel_oportunidad',
                'estadisticas_seccion.potencial_movilizacion',
                'estadisticas_seccion.grupo_dominante',
                'estadisticas_seccion.votos_partidos',
                'estadisticas_seccion.total_votos',
            ])
            ->get();

        $secciones = $filas->map(function ($fila) use ($config) {
            $competitividad = $this->calcularCompetitividad($fila->votos_partidos, $fila->total_votos, $config);

            return [
                'seccion_id' => $fila->seccion_id,
                'numero' => $fila->numero,
                'lista_nominal' => $fila->lista_nominal,
                'capturados' => (int) $fila->capturados,
                'meta' => (int) $fila->meta,
                'cobertura' => (float) $fila->cobertura,
                'ganador_bloque' => $fila->ganador_bloque,
                'margen_pp' => $fila->margen_pp !== null ? (float) $fila->margen_pp : null,
                'indice_oportunidad' => $fila->indice_oportunidad !== null ? (float) $fila->indice_oportunidad : null,
                'nivel_oportunidad' => $fila->nivel_oportunidad,
                'potencial_movilizacion' => $fila->potencial_movilizacion,
                'grupo_dominante' => $fila->grupo_dominante,
                'prioridad' => self::calcularPrioridad(
                    $fila->margen_pp !== null ? (float) $fila->margen_pp : null,
                    $fila->indice_oportunidad !== null ? (float) $fila->indice_oportunidad : null,
                    (float) $fila->cobertura,
                ),
                'estatus_slug' => $competitividad['estatus_slug'],
                'estatus_label' => $competitividad['estatus_label'],
                'estatus_color' => $competitividad['estatus_color'],
                'diferencia_votos' => $competitividad['diferencia_votos'],
                'diferencia_pct' => $competitividad['diferencia_pct'],
                'tipo_seccion' => $this->calcularTipoSeccion($fila->lista_nominal, $config)?->value,
            ];
        })->values();

        return Inertia::render('Prioridades', [
            'secciones' => $secciones,
            'mostrarCompetitividad' => $config['indicadores']['competitividad'],
            'mostrarTipoSeccion' => $config['indicadores']['tipo_seccion'],
            'mostrarIndiceNeutral' => $config['indicadores']['indice_neutral'],
            'mostrarOportunidad' => $config['indicadores']['oportunidad'],
        ]);
    }

    /**
     * Índice de prioridad 0-100 (neutral, sin perspectiva de bloque): pesa la
     * competitividad (margen chico = cada voto vale más), la oportunidad de
     * movilización por edad y la cobertura propia pendiente. Sin ningún dato
     * estadístico regresa null (la sección se muestra como "sin datos"); si
     * falta una parte, esa componente aporta 0.
     */
    public static function calcularPrioridad(?float $margenPp, ?float $indiceOportunidad, float $cobertura): ?float
    {
        if ($margenPp === null && $indiceOportunidad === null) {
            return null;
        }

        $competitividad = $margenPp !== null ? 100 - min($margenPp, 25.0) * 4 : 0.0;
        $oportunidad = $indiceOportunidad ?? 0.0;
        $coberturaPendiente = 100 - min($cobertura, 1.0) * 100;

        return round(0.4 * $competitividad + 0.4 * $oportunidad + 0.2 * $coberturaPendiente, 1);
    }

    public function metas(): Response
    {
        $tenant = TenantContext::get();
        $metasPorSeccion = MetaSeccion::all()->keyBy('seccion_id');

        $secciones = Seccion::where('municipio_id', $tenant?->municipio_id)
            ->orderBy('numero')
            ->get()
            ->map(function (Seccion $seccion) use ($metasPorSeccion) {
                $meta = $metasPorSeccion->get($seccion->id);

                return [
                    'seccion_id' => $seccion->id,
                    'numero' => $seccion->numero,
                    'lista_nominal' => $seccion->lista_nominal,
                    'meta_capturas' => $meta !== null ? $meta->meta_capturas : 0,
                    'fuente_meta' => $meta !== null ? $meta->fuente_meta : null,
                    'pct_lista_nominal' => $meta !== null ? $meta->pct_lista_nominal : null,
                ];
            });

        return Inertia::render('Metas', ['secciones' => $secciones]);
    }

    public function definirMeta(Request $request, Seccion $seccion): JsonResponse
    {
        $data = $request->validate([
            'fuente_meta' => ['required', 'in:manual,lista_nominal_pct'],
            'meta_capturas' => ['required_if:fuente_meta,manual', 'integer', 'min:0'],
            'pct_lista_nominal' => ['required_if:fuente_meta,lista_nominal_pct', 'numeric', 'min:0', 'max:100'],
        ]);

        $meta = (new DefinirMetaSeccion)->handle(
            $seccion,
            $data['fuente_meta'],
            metaCapturas: $data['meta_capturas'] ?? null,
            pct: $data['pct_lista_nominal'] ?? null,
        );

        return response()->json([
            'seccion_id' => $seccion->id,
            'meta_capturas' => $meta->meta_capturas,
            'fuente_meta' => $meta->fuente_meta,
        ]);
    }

    /**
     * El brigadista solo puede consultar sus zonas asignadas; para secciones
     * ajenas responde 403. Gestión y demás roles no están acotados.
     */
    private function asegurarAccesoSeccion(Seccion $seccion): void
    {
        // La sección debe ser del municipio de la campaña activa (Seccion no está
        // tenant-scoped). 404 para no revelar secciones de otros municipios.
        abort_if($seccion->municipio_id !== TenantContext::get()?->municipio_id, 404);

        $viewer = $this->miMembership();

        abort_if(
            $viewer !== null && ! $viewer->puedeCapturarEnSeccion($seccion->id),
            403,
        );
    }

    /**
     * Membresía activa del usuario en el tenant actual.
     */
    private function miMembership(): ?Membership
    {
        $user = request()->user();
        $tenant = TenantContext::get();

        return ($user !== null && $tenant !== null) ? $user->membershipEn($tenant) : null;
    }

    /**
     * Config de competitividad/tipo resuelta una sola vez por request (F2):
     * partido + coaliciones 2024 + categorías + modo de cálculo, para no
     * repetir queries ni recomputar slugs en el loop de secciones.
     *
     * @return array{partidoSiglas: string|null, coaliciones: Collection<int, Coalicion>, categorias: list<array{nombre: string, color: string, umbral: int|float|null, slug: string}>, modoCalculo: string, umbralAlfa: int, umbralBeta: int, indicadores: array<string, bool>}
     */
    private function configuracionCompetitividad(?Tenant $tenant): array
    {
        $configuracion = $tenant?->configuracion();

        return [
            'partidoSiglas' => $tenant?->partido?->siglas,
            'coaliciones' => Coalicion::where('anio', 2024)->get(),
            'categorias' => $configuracion['categorias_competitividad'] ?? [],
            'modoCalculo' => $configuracion['modo_calculo_competitividad'] ?? 'votos',
            'umbralAlfa' => $configuracion['umbral_alfa'] ?? 1000,
            'umbralBeta' => $configuracion['umbral_beta'] ?? 500,
            'indicadores' => $configuracion['indicadores'] ?? [
                'competitividad' => true,
                'tipo_seccion' => true,
                'indice_neutral' => true,
                'oportunidad' => true,
            ],
        ];
    }

    /**
     * @param  array<string, int>|string|null  $votosPartidos  array (cast Eloquent) o string jsonb crudo (DB::table)
     * @param  array{partidoSiglas: string|null, coaliciones: Collection<int, Coalicion>, categorias: list<array{nombre: string, color: string, umbral: int|float|null, slug: string}>, modoCalculo: string, umbralAlfa: int, umbralBeta: int, indicadores: array<string, bool>}  $config
     * @return array{estatus_slug: string, estatus_label: string, estatus_color: string, diferencia_votos: int|null, diferencia_pct: float|null, votos_bloque_propio: int|null, votos_mejor_rival: int|null, bloque_propio: string|null}
     */
    private function calcularCompetitividad(array|string|null $votosPartidos, ?int $totalVotos, array $config): array
    {
        if (is_string($votosPartidos)) {
            $votosPartidos = json_decode($votosPartidos, true);
        }

        return (new CalcularCompetitividadSeccion)->handle(
            $votosPartidos,
            $config['partidoSiglas'],
            $config['coaliciones'],
            $config['categorias'],
            $config['modoCalculo'],
            $totalVotos,
        );
    }

    /**
     * @param  array{umbralAlfa: int, umbralBeta: int}  $config
     */
    private function calcularTipoSeccion(?int $listaNominal, array $config): ?TipoSeccion
    {
        return TipoSeccion::desdeListaNominal($listaNominal, $config['umbralAlfa'], $config['umbralBeta']);
    }

    /**
     * Desglose de votos 2024 por bloque y opción de boleta (F3), solo para
     * el detalle de sección: null si no hay estadística electoral.
     *
     * @param  array{partidoSiglas: string|null, coaliciones: Collection<int, Coalicion>}  $config
     * @return array{total_votos: int|null, votos_nulos?: int, bloques: list<array{nombre: string, siglas: list<string>, color: string, total: int, es_bloque_propio: bool, opciones: list<array{clave: string, siglas: list<string>, votos: int}>}>}|null
     */
    private function calcularDesglose(?EstadisticaSeccion $estadistica, array $config): ?array
    {
        return (new DesglosarVotosSeccion)->handle(
            $estadistica?->votos_partidos,
            $estadistica?->total_votos,
            $config['partidoSiglas'],
            $config['coaliciones'],
            Partido::all(),
        );
    }
}
