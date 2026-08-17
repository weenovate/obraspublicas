<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza el cambio de la contraseña temporal (RF-AUT-004).
 *
 * Una contraseña temporal la eligió otra persona: mientras siga vigente, quien la
 * generó puede entrar a la cuenta. Por eso bloquea TODA la aplicación y no sólo
 * las pantallas administrativas, y por eso no se puede saltear navegando a otra
 * URL.
 */
final class EnsurePasswordIsChanged
{
    /** Rutas que tienen que seguir alcanzables, o el usuario queda encerrado. */
    private const PERMITIDAS = ['perfil.password', 'perfil.password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::PERMITIDAS, true)) {
            return $next($request);
        }

        return redirect()->route('perfil.password')
            ->with('error', 'Tenés que elegir una contraseña propia antes de continuar.');
    }
}
