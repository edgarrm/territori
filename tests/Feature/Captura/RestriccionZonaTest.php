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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El brigadista solo puede capturar en las secciones que tiene asignadas
 * (brigadista_seccion). Sin zonas no puede capturar en ninguna.
 */
class RestriccionZonaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private AvisoPrivacidad $aviso;

    private User $user;

    private Membership $brigadista;

    protected function setUp(): void
    {
        parent::setUp();

        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create();
        $this->brigadista = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'rol' => 'brigadista',
        ]);
    }

    private function seccion(int $numero): Seccion
    {
        return Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', $numero)->first();
    }

    private function asignarZona(Seccion $seccion): void
    {
        $this->brigadista->secciones()->attach($seccion->id, ['tenant_id' => $this->tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function capturar(Seccion $seccion, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/electores', array_merge([
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $seccion->id,
                'nombre' => 'Vecino Zona',
                'telefono' => '5512340009',
                'consentimiento' => true,
                'aviso_privacidad_id' => $this->aviso->id,
            ], $extra));
    }

    public function test_brigadista_captura_en_su_zona_asignada(): void
    {
        $seccion = $this->seccion(1);
        $this->asignarZona($seccion);

        $this->capturar($seccion)->assertCreated();

        TenantContext::set($this->tenant);
        $this->assertSame(1, Elector::query()->count());
    }

    public function test_brigadista_no_captura_fuera_de_su_zona(): void
    {
        $this->asignarZona($this->seccion(1));
        $ajena = $this->seccion(2);

        $this->capturar($ajena)
            ->assertStatus(422)
            ->assertJsonValidationErrors('seccion_id');

        TenantContext::set($this->tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_brigadista_sin_zonas_no_captura(): void
    {
        $this->capturar($this->seccion(1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('seccion_id');

        TenantContext::set($this->tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_brigadista_no_captura_por_gps_fuera_de_su_zona(): void
    {
        // La zona asignada es la sección 2, pero el GPS cae en la 1.
        $this->asignarZona($this->seccion(2));

        $this->capturar($this->seccion(1), [
            'seccion_id' => null,
            'ubicacion' => ['lat' => 0.5, 'lng' => 0.5],
        ])->assertStatus(422);

        TenantContext::set($this->tenant);
        $this->assertSame(0, Elector::query()->count());
    }

    public function test_brigadista_no_puede_abrir_loteria_fuera_de_su_zona(): void
    {
        $this->asignarZona($this->seccion(1));
        $ajena = $this->seccion(2);

        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->postJson('/api/loterias', ['seccion_id' => $ajena->id])
            ->assertStatus(422);
    }
}
