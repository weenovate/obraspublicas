<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Support\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hace efectiva la revocación de sesiones (RF-AUT-005/006/007, CA-024).
 *
 * Marcar una fila como revocada no desconecta a nadie: la cookie de sesión sigue
 * siendo válida hasta que alguien la revisa. Este middleware es ese alguien, y
 * corre en cada petición autenticada.
 *
 * Tres motivos para cortar, en este orden:
 *
 *   1. El usuario fue desactivado. No conserva acceso aunque su sesión siguiera
 *      abierta (RF-AUT-005).
 *   2. La sesión fue revocada, por cierre, cambio de contraseña o decisión del
 *      Admin (RF-USR-003).
 *   3. Venció por inactividad, a las 8 h configurables (RF-AUT-006). Las
 *      persistentes de LIVE están exentas de este tercer motivo, no de los dos
 *      primeros: una pantalla de exhibición no se desconecta sola, pero sí deja
 *      de funcionar si desactivan al usuario que la abrió.
 */
final class EnsureSessionIsActive
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->cortar($request, 'Tu usuario fue desactivado.');
        }

        $session = AuthSession::query()
            ->where('user_id', $user->getKey())
            ->where('session_id', $request->session()->getId())
            ->first();

        // Sin fila asociada no se corta la sesión: puede ser una sesión abierta
        // antes de que existiera este registro, y desconectar a todo el mundo en
        // el despliegue sería peor que el problema que resuelve.
        if ($session === null) {
            return $next($request);
        }

        if (! $this->sessions->touchAndCheck($session)) {
            return $this->cortar($request, 'Tu sesión ya no está activa. Ingresá de nuevo.');
        }

        return $next($request);
    }

    private function cortar(Request $request, string $mensaje): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $mensaje]);
    }
}
