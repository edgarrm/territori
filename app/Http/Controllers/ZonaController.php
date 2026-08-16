<?php

namespace App\Http\Controllers;

use App\Actions\Cobertura\AgregarCoberturaTerritorial;
use App\Actions\Estadisticas\AgregarEstadisticaTerritorial;
use App\Actions\Zonas\ActualizarZona;
use App\Actions\Zonas\CrearZona;
use App\Http\Requests\StoreZonaRequest;
use App\Http\Requests\UpdateZonaRequest;
use App\Models\Coalicion;
use App\Models\Partido;
use App\Models\Tenant;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ZonaController extends Controller
{
    private const TOLERANCIA_SIMPLIFICACION = 0.0001;

    /**
     * Límite defensivo de profundidad para el resolver recursivo de
     * `hijas()`: no deberían existir ciclos por construcción (vía UI/import),
     * pero esto evita una recursión infinita si alguno se coló.
     */
    private const PROFUNDIDAD_MAXIMA = 20;

    /**
     * Lectura abierta a cualquier rol del tenant (a diferencia de la
     * escritura, que exige rol:coordinador,admin en la ruta).
     */
    public function index(): Response
    {
        $zonas = Zona::query()
            ->with('padre:id,nombre')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Zona $zona): array => $this->presentar($zona))
            ->all();

        return Inertia::render('Zonas', [
            'zonas' => $zonas,
        ]);
    }

    public function store(StoreZonaRequest $request, CrearZona $crear): RedirectResponse
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? $request->user()?->membershipEn($tenant) : null;

        $crear->handle($request->validated(), $membership?->id);

        return back();
    }

    /**
     * Resolución manual (anti-binding): SubstituteBindings corre antes que
     * ResolveTenant en el stack web.
     */
    public function update(UpdateZonaRequest $request, string $zona, ActualizarZona $actualizar): RedirectResponse
    {
        $modelo = Zona::query()->findOrFail($zona);

        $actualizar->handle($modelo, $request->validated());

        return back();
    }

    public function destroy(string $zona): RedirectResponse
    {
        $modelo = Zona::query()->findOrFail($zona);
        $modelo->delete();

        return back();
    }

    /**
     * GET /api/zonas.geojson — reusa literalmente el patrón
     * ST_AsGeoJSON(ST_SimplifyPreserveTopology(...)) de MapaController::cobertura().
     */
    public function geojson(): JsonResponse
    {
        $tenantId = TenantContext::get()?->id;

        $features = DB::table('zonas')
            ->where('tenant_id', $tenantId)
            ->where('activa', true)
            ->whereNotNull('geom')
            ->orderBy('nombre')
            ->select([
                'id as zona_id',
                'nombre',
                'clave',
                'tipo',
                'zona_padre_id',
                DB::raw('ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, '.self::TOLERANCIA_SIMPLIFICACION.')) as geom_json'),
            ])
            ->get()
            ->map(fn (object $fila): array => [
                'type' => 'Feature',
                'geometry' => $fila->geom_json ? json_decode((string) $fila->geom_json) : null,
                'properties' => [
                    'zona_id' => (int) $fila->zona_id,
                    'nombre' => $fila->nombre,
                    'clave' => $fila->clave,
                    'tipo' => $fila->tipo,
                    'zona_padre_id' => $fila->zona_padre_id !== null ? (int) $fila->zona_padre_id : null,
                ],
            ])
            ->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Estadística electoral 2024 + cobertura de campaña agregadas para una
     * zona (F7): resuelve las secciones que le pertenecen (>= 0.5 de
     * intersección, descendiendo recursivamente por `hijas()` cuando la zona
     * es un nivel intermedio sin `zona_seccion` propia) y llama
     * DIRECTAMENTE a las Actions genéricas de F5 — cero lógica de agregación
     * nueva aquí. Resolución manual (anti-binding): SubstituteBindings corre
     * antes que ResolveTenant en el stack web.
     */
    public function resumen(string $zona): JsonResponse
    {
        $modelo = Zona::query()->findOrFail($zona);
        $tenant = TenantContext::get();
        $config = $this->configuracionCompetitividad($tenant);

        $seccionIds = $this->resolverSeccionesDeZona($modelo);

        $estadistica = (new AgregarEstadisticaTerritorial)->handle(
            $seccionIds,
            $config['partidoSiglas'],
            $config['coaliciones'],
            $config['categorias'],
            $config['modoCalculo'],
            Partido::all(),
        );

        $cobertura = (new AgregarCoberturaTerritorial)->handle($seccionIds);

        return response()->json([
            'zona_id' => $modelo->id,
            'zona_nombre' => $modelo->nombre,
            'secciones_total' => $seccionIds->count(),
            'estadistica' => $estadistica,
            'cobertura' => $cobertura,
        ]);
    }

    /**
     * Secciones que pertenecen a la zona (`pct_interseccion >= 0.5`), o —si
     * es un nivel intermedio de la jerarquía sin `zona_seccion` propia— la
     * unión recursiva de las secciones de todas sus `hijas()`. Protegido
     * contra ciclos con un límite de profundidad defensivo (no deberían
     * ocurrir por construcción).
     *
     * @return Collection<int, int>
     */
    private function resolverSeccionesDeZona(Zona $zona, int $profundidad = 0): Collection
    {
        if ($profundidad > self::PROFUNDIDAD_MAXIMA) {
            return collect();
        }

        $propias = DB::table('zona_seccion')
            ->where('zona_id', $zona->id)
            ->where('pct_interseccion', '>=', 0.5)
            ->pluck('seccion_id')
            ->map(fn (mixed $id): int => (int) $id);

        $deHijas = $zona->hijas()->get()->reduce(
            fn (Collection $acumulado, Zona $hija): Collection => $acumulado->merge(
                $this->resolverSeccionesDeZona($hija, $profundidad + 1)
            ),
            collect(),
        );

        return $propias->merge($deHijas)->unique()->values();
    }

    /**
     * Config de competitividad/tipo resuelta una sola vez por request, mismo
     * patrón que `TerritorioController::configuracionCompetitividad()` (F5).
     *
     * @return array{partidoSiglas: string|null, coaliciones: Collection<int, Coalicion>, categorias: non-empty-list<array{nombre: string, color: string, umbral: int|float|null, slug: string}>, modoCalculo: 'votos'|'porcentaje', umbralAlfa: int, umbralBeta: int, indicadores: array<string, bool>}
     */
    private function configuracionCompetitividad(?Tenant $tenant): array
    {
        $efectivo = $tenant ?? new Tenant;
        $configuracion = $efectivo->configuracion();
        $modoCalculo = $configuracion['modo_calculo_competitividad'] ?? 'votos';

        return [
            'partidoSiglas' => $tenant?->partido?->siglas,
            'coaliciones' => Coalicion::where('anio', 2024)->get(),
            'categorias' => $efectivo->categoriasCompetitividad(),
            'modoCalculo' => $modoCalculo === 'porcentaje' ? 'porcentaje' : 'votos',
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
     * @return array<string, mixed>
     */
    private function presentar(Zona $zona): array
    {
        return [
            'id' => $zona->id,
            'nombre' => $zona->nombre,
            'clave' => $zona->clave,
            'descripcion' => $zona->descripcion,
            'tipo' => $zona->tipo,
            'zona_padre_id' => $zona->zona_padre_id,
            'zona_padre_nombre' => $zona->padre?->nombre,
            'fuente' => $zona->fuente,
            'activa' => $zona->activa,
            'tiene_geometria' => $zona->geom !== null,
        ];
    }
}
