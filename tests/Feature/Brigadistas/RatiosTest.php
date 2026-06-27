<?php

namespace Tests\Feature\Brigadistas;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Brigadistas\RatiosBrigadista;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RatiosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Seccion $seccion;

    private AvisoPrivacidad $aviso;

    protected function setUp(): void
    {
        parent::setUp();

        // Tiempo fijo a mediodía: capturas_dia se mide desde el inicio del día,
        // así "hoy" vs "ayer" es determinista (sin fragilidad de medianoche).
        $this->travelTo(Carbon::parse('2026-06-26 12:00:00'));

        $municipio = Municipio::factory()->create();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $this->seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        TenantContext::set($this->tenant);
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function capturar(Membership $brigadista, array $overrides = []): Elector
    {
        return Elector::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $this->seccion->id,
            'membership_id' => $brigadista->id,
            'aviso_privacidad_id' => $this->aviso->id,
        ], $overrides));
    }

    public function test_avance_meta_con_meta_diaria(): void
    {
        $brigadista = Membership::factory()->brigadista()->create([
            'tenant_id' => $this->tenant->id,
            'meta_diaria' => 4,
        ]);

        $this->capturar($brigadista, ['created_at' => now()]);
        $this->capturar($brigadista, ['created_at' => now()]);
        $this->capturar($brigadista, ['created_at' => now()->subDay()]); // ayer: no cuenta a capturas_dia

        $ratios = (new RatiosBrigadista)->paraMembership($brigadista);

        $this->assertSame(2, $ratios['capturas_dia']);
        $this->assertSame(3, $ratios['capturas_total']);
        $this->assertSame(0.5, $ratios['avance_meta']); // 2 / 4
    }

    public function test_avance_meta_null_sin_meta_diaria(): void
    {
        $brigadista = Membership::factory()->brigadista()->create([
            'tenant_id' => $this->tenant->id,
            'meta_diaria' => null,
        ]);
        $this->capturar($brigadista, ['created_at' => now()]);

        $ratios = (new RatiosBrigadista)->paraMembership($brigadista);

        $this->assertNull($ratios['avance_meta']);
    }

    public function test_pct_completos_con_y_sin_telefono(): void
    {
        $brigadista = Membership::factory()->brigadista()->create(['tenant_id' => $this->tenant->id]);

        $this->capturar($brigadista, ['telefono_hash' => 'abc']);
        $this->capturar($brigadista, ['telefono_hash' => 'def']);
        $this->capturar($brigadista, ['telefono_hash' => null]); // incompleto

        $ratios = (new RatiosBrigadista)->paraMembership($brigadista);

        $this->assertSame(3, $ratios['capturas_total']);
        $this->assertEqualsWithDelta(2 / 3, $ratios['pct_completos'], 0.0001);
    }

    public function test_pct_completos_cero_sin_capturas(): void
    {
        $brigadista = Membership::factory()->brigadista()->create(['tenant_id' => $this->tenant->id]);

        $ratios = (new RatiosBrigadista)->paraMembership($brigadista);

        $this->assertSame(0, $ratios['capturas_total']);
        $this->assertSame(0.0, $ratios['pct_completos']);
        $this->assertSame(0, $ratios['capturas_dia']);
    }
}
