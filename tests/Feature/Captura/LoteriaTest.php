<?php

namespace Tests\Feature\Captura;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoteriaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio, 3: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $municipio, $aviso];
    }

    public function test_abrir_loteria_devuelve_id_y_seccion(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $response->assertCreated();
        $response->assertJsonPath('seccion_id', $seccion->id);
        $this->assertNotNull($response->json('loteria_id'));
    }

    public function test_abrir_segunda_loteria_devuelve_la_existente(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $otra = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();

        $primera = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);
        $idPrimera = $primera->json('loteria_id');

        $segunda = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $otra->id]);

        $this->assertSame($idPrimera, $segunda->json('loteria_id'));

        TenantContext::set($tenant);
        $this->assertSame(1, Loteria::query()->whereNull('cerrada_en')->count());
    }

    public function test_activa_devuelve_la_loteria_abierta(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/loterias/activa');

        $response->assertOk();
        $response->assertJsonPath('loteria.loteria_id', $abierta->json('loteria_id'));
    }

    public function test_activa_devuelve_null_sin_loteria(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/loterias/activa');

        $response->assertOk();
        $response->assertJsonPath('loteria', null);
    }

    public function test_cerrar_loteria(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/loterias/{$abierta->json('loteria_id')}/cerrar")
            ->assertOk();

        TenantContext::set($tenant);
        $this->assertNotNull(Loteria::query()->find($abierta->json('loteria_id'))->cerrada_en);
    }

    public function test_electores_lista_los_capturados_de_la_loteria(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);
        $loteriaId = $abierta->json('loteria_id');

        foreach (['Ana Capturada', 'Beto Capturado'] as $i => $nombre) {
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
                ->postJson('/api/electores', [
                    'modo_captura' => 'loteria',
                    'nombre' => $nombre,
                    'telefono' => '551234567'.$i,
                    'consentimiento' => true,
                    'aviso_privacidad_id' => $aviso->id,
                ])->assertCreated();
        }

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$loteriaId}/electores");

        $response->assertOk();
        $response->assertJsonPath('total', 2);
        $response->assertJsonCount(2, 'electores');
        $response->assertJsonFragment(['nombre' => 'Ana Capturada']);
        $response->assertJsonFragment(['nombre' => 'Beto Capturado']);
        // El brigadista que capturó ve el teléfono completo.
        $response->assertJsonFragment(['telefono' => '5512345670']);
    }

    public function test_electores_enmascara_telefono_para_quien_no_capturo(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'nombre' => 'Carmen Capturada',
                'telefono' => '5512345670',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        // Otro brigadista del mismo tenant que no capturó a estos electores.
        $otro = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $otro->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($otro)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$abierta->json('loteria_id')}/electores");

        $response->assertOk();
        $response->assertJsonFragment(['telefono' => '••••••5670']);
        $response->assertJsonMissing(['telefono' => '5512345670']);
    }

    public function test_electores_solo_devuelve_los_de_su_loteria(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/loterias/{$abierta->json('loteria_id')}/electores");

        $response->assertOk();
        $response->assertJsonPath('total', 0);
        $response->assertJsonCount(0, 'electores');
    }

    public function test_captura_modo_loteria_hereda_seccion_y_loteria(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();

        $abierta = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $seccion->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'nombre' => 'Voto Lotería',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $elector = Elector::query()->first();
        $this->assertSame('loteria', $elector->modo_captura);
        $this->assertSame($seccion->id, $elector->seccion_id);
        $this->assertSame($abierta->json('loteria_id'), $elector->loteria_id);
    }

    public function test_modo_loteria_sin_sesion_abierta_falla(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'loteria',
                'nombre' => 'Sin Sesión',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ]);

        $response->assertStatus(422);
    }
}
