<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Con registro_publico=false la instancia queda cerrada al auto-registro,
     * que es el interruptor usado al exponerla a internet. La ruta sigue
     * existiendo (Wayfinder depende de ello); lo que se corta es el acceso.
     */
    public function test_el_flag_apagado_cierra_el_registro(): void
    {
        config(['fortify.registro_publico' => false]);

        $this->get(route('register'))->assertNotFound();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /**
     * El resto de las rutas de Fortify no se ven afectadas por el flag, pero el
     * login deja de ofrecer el link a una ruta que ahora da 404.
     */
    public function test_el_flag_apagado_no_afecta_el_login_pero_oculta_su_link(): void
    {
        config(['fortify.registro_publico' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    public function test_el_login_ofrece_el_link_cuando_el_registro_esta_abierto(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canRegister', true));
    }
}
