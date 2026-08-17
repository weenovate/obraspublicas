<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkSubcategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subcategoría, que es la que define qué geometría admite la obra (RF-CAT-004).
 *
 * @property int $id
 * @property int $work_category_id
 * @property string $geometry_mode
 * @property string|null $routing_profile
 */
class WorkSubcategory extends Model
{
    /** @use HasFactory<WorkSubcategoryFactory> */
    use HasFactory;

    public const MODE_POINT = 'POINT';

    public const MODE_LINE_ROUTED_ROAD = 'LINE_ROUTED_ROAD';

    public const MODE_LINE_MANUAL_NETWORK = 'LINE_MANUAL_NETWORK';

    public const MODE_POLYGON = 'POLYGON';

    /**
     * Los dos modos que persisten `LINESTRING`. Son intercambiables entre sí
     * incluso con obras cargadas, porque la diferencia es sólo si se ofrece
     * trazado asistido y no toca ninguna geometría almacenada.
     *
     * @var list<string>
     */
    public const LINE_MODES = [self::MODE_LINE_ROUTED_ROAD, self::MODE_LINE_MANUAL_NETWORK];

    /** Ni la categoría padre ni el modo geométrico son asignables en masa. */
    protected $fillable = ['name', 'routing_profile', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<WorkCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkCategory::class, 'work_category_id');
    }

    /** @return HasMany<Work, $this> */
    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }

    public function isInUse(): bool
    {
        return $this->works()->withTrashed()->exists();
    }

    /** El tipo de geometría que la base tiene que ver para este modo. */
    public function expectedGeometryType(): string
    {
        return match ($this->geometry_mode) {
            self::MODE_POINT => 'POINT',
            self::MODE_POLYGON => 'POLYGON',
            default => 'LINESTRING',
        };
    }
}
