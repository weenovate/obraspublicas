<?php

declare(strict_types=1);

namespace App\Support\Fields;

use App\Models\Work;
use App\Models\WorkFieldDefinition;
use App\Models\WorkSubcategory;
use Illuminate\Support\Collection;

/**
 * Qué campos técnicos aplican a una obra (RF-DIN-001).
 *
 * Una obra presenta la UNIÓN de los campos definidos para su categoría y para su
 * subcategoría, sin códigos duplicados. Es lo que permite que «superficie» se
 * defina una vez para toda la categoría y que una subcategoría agregue lo suyo
 * sin repetir.
 *
 * EL DESEMPATE: ante el mismo código en los dos alcances, GANA LA SUBCATEGORÍA.
 * Es la definición más cercana al caso concreto, y quien la creó lo hizo sabiendo
 * que la categoría ya tenía una: la intención es afinarla, no duplicarla. El
 * criterio contrario haría que definir un campo específico no sirviera de nada.
 *
 * SÓLO ENTRAN LOS ACTIVOS. Un campo desactivado deja de ofrecerse para cargar,
 * pero sus valores ya guardados NO se tocan: se conservan como históricos
 * (RF-CAT-005, y la misma lógica de ADR-027 para los valores fuera de alcance).
 */
final class WorkFieldSet
{
    /**
     * Los campos que aplican a una subcategoría, ya resueltos y ordenados.
     *
     * @return Collection<int, WorkFieldDefinition>
     */
    public function paraSubcategoria(WorkSubcategory $subcategoria): Collection
    {
        $deLaCategoria = WorkFieldDefinition::query()
            ->where('scope_type', WorkFieldDefinition::SCOPE_CATEGORY)
            ->where('scope_id', $subcategoria->work_category_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $deLaSubcategoria = WorkFieldDefinition::query()
            ->where('scope_type', WorkFieldDefinition::SCOPE_SUBCATEGORY)
            ->where('scope_id', $subcategoria->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // La subcategoría se agrega PRIMERO al índice por código, así la
        // categoría no puede pisarla: `keyBy` conserva el último, y este orden
        // deja que el más específico sobreviva.
        $porCodigo = [];

        foreach ($deLaSubcategoria as $definicion) {
            $porCodigo[$definicion->code] = $definicion;
        }

        foreach ($deLaCategoria as $definicion) {
            $porCodigo[$definicion->code] ??= $definicion;
        }

        return collect(array_values($porCodigo))
            ->sortBy([
                // Primero los de la categoría —lo general antes que lo
                // particular—, después por el orden que fijó el Administrador.
                fn (WorkFieldDefinition $d): int => $d->scope_type === WorkFieldDefinition::SCOPE_CATEGORY ? 0 : 1,
                fn (WorkFieldDefinition $d): int => $d->sort_order,
                fn (WorkFieldDefinition $d): int => $d->id,
            ])
            ->values();
    }

    /** @return Collection<int, WorkFieldDefinition> */
    public function paraObra(Work $work): Collection
    {
        $subcategoria = $work->subcategory;

        return $subcategoria === null ? collect() : $this->paraSubcategoria($subcategoria);
    }

    /**
     * Los campos obligatorios que la obra tiene sin completar.
     *
     * Existe por la regla de no retroactividad: volver obligatorio un campo con
     * obras previas no las invalida, pero sí hay que poder DECIR cuáles quedaron
     * incompletas. Sin esta lista, la regla sería sólo permisividad.
     *
     * @return Collection<int, WorkFieldDefinition>
     */
    public function faltantesObligatorios(Work $work): Collection
    {
        $cargados = $work->fieldValues()->pluck('work_field_definition_id')->all();

        return $this->paraObra($work)
            ->filter(fn (WorkFieldDefinition $d): bool => $d->is_required && ! in_array($d->id, $cargados, true))
            ->values();
    }
}
