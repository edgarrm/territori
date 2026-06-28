<?php

namespace Tests\Feature\Export;

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

class ExportElectoresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Seccion, 4: AvisoPrivacidad}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $seccion, $aviso];
    }

    public function test_coordinador_exporta_csv_con_telefono_descifrado(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => 'Juana Export', 'telefono' => '5512345678',
            'telefono_hash' => Telefono::hash('5512345678'),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/api/export/electores.csv');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('nombre,telefono', $csv);
        $this->assertStringContainsString('Juana Export', $csv);
        $this->assertStringContainsString('5512345678', $csv);
    }

    public function test_brigadista_no_puede_exportar(): void
    {
        [$tenant] = $this->setupCampana('brigadista');
        $brigUser = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();

        $this->actingAs($brigUser)->withSession(['tenant_id' => $tenant->id])
            ->get('/api/export/electores.csv')->assertForbidden();
    }

    public function test_cancelados_se_excluyen_del_export(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $vivo = Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => 'Vivo Visible', 'telefono' => '5511112222',
            'telefono_hash' => Telefono::hash('5511112222'),
        ]);
        $cancelado = Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => 'Borrado Oculto', 'telefono' => '5533334444',
            'telefono_hash' => Telefono::hash('5533334444'),
        ]);
        $cancelado->delete();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/api/export/electores.csv');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Vivo Visible', $csv);
        $this->assertStringNotContainsString('Borrado Oculto', $csv);
    }

    public function test_filtro_por_seccion(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $otraSeccion = Seccion::query()->where('municipio_id', $s->municipio_id)->where('numero', 2)->first();

        Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => 'En Seccion Uno', 'telefono' => '5511110000',
            'telefono_hash' => Telefono::hash('5511110000'),
        ]);
        Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $otraSeccion->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => 'En Seccion Dos', 'telefono' => '5522220000',
            'telefono_hash' => Telefono::hash('5522220000'),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get("/api/export/electores.csv?seccion_id={$s->id}");
        $csv = $response->streamedContent();

        $this->assertStringContainsString('En Seccion Uno', $csv);
        $this->assertStringNotContainsString('En Seccion Dos', $csv);
    }
}
