<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa zonas personalizadas (F6) desde un GeoJSON FeatureCollection
 * preparado localmente (mismo espíritu que CartografiaSeeder): upsert
 * idempotente por (tenant_id, clave), no reproyecta (el GeoJSON debe venir ya
 * en 4326).
 */
#[Signature('territori:importar-zonas
    {archivo : Ruta al GeoJSON FeatureCollection}
    {--nivel= : Tipo/nivel a asignar a cada zona importada (ciudad|municipio|estado|pais|...)}
    {--tenant= : Id del tenant dueño de las zonas}
    {--padre-clave= : Clave de una zona existente del tenant para anidar todas las importadas como sus hijas}')]
#[Description('Importa un GeoJSON de zonas personalizadas a un tenant (upsert idempotente por clave).')]
class ImportarZonasCommand extends Command
{
    public function handle(): int
    {
        $ruta = (string) $this->argument('archivo');
        $nivel = $this->option('nivel');
        $tenantOpt = $this->option('tenant');
        $padreClave = $this->option('padre-clave');

        if (! $nivel) {
            $this->error('--nivel es obligatorio.');

            return self::FAILURE;
        }

        if (! $tenantOpt) {
            $this->error('--tenant es obligatorio.');

            return self::FAILURE;
        }

        if (! is_file($ruta)) {
            $this->error("No existe el archivo: {$ruta}");

            return self::FAILURE;
        }

        $tenant = Tenant::find((int) $tenantOpt);

        if ($tenant === null) {
            $this->error("No existe el tenant {$tenantOpt}.");

            return self::FAILURE;
        }

        // Comando de sistema: fija el TenantContext explícito (mismo patrón
        // que territori:recalcular-cobertura), no lo toma de ninguna sesión.
        TenantContext::set($tenant);

        $zonaPadreId = null;

        if ($padreClave) {
            $zonaPadreId = DB::table('zonas')
                ->where('tenant_id', $tenant->id)
                ->where('clave', $padreClave)
                ->value('id');

            if ($zonaPadreId === null) {
                $this->error("No se encontró una zona del tenant con clave '{$padreClave}' para usar como padre.");

                return self::FAILURE;
            }
        }

        $features = $this->leerFeatures($ruta);
        $insertadas = 0;
        $invalidas = 0;

        foreach ($features as $feature) {
            $propiedades = $feature['properties'] ?? [];
            $nombre = $propiedades['nombre'] ?? null;

            if (! $nombre) {
                $invalidas++;

                continue;
            }

            $clave = $propiedades['clave'] ?? null;
            $geometria = $feature['geometry'] ?? null;

            if ($geometria !== null) {
                $valida = DB::selectOne(
                    'select ST_IsValid(ST_GeomFromGeoJSON(?)) as valida',
                    [json_encode($geometria)]
                )->valida;

                if (! $valida) {
                    $invalidas++;

                    continue;
                }
            }

            $this->upsertZona($tenant->id, (string) $nombre, $clave, (string) $nivel, $zonaPadreId, $geometria);

            $insertadas++;
        }

        $this->info("zonas insertadas/actualizadas: {$insertadas}");
        $this->info("zonas invalidas: {$invalidas}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $geometria
     */
    private function upsertZona(int $tenantId, string $nombre, ?string $clave, string $nivel, ?int $zonaPadreId, ?array $geometria): void
    {
        $geomExpr = $geometria !== null ? 'ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))' : 'NULL';

        $bindings = [$tenantId, $nombre, $clave, $nivel, $zonaPadreId];

        if ($geometria !== null) {
            $bindings[] = json_encode($geometria);
        }

        DB::statement(
            "insert into zonas (tenant_id, nombre, clave, tipo, zona_padre_id, fuente, activa, geom, created_at, updated_at)
             values (?, ?, ?, ?, ?, 'importada', true, {$geomExpr}, now(), now())
             on conflict (tenant_id, clave) do update set
                nombre = excluded.nombre,
                tipo = excluded.tipo,
                zona_padre_id = excluded.zona_padre_id,
                geom = excluded.geom,
                updated_at = now()",
            $bindings
        );
    }

    /**
     * @return array<int, array{properties?: array<string, mixed>, geometry?: array<string, mixed>|null}>
     */
    private function leerFeatures(string $ruta): array
    {
        $crudo = file_get_contents($ruta);

        if ($crudo === false) {
            throw new \RuntimeException("No se pudo leer el archivo: {$ruta}");
        }

        $contenido = json_decode($crudo, true);

        return $contenido['features'] ?? [];
    }
}
