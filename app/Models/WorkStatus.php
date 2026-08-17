<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de una obra (RF-OBR-008/009).
 *
 * `is_final` es lo que gobierna la semántica de fechas. Ninguna regla del sistema
 * compara contra `key`: si lo hiciera, un estado propio como «Finalizada con
 * observaciones» no dispararía la validación y aceptaría una fecha futura como si
 * la obra no estuviera terminada.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property bool $is_final
 * @property bool $is_system
 * @property bool $is_active
 */
class WorkStatus extends Model
{
    /** @use HasFactory<WorkStatusFactory> */
    use HasFactory;

    /** Los cinco base (RF-OBR-008). Sus claves no cambian nunca. */
    public const KEY_PLANNED = 'PLANNED';

    public const KEY_PENDING = 'PENDING';

    public const KEY_IN_PROGRESS = 'IN_PROGRESS';

    public const KEY_COMPLETED = 'COMPLETED';

    public const KEY_CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = ['label', 'color', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Work, $this> */
    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    /**
     * ¿Hay obras con este estado, incluidas las de papelera?
     *
     * La papelera cuenta: restaurar una obra tiene que seguir dando un registro
     * válido, así que un estado con obras en papelera sigue estando en uso.
     */
    public function isInUse(): bool
    {
        return $this->works()->withTrashed()->exists();
    }
}
