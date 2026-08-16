<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ConexionGoogle;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * OAuth por tenant para el canal de encuestas Google Sheets (F9): cada
 * tenant conecta su propia cuenta de Google (scope readonly de Sheets), sin
 * service account de plataforma compartida.
 */
class GoogleConexionController extends Controller
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    private const SESSION_STATE_KEY = 'google_oauth_state';

    public function show(): Response
    {
        $conexion = ConexionGoogle::query()->first();

        return Inertia::render('settings/Google', [
            'conectado' => $conexion !== null,
            'cuentaEmail' => $conexion?->cuenta_email,
            'conectadoEn' => $conexion?->conectado_en?->toIso8601String(),
        ]);
    }

    /**
     * Redirige a la pantalla de consentimiento de Google. `access_type=offline`
     * + `prompt=consent` fuerzan un refresh_token incluso al reconectar. El
     * `state` viaja en sesión para validar el callback (CSRF del flujo OAuth).
     */
    public function conectar(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away("https://accounts.google.com/o/oauth2/v2/auth?{$query}");
    }

    public function callback(Request $request): RedirectResponse
    {
        $stateGuardado = $request->session()->pull(self::SESSION_STATE_KEY);
        $stateRecibido = (string) $request->query('state');

        abort_unless(
            is_string($stateGuardado) && $stateGuardado !== '' && hash_equals($stateGuardado, $stateRecibido),
            403,
            'El estado de la conexión con Google no coincide.'
        );

        $respuesta = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => (string) $request->query('code'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        abort_unless($respuesta->successful(), 422, 'Google rechazó el intercambio de tokens.');

        $accessToken = (string) $respuesta->json('access_token');
        $refreshTokenNuevo = $respuesta->json('refresh_token');
        $expiraEn = now()->addSeconds((int) $respuesta->json('expires_in', 3600));

        $cuentaEmail = $this->obtenerCuentaEmail($accessToken);

        $tenant = TenantContext::get();
        $membership = $tenant ? $request->user()?->membershipEn($tenant) : null;

        $existente = ConexionGoogle::query()->first();

        abort_if(
            $existente === null && ! (is_string($refreshTokenNuevo) && $refreshTokenNuevo !== ''),
            422,
            'Google no devolvió un refresh_token. Vuelve a intentar la conexión.'
        );

        $atributos = [
            'access_token' => $accessToken,
            // Google solo reenvía refresh_token con prompt=consent (siempre
            // lo pedimos), pero por si acaso no llega, conserva el anterior
            // en vez de dejar la conexión sin forma de refrescar.
            'refresh_token' => is_string($refreshTokenNuevo) && $refreshTokenNuevo !== ''
                ? $refreshTokenNuevo
                : $existente?->refresh_token,
            'expira_en' => $expiraEn,
            'cuenta_email' => $cuentaEmail,
            'conectado_por_membership_id' => $membership?->id,
            'conectado_en' => now(),
        ];

        if ($existente) {
            $existente->update($atributos);
        } else {
            ConexionGoogle::create($atributos);
        }

        return to_route('google.show');
    }

    public function desconectar(): RedirectResponse
    {
        $conexion = ConexionGoogle::query()->first();

        if ($conexion) {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                'token' => $conexion->refresh_token,
            ]);

            $conexion->delete();
        }

        return to_route('google.show');
    }

    private function obtenerCuentaEmail(string $accessToken): string
    {
        $respuesta = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');

        return (string) ($respuesta->json('email') ?? 'desconocido@google.com');
    }
}
