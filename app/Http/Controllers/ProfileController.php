<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuthSession;
use App\Support\Audit\AuditRecorder;
use App\Support\Settings\AppSettings;
use App\Support\Users\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Perfil propio (RF-USR-002, RF-CFG-004).
 *
 * Todo usuario puede cambiar su nombre, su contraseña y su preferencia de tema.
 * **No** puede cambiar su correo ni su rol: son datos que administra el Admin, y
 * dejarlos editables permitiría que alguien se auto-promoviera o se llevara la
 * cuenta a otro correo.
 */
final class ProfileController
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly UserManager $users,
    ) {}

    public function edit(Request $request): InertiaResponse
    {
        return Inertia::render('Perfil/Editar', [
            'temaPredeterminado' => AppSettings::get(AppSettings::DEFAULT_THEME),
            'sesiones' => $this->sesionesDe($request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // `null` es un valor válido: significa «seguir el predeterminado del
            // sistema» (RF-CFG-005), y es distinto de no haber enviado el campo.
            'theme_preference' => ['nullable', 'in:light,dark'],
        ], [], ['name' => 'nombre']);

        DB::transaction(function () use ($user, $datos): void {
            $antes = [
                'name' => $user->name,
                'theme_preference' => $user->theme_preference,
            ];

            $user->forceFill([
                'name' => $datos['name'],
                'theme_preference' => $datos['theme_preference'] ?? null,
            ])->save();

            $this->audit->registrar(
                action: 'user.profile_updated',
                entityType: 'user',
                entityId: $user->getKey(),
                before: $antes,
                after: ['name' => $user->name, 'theme_preference' => $user->theme_preference],
                actor: $user,
            );
        });

        return back()->with('success', 'Perfil actualizado.');
    }

    public function passwordForm(): InertiaResponse
    {
        return Inertia::render('Perfil/Password');
    }

    public function passwordUpdate(Request $request): RedirectResponse
    {
        $user = $request->user();

        $datos = $request->validate([
            'current_password' => ['required', 'string'],
            // Mínimo 12 caracteres (RF-AUT-003).
            'password' => ['required', 'confirmed', Password::min(12)],
        ], [], [
            'current_password' => 'contraseña actual',
            'password' => 'contraseña nueva',
        ]);

        if (! Hash::check($datos['current_password'], $user->password)) {
            // Un intento fallido de cambio de contraseña es un intento denegado:
            // camino no transaccional.
            $this->audit->registrarIntentoFallido('user.password_change.failed', [
                'motivo' => 'contrasena_actual_incorrecta',
            ], $user);

            throw ValidationException::withMessages([
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        // La sesión en curso se conserva; las demás se revocan. Si la contraseña
        // se cambió porque alguien más la conocía, dejar sus sesiones abiertas
        // haría el cambio inútil.
        $sesionActual = AuthSession::query()
            ->where('user_id', $user->getKey())
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->first();

        $this->users->changePassword(
            user: $user,
            newPassword: $datos['password'],
            actor: $user,
            temporary: false,
            keepSession: $sesionActual,
        );

        return redirect()->route('admin.inicio')
            ->with('success', 'Tu contraseña quedó actualizada.');
    }

    /**
     * Sesiones vivas del usuario, para que pueda ver desde dónde está conectado.
     *
     * @return list<array<string, mixed>>
     */
    private function sesionesDe(Request $request): array
    {
        return AuthSession::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (AuthSession $s): array => [
                'id' => $s->getKey(),
                'dispositivo' => $s->device_label,
                'ip' => $s->ip_address,
                'persistente' => $s->is_persistent,
                'ultima_actividad' => $s->last_seen_at?->diffForHumans(),
                'es_esta' => $s->session_id === $request->session()->getId(),
            ])
            ->all();
    }
}
