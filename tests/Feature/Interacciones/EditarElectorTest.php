<?php

namespace Tests\Feature\Interacciones;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditarElectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Seccion, 4: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $seccion, $aviso];
    }

    private function elector(Tenant $tenant, Membership $m, Seccion $s, AvisoPrivacidad $a, string $telefono): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => $telefono, 'telefono_hash' => Telefono::hash($telefono),
        ]);
    }

    public function test_edita_nombre_y_observaciones_sin_tocar_hash(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $elector = $this->elector($tenant, $m, $s, $a, '5511112222');
        $hashOriginal = $elector->telefono_hash;

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/electores/{$elector->id}", [
                'nombre' => 'Nombre Editado',
                'observaciones' => 'Vive en la esquina',
            ])->assertOk();

        TenantContext::set($tenant);
        $fresco = $elector->fresh();
        $this->assertSame('Nombre Editado', $fresco->nombre);
        $this->assertSame('Vive en la esquina', $fresco->observaciones);
        $this->assertSame($hashOriginal, $fresco->telefono_hash);
    }

    public function test_telefono_nuevo_unico_actualiza_hash(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $elector = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/electores/{$elector->id}", ['telefono' => '5599998888'])
            ->assertOk();

        TenantContext::set($tenant);
        $this->assertSame(Telefono::hash('5599998888'), $elector->fresh()->telefono_hash);
    }

    public function test_telefono_colisiona_con_otro_elector_da_409(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $this->elector($tenant, $m, $s, $a, '5511112222');
        $otro = $this->elector($tenant, $m, $s, $a, '5533334444');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/electores/{$otro->id}", ['telefono' => '5511112222'])
            ->assertStatus(409);
    }
}
