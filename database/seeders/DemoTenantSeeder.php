<?php

namespace Database\Seeders;

use App\Actions\Brigadistas\AsignarZonas;
use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DemoTenantSeeder extends Seeder
{
    /**
     * Crea un tenant demo sobre un municipio ya cargado (ver CartografiaSeeder)
     * con un usuario por rol. Invocar manualmente en desarrollo:
     * (new \Database\Seeders\DemoTenantSeeder)->run();
     */
    public function run(): void
    {
        // Elegir un municipio que tenga secciones cargadas (no el primero a ciegas):
        // la cartografía puede traer municipios del estado pero secciones solo de uno.
        $municipioId = Seccion::query()
            ->select('municipio_id')
            ->groupBy('municipio_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('municipio_id');

        $municipio = $municipioId !== null
            ? Municipio::query()->find((int) $municipioId)
            : Municipio::query()->first();

        if (! $municipio) {
            if (isset($this->command)) {
                $this->command->error('No hay municipios cargados. Corre CartografiaSeeder primero.');
            }

            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['subdominio' => 'demo'],
            [
                'nombre' => 'Campaña Demo',
                'municipio_id' => $municipio->id,
                'marca_nombre' => 'Campaña Demo',
                'marca_color' => '#1d4ed8',
            ],
        );

        $roles = [
            'admin@demo.test' => 'admin',
            'coordinador@demo.test' => 'coordinador',
            'brigadista@demo.test' => 'brigadista',
            'enlace@demo.test' => 'enlace',
        ];

        foreach ($roles as $email => $rol) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => ucfirst($rol).' Demo', 'password' => bcrypt('password')],
            );

            Membership::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['rol' => $rol, 'meta_diaria' => $rol === 'brigadista' ? 20 : null],
            );
        }

        // Aviso de privacidad vigente: sin él no se puede capturar (consentimiento).
        TenantContext::set($tenant);
        AvisoPrivacidad::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'version' => '1.0'],
            [
                'texto' => 'Aviso de privacidad demo conforme a la LFPDPPP. Sus datos se usan únicamente para fines de la campaña.',
                'vigente_desde' => now(),
            ],
        );

        // Asigna algunas secciones al brigadista demo para ver zonas y
        // poblar brigadistas_activos en el resumen del mapa.
        $brigadista = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('rol', 'brigadista')
            ->first();

        $seccionIds = Seccion::query()
            ->where('municipio_id', $municipio->id)
            ->orderBy('numero')
            ->limit(5)
            ->pluck('id')
            ->all();

        if ($brigadista && $seccionIds !== []) {
            (new AsignarZonas)->handle($brigadista, $seccionIds);
        }

        // Red ciudadana demo cuyo enlace es el usuario con rol "enlace".
        $enlace = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('rol', 'enlace')
            ->first();

        if ($enlace) {
            RedCiudadana::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'nombre' => 'Red Vecinal Centro'],
                ['enlace_membership_id' => $enlace->id, 'descripcion' => 'Red ciudadana demo del enlace.'],
            );
        }
    }
}
