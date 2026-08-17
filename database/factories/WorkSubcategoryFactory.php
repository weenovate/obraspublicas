<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkCategory;
use App\Models\WorkSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkSubcategory> */
class WorkSubcategoryFactory extends Factory
{
    protected $model = WorkSubcategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nombre = fake()->unique()->words(2, true);

        return [
            'work_category_id' => WorkCategory::factory(),
            'name' => Str::ucfirst($nombre),
            'slug' => Str::slug($nombre),
            'geometry_mode' => WorkSubcategory::MODE_POINT,
            'routing_profile' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function linea(): static
    {
        return $this->state(fn (): array => [
            'geometry_mode' => WorkSubcategory::MODE_LINE_ROUTED_ROAD,
            'routing_profile' => 'driving-car',
        ]);
    }

    public function poligono(): static
    {
        return $this->state(fn (): array => ['geometry_mode' => WorkSubcategory::MODE_POLYGON]);
    }
}
