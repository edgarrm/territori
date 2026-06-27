<?php

namespace Tests\Feature\Interacciones;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private Seccion $seccion;

    private AvisoPrivacidad $aviso;

    protected function setUp(): void
    {
        parent::setUp();
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
        $this->seccion = Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', 1)->first();
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function miembro(string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function elector(Membership $membership): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $this->seccion->id,
            'membership_id' => $membership->id,
            'aviso_privacidad_id' => $this->aviso->id,
        ]);
    }

    public function test_seguimiento_vencido_aparece_y_al_marcar_atendido_desaparece(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        $interaccion = Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
        ]);

        $antes = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $antes->assertOk();
        $this->assertCount(1, $antes->json());

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        $despues = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $this->assertCount(0, $despues->json());
    }

    public function test_seguimiento_futuro_no_aparece(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        Interaccion::factory()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
            'proximo_seguimiento' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $this->assertCount(0, $response->json());
    }

    public function test_brigadista_ve_solo_los_suyos_coordinador_ve_todos(): void
    {
        [$userBrig, $brig] = $this->miembro('brigadista');
        [$userCoord, $coord] = $this->miembro('coordinador');

        $electorBrig = $this->elector($brig);
        $electorCoord = $this->elector($coord);

        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $electorBrig->id, 'membership_id' => $brig->id,
        ]);
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $electorCoord->id, 'membership_id' => $coord->id,
        ]);

        $brigResp = $this->actingAs($userBrig)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $this->assertCount(1, $brigResp->json());

        $coordResp = $this->actingAs($userCoord)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $this->assertCount(2, $coordResp->json());
    }

    public function test_marcar_atendido_es_idempotente(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        $interaccion = Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        TenantContext::set($this->tenant);
        $marca = Interaccion::query()->find($interaccion->id)->atendido_en;
        $this->assertNotNull($marca);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        // La segunda marca no reescribe la primera (idempotente): mismo instante persistido.
        TenantContext::set($this->tenant);
        $this->assertTrue($marca->equalTo(Interaccion::query()->find($interaccion->id)->atendido_en));
    }
}
