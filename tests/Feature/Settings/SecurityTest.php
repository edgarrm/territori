<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_is_displayed()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->where('canManagePasskeys', true)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', true)
                ->where('twoFactorEnabled', false),
            );
    }

    public function test_security_page_requires_password_confirmation_when_enabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_page_renders_without_two_factor_when_feature_is_disabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->where('canManagePasskeys', false)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', false)
                ->missing('twoFactorEnabled')
                ->missing('requiresConfirmation'),
            );
    }

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    /**
     * `AuthenticateSession` ata cada sesión al hash de la contraseña; al
     * cambiarla, `logoutOtherDevices()` lo rota y las demás dejan de validar.
     * Sin esto, una cookie de sesión robada seguía viva justo después de la
     * reacción natural de quien sospecha del robo.
     */
    public function test_cambiar_la_contrasena_invalida_las_demas_sesiones(): void
    {
        $user = User::factory()->create();
        $hashPrevio = $user->password;

        // La "otra sesión": misma forma que deja StartSession + AuthenticateSession.
        $otraSesion = [
            'login_web_'.sha1(SessionGuard::class) => $user->id,
            'password_hash_web' => $hashPrevio,
        ];

        // Antes del cambio esa sesión es válida. Sin esta comprobación, el
        // assert de abajo pasaría igual si las claves estuvieran mal escritas
        // (una sesión no autenticada también redirige a /login).
        $this->withSession($otraSesion)->get(route('profile.edit'))->assertOk();

        $this->flushSession();

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        // El hash se rota (no solo se reemplaza por el de la nueva contraseña):
        // es ese cambio lo que invalida el `password_hash_*` de las otras sesiones.
        $this->assertNotSame($hashPrevio, $user->refresh()->password);

        $this->flushSession();

        $this->withSession($otraSesion)->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('security.edit'));
    }
}
