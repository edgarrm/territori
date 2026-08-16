<?php

namespace Tests\Feature\Seguridad;

use App\Actions\Privacidad\RegistrarAcceso;
use App\Models\AvisoPrivacidad;
use App\Models\BitacoraAcceso;
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
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Bitácora de accesos a datos personales (ADR-004, LFPDPPP art. 21) y controles
 * del export masivo. Cubre P3 y P4 de la auditoría de seguridad.
 */
class BitacoraTest extends TestCase
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
        $user = User::factory()->withTwoFactor()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $seccion, $aviso];
    }

    /**
     * Sesión con campaña activa y contraseña reconfirmada (el export exige
     * RequirePassword además de 2FA).
     */
    private function sesionDeGestion(User $user, Tenant $tenant): self
    {
        $this->actingAs($user)->withSession([
            'tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => time(),
        ]);

        return $this;
    }

    private function crearElector(Tenant $tenant, Membership $m, Seccion $s, AvisoPrivacidad $a, string $nombre, string $telefono): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $tenant->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'nombre' => $nombre, 'telefono' => $telefono,
            'telefono_hash' => Telefono::hash($telefono),
        ]);
    }

    public function test_export_registra_una_entrada_con_los_filtros_y_el_conteo(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $this->crearElector($tenant, $m, $s, $a, 'Juana Bitacora', '5512345678');
        $this->crearElector($tenant, $m, $s, $a, 'Pedro Bitacora', '5587654321');

        $response = $this->sesionDeGestion($user, $tenant)
            ->get("/api/export/electores.csv?seccion_id={$s->id}");
        $response->assertOk();
        $response->streamedContent();

        TenantContext::set($tenant);
        $entradas = BitacoraAcceso::query()->where('accion', 'export.electores')->get();

        $this->assertCount(1, $entradas);
        $entrada = $entradas->first();
        $this->assertSame($tenant->id, $entrada->tenant_id);
        $this->assertSame($m->id, $entrada->membership_id);
        $this->assertSame($user->id, $entrada->user_id);
        $this->assertSame(2, $entrada->metadatos['filas']);
        $this->assertFalse($entrada->metadatos['truncado']);
        $this->assertSame(['seccion_id' => (string) $s->id], $entrada->metadatos['filtros']);
    }

    public function test_la_bitacora_no_guarda_datos_personales(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $this->crearElector($tenant, $m, $s, $a, 'Juana Bitacora', '5512345678');

        $this->sesionDeGestion($user, $tenant)->get('/api/export/electores.csv')->streamedContent();

        // La fila completa, no solo metadatos: ninguna columna debe llevar PII.
        $fila = DB::table('bitacora_accesos')->where('accion', 'export.electores')->first();
        $serializada = json_encode((array) $fila, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Juana Bitacora', $serializada);
        $this->assertStringNotContainsString('5512345678', $serializada);
    }

    public function test_export_corta_al_cuarto_intento_en_la_misma_hora(): void
    {
        [$tenant, $user] = $this->setupCampana();

        for ($i = 0; $i < 3; $i++) {
            $this->sesionDeGestion($user, $tenant)
                ->get('/api/export/electores.csv')
                ->assertOk();
        }

        $this->sesionDeGestion($user, $tenant)
            ->get('/api/export/electores.csv')
            ->assertStatus(429);
    }

    public function test_export_exige_reconfirmar_contrasena(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/api/export/electores.csv')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_leer_la_ficha_de_un_elector_deja_traza(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $elector = $this->crearElector($tenant, $m, $s, $a, 'Ficha Consultada', '5511112222');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get("/electores/{$elector->id}")
            ->assertOk();

        TenantContext::set($tenant);
        $entrada = BitacoraAcceso::query()->where('accion', 'elector.consultado')->firstOrFail();

        $this->assertSame('elector', $entrada->recurso_tipo);
        $this->assertSame($elector->id, $entrada->recurso_id);
        $this->assertSame('ficha', $entrada->metadatos['via']);
        $this->assertTrue($entrada->metadatos['pii_visible']);
    }

    public function test_editar_un_elector_registra_los_campos_no_los_valores(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $elector = $this->crearElector($tenant, $m, $s, $a, 'Antes Editar', '5533334444');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/electores/{$elector->id}", [
                'nombre' => 'Despues Editar',
                'domicilio' => 'Calle Secreta 123',
            ])
            ->assertOk();

        TenantContext::set($tenant);
        $entrada = BitacoraAcceso::query()->where('accion', 'elector.actualizado')->firstOrFail();

        $this->assertSame(['nombre', 'domicilio'], $entrada->metadatos['campos']);
        $this->assertStringNotContainsString('Despues Editar', json_encode($entrada->metadatos));
        $this->assertStringNotContainsString('Calle Secreta', json_encode($entrada->metadatos));
    }

    /**
     * El 409 por teléfono duplicado es un oráculo de enumeración (A6): la
     * respuesta no revela el id, pero la bitácora sí, para poder detectar el
     * barrido después.
     */
    public function test_el_409_de_duplicado_deja_traza_con_el_elector_existente(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $existente = $this->crearElector($tenant, $m, $s, $a, 'Ya Registrado', '5512345678');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $s->id,
                'nombre' => 'Intento Duplicado',
                'telefono' => '5512345678',
                'consentimiento' => true,
                'aviso_privacidad_id' => $a->id,
            ])
            ->assertStatus(409);

        TenantContext::set($tenant);
        $entrada = BitacoraAcceso::query()->where('accion', 'elector.duplicado')->firstOrFail();

        $this->assertSame($existente->id, $entrada->recurso_id);
    }

    public function test_cancelacion_arco_deja_traza(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $elector = $this->crearElector($tenant, $m, $s, $a, 'A Cancelar', '5599998888');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$elector->id}")
            ->assertOk();

        TenantContext::set($tenant);
        $entrada = BitacoraAcceso::query()->where('accion', 'elector.cancelado')->firstOrFail();

        $this->assertSame($elector->id, $entrada->recurso_id);
        $this->assertSame($s->id, $entrada->metadatos['seccion_id']);
    }

    public function test_una_entrada_de_bitacora_no_se_puede_modificar_ni_borrar(): void
    {
        [$tenant] = $this->setupCampana();
        TenantContext::set($tenant);

        $entrada = app(RegistrarAcceso::class)->handle('prueba.append_only');
        $this->assertNotNull($entrada);

        $entrada->accion = 'otra.cosa';
        $this->expectException(LogicException::class);
        $entrada->save();
    }

    public function test_una_entrada_de_bitacora_no_se_puede_borrar(): void
    {
        [$tenant] = $this->setupCampana();
        TenantContext::set($tenant);

        $entrada = app(RegistrarAcceso::class)->handle('prueba.append_only');

        $this->expectException(LogicException::class);
        $entrada->delete();
    }

    public function test_sin_campana_activa_no_se_escribe_traza(): void
    {
        TenantContext::clear();

        $this->assertNull(app(RegistrarAcceso::class)->handle('prueba.sin_tenant'));
        $this->assertDatabaseCount('bitacora_accesos', 0);
    }
}
