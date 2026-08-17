<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\SessionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Operaciones de dominio sobre usuarios (RF-USR-001…003, RF-AUT-003…005).
 *
 * Están acá y no en el controlador porque tres de ellas tienen que ser atómicas
 * con su auditoría y con su cascada de revocación de sesiones, y porque la regla
 * del último Admin no se puede expresar como validación de formulario.
 */
final class UserManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * Alta con contraseña temporal (RF-USR-001, RF-AUT-004).
     *
     * El primer ingreso obliga a cambiarla: `must_change_password` bloquea el
     * resto de la aplicación hasta que el usuario elija una propia.
     */
    public function create(string $name, string $email, string $role, string $temporaryPassword, ?User $actor = null): User
    {
        return DB::transaction(function () use ($name, $email, $role, $temporaryPassword, $actor): User {
            $user = new User;
            $user->name = $name;
            $user->email = mb_strtolower($email);
            $user->password = $temporaryPassword; // el cast `hashed` lo hashea con Argon2id
            $user->role = $role;
            $user->is_active = true;
            $user->must_change_password = true;
            $user->save();

            // La contraseña NO viaja al evento: la lista de redacción de
            // AuditRecorder la filtraría igual, pero no se la pasa (RF-AUD-002).
            $this->audit->registrar(
                action: 'user.created',
                entityType: 'user',
                entityId: $user->getKey(),
                after: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => true,
                    'must_change_password' => true,
                ],
                actor: $actor,
            );

            return $user;
        });
    }

    /**
     * Desactiva un usuario y revoca sus sesiones, todo en una transacción
     * (RF-AUT-005).
     *
     * LA REGLA DEL ÚLTIMO ADMIN se valida con un bloqueo de filas, no con un
     * `count()` previo. Dos administradores desactivándose al mismo tiempo
     * leerían cada uno «hay 2 activos», los dos pasarían el chequeo y el sistema
     * quedaría sin acceso. El `lockForUpdate()` serializa las dos transacciones:
     * la segunda ve el estado ya confirmado por la primera.
     */
    public function deactivate(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $this->assertNotLastActiveAdmin($user, 'desactivar');

            $antes = ['is_active' => $user->is_active];

            $user->forceFill(['is_active' => false])->save();

            // Cascada: un usuario desactivado no puede seguir con sesiones
            // abiertas, ni normales ni persistentes de LIVE (CA-024).
            $revocadas = $this->sessions->revokeAllFor(
                $user,
                AuthSession::REASON_USER_DEACTIVATED,
                $actor,
            );

            $this->audit->registrar(
                action: 'user.deactivated',
                entityType: 'user',
                entityId: $user->getKey(),
                before: $antes,
                after: ['is_active' => false, 'sesiones_revocadas' => $revocadas],
                actor: $actor,
            );
        });
    }

    public function activate(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $antes = ['is_active' => $user->is_active];
            $user->forceFill(['is_active' => true])->save();

            $this->audit->registrar(
                action: 'user.activated',
                entityType: 'user',
                entityId: $user->getKey(),
                before: $antes,
                after: ['is_active' => true],
                actor: $actor,
            );
        });
    }

    /**
     * Cambia el rol, con la misma protección del último Admin: degradar al
     * último Admin activo deja el sistema sin quien administre (RF-AUT-005).
     */
    public function changeRole(User $user, string $role, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $role, $actor): void {
            if ($user->role === User::ROLE_ADMIN && $role !== User::ROLE_ADMIN) {
                $this->assertNotLastActiveAdmin($user, 'quitarle el rol de Administrador a');
            }

            $antes = ['role' => $user->role];
            $user->forceFill(['role' => $role])->save();

            $this->audit->registrar(
                action: 'user.role_changed',
                entityType: 'user',
                entityId: $user->getKey(),
                before: $antes,
                after: ['role' => $role],
                actor: $actor,
            );
        });
    }

    /**
     * Cambio de contraseña.
     *
     * Revoca las demás sesiones del usuario (RF-AUT-007): si la contraseña se
     * cambió porque alguien más la conocía, dejar sus sesiones abiertas haría el
     * cambio inútil. La sesión actual se conserva cuando se le pasa.
     */
    public function changePassword(
        User $user,
        string $newPassword,
        ?User $actor = null,
        bool $temporary = false,
        ?AuthSession $keepSession = null,
    ): void {
        DB::transaction(function () use ($user, $newPassword, $actor, $temporary, $keepSession): void {
            $user->forceFill([
                'password' => Hash::make($newPassword),
                'must_change_password' => $temporary,
                'password_changed_at' => now(),
            ])->save();

            $sesiones = AuthSession::query()
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->when($keepSession !== null, fn ($q) => $q->whereKeyNot($keepSession->getKey()))
                ->get();

            foreach ($sesiones as $sesion) {
                $sesion->forceFill([
                    'revoked_at' => now(),
                    'revoked_reason' => AuthSession::REASON_PASSWORD_CHANGED,
                    'revoked_by_user_id' => $actor?->getKey(),
                ])->save();
            }

            // Ni la contraseña vieja ni la nueva entran al evento (RF-AUD-002).
            $this->audit->registrar(
                action: 'user.password_changed',
                entityType: 'user',
                entityId: $user->getKey(),
                after: [
                    'temporal' => $temporary,
                    'sesiones_revocadas' => $sesiones->count(),
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Impide dejar al sistema sin ningún Admin activo.
     *
     * `lockForUpdate()` sobre las filas de administradores activos es lo que hace
     * la regla resistente a concurrencia: sin él, la validación es una foto vieja.
     */
    private function assertNotLastActiveAdmin(User $user, string $accion): void
    {
        if ($user->role !== User::ROLE_ADMIN || ! $user->is_active) {
            return;
        }

        $otrosAdminsActivos = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count();

        if ($otrosAdminsActivos === 0) {
            throw new LastAdminException(
                "No se puede {$accion} al último Administrador activo: el sistema quedaría sin nadie "
                .'que pueda administrar usuarios, catálogos ni configuración.',
            );
        }
    }
}
