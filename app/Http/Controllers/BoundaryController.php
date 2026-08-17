<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * El contorno oficial del partido, para el mapa (compuerta G3).
 *
 * Va por una ruta y no como propiedad de Inertia porque son 58 kB que no
 * cambian nunca: embebido viajaría en cada carga de cada pantalla con mapa, y
 * como archivo viaja una vez y después lo resuelve el caché del navegador.
 *
 * El validador es el hash que ya está en la configuración. No se calcula en cada
 * petición: es el mismo archivo congelado por versión (ADR-024), y si alguien lo
 * reemplaza sin actualizar la configuración, la suite se pone en rojo antes.
 */
final class BoundaryController
{
    public function __invoke(Request $request): SymfonyResponse
    {
        $etag = '"'.substr((string) config('obras.mapa.dataset_sha256'), 0, 32).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response(status: 304, headers: ['ETag' => $etag]);
        }

        $ruta = base_path((string) config('obras.mapa.dataset'));

        if (! is_file($ruta)) {
            return new JsonResponse(['message' => 'El recorte del partido no está disponible.'], 404);
        }

        return new Response(
            (string) file_get_contents($ruta),
            200,
            [
                'Content-Type' => 'application/geo+json',
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=86400, must-revalidate',
            ],
        );
    }
}
