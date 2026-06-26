<?php

namespace Tests\Feature\Captura;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapturaPaginaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_captura_expone_secciones_del_municipio(): void
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => 7]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/captura');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Captura')
            ->has('secciones', 1)
            ->where('secciones.0.numero', 7));
    }

    public function test_brigadista_accede_a_captura(): void
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/captura')->assertOk();
    }
}
