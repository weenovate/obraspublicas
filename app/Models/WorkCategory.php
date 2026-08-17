<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de obra, que en el mapa es una capa (RF-CAT-001).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $icon
 * @property string $color
 * @property bool $is_active
 */
class WorkCategory extends Model
{
    /** @use HasFactory<WorkCategoryFactory> */
    use HasFactory;

    /** `slug` NO es asignable en masa: es inmutable una vez que hay obras. */
    protected $fillable = ['name', 'icon', 'color', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<WorkSubcategory, $this> */
    public function subcategories(): HasMany
    {
        return $this->hasMany(WorkSubcategory::class);
    }

    /**
     * ¿Tiene obras, por sus subcategorías, incluidas las de papelera?
     */
    public function isInUse(): bool
    {
        return Work::query()
            ->withTrashed()
            ->whereIn('work_subcategory_id', $this->subcategories()->select('id'))
            ->exists();
    }

    public function hasSubcategories(): bool
    {
        return $this->subcategories()->exists();
    }
}
