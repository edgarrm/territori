<?php

namespace App\Http\Controllers;

use App\Actions\Zonas\ActualizarZona;
use App\Actions\Zonas\CrearZona;
use App\Http\Requests\StoreZonaRequest;
use App\Http\Requests\UpdateZonaRequest;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ZonaController extends Controller
{
    private const TOLERANCIA_SIMPLIFICACION = 0.0001;

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
