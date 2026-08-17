<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\WorkCategory;
use App\Models\WorkFieldDefinition;
use App\Models\WorkFieldOption;
use App\Models\WorkSubcategory;
use App\Policies\AdminPolicy;
use App\Support\Catalog\CatalogRules;
use App\Support\Catalog\CatalogRuleViolation;
use App\Support\Catalog\CatalogWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Campos técnicos dinámicos y sus opciones (RF-DIN-001…005).
 */
final class FieldDefinitionController
{
    /** @var list<string> */
    private const AUDITADOS = [
        'scope_type', 'scope_id', 'code', 'label', 'data_type', 'unit', 'min_value', 'max_value',
        'is_required', 'public_visible', 'live_visible', 'sort_order', 'is_active',
    ];

    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        return Inertia::render('Admin/Catalogos/Campos', [
            'campos' => WorkFieldDefinition::query()
                ->with('options')
                ->orderBy('scope_type')->orderBy('scope_id')->orderBy('sort_order')
                ->get()
                ->map(fn (WorkFieldDefinition $d): array => [
                    'id' => $d->id,
                    'scope_type' => $d->scope_type,
                    'scope_id' => $d->scope_id,
                    'code' => $d->code,
                    'label' => $d->label,
                    'help_text' => $d->help_text,
                    'data_type' => $d->data_type,
                    'unit' => $d->unit,
                    // Se envían aunque la tabla no los muestre: el formulario de
                    // edición los reenvía tal cual, y omitirlos acá los borraría
                    // en la primera edición que tocara otra cosa.
                    'min_value' => $d->min_value,
                    'max_value' => $d->max_value,
                    'sort_order' => $d->sort_order,
                    'is_required' => $d->is_required,
                    'public_visible' => $d->public_visible,
                    'live_visible' => $d->live_visible,
                    'is_active' => $d->is_active,
                    // Con valores cargados, el tipo queda congelado (RF-DIN-004).
                    'tiene_valores' => $d->hasValues(),
                    'opciones' => $d->options->map(fn (WorkFieldOption $o): array => [
                        'id' => $o->id,
                        'value' => $o->value,
                        'label' => $o->label,
                        'is_active' => $o->is_active,
                    ]),
                ]),
            'categorias' => WorkCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategorias' => WorkSubcategory::query()->orderBy('name')->get(['id', 'name']),
            'tipos' => [
                WorkFieldDefinition::TYPE_TEXT => 'Texto corto',
                WorkFieldDefinition::TYPE_LONG_TEXT => 'Texto largo',
                WorkFieldDefinition::TYPE_INTEGER => 'Número entero',
                WorkFieldDefinition::TYPE_DECIMAL => 'Número decimal',
                WorkFieldDefinition::TYPE_BOOLEAN => 'Sí / No',
                WorkFieldDefinition::TYPE_DATE => 'Fecha',
                WorkFieldDefinition::TYPE_SELECT => 'Selección simple',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $request->validate([
            'scope_type' => ['required', 'in:CATEGORY,SUBCATEGORY'],
            'scope_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:255'],
            'data_type' => ['required', 'in:'.implode(',', array_keys(WorkFieldDefinition::VALUE_COLUMNS))],
            'unit' => ['nullable', 'string', 'max:32'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
            'is_required' => ['boolean'],
            'public_visible' => ['boolean'],
            'live_visible' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['label' => 'etiqueta', 'data_type' => 'tipo de dato']);

        $definicion = new WorkFieldDefinition;

        $this->writer->apply(
            model: $definicion,
            action: 'catalog.field.created',
            cambio: function () use ($definicion, $datos): void {
                $definicion->fill($datos);
                $definicion->scope_type = $datos['scope_type'];
                $definicion->scope_id = $datos['scope_id'];
                $definicion->data_type = $datos['data_type'];
                // El código técnico se deriva una vez y ya no cambia nunca: es la
                // clave con la que se guardan los valores (RF-DIN-003).
                $definicion->code = Str::slug($datos['label'], '_');
                $definicion->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Campo creado.');
    }

    public function update(Request $request, WorkFieldDefinition $definition): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'data_type' => ['required', 'in:'.implode(',', array_keys(WorkFieldDefinition::VALUE_COLUMNS))],
            'unit' => ['nullable', 'string', 'max:32'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
            'is_required' => ['boolean'],
            'public_visible' => ['boolean'],
            'live_visible' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ], [], ['label' => 'etiqueta', 'data_type' => 'tipo de dato']);

        try {
            if ($datos['data_type'] !== $definition->data_type) {
                CatalogRules::assertDataTypeEditable($definition);
            }
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['data_type' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $definition,
            action: 'catalog.field.updated',
            cambio: function () use ($definition, $datos): void {
                $definition->fill($datos);
                $definition->data_type = $datos['data_type'];
                $definition->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Campo actualizado.');
    }

    public function destroy(Request $request, WorkFieldDefinition $definition): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        try {
            CatalogRules::assertDefinitionDeletable($definition);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['id' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $definition,
            action: 'catalog.field.deleted',
            cambio: fn () => $definition->delete(),
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Campo eliminado.');
    }

    public function storeOption(Request $request, WorkFieldDefinition $definition): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['label' => 'etiqueta']);

        $opcion = new WorkFieldOption;

        $this->writer->apply(
            model: $opcion,
            action: 'catalog.field_option.created',
            cambio: function () use ($opcion, $definition, $datos): void {
                $opcion->fill($datos);
                $opcion->work_field_definition_id = $definition->getKey();
                $opcion->value = Str::slug($datos['label'], '_');
                $opcion->save();
            },
            atributos: ['work_field_definition_id', 'value', 'label', 'sort_order', 'is_active'],
            actor: $request->user(),
        );

        return back()->with('success', 'Opción agregada.');
    }

    public function destroyOption(Request $request, WorkFieldOption $option): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        try {
            CatalogRules::assertOptionDeletable($option);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['id' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $option,
            action: 'catalog.field_option.deleted',
            cambio: fn () => $option->delete(),
            atributos: ['work_field_definition_id', 'value', 'label', 'is_active'],
            actor: $request->user(),
        );

        return back()->with('success', 'Opción eliminada.');
    }
}
