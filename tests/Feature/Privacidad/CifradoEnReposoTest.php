<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P7: `ubicacion` y `observaciones` viajaban en claro a la base, lo que anulaba
 * el cifrado de `domicilio` — una coordenada exacta *es* el domicilio. Estas
 * pruebas leen la columna cruda (sin pasar por los casts del modelo) para
 * comprobar que en disco solo hay ciphertext.
 */
class CifradoEnReposoTest extends TestCase
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

    public function test_la_captura_guarda_ubicacion_y_observaciones_cifradas(): void
    {
        [$tenant, $user, , $seccion, $aviso] = $this->setupCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Ana López',
                'telefono' => '5511112222',
                'observaciones' => 'Padece diabetes',
                'ubicacion' => ['lat' => 0.5, 'lng' => 0.5],
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertCreated();

        $crudo = DB::table('electores')->where('id', $response->json('id'))->first();

        $this->assertStringNotContainsString('Padece diabetes', $crudo->observaciones);
        $this->assertStringNotContainsString('0.5', $crudo->ubicacion);

        // Y el modelo sigue devolviendo los valores originales tras descifrar.
        TenantContext::set($tenant);
        $elector = Elector::query()->find($response->json('id'));
        $this->assertSame('Padece diabetes', $elector->observaciones);
        $this->assertSame(['lat' => 0.5, 'lng' => 0.5], $elector->ubicacion);
    }
}
