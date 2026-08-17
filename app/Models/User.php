<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Usuario interno. Dos roles, sin jerarquía intermedia (spec 4).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property bool $is_active
 * @property bool $must_change_password
 * @property string|null $theme_preference `null` = no eligió; manda el predeterminado
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_ADMIN = 'ADMIN';

    public const ROLE_OBRAS_PUBLICAS = 'OBRAS_PUBLICAS';

    /**
     * Explícito, nunca `$guarded = []` (RNF-SEC-003).
     *
     * `role`, `is_active` y `must_change_password` NO son asignables en masa: se
     * cambian por métodos del dominio y con auditoría, no porque llegaron en un
     * formulario.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'theme_preference',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            // El driver de hashing es Argon2id (config/hashing.php).
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Un usuario desactivado no puede iniciar sesión ni sostener una sesión
     * abierta. Se consulta en el login y en el middleware de sesión.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active;
    }
}
