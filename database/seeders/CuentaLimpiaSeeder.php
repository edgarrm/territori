<?php

namespace Database\Seeders;

use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta limpia lista para usar: crea un tenant propio sobre un municipio con
 * cartografía cargada, usuarios por rol y aviso de privacidad, y fija la meta de
 * cada sección a la MITAD de su lista nominal (fuente lista_nominal_pct al 50%).
 * NO genera electores, zonas ni red ciudadana: la cobertura arranca en 0, lista
 * para capturas reales. Idempotente. Requiere correr CartografiaSeeder (con el
 * lista_nominal.csv) primero.
 *
 * Invocar manualmente en desarrollo:
 * (new \Database\Seeders\CuentaLimpiaSeeder)->run();
 */
class CuentaLimpiaSeeder extends Seeder
{
    /** Porcentaje de la lista nominal que se fija como meta por sección. */
    private const PCT_LISTA_NOMINAL = 50;

    public function run(): void
    {
        // Municipio con secciones cargadas (no el primero a ciegas): la cartografía
        // trae municipios del estado pero secciones solo de uno.
        $municipioId = Seccion::query()
            ->select('municipio_id')
            ->groupBy('municipio_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('municipio_id');

        $municipio = $municipioId !== null
            ? Municipio::query()->find((int) $municipioId)
            : Municipio::query()->first();

        if (! $municipio) {
            $this->command?->error('No hay municipios con secciones cargadas. Corre CartografiaSeeder primero.');

            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['subdominio' => 'mazatlan'],
            [
                'nombre' => 'Campaña Mazatlán',
                'municipio_id' => $municipio->id,
                'marca_nombre' => 'Campaña Mazatlán',
                'marca_color' => '#1d4ed8',
            ],
        );

        $this->crearUsuarios($tenant);

        TenantContext::set($tenant);

        // Aviso de privacidad vigente: sin él no se puede capturar (consentimiento).
        AvisoPrivacidad::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'version' => '1.0'],
            [
                'texto' => 'Aviso de privacidad conforme a la LFPDPPP. Sus datos se usan únicamente para fines de la campaña.',
                'vigente_desde' => now(),
            ],
        );

        $this->sembrarMetas($tenant, (int) $municipio->id);
    }

    private function crearUsuarios(Tenant $tenant): void
    {
        $roles = [
            'admin@mazatlan.test' => 'admin',
            'coordinador@mazatlan.test' => 'coordinador',
            'brigadista@mazatlan.test' => 'brigadista',
            'enlace@mazatlan.test' => 'enlace',
        ];

        foreach ($roles as $email => $rol) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => ucfirst($rol).' Mazatlán', 'password' => bcrypt('password')],
            );

            Membership::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['rol' => $rol, 'meta_diaria' => $rol === 'brigadista' ? 20 : null],
            );
        }
    }

    /**
     * Meta = mitad de la lista nominal por sección. Salta las secciones sin padrón
     * (lista_nominal null/0) para NO fijar meta 0 —que dejaría la cobertura muerta—
     * y las reporta. Idempotente: limpia metas/cobertura del tenant y resiembra.
     */
    private function sembrarMetas(Tenant $tenant, int $municipioId): void
    {
        DB::table('metas_seccion')->where('tenant_id', $tenant->id)->delete();
        DB::table('cobertura_seccion')->where('tenant_id', $tenant->id)->delete();

        $secciones = Seccion::query()
            ->where('municipio_id', $municipioId)
            ->orderBy('numero')
            ->get();

        $metas = [];
        $sinPadron = 0;

        foreach ($secciones as $seccion) {
            $listaNominal = (int) ($seccion->lista_nominal ?? 0);

            if ($listaNominal <= 0) {
                $sinPadron++;

                continue;
            }

            $metas[] = [
                'tenant_id' => $tenant->id,
                'seccion_id' => $seccion->id,
                'meta_capturas' => (int) round($listaNominal * self::PCT_LISTA_NOMINAL / 100),
                'fuente_meta' => 'lista_nominal_pct',
                'pct_lista_nominal' => self::PCT_LISTA_NOMINAL,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($metas): void {
            foreach (array_chunk($metas, 1000) as $bloque) {
                DB::table('metas_seccion')->insert($bloque);
            }
        });

        // Recálculo canónico e idempotente de cobertura_seccion (capturados=0, meta,
        // cobertura=0, penetracion=0).
        Artisan::call('territori:recalcular-cobertura', ['tenant' => $tenant->id]);

        $this->command?->info(sprintf(
            'Cuenta limpia "%s": %d secciones con meta = %d%% de la lista nominal, %d sin padrón (meta omitida).',
            $tenant->subdominio,
            count($metas),
            self::PCT_LISTA_NOMINAL,
            $sinPadron,
        ));

        if ($sinPadron > 0) {
            $this->command?->warn(
                "Hay {$sinPadron} secciones sin lista nominal: quedaron sin meta (cobertura 0). ".
                'Verifica que el lista_nominal.csv cubra todas las secciones del municipio.'
            );
        }
    }
}
