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
use Tests\Concerns\AsignaZonas;
use Tests\TestCase;

class CapturaElectorTest extends TestCase
{
    use AsignaZonas;
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
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        if ($rol === 'brigadista') {
            $this->asignarTodasLasZonas($membership, $municipio->id);
        }

        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $municipio, $aviso];
    }

    public function test_captura_enlace_seccional_con_seccion_explicita(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Juana Pérez',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame($seccion->id, $elector->seccion_id);
        $this->assertSame('enlace_seccional', $elector->modo_captura);
        $this->assertSame('Juana Pérez', $elector->nombre);
        $membership = Membership::query()->where('user_id', $user->id)->first();
        $this->assertSame($membership->id, $elector->membership_id);
    }

    public function test_captura_enlace_seccional_resuelve_seccion_por_gps(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccionUno = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'nombre' => 'Pedro GPS',
                'telefono' => '5500000001',
                'ubicacion' => ['lat' => 0.5, 'lng' => 0.5],
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertCreated();

        TenantContext::set($tenant);
        $this->assertSame($seccionUno->id, Elector::query()->first()->seccion_id);
    }

    public function test_rechaza_captura_sin_consentimiento(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Sin Consentimiento',
                'telefono' => '5512345678',
                'consentimiento' => false,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertStatus(422);

        TenantContext::set($tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_rechaza_telefono_con_menos_de_diez_digitos(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Tel Corto',
                'telefono' => '123',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertStatus(422);

        TenantContext::set($tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_dedup_por_telefono_devuelve_409_sin_filtrar_id(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $payload = [
            'modo_captura' => 'enlace_seccional',
            'seccion_id' => $seccion->id,
            'nombre' => 'Primero',
            'telefono' => '55 1234 5678',
            'consentimiento' => true,
            'aviso_privacidad_id' => $aviso->id,
        ];

        $primero = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', $payload);
        $primero->assertCreated();

        $segundo = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', array_merge($payload, ['nombre' => 'Duplicado', 'telefono' => '(55) 1234-5678']));

        // 409, pero NO debe revelar el id del elector existente (oráculo de enumeración).
        $segundo->assertStatus(409);
        $segundo->assertJsonMissingPath('id');

        TenantContext::set($tenant);
        $this->assertSame(1, Elector::query()->count());
    }

    public function test_mismo_telefono_en_dos_tenants_no_se_deduplica(): void
    {
        [$tenantA, $userA, $municipioA, $avisoA] = $this->setupCampana();
        $seccionA = Seccion::query()->where('municipio_id', $municipioA->id)->where('numero', 1)->first();

        $tenantB = Tenant::factory()->create(['municipio_id' => $municipioA->id]);
        $userB = User::factory()->create();
        $brigB = Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'rol' => 'brigadista']);
        $this->asignarTodasLasZonas($brigB, $municipioA->id);
        TenantContext::set($tenantB);
        $avisoB = AvisoPrivacidad::factory()->create(['tenant_id' => $tenantB->id]);

        $payload = [
            'modo_captura' => 'enlace_seccional',
            'seccion_id' => $seccionA->id,
            'nombre' => 'Misma Persona',
            'telefono' => '5512345678',
            'consentimiento' => true,
        ];

        $this->actingAs($userA)->withSession(['tenant_id' => $tenantA->id])
            ->postJson('/api/electores', array_merge($payload, ['aviso_privacidad_id' => $avisoA->id]))
            ->assertCreated();

        $this->actingAs($userB)->withSession(['tenant_id' => $tenantB->id])
            ->postJson('/api/electores', array_merge($payload, ['aviso_privacidad_id' => $avisoB->id]))
            ->assertCreated();

        $this->assertSame(2, Elector::withoutGlobalScopes()->count());
    }

    public function test_usuario_sin_membership_no_puede_capturar(): void
    {
        [$tenant, , $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $intruso = User::factory()->create();

        $response = $this->actingAs($intruso)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Intruso',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertForbidden();
    }

    public function test_no_captura_en_seccion_de_otro_municipio(): void
    {
        [$tenant, $user, , $aviso] = $this->setupCampana();
        $otroMunicipio = Municipio::query()->where('clave', 1)->first();
        $seccionAjena = Seccion::query()->where('municipio_id', $otroMunicipio->id)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccionAjena->id,
                'nombre' => 'Fuera de municipio',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('seccion_id');

        TenantContext::set($tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_no_puede_ver_elector_de_otro_tenant(): void
    {
        [$tenantA, $userA, $municipio, $avisoA] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        TenantContext::set($tenantA);
        $elector = Elector::factory()->create([
            'tenant_id' => $tenantA->id,
            'seccion_id' => $seccion->id,
            'aviso_privacidad_id' => $avisoA->id,
            'membership_id' => Membership::query()->where('tenant_id', $tenantA->id)->first()->id,
        ]);

        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $userB = User::factory()->create();
        Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'rol' => 'coordinador']);

        $response = $this->actingAs($userB)->withSession(['tenant_id' => $tenantB->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertNotFound();
    }

    public function test_telefono_y_domicilio_estan_cifrados_en_reposo(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Cifrado',
                'telefono' => '5512345678',
                'domicilio' => 'Calle Falsa 123',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        $crudo = DB::table('electores')->first();
        $this->assertNotSame('5512345678', $crudo->telefono);
        $this->assertNotSame('Calle Falsa 123', $crudo->domicilio);

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame('5512345678', $elector->telefono);
        $this->assertSame('Calle Falsa 123', $elector->domicilio);
    }
}
