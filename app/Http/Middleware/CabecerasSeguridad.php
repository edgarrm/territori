<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad ausentes en el stack (I5 de la auditoría): sin ellas
 * un XSS puede exfiltrar el padrón hacia un dominio externo, la app queda
 * embebible en un iframe ajeno (clickjacking) y no hay HSTS que fuerce HTTPS
 * en visitas futuras.
 *
 * `style-src` incluye deliberadamente `'unsafe-inline'`: la UI usa `:style`
 * dinámico en varios componentes (Sidebar, Mapa, Prioridades...) y CSP no
 * tiene forma de dar nonce a un atributo `style=""` — el nonce solo cubre
 * elementos `<style>`/`<script>`. El vector real de un XSS es ejecutar script,
 * no inyectar una regla de estilo, así que `script-src` es el estricto.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->politica(Vite::cspNonce()));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', $this->permisos());

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * `https://rsms.me` sirve la fuente Inter de la landing pública
     * (`Welcome.vue`, el starter kit por defecto); `*.basemaps.cartocdn.com`
     * son las teselas del mapa de cobertura (Leaflet, en `Mapa.vue`).
     */
    private function politica(string $nonce): string
    {
        $directivas = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://rsms.me",
            "font-src 'self' https://rsms.me data:",
            "img-src 'self' data: https://*.basemaps.cartocdn.com",
            "connect-src 'self'".$this->connectSrcDesarrollo(),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directivas);
    }

    /**
     * `npm run dev` sirve los assets desde el servidor de Vite en un origen
     * distinto (WebSocket de hot-reload incluido). En producción se sirve el
     * build estático desde la propia app, sin necesidad de esta excepción.
     */
    private function connectSrcDesarrollo(): string
    {
        return app()->isProduction() ? '' : ' ws://127.0.0.1:* http://127.0.0.1:*';
    }

    /**
     * `geolocation` queda permitida para el propio origen: Captura.vue la usa
     * para fijar el domicilio del elector. El resto, sin uso en la app, cerrado.
     */
    private function permisos(): string
    {
        return implode(', ', [
            'geolocation=(self)',
            'camera=()',
            'microphone=()',
            'payment=()',
            'usb=()',
        ]);
    }
}
