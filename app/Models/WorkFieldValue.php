<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valor de un campo técnico para una obra (spec 9.3).
 *
 * Columnas tipadas, no un `value` genérico: guardar todo como texto convertiría
 * cada filtro numérico o por fecha en una comparación de cadenas, y perdería el
 * error al cargar un dato que no corresponde al tipo.
 *
 * @property int $id
 */
class WorkFieldValue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'value_text',
        'value_integer',
        'value_decimal',
        'value_boolean',
        'value_date',
        'option_id',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:6',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
        ];
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return BelongsTo<WorkFieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkFieldDefinition::class, 'work_field_definition_id');
    }
}
