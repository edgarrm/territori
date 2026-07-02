<?php

namespace Tests\Feature\Registros;

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
use Tests\TestCase;

class ListaCapturadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private AvisoPrivacidad $aviso;

    private Seccion $seccion;

    private Seccion $otraSeccion;

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
        $this->otraSeccion = Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', 2)->first();

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
        $url = route('capturados.data');

        return $this->actingAs($this->coordinador)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url.($query !== [] ? '?'.http_build_query($query) : ''))
            ->json('data');
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
        $this->elector(['nombre' => 'Ana', 'modo_captura' => 'enlace_seccional']);
        $this->elector(['nombre' => 'Beto', 'modo_captura' => 'loteria']);
        $this->elector(['nombre' => 'Caro', 'modo_captura' => 'enlace_seccional']);

        $resultado = $this->lista(['modos' => ['enlace_seccional']]);

        $this->assertCount(2, $resultado);
        $this->assertEqualsCanonicalizing(
            ['Ana', 'Caro'],
            array_column($resultado, 'nombre'),
        );
    }

    public function test_filtra_por_varios_tipos_de_captura(): void
    {
        $this->elector(['nombre' => 'Ana', 'modo_captura' => 'enlace_seccional']);
        $this->elector(['nombre' => 'Beto', 'modo_captura' => 'loteria']);
        $this->elector(['nombre' => 'Caro', 'modo_captura' => 'evento']);

        $resultado = $this->lista(['modos' => ['enlace_seccional', 'loteria']]);

        $this->assertCount(2, $resultado);
        $this->assertEqualsCanonicalizing(
            ['Ana', 'Beto'],
            array_column($resultado, 'nombre'),
        );
    }

    public function test_filtra_por_secciones(): void
    {
        $this->elector(['nombre' => 'Ana', 'seccion_id' => $this->seccion->id]);
        $this->elector(['nombre' => 'Beto', 'seccion_id' => $this->otraSeccion->id]);

        $resultado = $this->lista(['secciones' => [$this->otraSeccion->id]]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Beto', $resultado[0]['nombre']);
    }

    public function test_combina_filtros_de_tipo_y_seccion(): void
    {
        $this->elector(['nombre' => 'Ana', 'modo_captura' => 'loteria', 'seccion_id' => $this->seccion->id]);
        $this->elector(['nombre' => 'Beto', 'modo_captura' => 'loteria', 'seccion_id' => $this->otraSeccion->id]);
        $this->elector(['nombre' => 'Caro', 'modo_captura' => 'evento', 'seccion_id' => $this->otraSeccion->id]);

        $resultado = $this->lista([
            'modos' => ['loteria'],
            'secciones' => [$this->otraSeccion->id],
        ]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Beto', $resultado[0]['nombre']);
    }

    public function test_paginacion_conserva_los_filtros(): void
    {
        foreach (range(1, 30) as $i) {
            $this->elector(['nombre' => 'Comun '.$i, 'modo_captura' => 'enlace_seccional']);
        }

        $url = route('capturados.data').'?'.http_build_query(['modos' => ['enlace_seccional']]);
        $respuesta = $this->actingAs($this->coordinador)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url);

        $respuesta->assertOk();
        $this->assertSame(25, count($respuesta->json('data')));
        $this->assertStringContainsString('modos', (string) $respuesta->json('next_page_url'));
    }

    public function test_brigadista_no_puede_acceder_a_la_lista_global(): void
    {
        $brigadista = User::factory()->create();
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $brigadista->id,
            'rol' => 'brigadista',
        ]);

        $this->actingAs($brigadista)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson(route('capturados.data'))
            ->assertForbidden();
    }
}
