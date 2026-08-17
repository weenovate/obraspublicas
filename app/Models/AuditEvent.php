<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Evento de auditoría. Sólo se inserta y se lee: nunca se modifica ni se borra.
 *
 * Es la capa 3 de la inmutabilidad, la que da un error legible en desarrollo
 * antes de que el disparador de MariaDB aborte la sentencia en producción.
 *
 * @property-read int $id
 */
class AuditEvent extends Model
{
    /** No hay `updated_at` que actualizar: un evento no cambia. */
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'user_id',
        'actor_email',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'request_id',
        'before_json',
        'after_json',
        'metadata_json',
        'is_failed_attempt',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'before_json' => 'array',
            'after_json' => 'array',
            'metadata_json' => 'array',
            'is_failed_attempt' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'La bitácora de auditoría es inmutable: no se puede modificar un evento ya registrado.',
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'La bitácora de auditoría es inmutable: no se puede eliminar un evento ya registrado.',
            );
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
