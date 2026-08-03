<?php

namespace Tests\Feature\Settings;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrar la cuenta cruzaba la frontera entre "datos del usuario" y "datos de la
 * campaña": la FK de `loterias` y `redes_ciudadanas` estaba en CASCADE (borraba
 * loterías y redes ajenas al usuario) y la de `interacciones` en NO ACTION (el
 * borrado reventaba con SQLSTATE[23503]). Además nada impedía que el último
 * admin se diera de baja y dejara la campaña sin quien la administre.
 */
class BorradoDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Seccion}
     */
    private function campanaConAdmin(string $rol = 'admin'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->sole();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
            'activo' => true,
        ]);

        TenantContext::set($tenant);

        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->sole();

        return [$tenant, $user, $membership, $seccion];
    }

    private function sembrarDatosDeCampana(Membership $membership, Seccion $seccion): Loteria
    {
        $loteria = Loteria::create([
            'membership_id' => $membership->id,
            'creada_por_membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
            'nombre' => 'Lotería del barrio',
            'fecha' => now(),
        ]);

        RedCiudadana::create([
            'enlace_membership_id' => $membership->id,
            'nombre' => 'Red del centro',
        ]);

        $elector = Elector::factory()->create([
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
        ]);

        Interaccion::create([
            'elector_id' => $elector->id,
            'membership_id' => $membership->id,
            'tipo' => 'llamada',
            'fecha' => now(),
        ]);

        return $loteria;
    }

    public function test_borrar_la_cuenta_no_falla_con_interacciones_registradas(): void
    {
        // Antes: SQLSTATE[23503] por interacciones_membership_id_foreign, o sea
        // un 500 para cualquiera que hubiera registrado una sola interacción.
        [, $user, $membership, $seccion] = $this->campanaConAdmin('coordinador');
        $this->sembrarDatosDeCampana($membership, $seccion);

        // Un segundo admin, para que la guardia del último admin no intervenga.
        Membership::create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'admin',
            'activo' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
    }

    public function test_borrar_la_cuenta_conserva_loterias_y_redes_de_la_campana(): void
    {
        [, $user, $membership, $seccion] = $this->campanaConAdmin('coordinador');
        $loteria = $this->sembrarDatosDeCampana($membership, $seccion);

        Membership::create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'admin',
            'activo' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        // La lotería y la red son datos de la campaña: sobreviven al miembro y
        // solo pierden la atribución.
        $this->assertTrue(Loteria::query()->whereKey($loteria->id)->exists());
        $this->assertTrue(RedCiudadana::query()->where('nombre', 'Red del centro')->exists());
        $this->assertNull(Loteria::query()->whereKey($loteria->id)->sole()->membership_id);
        $this->assertNull(RedCiudadana::query()->where('nombre', 'Red del centro')->sole()->enlace_membership_id);

        // Y los electores capturados siguen ahí, también sin atribución.
        $this->assertSame(1, Elector::query()->count());
        $this->assertSame(1, Interaccion::query()->count());
    }

    public function test_el_ultimo_admin_no_puede_eliminar_su_cuenta(): void
    {
        [, $user] = $this->campanaConAdmin();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh());
    }

    public function test_un_admin_puede_eliminar_su_cuenta_si_queda_otro(): void
    {
        [$tenant, $user] = $this->campanaConAdmin();

        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'admin',
            'activo' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
    }

    public function test_un_admin_desactivado_no_cuenta_como_relevo(): void
    {
        [$tenant, $user] = $this->campanaConAdmin();

        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'admin',
            'activo' => false,
        ]);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh());
    }
}
