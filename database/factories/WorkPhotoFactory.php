<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Work;
use App\Models\WorkPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkPhoto>
 */
class WorkPhotoFactory extends Factory
{
    protected $model = WorkPhoto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nombre = fake()->uuid().'.jpg';

        return [
            'work_id' => Work::factory(),
            'status' => WorkPhoto::STATUS_PENDING,
            'original_filename' => fake()->word().'.jpg',
            'disk' => 'local',
            'path_original' => fn (array $a): string => "fotos/{$a['work_id']}/{$nombre}",
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 4_000_000),
            'sort_order' => 1,
            'attempts' => 0,
            'uploaded_by' => User::factory(),
        ];
    }

    /**
     * Procesada y publicable.
     *
     * Las rutas de los derivados se calculan en `afterMaking` y no en el estado:
     * ahí `path_original` ya es una cadena, mientras que dentro del estado
     * todavía puede ser el closure que depende de `work_id`.
     */
    public function lista(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkPhoto::STATUS_READY,
            'width' => 1600,
            'height' => 1200,
            'attempts' => 1,
            'processed_at' => now(),
        ])->afterMaking(function (WorkPhoto $foto): void {
            $foto->path_large = str_replace('.jpg', '-large.jpg', $foto->path_original);
            $foto->path_thumb = str_replace('.jpg', '-thumb.jpg', $foto->path_original);
        });
    }

    /** Falló el procesamiento y se puede reintentar. */
    public function fallida(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkPhoto::STATUS_FAILED,
            'failure_reason' => 'No se pudo procesar la imagen. Puede estar dañada o no ser una foto válida.',
            'attempts' => 1,
            'processed_at' => now(),
        ]);
    }

    /** Falló tantas veces que ya no se reintenta sola. */
    public function agotada(): static
    {
        return $this->fallida()->state(fn (): array => ['attempts' => WorkPhoto::MAX_ATTEMPTS]);
    }
}
