<?php

namespace Tests\Feature\Brigadistas;

use App\Actions\Brigadistas\AsignarZonas;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AsignarZonasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Tenant, Municipio, Membership}
     */
    private function tenantConBrigadista(): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $brigadista = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $municipio, $brigadista];
    }

    public function test_sync_reemplaza_y_persiste_tenant_id(): void
    {
        [$tenant, $municipio, $brigadista] = $this->tenantConBrigadista();
        $a = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $b = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $c = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);

        (new AsignarZonas)->handle($brigadista, [$a->id, $b->id]);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            DB::table('brigadista_seccion')->where('membership_id', $brigadista->id)->pluck('seccion_id')->all()
        );
        $this->assertSame($tenant->id, DB::table('brigadista_seccion')->where('membership_id', $brigadista->id)->value('tenant_id'));

        // Reenviar otra lista reemplaza, no acumula.
        (new AsignarZonas)->handle($brigadista, [$c->id]);
        $this->assertEqualsCanonicalizing(
            [$c->id],
            DB::table('brigadista_seccion')->where('membership_id', $brigadista->id)->pluck('seccion_id')->all()
        );
    }

    public function test_seccion_de_otro_municipio_es_rechazada(): void
    {
        [$tenant, $municipio, $brigadista] = $this->tenantConBrigadista();
        $propia = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $ajena = Seccion::factory()->create(['municipio_id' => Municipio::factory()->create()->id]);

        TenantContext::set($tenant);

        try {
            (new AsignarZonas)->handle($brigadista, [$propia->id, $ajena->id]);
            $this->fail('Se esperaba ValidationException por sección de otro municipio.');
        } catch (ValidationException $e) {
            // Nada se persiste: ni siquiera la sección propia.
            $this->assertSame(0, DB::table('brigadista_seccion')->where('membership_id', $brigadista->id)->count());
        }
    }

    public function test_aislamiento_del_pivote_entre_tenants(): void
    {
        $municipio = Municipio::factory()->create();
        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $brigA = Membership::factory()->brigadista()->create(['tenant_id' => $tenantA->id]);
        $brigB = Membership::factory()->brigadista()->create(['tenant_id' => $tenantB->id]);
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantA);
        (new AsignarZonas)->handle($brigA, [$seccion->id]);

        TenantContext::set($tenantB);
        (new AsignarZonas)->handle($brigB, [$seccion->id]);

        $this->assertSame($tenantA->id, DB::table('brigadista_seccion')->where('membership_id', $brigA->id)->value('tenant_id'));
        $this->assertSame($tenantB->id, DB::table('brigadista_seccion')->where('membership_id', $brigB->id)->value('tenant_id'));
    }
}
