<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureRedirectDeAutenticados();
        $this->throttleRutasSensibles();
    }

    /**
     * `forgot-password`, `reset-password`, `register` y `user/confirm-password`
     * no tienen un limiter dedicado en `fortify.limiters` (solo existe para
     * login, two-factor y passkeys), así que no hay forma de asignarles rate
     * limit desde config. Se añade el middleware por nombre de ruta después de
     * que Fortify las registra en su propio `boot()`, que corre antes que este
     * (ver `bootstrap/providers.php`).
     */
    private function throttleRutasSensibles(): void
    {
        $rutas = Route::getRoutes();

        // `RouteCollection::addLookups()` indexa por nombre en el momento de
        // `add()`, antes de que el `->name(...)` encadenado se aplique al
        // objeto Route — el índice por nombre queda vacío hasta el primer
        // `refreshNameLookups()` (normalmente disparado en el primer dispatch,
        // o por `route:cache`). Se fuerza aquí para poder resolver por nombre
        // ahora mismo, y `route:cache` conserva el middleware añadido porque
        // serializa la colección después de que toda la app arranca.
        $rutas->refreshNameLookups();

        foreach (['password.email', 'password.update', 'register.store', 'password.confirm.store'] as $nombre) {
            $rutas->getByName($nombre)?->middleware('throttle:6,1');
        }
    }

    /**
     * Un usuario ya autenticado que visita /login se redirige a su ruta inicial
     * según el rol. El default de Fortify (`fortify.home` = /dashboard) daría 403
     * a los roles que no alcanzan el dashboard (brigadista, enlace, anfitrión).
     */
    private function configureRedirectDeAutenticados(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            $user = $request->user();
            $tenant = TenantContext::get();
            $membership = $user instanceof User && $tenant !== null ? $user->membershipEn($tenant) : null;

            return route($membership?->rutaInicial() ?? 'dashboard');
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            // Con el registro público cerrado la ruta da 404: no ofrecer el link.
            'canRegister' => config('fortify.registro_publico'),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
