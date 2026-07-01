<?php

namespace Tests\Feature\Privacidad;

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

class VisibilidadPiiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Municipio, 2: Seccion, 3: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $municipio, $seccion, $aviso];
    }

    private function miembro(Tenant $tenant, string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function elector(Tenant $tenant, Membership $m, Seccion $s, AvisoPrivacidad $a, string $telefono, string $domicilio): Elector
    {
        TenantContext::set($tenant);

        return Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => $telefono, 'telefono_hash' => Telefono::hash($telefono),
            'domicilio' => $domicilio,
        ]);
    }

    public function test_brigadista_ve_pii_completa_de_su_propio_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('telefono', '5511112222');
        $response->assertJsonPath('domicilio', 'Calle Falsa 123');
    }

    public function test_brigadista_ve_pii_enmascarada_de_elector_ajeno(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $otro, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $this->assertNotSame('5511112222', $response->json('telefono'));
        $this->assertStringContainsString('2222', $response->json('telefono'));
        $this->assertStringNotContainsString('5511112222', (string) $response->json('telefono'));
        $this->assertNotSame('Calle Falsa 123', $response->json('domicilio'));
    }

    public function test_coordinador_ve_pii_completa_de_cualquier_elector(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user] = $this->miembro($tenant, 'coordinador');
        [, $brigadista] = $this->miembro($tenant, 'brigadista');
        $elector = $this->elector($tenant, $brigadista, $seccion, $aviso, '5511112222', 'Calle Falsa 123');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$elector->id}");

        $response->assertOk();
        $response->assertJsonPath('telefono', '5511112222');
        $response->assertJsonPath('domicilio', 'Calle Falsa 123');
    }

    public function test_lista_por_seccion_enmascara_telefono_de_ajenos_para_brigadista(): void
    {
        [$tenant, , $seccion, $aviso] = $this->setupCampana();
        [$user, $membership] = $this->miembro($tenant, 'brigadista');
        [, $otro] = $this->miembro($tenant, 'brigadista');
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);
        $propio = $this->elector($tenant, $membership, $seccion, $aviso, '5511112222', 'Mi Calle 1');
        $ajeno = $this->elector($tenant, $otro, $seccion, $aviso, '5599998888', 'Otra Calle 2');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/electores");

        $response->assertOk();
        $porId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('5511112222', $porId[$propio->id]['telefono']);
        $this->assertNotSame('5599998888', $porId[$ajeno->id]['telefono']);
        $this->assertStringContainsString('8888', $porId[$ajeno->id]['telefono']);
    }
}
