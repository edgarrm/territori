<?php

namespace Tests\Feature\Encuestas;

use App\Models\ConexionGoogle;
use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\EncuestaRespuestaAgregada;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Canal Google Sheets (F9): criterios 7-13. La lectura del Sheet y el
 * intercambio/refresco de tokens se simulan con Http::fake(); ver
 * tests/Feature/GoogleSheets/ClienteSheetsTest.php para el refresco (criterio 5).
 */
class SincronizarEncuestaGoogleSheetsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        $this->withoutVite();

        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
        TenantContext::set($tenant);

        return [$tenant, $user, $municipio];
    }

    private function actuandoEn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
    }

    private function fakeSheets(array $valores): void
    {
        Http::fake([
            'sheets.googleapis.com/*' => Http::response(['values' => $valores]),
        ]);
    }

    /**
     * @return array{0: Encuesta, 1: array<int, Seccion>}
     */
    private function crearEncuestaGoogleSheetsConSecciones(Municipio $municipio, array $overridesConfig = []): array
    {
        $secciones = [];
        foreach ([101, 102, 103, 104, 105, 106, 107, 108] as $numero) {
            $secciones[] = Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => $numero]);
        }

        $encuesta = Encuesta::factory()->create([
            'canal' => 'google_sheets',
            'ambito_tipo' => 'seccion',
            'configuracion_canal' => array_merge([
                'spreadsheet_id' => 'sheet-123',
                'nombre_hoja' => 'Respuestas',
                'campo_identificador' => 'seccion',
            ], $overridesConfig),
        ]);

        EncuestaPregunta::factory()->create([
            'encuesta_id' => $encuesta->id,
            'campo' => 'apoya_obra',
            'etiqueta' => 'apoya_obra',
            'tipo' => 'opcion_unica',
            'opciones' => ['Sí', 'No'],
            'incluida' => true,
        ]);

        return [$encuesta, $secciones];
    }

    /**
     * 10 filas: 8 con identificador que matchea una sección existente, 2 sin
     * match. Criterios 8 y 9 combinados en un solo Sheet.
     */
    private function filasDiezConDosSinMatch(): array
    {
        return [
            ['seccion', 'apoya_obra'],
            ['101', 'Sí'],
            ['102', 'Sí'],
            ['103', 'No'],
            ['104', 'Sí'],
            ['105', 'No'],
            ['106', 'Sí'],
            ['107', 'No'],
            ['108', 'Sí'],
            ['999', 'Sí'],   // sección inexistente
            ['abc', 'No'],   // identificador no numérico
        ];
    }

    // --- 7. Sin conexiones_google: error claro, sin excepción no controlada ---
    public function test_verificar_acceso_y_sincronizar_sin_conexion_responde_error_claro(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        [$encuesta] = $this->crearEncuestaGoogleSheetsConSecciones($municipio);

        $this->actuandoEn($user, $tenant)
            ->postJson("/api/encuestas/{$encuesta->id}/verificar-acceso")
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->actuandoEn($user, $tenant)
            ->postJson("/api/encuestas/{$encuesta->id}/sincronizar")
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    // --- 8 y 9. Recuento completo + filas sin match no rompen la sincronización ---
    public function test_sincronizar_tabula_las_filas_con_match_y_cuenta_las_sin_match(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        [$encuesta] = $this->crearEncuestaGoogleSheetsConSecciones($municipio);

        TenantContext::set($tenant);
        ConexionGoogle::factory()->create();

        $this->fakeSheets($this->filasDiezConDosSinMatch());

        $respuesta = $this->actuandoEn($user, $tenant)
            ->postJson("/api/encuestas/{$encuesta->id}/sincronizar")
            ->assertOk();

        $respuesta->assertJson(['procesadas' => 8, 'sin_match' => 2]);

        TenantContext::set($tenant);
        $encuesta->refresh();
        $this->assertNotNull($encuesta->ultima_sincronizacion);
        // assertEquals, no assertSame: jsonb de Postgres no preserva el orden
        // de inserción de las claves al releer.
        $this->assertEquals(['procesadas' => 8, 'sin_match' => 2], $encuesta->ultimo_resultado);

        $totalTabulado = EncuestaRespuestaAgregada::query()
            ->where('encuesta_id', $encuesta->id)
            ->sum('conteo');
        $this->assertSame(8, $totalTabulado);
    }

    // --- 10. Columna nueva del Sheet -> se crea con incluida=false ---
    public function test_columna_nueva_se_crea_en_encuesta_preguntas_con_incluida_false(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        [$encuesta] = $this->crearEncuestaGoogleSheetsConSecciones($municipio);

        TenantContext::set($tenant);
        ConexionGoogle::factory()->create();

        $filas = $this->filasDiezConDosSinMatch();
        // Agrega una columna nueva ('problema') a encabezados + cada fila.
        $filas[0][] = 'problema';
        for ($i = 1; $i < count($filas); $i++) {
            $filas[$i][] = 'Agua';
        }

        $this->fakeSheets($filas);

        $this->actuandoEn($user, $tenant)
            ->postJson("/api/encuestas/{$encuesta->id}/sincronizar")
            ->assertOk();

        TenantContext::set($tenant);
        $nueva = EncuestaPregunta::query()
            ->where('encuesta_id', $encuesta->id)
            ->where('campo', 'problema')
            ->first();

        $this->assertNotNull($nueva);
        $this->assertFalse($nueva->incluida);

        // La pregunta preexistente (apoya_obra) no se toca por la detección.
        $existente = EncuestaPregunta::query()->where('campo', 'apoya_obra')->firstOrFail();
        $this->assertTrue($existente->incluida);
    }

    // --- 11. Sincronizar dos veces sin cambios: resultado idéntico ---
    public function test_sincronizar_dos_veces_sin_cambios_es_idempotente(): void
    {
        [$tenant, $user, $municipio] = $this->setupCampana();
        [$encuesta] = $this->crearEncuestaGoogleSheetsConSecciones($municipio);

        TenantContext::set($tenant);
        ConexionGoogle::factory()->create();

        $this->fakeSheets($this->filasDiezConDosSinMatch());

        $this->actuandoEn($user, $tenant)->postJson("/api/encuestas/{$encuesta->id}/sincronizar")->assertOk();

        TenantContext::set($tenant);
        $primeraVez = EncuestaRespuestaAgregada::query()
            ->where('encuesta_id', $encuesta->id)
            ->orderBy('pregunta_id')->orderBy('ambito_id')->orderBy('opcion')
            ->get(['pregunta_id', 'ambito_tipo', 'ambito_id', 'opcion', 'conteo'])
            ->toArray();

        $this->fakeSheets($this->filasDiezConDosSinMatch());
        $this->actuandoEn($user, $tenant)->postJson("/api/encuestas/{$encuesta->id}/sincronizar")->assertOk();

        TenantContext::set($tenant);
        $segundaVez = EncuestaRespuestaAgregada::query()
            ->where('encuesta_id', $encuesta->id)
            ->orderBy('pregunta_id')->orderBy('ambito_id')->orderBy('opcion')
            ->get(['pregunta_id', 'ambito_tipo', 'ambito_id', 'opcion', 'conteo'])
            ->toArray();

        $this->assertSame($primeraVez, $segundaVez);
    }

    // --- 12. El valor crudo del identificador nunca se persiste ---
    public function test_el_identificador_crudo_nunca_se_persiste(): void
    {
        [$tenant, $user] = $this->setupCampana();

        TenantContext::set($tenant);
        $zonaUno = Zona::factory()->create(['clave' => 'ZID-777']);
        $zonaDos = Zona::factory()->create(['clave' => 'ZID-888']);

        $encuesta = Encuesta::factory()->create([
            'canal' => 'google_sheets',
            'ambito_tipo' => 'zona',
            'configuracion_canal' => [
                'spreadsheet_id' => 'sheet-zonas',
                'nombre_hoja' => 'Respuestas',
                'campo_identificador' => 'zona',
            ],
        ]);

        EncuestaPregunta::factory()->create([
            'encuesta_id' => $encuesta->id,
            'campo' => 'apoya_obra',
            'etiqueta' => 'apoya_obra',
            'tipo' => 'opcion_unica',
            'opciones' => ['Sí', 'No'],
            'incluida' => true,
        ]);

        ConexionGoogle::factory()->create();

        $this->fakeSheets([
            ['zona', 'apoya_obra'],
            ['ZID-777', 'Sí'],
            ['ZID-888', 'No'],
        ]);

        $this->actuandoEn($user, $tenant)
            ->postJson("/api/encuestas/{$encuesta->id}/sincronizar")
            ->assertOk()
            ->assertJson(['procesadas' => 2, 'sin_match' => 0]);

        TenantContext::set($tenant);

        $filas = EncuestaRespuestaAgregada::query()->where('encuesta_id', $encuesta->id)->get();
        $this->assertTrue($filas->every(fn (EncuestaRespuestaAgregada $f): bool => in_array($f->ambito_id, [$zonaUno->id, $zonaDos->id], true)));
        // El identificador crudo ('ZID-777'/'ZID-888') no aparece en ninguna
        // columna de texto de las tablas que escribe SincronizarEncuesta.
        $this->assertFalse($filas->contains(fn (EncuestaRespuestaAgregada $f): bool => str_contains((string) $f->opcion, 'ZID-')));
        $this->assertFalse(EncuestaPregunta::query()->where('encuesta_id', $encuesta->id)->get()->contains(
            fn (EncuestaPregunta $p): bool => str_contains((string) $p->campo, 'ZID-') || str_contains((string) $p->etiqueta, 'ZID-')
        ));
    }

    // --- 13. Aislamiento: sincronizar el Sheet de un tenant nunca usa el token del otro ---
    public function test_sincronizar_nunca_usa_el_token_de_otro_tenant(): void
    {
        [$tenantA, $userA, $municipioA] = $this->setupCampana();
        [$encuestaA] = $this->crearEncuestaGoogleSheetsConSecciones($municipioA);

        TenantContext::set($tenantA);
        ConexionGoogle::factory()->create(['access_token' => 'token-de-A']);

        [$tenantB] = $this->setupCampana();
        TenantContext::set($tenantB);
        ConexionGoogle::factory()->create(['access_token' => 'token-de-B']);

        $this->fakeSheets($this->filasDiezConDosSinMatch());

        $this->actuandoEn($userA, $tenantA)
            ->postJson("/api/encuestas/{$encuestaA->id}/sincronizar")
            ->assertOk();

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'sheets.googleapis.com')) {
                return true;
            }

            $auth = $request->header('Authorization')[0] ?? '';
            $this->assertSame('Bearer token-de-A', $auth);
            $this->assertNotSame('Bearer token-de-B', $auth);

            return true;
        });
    }
}
