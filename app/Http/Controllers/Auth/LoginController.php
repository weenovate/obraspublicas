<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Login mínimo pero funcional de F0.
 *
 * F0 incluye autenticación real, no sólo configuración: un límite de tasa sin
 * endpoint es una línea de `config`, no un control. Con el endpoint puesto, los
 * tests pueden verificar de verdad que tras N intentos la respuesta es 429, que
 * la respuesta no revela si el correo existe, y que cada intento fallido dejó su
 * evento.
 *
 * Roles, políticas, CRUD de usuarios, contraseñas temporales y revocación de
 * sesiones son de F1.
 *
 * Asignación de los dos caminos de auditoría (sección 4 del plan):
 *
 *   - intento fallido y rechazo por límite de tasa → `registrarIntentoFallido()`
 *   - login exitoso                                → `registrar()`, transaccional,
 *     y DESPUÉS de crear la sesión y regenerar su identificador
 */
final class LoginController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function show(): InertiaResponse
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'correo electrónico',
            'password' => 'contraseña',
        ]);

        $this->assertNotRateLimited($request, $credentials['email']);

        $user = User::query()->where('email', $credentials['email'])->first();

        // Se verifica el hash incluso si el usuario no existe o está inactivo,
        // con un hash de descarte, para que el tiempo de respuesta no delate
        // cuáles correos están registrados.
        $passwordMatches = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], $this->dummyHash());

        if ($user === null || ! $passwordMatches || ! $user->canAuthenticate()) {
            $this->onFailedAttempt($request, $credentials['email'], $user, $passwordMatches);
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        // Un login exitoso es un cambio de estado —`last_login_at`, y en F1 la
        // fila de `auth_sessions`—, así que su auditoría va en la misma
        // transacción. El evento se emite después de regenerar el identificador
        // de sesión: escribirlo antes afirmaría un ingreso que todavía puede
        // fallar.
        Auth::login($user);
        $request->session()->regenerate();

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill(['last_login_at' => now()])->save();

            $this->audit->registrar(
                action: 'auth.login',
                entityType: 'user',
                entityId: $user->getKey(),
                metadata: [
                    'session_id' => $request->session()->getId(),
                    'must_change_password' => $user->must_change_password,
                ],
                actor: $user,
            );
        });

        return redirect()->intended('/admin');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Cerrar sesión también es una operación exitosa: camino transaccional.
        DB::transaction(function () use ($user, $request): void {
            $this->audit->registrar(
                action: 'auth.logout',
                entityType: 'user',
                entityId: $user?->getKey(),
                metadata: ['session_id' => $request->session()->getId()],
                actor: $user,
            );
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Límite compuesto por correo + IP, con dos ventanas (RNF-SEC-004).
     *
     * Por minuto frena el ataque rápido; por hora frena el lento, que es el que
     * suele pasar inadvertido.
     */
    private function assertNotRateLimited(Request $request, string $email): void
    {
        $perMinuteKey = $this->throttleKey($request, $email);
        $perHourKey = $perMinuteKey.':hora';

        $perMinute = (int) config('auth.login_throttle.per_minute', 5);
        $perHour = (int) config('auth.login_throttle.per_hour', 20);

        foreach ([[$perMinuteKey, $perMinute, 60], [$perHourKey, $perHour, 3600]] as [$key, $max, $decay]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            // No hay transacción de negocio que confirmar: camino no transaccional.
            $this->audit->registrarIntentoFallido('auth.login.rate_limited', [
                'email' => $email,
                'ventana_segundos' => $decay,
                'reintentar_en_segundos' => $seconds,
            ]);

            // 429 de verdad, no un 302 con el mensaje adentro. Para un formulario
            // web, `ValidationException` redirige y el estado se pierde; acá el
            // código de estado es parte del contrato (RNF-SEC-004), así que se
            // devuelve la misma pantalla con estado 429 y el mensaje visible.
            throw new HttpResponseException(
                Inertia::render('Auth/Login', [
                    'errors' => ['email' => "Demasiados intentos. Probá de nuevo en {$seconds} segundos."],
                    'rateLimitedSeconds' => $seconds,
                ])->toResponse($request)->setStatusCode(429),
            );
        }
    }

    /**
     * Un intento fallido: se audita, se cuenta y se responde siempre igual.
     */
    private function onFailedAttempt(
        Request $request,
        string $email,
        ?User $user,
        bool $passwordMatches,
    ): never {
        $key = $this->throttleKey($request, $email);
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key.':hora', 3600);

        // El motivo se registra para poder investigar, pero NO se le informa al
        // cliente: la respuesta es uniforme.
        $this->audit->registrarIntentoFallido('auth.login.failed', [
            'email' => $email,
            'motivo' => match (true) {
                $user === null => 'usuario_inexistente',
                ! $passwordMatches => 'contrasena_incorrecta',
                default => 'usuario_inactivo',
            },
        ]);

        throw ValidationException::withMessages([
            'email' => 'Las credenciales no son correctas.',
        ]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.Str::lower($email).'|'.$request->ip();
    }

    /**
     * Hash de descarte para igualar el tiempo de respuesta cuando el usuario no
     * existe. Se calcula una sola vez por proceso.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('contrasena-de-descarte-para-igualar-el-tiempo');
    }
}
