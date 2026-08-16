<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Zona>
 */
class ZonaFactory extends Factory
{
    protected $model = Zona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nombre' => 'Zona '.fake()->unique()->word(),
            'clave' => null,
            'descripcion' => null,
            'tipo' => 'colonia',
            'zona_padre_id' => null,
            'fuente' => 'dibujada',
            'activa' => true,
            'creado_por_membership_id' => null,
        ];
    }

    /**
     * `geom` no es fillable-por-Eloquent en las Actions reales (se escribe con
     * ST_GeomFromGeoJSON crudo, igual que CartografiaSeeder); la factory hace
     * lo mismo aquí para no depender de la conversión GeoJSON->Geometry del
     * paquete, que exige que el `type` del GeoJSON coincida exactamente con
     * la clase del cast (MultiPolygon).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Zona $zona): void {
            $geometria = [
                'type' => 'Polygon',
                'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]],
            ];

            DB::statement(
                'update zonas set geom = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) where id = ?',
                [json_encode($geometria), $zona->id]
            );
        });
    }

    public function sinGeom(): static
    {
        return $this->afterCreating(function (Zona $zona): void {
            DB::table('zonas')->where('id', $zona->id)->update(['geom' => null]);
        });
    }

    public function mundial(): static
    {
        return $this->state(['tipo' => 'mundial'])->sinGeom();
    }
}
