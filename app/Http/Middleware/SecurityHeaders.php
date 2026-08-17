<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad (RNF-SEC-001).
 *
 * La CSP se define desde F0 y no al final, porque el mapa carga teselas de un
 * tercero y agregar una política restrictiva sobre una aplicación ya construida
 * rompe cosas en lugares que nadie recuerda. Empezar con ella puesta obliga a
 * declarar cada origen externo cuando se lo agrega.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Permissions-Policy',
            // La geolocalización del navegador no se usa: la ubicación de una obra
            // la define quien la carga sobre el mapa, no el dispositivo.
            'geolocation=(), camera=(), microphone=(), payment=(), usb=()',
        );

        // HSTS sólo sobre HTTPS: enviarlo por HTTP no tiene efecto y en
        // desarrollo local sobre http rompería el acceso al fijarse en el
        // navegador.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $tileHost = $this->hostOf((string) config('mapa.tiles.url_template'));
        $imageSources = array_filter(["'self'", 'data:', 'blob:', $tileHost]);

        // `connect-src` incluye el propio origen nada más: ORS y Nominatim se
        // consultan desde el backend, nunca desde el navegador, así que la clave
        // de ORS no sale del servidor (RF-GEO-016).
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            'img-src '.implode(' ', $imageSources),
            "font-src 'self'",
            "connect-src 'self'",
            // Vite inyecta estilos en desarrollo; en producción los assets ya
            // están compilados y con hash.
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'".(app()->environment('local') ? " 'unsafe-eval'" : ''),
        ];

        if (! app()->environment('local')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function hostOf(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$host}";
    }
}
