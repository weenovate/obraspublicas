<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Settings\AppSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta y revocación de sesiones (RF-AUT-006/007, RF-USR-003, CA-024).
 *
 * Todo lo de acá es una operación EXITOSA —se abre una sesión, se cierra, se
 * revoca—, así que va por el camino transaccional de la auditoría. Registrar una
 * revocación por fuera de su transacción dejaría un evento afirmando que la
 * sesión se cortó cuando en realidad sigue viva, que es justo el tipo de mentira
 * que una bitácora de seguridad no puede permitirse.
 */
final class SessionRegistry
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Registra una sesión recién abierta.
     *
     * Se llama DESPUÉS de regenerar el identificador de sesión: la fila tiene que
     * guardar el identificador definitivo, no el que se descartó.
     */
    public function register(User $user, Request $request, bool $persistent = false): AuthSession
    {
        $session = new AuthSession;
        $session->user_id = $user->getKey();
        $session->session_id = $request->hasSession() ? $request->session()->getId() : null;
        $session->device_label = $this->deviceLabel($request);
        $session->ip_address = $request->ip();
        $session->user_agent = Str::limit((string) $request->userAgent(), 500, '');
        $session->is_persistent = $persistent;
        $session->last_seen_at = now();
        $session->save();

        return $session;
    }

    /**
     * Revoca una sesión concreta, auditando en la misma transacción.
     */
    public function revoke(AuthSession $session, string $reason, ?User $actor = null): void
    {
        if (! $session->isActive()) {
            return;
        }

        DB::transaction(function () use ($session, $reason, $actor): void {
            $session->revoked_at = now();
            $session->revoked_reason = $reason;
            $session->revoked_by_user_id = $actor?->getKey();
            $session->save();

            $this->audit->registrar(
                action: 'auth.session.revoked',
                entityType: 'auth_session',
                entityId: $session->getKey(),
                after: [
                    'user_id' => $session->user_id,
                    'motivo' => $reason,
                    'persistente' => $session->is_persistent,
                    'dispositivo' => $session->device_label,
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Revoca TODAS las sesiones vivas de un usuario.
     *
     * No abre transacción propia a propósito: se llama desde operaciones que ya
     * tienen una —desactivar un usuario, cambiar su contraseña— y la cascada
     * tiene que ser atómica con ellas. Si el usuario no se llega a desactivar,
     * sus sesiones no pueden quedar revocadas.
     *
     * @return int cantidad de sesiones revocadas
     */
    public function revokeAllFor(User $user, string $reason, ?User $actor = null): int
    {
        $sesiones = AuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->get();

        if ($sesiones->isEmpty()) {
            return 0;
        }

        foreach ($sesiones as $sesion) {
            $sesion->revoked_at = now();
            $sesion->revoked_reason = $reason;
            $sesion->revoked_by_user_id = $actor?->getKey();
            $sesion->save();
        }

        $this->audit->registrar(
            action: 'auth.session.revoked_all',
            entityType: 'user',
            entityId: $user->getKey(),
            after: [
                'motivo' => $reason,
                'sesiones_revocadas' => $sesiones->count(),
            ],
            actor: $actor,
        );

        return $sesiones->count();
    }

    /**
     * Marca actividad y decide si la sesión sigue viva.
     *
     * Las persistentes de LIVE no vencen por inactividad (RF-AUT-007); las de
     * backoffice sí, a los minutos configurados (RF-AUT-006).
     */
    public function touchAndCheck(AuthSession $session): bool
    {
        if (! $session->isActive()) {
            return false;
        }

        $minutos = (int) AppSettings::get(AppSettings::SESSION_IDLE_MINUTES);

        if ($session->isExpiredByInactivity($minutos)) {
            $this->revoke($session, AuthSession::REASON_INACTIVITY);

            return false;
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        return true;
    }

    /**
     * Etiqueta legible del dispositivo, para que el Admin distinga «la tele del
     * hall» de «mi notebook» al decidir cuál revocar.
     *
     * Es una heurística sobre el agente de usuario, deliberadamente pobre: sirve
     * para reconocer, no para identificar, y no se usa para ninguna decisión de
     * seguridad.
     */
    private function deviceLabel(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $sistema = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Desconocido',
        };

        $navegador = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Navegador',
        };

        return "{$navegador} en {$sistema}";
    }
}
