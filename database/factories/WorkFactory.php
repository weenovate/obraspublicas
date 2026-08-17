<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Work;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Work>
 *
 * Las coordenadas ahora son REALES: el centroide del partido de Ramallo, tomado
 * del recorte oficial del IGN que cerró G3 y comprobado dentro del polígono
 * (`database/geo/MANIFIESTO.md`). No se escriben acá: salen de `config/obras.php`,
 * para que un recorte nuevo no deje los fixtures apuntando a otro lado.
 *
 * Siguen siendo asimétricas —longitud ≈ −60, latitud ≈ −33— y eso importa: si
 * alguien intercambia los ejes, la aserción rompe en vez de compensarse.
 */
class WorkFactory extends Factory
{
    protected $model = Work::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $inicio = fake()->dateTimeBetween('-2 years', '-1 month');
        $prevista = fake()->dateTimeBetween($inicio, '+1 year');

        return [
            'sequence_number' => fake()->unique()->numberBetween(1, 999999),
            'code' => fn (array $atributos): string => sprintf('OBR-2026-%04d', $atributos['sequence_number']),
            'code_year' => 2026,
            'name' => fake()->sentence(4),
            'work_subcategory_id' => WorkSubcategory::factory(),
            'work_status_id' => fn (): int => WorkStatus::query()->firstOr(
                fn () => WorkStatus::factory()->create(),
            )->getKey(),
            'start_date' => $inicio,
            'estimated_end_date' => $prevista,
            'actual_end_date' => null,
            // Sin estado finalizador, la fecha efectiva es la prevista (ADR-008).
            'effective_end_date' => $prevista,
            'district' => 'Ramallo',
            'province' => 'Buenos Aires',
            'lock_version' => 0,
        ];
    }

    /**
     * Las columnas geométricas no pasan por el `create()` de Eloquent: son
     * `GEOMETRY NOT NULL` y hay que construirlas con `ST_GeomFromText`. Se
     * escriben en un segundo paso, con el WKT y el SRID por binding, igual que en
     * el código de producción.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Work $work): void {
            [$lon, $lat] = config('obras.mapa.centro');
            $punto = sprintf("ST_GeomFromText('POINT(%.6F %.6F)', 4326)", $lon, $lat);

            // Eloquent necesita algo en las columnas para que el INSERT sea
            // válido; se reemplaza inmediatamente por la geometría real.
            $work->setRawAttributes(array_merge($work->getAttributes(), [
                'geometry' => DB::raw($punto),
                'representative_point' => DB::raw($punto),
            ]), true);
        });
    }
}
