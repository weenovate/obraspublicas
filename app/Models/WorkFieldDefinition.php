<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Definición de un campo técnico dinámico (RF-DIN-001…005).
 *
 * @property int $id
 * @property string $scope_type
 * @property int $scope_id
 * @property string $code
 * @property string $data_type
 */
class WorkFieldDefinition extends Model
{
    public const SCOPE_CATEGORY = 'CATEGORY';

    public const SCOPE_SUBCATEGORY = 'SUBCATEGORY';

    public const TYPE_TEXT = 'TEXT';

    public const TYPE_LONG_TEXT = 'LONG_TEXT';

    public const TYPE_INTEGER = 'INTEGER';

    public const TYPE_DECIMAL = 'DECIMAL';

    public const TYPE_BOOLEAN = 'BOOLEAN';

    public const TYPE_DATE = 'DATE';

    public const TYPE_SELECT = 'SELECT';

    /**
     * La columna tipada que corresponde a cada tipo de dato. Es la tabla que usa
     * la validación de «exactamente una columna coincide» (spec 9.3).
     *
     * @var array<string, string>
     */
    public const VALUE_COLUMNS = [
        self::TYPE_TEXT => 'value_text',
        self::TYPE_LONG_TEXT => 'value_text',
        self::TYPE_INTEGER => 'value_integer',
        self::TYPE_DECIMAL => 'value_decimal',
        self::TYPE_BOOLEAN => 'value_boolean',
        self::TYPE_DATE => 'value_date',
        self::TYPE_SELECT => 'option_id',
    ];

    /** `code`, `data_type` y el alcance no son asignables en masa: son inmutables. */
    protected $fillable = [
        'label',
        'help_text',
        'unit',
        'min_value',
        'max_value',
        'is_required',
        'public_visible',
        'live_visible',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'public_visible' => 'boolean',
            'live_visible' => 'boolean',
            'is_active' => 'boolean',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }

    /** @return HasMany<WorkFieldOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(WorkFieldOption::class);
    }

    /** @return HasMany<WorkFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(WorkFieldValue::class);
    }

    /** ¿Ya hay valores cargados? Es lo que congela `data_type` (RF-DIN-004). */
    public function hasValues(): bool
    {
        return $this->values()->exists();
    }

    public function valueColumn(): string
    {
        return self::VALUE_COLUMNS[$this->data_type];
    }
}
