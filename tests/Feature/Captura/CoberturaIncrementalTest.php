<?php

namespace Tests\Feature\Captura;

use App\Actions\Cobertura\RecalcularCoberturaSeccion;
use App\Actions\Metas\DefinirMetaSeccion;
use App\Events\ElectorCapturado;
use App\Jobs\ActualizarCoberturaSeccion;
use App\Models\AvisoPrivacidad;
use App\Models\CoberturaSeccion;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AsignaZonas;
use Tests\TestCase;

class CoberturaIncrementalTest extends TestCase
{
    use AsignaZonas;
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio, 3: AvisoPrivacidad, 4: Membership}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);
        $this->asignarTodasLasZonas($membership, $municipio->id);
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $municipio, $aviso, $membership];
    }

    public function test_capturar_dispara_evento_elector_capturado(): void
    {
        Event::fake([ElectorCapturado::class]);

        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Disparo',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        Event::assertDispatched(ElectorCapturado::class, function (ElectorCapturado $e) use ($tenant, $seccion) {
            return $e->tenantId === $tenant->id && $e->seccionId === $seccion->id;
        });
    }

    public function test_captura_actualiza_cobertura_seccion(): void
    {
        [$tenant, $user, $municipio, $aviso] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        TenantContext::set($tenant);
        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 4);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'individual',
                'seccion_id' => $seccion->id,
                'nombre' => 'Capturado',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $aviso->id,
            ])->assertCreated();

        TenantContext::set($tenant);
        $cobertura = CoberturaSeccion::query()->where('seccion_id', $seccion->id)->first();
        $this->assertSame(1, $cobertura->capturados);
        $this->assertSame('0.2500', $cobertura->cobertura);
    }

    public function test_job_es_idempotente(): void
    {
        [$tenant, , $municipio, $aviso, $membership] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        TenantContext::set($tenant);
        Elector::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'aviso_privacidad_id' => $aviso->id,
        ]);

        (new ActualizarCoberturaSeccion($tenant->id, $seccion->id))->handle(new RecalcularCoberturaSeccion);
        (new ActualizarCoberturaSeccion($tenant->id, $seccion->id))->handle(new RecalcularCoberturaSeccion);

        TenantContext::set($tenant);
        $this->assertSame(3, CoberturaSeccion::query()->where('seccion_id', $seccion->id)->first()->capturados);
    }

    public function test_recalcular_cobertura_cuenta_electores_reales(): void
    {
        [$tenant, , $municipio, $aviso, $membership] = $this->setupCampana();
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();

        TenantContext::set($tenant);
        Elector::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'aviso_privacidad_id' => $aviso->id,
        ]);

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenant->id])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(2, CoberturaSeccion::query()->where('seccion_id', $seccion->id)->first()->capturados);
    }
}
