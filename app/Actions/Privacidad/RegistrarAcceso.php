<?php

namespace App\Actions\Privacidad;

use App\Models\BitacoraAcceso;
use App\Support\Tenancy\TenantContext;

/**
 * Escribe una entrada en la bitácora de accesos a datos personales (ADR-004,
 * LFPDPPP art. 21). Único punto de escritura de `bitacora_accesos`.
 *
 * El actor (membresía, usuario, IP, user agent) se resuelve del contexto actual;
 * quien llama solo aporta la acción, el recurso y los metadatos. Los metadatos
 * describen el acceso —filtros aplicados, número de filas, nombres de los campos
 * modificados— y **nunca** llevan el valor de un dato personal.
 */
class RegistrarAcceso
{
    /**
     * Registra un acceso. Devuelve null (sin escribir) si no hay campaña activa:
     * una traza sin tenant no es atribuible, y ese caso solo ocurre fuera del
     * flujo HTTP con contexto (seeders, comandos).
     *
     * @param  array<string, mixed>  $metadatos
     */
    public function handle(string $accion, ?string $recursoTipo = null, ?int $recursoId = null, array $metadatos = []): ?BitacoraAcceso
    {
        $tenant = TenantContext::get();

        if ($tenant === null) {
            return null;
        }

        $request = request();
        $user = $request->user();
        $membership = $user?->membershipEn($tenant);

        return BitacoraAcceso::create([
            'membership_id' => $membership?->id,
            'user_id' => $user?->id,
            'accion' => $accion,
            'recurso_tipo' => $recursoTipo,
            'recurso_id' => $recursoId,
            'metadatos' => $metadatos === [] ? null : $metadatos,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
        ]);
    }
}
