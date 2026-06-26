<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
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
        $municipio = Municipio::query()->first();

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
    }
}
