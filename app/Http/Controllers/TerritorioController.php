<?php

namespace App\Http\Controllers;

use App\Actions\Cobertura\AgregarCoberturaTerritorial;
use App\Actions\Estadisticas\AgregarEstadisticaTerritorial;
use App\Models\Coalicion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregación estadística territorial (F5): resumen y geometría a nivel
 * municipio/estado, reusando exactamente los mismos cálculos de
 * competitividad/desglose ya validados por sección (F2/F3).
 */
class TerritorioController extends Controller
{
    private const TOLERANCIA_SIMPLIFICACION = 0.0001;

    public function resumen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nivel' => ['required', 'in:municipio,entidad'],
            'id' => ['nullable', 'integer'],
        ]);

        $tenant = TenantContext::get();
        $viewer = $this->miMembership();
        $config = $this->configuracionCompetitividad($tenant);

        $seccionIds = $this->resolverSecciones($data['nivel'], $data['id'] ?? null, $tenant);

        if ($viewer?->esBrigadista()) {
            $asignadas = $viewer->secciones()->pluck('secciones.id');
            $seccionIds = $seccionIds->intersect($asignadas)->values();
        }

        $estadistica = (new AgregarEstadisticaTerritorial)->handle(
            $seccionIds,
            $config['partidoSiglas'],
            $config['coaliciones'],
            $config['categorias'],
            $config['modoCalculo'],
            Partido::all(),
        );

        $cobertura = (new AgregarCoberturaTerritorial)->handle($seccionIds);

        $respuesta = [
            'nivel' => $data['nivel'],
            'secciones_total' => $seccionIds->count(),
            'estadistica' => $estadistica,
            'cobertura' => $cobertura,
        ];

        if ($data['nivel'] === 'entidad') {
            $entidadId = $data['id'] ?? $tenant?->municipio?->entidad_id;
            $respuesta['cobertura_cartografia'] = $this->coberturaCartografia($entidadId);
        }

        return response()->json($respuesta);
    }

    public function geojson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nivel' => ['required', 'in:municipio,entidad'],
            'id' => ['nullable', 'integer'],
        ]);

        $tenant = TenantContext::get();

        $features = match ($data['nivel']) {
            'municipio' => $this->featuresMunicipio($data['id'] ?? $tenant?->municipio_id),
            'entidad' => $this->featuresEntidad($data['id'] ?? $tenant?->municipio?->entidad_id),
        };

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function resolverSecciones(string $nivel, ?int $id, ?Tenant $tenant): Collection
    {
        return match ($nivel) {
            'municipio' => $this->seccionesDeMunicipio($id ?? $tenant?->municipio_id),
            'entidad' => $this->seccionesDeEntidad($id ?? $tenant?->municipio?->entidad_id),
        };
    }

    /**
     * @return Collection<int, int>
     */
    private function seccionesDeMunicipio(?int $municipioId): Collection
    {
        return Seccion::query()->where('municipio_id', $municipioId)->pluck('id');
    }

    /**
     * @return Collection<int, int>
     */
    private function seccionesDeEntidad(?int $entidadId): Collection
    {
        $municipioIds = Municipio::query()->where('entidad_id', $entidadId)->pluck('id');

        return Seccion::query()->whereIn('municipio_id', $municipioIds)->pluck('id');
    }

    /**
     * @return array{municipios_cargados: int, municipios_totales: int}
     */
    private function coberturaCartografia(?int $entidadId): array
    {
        return [
            'municipios_cargados' => Municipio::query()
                ->where('entidad_id', $entidadId)
                ->whereHas('secciones')
                ->count(),
            'municipios_totales' => Municipio::query()->where('entidad_id', $entidadId)->count(),
        ];
    }

    /**
     * @return list<array{type: string, geometry: mixed, properties: array{municipio_id: int, nombre: string}}>
     */
    private function featuresMunicipio(?int $municipioId): array
    {
        return DB::table('municipios')
            ->where('id', $municipioId)
            ->select([
                'id as municipio_id',
                'nombre',
                DB::raw('ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, '.self::TOLERANCIA_SIMPLIFICACION.')) as geom_json'),
            ])
            ->get()
            ->map(fn (object $fila): array => [
                'type' => 'Feature',
                'geometry' => $fila->geom_json ? json_decode((string) $fila->geom_json) : null,
                'properties' => [
                    'municipio_id' => (int) $fila->municipio_id,
                    'nombre' => $fila->nombre,
                ],
            ])
            ->all();
    }

    /**
     * Un Feature por cada municipio de la entidad (la geometría visual sigue
     * siendo por municipio, no se funde en un solo polígono de estado).
     *
     * @return list<array{type: string, geometry: mixed, properties: array{municipio_id: int, nombre: string}}>
     */
    private function featuresEntidad(?int $entidadId): array
    {
        return DB::table('municipios')
            ->where('entidad_id', $entidadId)
            ->select([
                'id as municipio_id',
                'nombre',
                DB::raw('ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, '.self::TOLERANCIA_SIMPLIFICACION.')) as geom_json'),
            ])
            ->get()
            ->map(fn (object $fila): array => [
                'type' => 'Feature',
                'geometry' => $fila->geom_json ? json_decode((string) $fila->geom_json) : null,
                'properties' => [
                    'municipio_id' => (int) $fila->municipio_id,
                    'nombre' => $fila->nombre,
                ],
            ])
            ->all();
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
     * Config de competitividad/tipo resuelta una sola vez por request, mismo
     * patrón que `MapaController::configuracionCompetitividad()` (F2).
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
}
