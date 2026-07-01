<?php

namespace Tests\Browser;

use App\Models\Membership;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class BrigadistaTest extends DuskTestCase
{
    /**
     * Alta de un brigadista desde la UI; aparece en la tabla y queda en BD.
     */
    public function test_alta_de_brigadista(): void
    {
        [$tenant, $user] = $this->crearCampana('coordinador');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/brigadistas')
                ->waitForText('Brigadistas')
                ->type('input[placeholder="miembro@x.com"]', 'nuevo@brigada.test')
                ->type('input[placeholder="Nombre"]', 'Nuevo Brigadista')
                ->press('Agregar miembro')
                ->waitForText('nuevo@brigada.test');
        });

        $this->assertDatabaseHas('users', ['email' => 'nuevo@brigada.test']);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'rol' => 'brigadista',
        ]);
    }

    /**
     * Gestión da de alta a un enlace desde la pantalla de Miembros; aparece en
     * la tabla de "Otros miembros" y queda con rol enlace en BD.
     */
    public function test_alta_de_enlace_desde_miembros(): void
    {
        [$tenant, $user] = $this->crearCampana('coordinador');

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/brigadistas')
                ->waitForText('Otros miembros')
                ->type('input[placeholder="miembro@x.com"]', 'nuevo@enlace.test')
                ->type('input[placeholder="Nombre"]', 'Nuevo Enlace')
                ->select('select', 'enlace')
                ->press('Agregar miembro')
                ->waitForText('nuevo@enlace.test');
        });

        $user = User::where('email', 'nuevo@enlace.test')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => 'enlace',
        ]);
    }

    /**
     * Activar/desactivar un brigadista alterna su estado.
     */
    public function test_desactivar_brigadista(): void
    {
        [$tenant, $user] = $this->crearCampana('coordinador');
        $brig = User::factory()->create();
        $membership = Membership::factory()->for($tenant)->for($brig)
            ->create(['rol' => 'brigadista', 'activo' => true]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/brigadistas')
                ->waitForText('Brigadistas')
                ->press('Activo')
                ->waitForText('Inactivo');
        });

        $this->assertDatabaseHas('memberships', [
            'id' => $membership->id,
            'activo' => false,
        ]);
    }
}
