<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\WorkCategory;
use App\Models\WorkFieldDefinition;
use App\Models\WorkFieldOption;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;

/**
 * Reglas de qué se puede cambiar en un catálogo y qué no, una vez que está en uso.
 *
 * La regla general es de RF-CAT-005: lo referenciado no se elimina, se desactiva.
 * Pero cada catálogo tiene una trampa propia, y todas comparten la misma forma:
 * un cambio que parece inocente invalida datos ya cargados.
 *
 * DEFINICIÓN DE «EN USO»: incluye las obras en papelera. Restaurar una obra tiene
 * que seguir dando un registro válido (RF-DEL-003), así que una subcategoría con
 * obras en papelera sigue estando en uso aunque no aparezca en ningún listado.
 * Es el error más fácil de cometer acá, y por eso todos los `isInUse()` de los
 * modelos usan `withTrashed()`.
 *
 * Estas reglas no se pueden expresar en el esquema: dependen de una consulta a
 * otra tabla. Viven acá, se invocan desde los servicios de aplicación y están
 * fijadas por tests.
 */
final class CatalogRules
{
    // -----------------------------------------------------------------------
    // Categorías
    // -----------------------------------------------------------------------

    /**
     * El slug participa de URLs compartibles (RF-WEB-006): una vez que la
     * categoría tiene obras, una URL que alguien guardó no puede dejar de
     * funcionar porque se corrigió un nombre.
     *
     * Se descartó mantener alias históricos: agrega una tabla y una resolución
     * en cada request para un caso que en v1 casi no ocurre.
     */
    public static function assertSlugEditable(WorkCategory $category): void
    {
        if ($category->isInUse()) {
            throw new CatalogRuleViolation(
                'No se puede cambiar la dirección web de una categoría que ya tiene obras: '
                .'los enlaces compartidos dejarían de funcionar. Podés cambiar el nombre visible.',
            );
        }
    }

    /**
     * Una categoría con subcategorías u obras se desactiva; no se elimina.
     * Desactivada no se ofrece para nuevas altas, pero sus obras siguen
     * publicadas y filtrables, porque son obras válidas.
     */
    public static function assertCategoryDeletable(WorkCategory $category): void
    {
        if ($category->hasSubcategories() || $category->isInUse()) {
            throw new CatalogRuleViolation(
                'Esta categoría tiene subcategorías u obras asociadas, así que no se elimina: '
                .'desactivala para que no se pueda elegir en obras nuevas.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Subcategorías
    // -----------------------------------------------------------------------

    /** La categoría padre es inmutable una vez usada (RF-CAT-004). */
    public static function assertParentCategoryEditable(WorkSubcategory $subcategory): void
    {
        if ($subcategory->isInUse()) {
            throw new CatalogRuleViolation(
                'No se puede mover una subcategoría que ya tiene obras a otra categoría: '
                .'las obras existentes quedarían clasificadas en un lugar que no eligió nadie.',
            );
        }
    }

    /**
     * El modo geométrico es inmutable con obras asociadas, con UNA excepción.
     *
     * Cambiar `POINT` por `POLYGON` invalidaría de golpe la geometría de cada
     * obra existente, y no hay conversión razonable de un punto a un polígono.
     *
     * La excepción es entre los dos modos de línea: los dos persisten
     * `LINESTRING` y la única diferencia es si se ofrece el trazado asistido. No
     * toca ninguna geometría almacenada, y es el caso realista de una
     * subcategoría mal clasificada al inicio.
     */
    public static function assertGeometryModeEditable(WorkSubcategory $subcategory, string $newMode): void
    {
        if ($subcategory->geometry_mode === $newMode || ! $subcategory->isInUse()) {
            return;
        }

        $ambosSonLinea = in_array($subcategory->geometry_mode, WorkSubcategory::LINE_MODES, true)
            && in_array($newMode, WorkSubcategory::LINE_MODES, true);

        if ($ambosSonLinea) {
            return;
        }

        throw new CatalogRuleViolation(
            'No se puede cambiar el tipo de geometría de una subcategoría que ya tiene obras: '
            .'la geometría cargada dejaría de ser válida. Creá una subcategoría nueva.',
        );
    }

    // -----------------------------------------------------------------------
    // Estados
    // -----------------------------------------------------------------------

    /**
     * Los cinco estados base no se eliminan ni cambian de clave (RF-OBR-008).
     */
    public static function assertStatusDeletable(WorkStatus $status): void
    {
        if ($status->is_system) {
            throw new CatalogRuleViolation(
                'Los estados base del sistema no se eliminan. Podés cambiarles la etiqueta '
                .'o desactivarlos.',
            );
        }

        if ($status->isInUse()) {
            throw new CatalogRuleViolation(
                'Este estado está en uso por obras existentes: desactivalo en lugar de eliminarlo.',
            );
        }
    }

    /**
     * `is_final` sólo se edita mientras el estado no tenga obras.
     *
     * Cambiarlo con obras asociadas altera la semántica de fechas de registros ya
     * guardados —pasa a exigir `actual_end_date` donde antes no existía— y
     * desincroniza `effective_end_date`, que está materializada.
     *
     * Si la Municipalidad necesita convertirlo igual, es un procedimiento de
     * datos con backfill explícito y auditado, no una casilla de la interfaz.
     */
    public static function assertIsFinalEditable(WorkStatus $status): void
    {
        if ($status->isInUse()) {
            throw new CatalogRuleViolation(
                'No se puede cambiar si este estado finaliza una obra mientras tenga obras asociadas: '
                .'cambiaría el significado de las fechas ya cargadas. Creá un estado nuevo.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Campos técnicos
    // -----------------------------------------------------------------------

    /**
     * El tipo de dato es inmutable si ya hay valores cargados (RF-DIN-004): no
     * existe conversión segura de, por ejemplo, texto libre a entero.
     */
    public static function assertDataTypeEditable(WorkFieldDefinition $definition): void
    {
        if ($definition->hasValues()) {
            throw new CatalogRuleViolation(
                'No se puede cambiar el tipo de un campo que ya tiene valores cargados. '
                .'Desactivalo y creá uno nuevo: los valores históricos se conservan.',
            );
        }
    }

    public static function assertDefinitionDeletable(WorkFieldDefinition $definition): void
    {
        if ($definition->hasValues()) {
            throw new CatalogRuleViolation(
                'Este campo tiene valores cargados: desactivalo en lugar de eliminarlo, '
                .'así los valores históricos se conservan.',
            );
        }
    }

    public static function assertOptionDeletable(WorkFieldOption $option): void
    {
        if ($option->isInUse()) {
            throw new CatalogRuleViolation(
                'Esta opción está elegida en obras existentes: desactivala en lugar de eliminarla.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Iconos
    // -----------------------------------------------------------------------

    public static function assertIconSelectable(string $icon): void
    {
        if (! IconRegistry::exists($icon)) {
            throw new CatalogRuleViolation("El icono «{$icon}» no existe en el conjunto disponible.");
        }

        if (! IconRegistry::isSelectable($icon)) {
            throw new CatalogRuleViolation(
                "El icono «{$icon}» ya no se ofrece para categorías nuevas.",
            );
        }
    }
}
