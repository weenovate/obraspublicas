<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WorkStatus;
use Illuminate\Database\Seeder;

/**
 * Los cinco estados base (RF-OBR-008).
 *
 * Las claves internas son estables y no cambian nunca: toda regla del sistema se
 * apoya en `is_final`, no en comparar contra ellas, pero los datos históricos y
 * las integraciones futuras sí dependen de que la clave no se mueva.
 *
 * `is_final` merece atención: sólo COMPLETED lo tiene. CANCELLED es un final del
 * recorrido pero NO una finalización de la obra —una obra cancelada no se
 * terminó— así que conserva la semántica de fecha prevista.
 */
class CatalogoBaseSeeder extends Seeder
{
    public function run(): void
    {
        $estados = [
            [WorkStatus::KEY_PLANNED, 'Planificada', false, 10],
            [WorkStatus::KEY_PENDING, 'Pendiente', false, 20],
            [WorkStatus::KEY_IN_PROGRESS, 'En ejecución', false, 30],
            [WorkStatus::KEY_COMPLETED, 'Finalizada', true, 40],
            [WorkStatus::KEY_CANCELLED, 'Cancelada', false, 50],
        ];

        foreach ($estados as [$key, $label, $isFinal, $orden]) {
            WorkStatus::query()->updateOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'is_final' => $isFinal,
                    'is_system' => true,
                    'sort_order' => $orden,
                    'is_active' => true,
                ],
            );
        }
    }
}
