<?php

namespace App\Http\Controllers;

use App\Actions\Metas\DefinirMetaSeccion;
use App\Models\CoberturaSeccion;
use App\Models\MetaSeccion;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MapaController extends Controller
{
    private const TOLERANCIA_SIMPLIFICACION = 0.0001;

    public function cobertura(Request $request): JsonResponse
    {
        $tenant = TenantContext::get();

        $filas = DB::table('secciones')
            ->leftJoin('cobertura_seccion', function ($join) use ($tenant) {
                $join->on('secciones.id', '=', 'cobertura_seccion.seccion_id')
                    ->where('cobertura_seccion.tenant_id', '=', $tenant?->id);
            })
            ->where('secciones.municipio_id', $tenant?->municipio_id)
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
                DB::raw('ST_AsGeoJSON(ST_SimplifyPreserveTopology(secciones.geom, '.self::TOLERANCIA_SIMPLIFICACION.')) as geom_json'),
            ])
            ->get();

        $features = $filas->map(fn ($fila) => [
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
            ],
        ])->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    public function resumenSeccion(Seccion $seccion): JsonResponse
    {
        $tenant = TenantContext::get();

        $cobertura = CoberturaSeccion::where('tenant_id', $tenant?->id)
            ->where('seccion_id', $seccion->id)
            ->first();

        return response()->json([
            'numero' => $seccion->numero,
            'capturados' => $cobertura !== null ? $cobertura->capturados : 0,
            'meta' => $cobertura !== null ? $cobertura->meta : 0,
            'cobertura' => $cobertura !== null ? (float) $cobertura->cobertura : 0,
            'penetracion' => $cobertura !== null ? (float) $cobertura->penetracion : 0,
            'brigadistas_activos' => [],
            'ultimo_registro' => null,
        ]);
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
}
