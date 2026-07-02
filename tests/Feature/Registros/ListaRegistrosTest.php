<?php

namespace Tests\Feature\Registros;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListaRegistrosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private AvisoPrivacidad $aviso;

    private Seccion $seccion;

    private User $coordinador;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->seccion = Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', 1)->first();

        $this->coordinador = User::factory()->create();
        $this->membership = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->coordinador->id,
            'rol' => 'coordinador',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function elector(array $overrides): Elector
    {
        TenantContext::set($this->tenant);

        return Elector::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $this->seccion->id,
            'membership_id' => $this->membership->id,
            'aviso_privacidad_id' => $this->aviso->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function lista(array $query = []): array
    {
        $url = route('secciones.electores', $this->seccion->id);

        return $this->actingAs($this->coordinador)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url.($query !== [] ? '?'.http_build_query($query) : ''))
            ->json('data');
    }

    public function test_lista_muestra_el_origen_de_cada_registro(): void
    {
        TenantContext::set($this->tenant);
        $evento = Evento::factory()->create(['tenant_id' => $this->tenant->id, 'nombre' => 'Mitin Central']);
        $red = RedCiudadana::factory()->create([
            'tenant_id' => $this->tenant->id,
            'enlace_membership_id' => $this->membership->id,
            'nombre' => 'Red Norte',
        ]);

        $enlaceSeccional = $this->elector(['nombre' => 'Ana', 'modo_captura' => 'enlace_seccional']);
        $porEvento = $this->elector(['nombre' => 'Beto', 'modo_captura' => 'evento', 'evento_id' => $evento->id]);
        $porRed = $this->elector(['nombre' => 'Caro', 'modo_captura' => 'red_ciudadana', 'red_ciudadana_id' => $red->id]);

        $porId = collect($this->lista())->keyBy('id');

        $this->assertSame('Enlace Seccional', $porId[$enlaceSeccional->id]['origen']);
        $this->assertSame('Evento: Mitin Central', $porId[$porEvento->id]['origen']);
        $this->assertSame('Red: Red Norte', $porId[$porRed->id]['origen']);
    }

    public function test_busqueda_filtra_por_nombre_sin_distinguir_mayusculas(): void
    {
        $this->elector(['nombre' => 'Juan Pérez']);
        $this->elector(['nombre' => 'María López']);

        $resultado = $this->lista(['q' => 'juan']);

        $this->assertCount(1, $resultado);
        $this->assertSame('Juan Pérez', $resultado[0]['nombre']);
    }

    public function test_filtra_por_tipo_de_captura(): void
    {
        TenantContext::set($this->tenant);
        $evento = Evento::factory()->create(['tenant_id' => $this->tenant->id, 'nombre' => 'Mitin Central']);

        $this->elector(['nombre' => 'Ana', 'modo_captura' => 'enlace_seccional']);
        $this->elector(['nombre' => 'Beto', 'modo_captura' => 'evento', 'evento_id' => $evento->id]);

        $resultado = $this->lista(['modos' => ['evento']]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Beto', $resultado[0]['nombre']);
    }

    public function test_paginacion_conserva_la_busqueda(): void
    {
        foreach (range(1, 30) as $i) {
            $this->elector(['nombre' => 'Comun '.$i]);
        }

        $url = route('secciones.electores', $this->seccion->id).'?q=Comun';
        $respuesta = $this->actingAs($this->coordinador)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url);

        $respuesta->assertOk();
        // La página se limita a 25; el enlace a la siguiente debe conservar q.
        $this->assertSame(25, count($respuesta->json('data')));
        $this->assertStringContainsString('q=Comun', (string) $respuesta->json('next_page_url'));
    }
}
