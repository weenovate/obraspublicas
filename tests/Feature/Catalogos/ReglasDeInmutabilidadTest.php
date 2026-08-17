<?php

declare(strict_types=1);

use App\Models\Work;
use App\Models\WorkCategory;
use App\Models\WorkFieldDefinition;
use App\Models\WorkFieldOption;
use App\Models\WorkFieldValue;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use App\Support\Catalog\CatalogRules;
use App\Support\Catalog\CatalogRuleViolation;

/*
| Reglas de inmutabilidad de los catálogos (RF-CAT-003/004/005, RF-DIN-004,
| RF-OBR-008).
|
| Cada regla se prueba en las DOS direcciones: que rechace el cambio prohibido y
| que deje pasar el permitido. Un test que sólo verifica el rechazo no distingue
| una regla correcta de una que bloquea todo.
|
| Y en todas, «en uso» incluye la papelera: restaurar una obra tiene que devolver
| un registro válido (RF-DEL-003), así que una subcategoría con obras borradas
| sigue estando en uso aunque no aparezca en ningún listado. Es el error más
| fácil de cometer acá, y por eso tiene test propio.
*/

/** Una obra mínima sobre la subcategoría dada. */
function obraSobre(WorkSubcategory $subcategoria, ?WorkStatus $estado = null): Work
{
    return Work::factory()->create([
        'work_subcategory_id' => $subcategoria->getKey(),
        'work_status_id' => ($estado ?? WorkStatus::factory()->create())->getKey(),
    ]);
}

// ---------------------------------------------------------------------------
// Categorías
// ---------------------------------------------------------------------------

it('congela la dirección web de una categoría con obras', function () {
    $categoria = WorkCategory::factory()->create();
    obraSobre(WorkSubcategory::factory()->create(['work_category_id' => $categoria->getKey()]));

    expect(fn () => CatalogRules::assertSlugEditable($categoria->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

it('deja cambiar la dirección web mientras la categoría no tenga obras', function () {
    $categoria = WorkCategory::factory()->create();
    // Con subcategorías pero sin obras: el slug todavía no viaja en ninguna URL
    // compartida que pueda romperse.
    WorkSubcategory::factory()->create(['work_category_id' => $categoria->getKey()]);

    CatalogRules::assertSlugEditable($categoria);
})->throwsNoExceptions();

it('no elimina una categoría con subcategorías, aunque no tenga obras', function () {
    $categoria = WorkCategory::factory()->create();
    WorkSubcategory::factory()->create(['work_category_id' => $categoria->getKey()]);

    expect(fn () => CatalogRules::assertCategoryDeletable($categoria))
        ->toThrow(CatalogRuleViolation::class);
});

it('elimina una categoría vacía', function () {
    CatalogRules::assertCategoryDeletable(WorkCategory::factory()->create());
})->throwsNoExceptions();

// ---------------------------------------------------------------------------
// Subcategorías
// ---------------------------------------------------------------------------

it('no mueve de categoría una subcategoría con obras', function () {
    $subcategoria = WorkSubcategory::factory()->create();
    obraSobre($subcategoria);

    expect(fn () => CatalogRules::assertParentCategoryEditable($subcategoria->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

it('mueve de categoría una subcategoría sin obras', function () {
    CatalogRules::assertParentCategoryEditable(WorkSubcategory::factory()->create());
})->throwsNoExceptions();

it('no cambia el tipo de geometría de una subcategoría con obras', function () {
    $subcategoria = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT]);
    obraSobre($subcategoria);

    expect(fn () => CatalogRules::assertGeometryModeEditable(
        $subcategoria->refresh(),
        WorkSubcategory::MODE_POLYGON,
    ))->toThrow(CatalogRuleViolation::class);
});

it('permite pasar de un modo de línea al otro aunque haya obras', function () {
    // Los dos persisten LINESTRING: la única diferencia es si el editor ofrece
    // trazado asistido. Ninguna geometría almacenada deja de ser válida.
    $subcategoria = WorkSubcategory::factory()->linea()->create();
    obraSobre($subcategoria);

    CatalogRules::assertGeometryModeEditable(
        $subcategoria->refresh(),
        WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
    );
})->throwsNoExceptions();

it('cambia libremente el tipo de geometría mientras no haya obras', function () {
    CatalogRules::assertGeometryModeEditable(
        WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT]),
        WorkSubcategory::MODE_POLYGON,
    );
})->throwsNoExceptions();

it('cuenta las obras en papelera como uso de la subcategoría', function () {
    $subcategoria = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT]);
    $obra = obraSobre($subcategoria);

    $obra->delete();

    expect($obra->trashed())->toBeTrue()
        ->and($subcategoria->refresh()->isInUse())->toBeTrue();

    expect(fn () => CatalogRules::assertGeometryModeEditable($subcategoria, WorkSubcategory::MODE_POLYGON))
        ->toThrow(CatalogRuleViolation::class);
});

// ---------------------------------------------------------------------------
// Estados
// ---------------------------------------------------------------------------

it('no elimina un estado del sistema aunque no tenga obras', function () {
    expect(fn () => CatalogRules::assertStatusDeletable(WorkStatus::factory()->delSistema()->create()))
        ->toThrow(CatalogRuleViolation::class);
});

it('no elimina un estado propio que esté en uso', function () {
    $estado = WorkStatus::factory()->create();
    obraSobre(WorkSubcategory::factory()->create(), $estado);

    expect(fn () => CatalogRules::assertStatusDeletable($estado->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

it('elimina un estado propio sin obras', function () {
    CatalogRules::assertStatusDeletable(WorkStatus::factory()->create());
})->throwsNoExceptions();

it('no cambia «finaliza la obra» en un estado con obras', function () {
    // Cambiarlo desincronizaría `effective_end_date`, que está materializada, y
    // pasaría a exigir una fecha real donde antes no existía.
    $estado = WorkStatus::factory()->create();
    obraSobre(WorkSubcategory::factory()->create(), $estado);

    expect(fn () => CatalogRules::assertIsFinalEditable($estado->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

it('cambia «finaliza la obra» mientras el estado no tenga obras', function () {
    CatalogRules::assertIsFinalEditable(WorkStatus::factory()->create());
})->throwsNoExceptions();

it('cuenta las obras en papelera como uso del estado', function () {
    $estado = WorkStatus::factory()->create();
    obraSobre(WorkSubcategory::factory()->create(), $estado)->delete();

    expect(fn () => CatalogRules::assertIsFinalEditable($estado->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

// ---------------------------------------------------------------------------
// Campos técnicos
// ---------------------------------------------------------------------------

/** Una definición de campo con su alcance ya resuelto. */
function unaDefinicion(string $tipo = WorkFieldDefinition::TYPE_TEXT): WorkFieldDefinition
{
    $definicion = new WorkFieldDefinition;
    $definicion->forceFill([
        'scope_type' => WorkFieldDefinition::SCOPE_CATEGORY,
        'scope_id' => WorkCategory::factory()->create()->getKey(),
        'code' => 'superficie',
        'label' => 'Superficie',
        'data_type' => $tipo,
    ])->save();

    return $definicion;
}

it('no cambia el tipo de un campo con valores cargados', function () {
    $definicion = unaDefinicion();
    $obra = obraSobre(WorkSubcategory::factory()->create());

    $valor = new WorkFieldValue;
    $valor->forceFill([
        'work_id' => $obra->getKey(),
        'work_field_definition_id' => $definicion->getKey(),
        'value_text' => 'doscientos metros',
    ])->save();

    expect(fn () => CatalogRules::assertDataTypeEditable($definicion->refresh()))
        ->toThrow(CatalogRuleViolation::class);

    // Y tampoco se elimina: desactivarlo conserva los históricos (RF-CAT-005).
    expect(fn () => CatalogRules::assertDefinitionDeletable($definicion))
        ->toThrow(CatalogRuleViolation::class);
});

it('cambia el tipo de un campo que todavía no tiene valores', function () {
    CatalogRules::assertDataTypeEditable(unaDefinicion());
})->throwsNoExceptions();

it('no elimina una opción elegida en alguna obra', function () {
    $definicion = unaDefinicion(WorkFieldDefinition::TYPE_SELECT);

    $opcion = new WorkFieldOption;
    $opcion->forceFill([
        'work_field_definition_id' => $definicion->getKey(),
        'value' => 'asfalto',
        'label' => 'Asfalto',
    ])->save();

    $valor = new WorkFieldValue;
    $valor->forceFill([
        'work_id' => obraSobre(WorkSubcategory::factory()->create())->getKey(),
        'work_field_definition_id' => $definicion->getKey(),
        'option_id' => $opcion->getKey(),
    ])->save();

    expect(fn () => CatalogRules::assertOptionDeletable($opcion->refresh()))
        ->toThrow(CatalogRuleViolation::class);
});

it('elimina una opción que nadie eligió', function () {
    $definicion = unaDefinicion(WorkFieldDefinition::TYPE_SELECT);

    $opcion = new WorkFieldOption;
    $opcion->forceFill([
        'work_field_definition_id' => $definicion->getKey(),
        'value' => 'hormigon',
        'label' => 'Hormigón',
    ])->save();

    CatalogRules::assertOptionDeletable($opcion);
})->throwsNoExceptions();

// ---------------------------------------------------------------------------
// Iconos
// ---------------------------------------------------------------------------

it('rechaza un icono que no existe en el conjunto', function () {
    expect(fn () => CatalogRules::assertIconSelectable('icono-inventado'))
        ->toThrow(CatalogRuleViolation::class);
});
