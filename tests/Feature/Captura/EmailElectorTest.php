<?php

namespace Tests\Feature\Captura;

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

class EmailElectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio, 3: AvisoPrivacidad}
     */
    private function setupCampana(string $rol = 'brigadista'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();

        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $municipio, $aviso];
    }

    private function seccion(Municipio $municipio): Seccion
    {
        return Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
    }

    public function test_captura_con_email_valido_persiste_y_se_devuelve(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = $this->seccion($municipio);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Con Email',
                'telefono' => '5512345678',
                'email' => 'con.email@correo.com',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('email', 'con.email@correo.com');

        TenantContext::set($tenant);
        $this->assertSame('con.email@correo.com', Elector::query()->first()->email);
    }

    public function test_captura_sin_email_es_valida(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = $this->seccion($municipio);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Sin Email',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertCreated();

        TenantContext::set($tenant);
        $this->assertNull(Elector::query()->first()->email);
    }

    public function test_rechaza_email_invalido(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = $this->seccion($municipio);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Email Malo',
                'telefono' => '5512345678',
                'email' => 'no-es-un-email',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');

        TenantContext::set($tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_email_se_cifra_en_reposo(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = $this->seccion($municipio);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Cifrado',
                'telefono' => '5512345678',
                'email' => 'secreto@correo.com',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        $crudo = DB::table('electores')->first();
        $this->assertNotSame('secreto@correo.com', $crudo->email);

        TenantContext::set($tenant);
        $this->assertSame('secreto@correo.com', Elector::query()->first()->email);
    }

    public function test_email_se_devuelve_enmascarado_para_brigadista_ajeno(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = $this->seccion($municipio);

        $otroUser = User::factory()->create();
        $otro = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otroUser->id, 'rol' => 'brigadista']);

        TenantContext::set($tenant);
        $elector = Elector::factory()->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $otro->id,
            'aviso_privacidad_id' => $aviso->id,
            'email' => 'privado@correo.com',
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $this->assertNotSame('privado@correo.com', $response->json('email'));
        $this->assertStringContainsString('@correo.com', (string) $response->json('email'));
        $this->assertStringNotContainsString('privado', (string) $response->json('email'));
    }
}
