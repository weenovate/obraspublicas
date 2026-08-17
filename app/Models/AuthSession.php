<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuthSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sesión revocable (RF-AUT-006/007, RF-USR-003).
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_id
 * @property string|null $device_label
 * @property string|null $ip_address
 * @property bool $is_persistent
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
class AuthSession extends Model
{
    /** @use HasFactory<AuthSessionFactory> */
    use HasFactory;

    /** Motivos de revocación. El motivo es parte del registro, no un detalle. */
    public const REASON_LOGOUT = 'LOGOUT';

    public const REASON_USER_DEACTIVATED = 'USER_DEACTIVATED';

    public const REASON_PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    public const REASON_ADMIN_REVOKED = 'ADMIN_REVOKED';

    public const REASON_INACTIVITY = 'INACTIVITY';

    /** @var list<string> */
    protected $fillable = ['device_label'];

    protected function casts(): array
    {
        return [
            'is_persistent' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * ¿Venció por inactividad?
     *
     * Las persistentes de LIVE no vencen nunca por este camino: una pantalla de
     * exhibición que se desconecta sola a las ocho horas es una pantalla que
     * alguien tiene que ir a reiniciar cada mañana (RF-AUT-007).
     */
    public function isExpiredByInactivity(int $minutes): bool
    {
        if ($this->is_persistent || $this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->diffInMinutes(now()) >= $minutes;
    }
}
