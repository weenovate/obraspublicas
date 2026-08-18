<?php

declare(strict_types=1);

namespace App\Support\Fields;

use App\Models\Work;
use App\Models\WorkFieldDefinition;
use App\Models\WorkFieldOption;
use App\Models\WorkFieldValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Valida y persiste los valores de los campos técnicos de una obra (spec 9.3).
 *
 * TRES REGLAS QUE SOSTIENEN EL RESTO:
 *
 *   EXACTAMENTE UNA COLUMNA TIPADA. Cada valor se guarda en la columna que
 *   corresponde a su `data_type` y las demás quedan nulas. No hay un `value`
 *   genérico de texto: guardarlo todo como cadena convertiría cada filtro
 *   numérico o por fecha en una comparación de strings, y perdería el error al
 *   cargar un dato que no corresponde.
 *
 *   LO OBLIGATORIO NO ES RETROACTIVO. Volver obligatorio un campo con obras ya
 *   cargadas no las invalida: se exige en la edición que se está guardando y las
 *   viejas quedan marcadas como incompletas. Rechazarlas dejaría obras que no se
 *   pueden ni corregir sin llenar campos que no existían cuando se cargaron.
 *
 *   LOS VALORES FUERA DE ALCANCE NO SE TOCAN (ADR-027). Sólo se escriben los
 *   campos que aplican hoy; los que dejaron de aplicar quedan donde están.
 *
 * Se escribe DENTRO de la transacción de `WorkWriter`, nunca después: un valor
 * que sobreviviera a un alta revertida es el mismo defecto que ADR-004 corrigió
 * en la auditoría.
 */
final class WorkFieldValueWriter
{
    public function __construct(private readonly WorkFieldSet $campos) {}

    /**
     * @param  array<array-key, mixed>  $valores  Indexado por id de definición
     *
     * @throws FieldRuleViolation
     */
    public function guardar(Work $work, array $valores, bool $exigirObligatorios = true): void
    {
        if (DB::transactionLevel() === 0) {
            // Igual que `WorkCodeGenerator`: se exige el contexto en lugar de
            // suponerlo, porque el fallo silencioso es peor que el ruidoso.
            throw new FieldRuleViolation(
                'Los valores de campos técnicos se guardan dentro de la transacción de la obra.',
            );
        }

        $aplicables = $this->campos->paraObra($work);

        foreach ($aplicables as $definicion) {
            $crudo = $valores[$definicion->id] ?? null;

            if ($this->estaVacio($crudo)) {
                if ($exigirObligatorios && $definicion->is_required) {
                    throw new FieldRuleViolation("«{$definicion->label}» es obligatorio.");
                }

                // Vaciar un campo borra su valor: es cómo se corrige una carga
                // equivocada. No se conserva, porque el campo SIGUE en alcance y
                // dejar el valor viejo contradiría lo que la persona ve.
                $work->fieldValues()->where('work_field_definition_id', $definicion->id)->delete();

                continue;
            }

            $columna = $definicion->valueColumn();

            $fila = WorkFieldValue::query()->firstOrNew([
                'work_id' => $work->getKey(),
                'work_field_definition_id' => $definicion->id,
            ]);

            // Por asignación directa y no por `fill`: las dos claves NO son
            // asignables en masa —y está bien que no lo sean, las pone el
            // dominio y no un formulario—, así que `firstOrNew` las descarta al
            // construir la fila nueva y el INSERT saldría sin ellas.
            $fila->work_id = $work->getKey();
            $fila->work_field_definition_id = $definicion->id;

            // Se limpian TODAS las columnas tipadas antes de escribir la que
            // toca: si un campo cambió de tipo alguna vez, podría quedar un
            // resto en otra columna y romper la regla de «exactamente una».
            foreach (array_unique(array_values(WorkFieldDefinition::VALUE_COLUMNS)) as $cualquiera) {
                $fila->{$cualquiera} = null;
            }

            $fila->{$columna} = $this->convertir($definicion, $crudo);
            $fila->save();
        }
    }

    /**
     * @throws FieldRuleViolation
     */
    private function convertir(WorkFieldDefinition $definicion, mixed $crudo): mixed
    {
        return match ($definicion->data_type) {
            WorkFieldDefinition::TYPE_TEXT,
            WorkFieldDefinition::TYPE_LONG_TEXT => (string) $crudo,

            WorkFieldDefinition::TYPE_INTEGER => $this->entero($definicion, $crudo),
            WorkFieldDefinition::TYPE_DECIMAL => $this->decimal($definicion, $crudo),
            WorkFieldDefinition::TYPE_BOOLEAN => (bool) filter_var($crudo, FILTER_VALIDATE_BOOLEAN),
            WorkFieldDefinition::TYPE_DATE => $this->fecha($definicion, $crudo),
            WorkFieldDefinition::TYPE_SELECT => $this->opcion($definicion, $crudo),

            // Acá SÍ hace falta la rama: a diferencia de la geometría, donde el
            // tipo queda acotado por construcción, `data_type` es una columna y
            // nada impide que una migración futura agregue un valor sin agregar
            // su conversión. Que rompa ruidosamente es lo correcto: guardar un
            // campo cuyo tipo no se sabe manejar sería perder el dato en
            // silencio.
            default => throw new FieldRuleViolation(
                "El campo «{$definicion->label}» tiene un tipo de dato que el sistema no sabe guardar: "
                ."«{$definicion->data_type}».",
            ),
        };
    }

    private function entero(WorkFieldDefinition $definicion, mixed $crudo): int
    {
        if (! is_numeric($crudo) || (string) (int) $crudo !== trim((string) $crudo)) {
            throw new FieldRuleViolation("«{$definicion->label}» tiene que ser un número entero.");
        }

        return (int) $this->dentroDelRango($definicion, (float) $crudo);
    }

    private function decimal(WorkFieldDefinition $definicion, mixed $crudo): float
    {
        if (! is_numeric($crudo)) {
            throw new FieldRuleViolation("«{$definicion->label}» tiene que ser un número.");
        }

        return $this->dentroDelRango($definicion, (float) $crudo);
    }

    /** El rango es SÓLO para numéricos (RF-DIN-002); en el resto no se declara. */
    private function dentroDelRango(WorkFieldDefinition $definicion, float $valor): float
    {
        $unidad = $definicion->unit === null ? '' : " {$definicion->unit}";

        if ($definicion->min_value !== null && $valor < (float) $definicion->min_value) {
            throw new FieldRuleViolation(
                "«{$definicion->label}» no puede ser menor que {$this->legible($definicion->min_value)}{$unidad}.",
            );
        }

        if ($definicion->max_value !== null && $valor > (float) $definicion->max_value) {
            throw new FieldRuleViolation(
                "«{$definicion->label}» no puede ser mayor que {$this->legible($definicion->max_value)}{$unidad}.",
            );
        }

        return $valor;
    }

    /**
     * El límite como lo escribiría una persona.
     *
     * La columna es `decimal(20,6)`, así que el valor llega como «10.000000» y
     * el mensaje quedaría «no puede ser mayor que 10.000000 m». Se recortan los
     * ceros de relleno: un aviso que se lee mal se ignora igual que uno que no
     * está.
     */
    private function legible(mixed $numero): string
    {
        $texto = rtrim(rtrim((string) $numero, '0'), '.');

        return $texto === '' || $texto === '-' ? '0' : $texto;
    }

    private function fecha(WorkFieldDefinition $definicion, mixed $crudo): Carbon
    {
        try {
            return Carbon::parse((string) $crudo)->startOfDay();
        } catch (\Throwable) {
            throw new FieldRuleViolation("«{$definicion->label}» tiene que ser una fecha válida.");
        }
    }

    /**
     * La opción tiene que ser DE ESE CAMPO y estar activa.
     *
     * Verificar sólo que el id exista dejaría elegir, manipulando el envío, una
     * opción de otro campo: el desplegable no lo ofrece, pero la petición sí lo
     * puede pedir.
     */
    private function opcion(WorkFieldDefinition $definicion, mixed $crudo): int
    {
        $opcion = WorkFieldOption::query()
            ->where('work_field_definition_id', $definicion->id)
            ->where('is_active', true)
            ->find($crudo);

        if ($opcion === null) {
            throw new FieldRuleViolation(
                "La opción elegida para «{$definicion->label}» no es válida o ya no está disponible.",
            );
        }

        return (int) $opcion->getKey();
    }

    private function estaVacio(mixed $valor): bool
    {
        // El `false` de un booleano y el `0` de un numérico son valores, no
        // vacíos: compararlos con `empty()` los borraría en silencio.
        return $valor === null || $valor === '' || (is_array($valor) && $valor === []);
    }
}
