<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opción de un campo de selección simple (RF-DIN-002).
 *
 * @property int $id
 * @property string $value
 */
class WorkFieldOption extends Model
{
    /** `value` es la clave con la que se guardan los datos: no se reasigna. */
    protected $fillable = ['label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<WorkFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkFieldDefinition::class, 'work_field_definition_id');
    }

    /** @return HasMany<WorkFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(WorkFieldValue::class, 'option_id');
    }

    /** Una opción usada se desactiva, no se borra (RF-CAT-005). */
    public function isInUse(): bool
    {
        return $this->values()->exists();
    }
}
