<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkCategory> */
class WorkCategoryFactory extends Factory
{
    protected $model = WorkCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nombre = fake()->unique()->words(2, true);

        return [
            'name' => Str::ucfirst($nombre),
            'slug' => Str::slug($nombre),
            'icon' => 'road',
            // Un verde que cumple contraste contra los dos temas: el de la
            // extensión de accesibilidad. Un color al azar haría fallar la
            // validación de RF-CAT-003 de forma intermitente.
            'color' => '#497D1F',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
