<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkStatus> */
class WorkStatusFactory extends Factory
{
    protected $model = WorkStatus::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $etiqueta = fake()->unique()->word();

        return [
            'key' => Str::upper(Str::slug($etiqueta, '_')),
            'label' => Str::ucfirst($etiqueta),
            'is_final' => false,
            'is_system' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function finalizador(): static
    {
        return $this->state(fn (): array => ['is_final' => true]);
    }

    public function delSistema(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
