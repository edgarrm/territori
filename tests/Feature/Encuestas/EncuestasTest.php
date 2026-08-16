<?php

namespace Tests\Feature\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\EncuestaRespuestaAgregada;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncuestasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    private function setupCampana(string $rol = 'brigadista'): array
    {
        // Varias páginas nuevas son Inertia; sin build no hay manifest de Vite.
        $this->withoutVite();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
        ]);
        TenantContext::set($tenant);

        return [$tenant, $user, $membership];
    }

    /**
     * Crea una encuesta canal=nativo con 2 preguntas opcion_unica. El
     * TenantContext debe estar en $tenant antes de llamar (BelongsToTenant
     * clava tenant_id desde el TenantContext activo, ignorando lo explícito).
     *
     * @return array{0: Encuesta, 1: EncuestaPregunta, 2: EncuestaPregunta}
     */
    private function crearEncuestaConDosPreguntas(Tenant $tenant, array $overrides = []): array
    {
        TenantContext::set($tenant);

        $encuesta = Encuesta::factory()->create(array_merge(['ambito_tipo' => 'seccion'], $overrides));

        $p1 = EncuestaPregunta::factory()->create([
            'encuesta_id' => $encuesta->id,
            'etiqueta' => '¿Apoya la obra del parque?',
            'tipo' => 'opcion_unica',
            'opciones' => ['Sí', 'No', 'No sabe'],
            'orden' => 1,
        ]);

        $p2 = EncuestaPregunta::factory()->create([
            'encuesta_id' => $encuesta->id,
            'etiqueta' => '¿Qué problema le preocupa más?',
            'tipo' => 'opcion_unica',
            'opciones' => ['Seguridad', 'Agua', 'Baches'],
            'orden' => 2,
        ]);

        return [$encuesta, $p1, $p2];
    }

    // --- 1. Alta con preguntas: incluida=true por default ---
    public function test_crear_encuesta_deja_preguntas_incluidas_por_default(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/encuestas', [
                'nombre' => 'Encuesta de obra pública',
                'ambito_tipo' => 'seccion',
                'canal' => 'nativo',
                'modo_acceso' => 'brigadista',
                'preguntas' => [
                    ['etiqueta' => '¿Apoya la obra?', 'tipo' => 'opcion_unica', 'opciones' => ['Sí', 'No']],
                    ['etiqueta' => '¿Qué le preocupa?', 'tipo' => 'opcion_unica', 'opciones' => ['Seguridad', 'Agua']],
                ],
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $encuesta = Encuesta::query()->where('nombre', 'Encuesta de obra pública')->firstOrFail();
        $this->assertSame(2, $encuesta->preguntas()->count());
        $this->assertTrue($encuesta->preguntas()->where('incluida', true)->count() === 2);
    }

    // --- 2 y 3. Brigadista responde: incrementa conteo, incremental ---
    public function test_brigadista_responde_incrementa_conteo_de_su_seccion(): void
    {
        [$tenant, $user, $membership] = $this->setupCampana('brigadista');
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $p1, $p2] = $this->crearEncuestaConDosPreguntas($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/encuestas/{$encuesta->id}/respuestas", [
                'ambito_id' => $seccion->id,
                'respuestas' => [
                    (string) $p1->id => 'Sí',
                    (string) $p2->id => 'Agua',
                ],
            ])->assertCreated();

        TenantContext::set($tenant);
        $fila = EncuestaRespuestaAgregada::query()
            ->where('pregunta_id', $p1->id)
            ->where('ambito_tipo', 'seccion')
            ->where('ambito_id', $seccion->id)
            ->where('opcion', 'Sí')
            ->first();

        $this->assertNotNull($fila);
        $this->assertSame(1, $fila->conteo);
    }

    public function test_dos_respuestas_seguidas_incrementan_el_conteo_a_dos(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $p1] = $this->crearEncuestaConDosPreguntas($tenant);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
                ->postJson("/api/encuestas/{$encuesta->id}/respuestas", [
                    'ambito_id' => $seccion->id,
                    'respuestas' => [(string) $p1->id => 'Sí'],
                ])->assertCreated();
        }

        TenantContext::set($tenant);
        $fila = EncuestaRespuestaAgregada::query()
            ->where('pregunta_id', $p1->id)
            ->where('ambito_id', $seccion->id)
            ->where('opcion', 'Sí')
            ->firstOrFail();

        $this->assertSame(2, $fila->conteo);
    }

    // --- 4. Opción fuera de catálogo: 422, nada tabulado ---
    public function test_opcion_fuera_de_catalogo_falla_validacion_sin_tabular(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $p1] = $this->crearEncuestaConDosPreguntas($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/encuestas/{$encuesta->id}/respuestas", [
                'ambito_id' => $seccion->id,
                'respuestas' => [(string) $p1->id => 'Opción inventada'],
            ])->assertStatus(422);

        TenantContext::set($tenant);
        $this->assertSame(0, EncuestaRespuestaAgregada::query()->count());
    }

    // --- 5. token_publico único y no adivinable ---
    public function test_token_publico_se_genera_al_crear_con_modo_acceso_publico(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/encuestas', [
                'nombre' => 'Encuesta pública uno',
                'ambito_tipo' => 'seccion',
                'modo_acceso' => 'publico',
                'preguntas' => [
                    ['etiqueta' => '¿Le gusta el parque?', 'tipo' => 'opcion_unica', 'opciones' => ['Sí', 'No']],
                ],
            ])->assertSessionHasNoErrors();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/encuestas', [
                'nombre' => 'Encuesta pública dos',
                'ambito_tipo' => 'seccion',
                'modo_acceso' => 'publico',
                'preguntas' => [
                    ['etiqueta' => '¿Le gusta el parque?', 'tipo' => 'opcion_unica', 'opciones' => ['Sí', 'No']],
                ],
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $uno = Encuesta::query()->where('nombre', 'Encuesta pública uno')->firstOrFail();
        $dos = Encuesta::query()->where('nombre', 'Encuesta pública dos')->firstOrFail();

        $this->assertNotNull($uno->token_publico);
        $this->assertSame(40, strlen($uno->token_publico));
        $this->assertNotSame($uno->token_publico, $dos->token_publico);
        // No derivado del id (ids consecutivos, ej. 5 y 6): ninguna derivación
        // determinista obvia del id produce el token real.
        $this->assertNotSame(str_pad((string) $uno->id, 40, '0', STR_PAD_LEFT), $uno->token_publico);
        $this->assertNotSame(sha1((string) $uno->id), $uno->token_publico);
        $this->assertNotSame(md5((string) $uno->id), $uno->token_publico);
        // Los tokens de ids consecutivos no comparten ningún prefijo: si el
        // token derivara del id, ids consecutivos producirían prefijos
        // relacionados (ej. un hash del id compartiría estructura).
        $this->assertNotSame(
            substr($uno->token_publico, 0, 20),
            substr($dos->token_publico, 0, 20),
        );
    }

    // --- 6. GET /e/{token} carga sin sesión ---
    public function test_pagina_publica_carga_sin_sesion_autenticada(): void
    {
        $this->withoutVite();
        [$tenant] = $this->setupCampana('coordinador');
        [$encuesta] = $this->crearEncuestaConDosPreguntas($tenant, [
            'modo_acceso' => 'publico',
            'token_publico' => Str::random(40),
        ]);

        $this->get("/e/{$encuesta->token_publico}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('EncuestaPublica'));
    }

    // --- 7. Rate limit: 6ta request en 1 minuto → 429 ---
    public function test_mas_de_cinco_respuestas_publicas_por_minuto_se_rechazan(): void
    {
        [$tenant] = $this->setupCampana('coordinador');
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $p1] = $this->crearEncuestaConDosPreguntas($tenant, [
            'modo_acceso' => 'publico',
            'token_publico' => Str::random(40),
        ]);

        $payload = [
            'ambito_id' => $seccion->id,
            'respuestas' => [(string) $p1->id => 'Sí'],
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/e/{$encuesta->token_publico}/respuestas", $payload)
                ->assertCreated();
        }

        $this->postJson("/e/{$encuesta->token_publico}/respuestas", $payload)
            ->assertStatus(429);
    }

    // --- 8. tenant_id del body se ignora ---
    public function test_respuesta_publica_ignora_tenant_id_del_body(): void
    {
        [$tenant] = $this->setupCampana('coordinador');
        $otroTenant = Tenant::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);
        [$encuesta, $p1] = $this->crearEncuestaConDosPreguntas($tenant, [
            'modo_acceso' => 'publico',
            'token_publico' => Str::random(40),
        ]);

        $this->postJson("/e/{$encuesta->token_publico}/respuestas", [
            'tenant_id' => $otroTenant->id,
            'ambito_id' => $seccion->id,
            'respuestas' => [(string) $p1->id => 'Sí'],
        ])->assertCreated();

        TenantContext::set($tenant);
        $fila = EncuestaRespuestaAgregada::query()
            ->where('pregunta_id', $p1->id)
            ->where('ambito_id', $seccion->id)
            ->where('opcion', 'Sí')
            ->firstOrFail();

        $this->assertSame($tenant->id, $fila->tenant_id);
        $this->assertNotSame($otroTenant->id, $fila->tenant_id);
    }

    // --- 9. modo_acceso=brigadista (sin token) → 404 en cualquier token ---
    public function test_get_encuesta_publica_404_si_modo_acceso_es_solo_brigadista(): void
    {
        [$tenant] = $this->setupCampana('coordinador');
        [$encuesta] = $this->crearEncuestaConDosPreguntas($tenant, ['modo_acceso' => 'brigadista']);

        $this->assertNull($encuesta->token_publico);

        $this->get('/e/'.Str::random(40))->assertNotFound();
    }

    // --- 10. texto_libre / numerica nunca se tabulan ---
    public function test_preguntas_texto_libre_y_numerica_nunca_se_tabulan(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');
        $seccion = Seccion::factory()->create(['municipio_id' => $tenant->municipio_id]);

        TenantContext::set($tenant);
        $encuesta = Encuesta::factory()->create(['ambito_tipo' => 'seccion']);
        $pTexto = EncuestaPregunta::factory()->textoLibre()->create([
            'encuesta_id' => $encuesta->id,
            'etiqueta' => 'Comentarios',
        ]);
        $pNumerica = EncuestaPregunta::factory()->numerica()->create([
            'encuesta_id' => $encuesta->id,
            'etiqueta' => '¿Cuántas personas viven en su casa?',
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson("/api/encuestas/{$encuesta->id}/respuestas", [
                'ambito_id' => $seccion->id,
                'respuestas' => [
                    (string) $pTexto->id => 'Todo bien por aquí',
                    (string) $pNumerica->id => '4',
                ],
            ])->assertCreated();

        TenantContext::set($tenant);
        $this->assertSame(0, EncuestaRespuestaAgregada::query()->where('pregunta_id', $pTexto->id)->count());
        $this->assertSame(0, EncuestaRespuestaAgregada::query()->where('pregunta_id', $pNumerica->id)->count());
    }

    // --- 11. viewer sin rol coordinador/admin: 403 al crear ---
    public function test_brigadista_no_puede_crear_encuesta(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/encuestas', [
                'nombre' => 'Encuesta prohibida',
                'ambito_tipo' => 'seccion',
                'modo_acceso' => 'brigadista',
                'preguntas' => [
                    ['etiqueta' => '¿Algo?', 'tipo' => 'opcion_unica', 'opciones' => ['Sí', 'No']],
                ],
            ])->assertForbidden();
    }

    // --- Extra: index Inertia + update reemplaza preguntas ---
    public function test_index_renderiza_encuestas_del_tenant(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        $this->crearEncuestaConDosPreguntas($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/encuestas')
            ->assertInertia(fn ($page) => $page
                ->component('Encuestas')
                ->has('encuestas', 1)
                ->has('encuestas.0.preguntas', 2)
            );
    }

    public function test_update_reemplaza_el_set_de_preguntas(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        [$encuesta] = $this->crearEncuestaConDosPreguntas($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put("/api/encuestas/{$encuesta->id}", [
                'preguntas' => [
                    ['etiqueta' => 'Nueva pregunta única', 'tipo' => 'opcion_unica', 'opciones' => ['A', 'B']],
                ],
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $this->assertSame(1, $encuesta->preguntas()->count());
        $this->assertSame('Nueva pregunta única', $encuesta->preguntas()->first()->etiqueta);
    }
}
