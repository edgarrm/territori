<?php

namespace App\Support\GoogleSheets;

use App\Exceptions\ConexionGoogleInvalida;
use App\Exceptions\ConexionGoogleNoEncontrada;
use App\Models\Concerns\TenantScope;
use App\Models\ConexionGoogle;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API REST de Google Sheets v4 para un tenant (F9). Consulta
 * `conexiones_google` por `tenant_id` explícito, sin depender del
 * TenantContext activo (`withoutGlobalScope` + `where tenant_id`, nunca el
 * scope implícito) — el aislamiento entre tenants no debe depender de que el
 * middleware de tenant haya corrido (criterio 13, el riesgo de seguridad más
 * importante de este canal).
 *
 * El chequeo de "¿está vencido?" compara enteramente en PHP contra el
 * `expira_en` ya cast por Eloquent (Carbon), NUNCA contra el `now()` de la
 * sesión de Postgres: en este entorno el round-trip de escritura de una
 * columna `timestamptz` vía Eloquent tiene el mismo defecto de
 * representación de offset ya documentado para `atendido_en`/`fecha`
 * (Sprint 6) — un valor escrito con `now()` de PHP y comparado en SQL crudo
 * contra `now()` de Postgres puede diferir por el offset de la sesión (se
 * reprodujo en TDD: la comparación en SQL crudo daba `false` para un token
 * vencido 5 minutos atrás). Comparar dentro del mismo boundary de Eloquent
 * (mismo cast en escritura y lectura) es autoconsistente; mezclar con el
 * `now()` de Postgres no lo es.
 */
class ClienteSheets
{
    public function __construct(private readonly int $tenantId) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function obtenerValores(string $spreadsheetId, string $rango): array
    {
        $accessToken = $this->accessTokenVigente();

        $respuesta = Http::withToken($accessToken)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($rango));

        if ($respuesta->failed()) {
            throw new ConexionGoogleInvalida('No se pudo leer el Sheet. Verifica el ID, la hoja y que la cuenta conectada tenga acceso.');
        }

        /** @var array<int, array<int, string>> $valores */
        $valores = $respuesta->json('values', []);

        return $valores;
    }

    private function accessTokenVigente(): string
    {
        $conexion = $this->conexion();

        if ($conexion === null) {
            throw new ConexionGoogleNoEncontrada;
        }

        if ($conexion->expira_en->lessThanOrEqualTo(now()->addMinute())) {
            return $this->refrescar($conexion);
        }

        return $conexion->access_token;
    }

    private function refrescar(ConexionGoogle $conexion): string
    {
        $respuesta = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $conexion->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($respuesta->failed()) {
            // El refresh_token fue revocado fuera del control de la app
            // (o algo similar irrecuperable): borra la conexión inválida para
            // que la UI vuelva a "no conectado" en vez de fallar en bucle.
            $conexion->delete();

            throw new ConexionGoogleInvalida;
        }

        $accessToken = (string) $respuesta->json('access_token');

        $conexion->update([
            'access_token' => $accessToken,
            'expira_en' => now()->addSeconds((int) $respuesta->json('expires_in', 3600)),
        ]);

        return $accessToken;
    }

    private function conexion(): ?ConexionGoogle
    {
        return ConexionGoogle::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->first();
    }
}
