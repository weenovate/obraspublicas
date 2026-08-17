<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\WorkCategory;
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
 * Subcategorías (RF-CAT-004).
 *
 * Es el catálogo con las reglas más duras, porque su modo geométrico determina
 * qué se puede dibujar: cambiarlo con obras cargadas invalidaría geometrías.
 */
final class SubcategoryController
{
    /** @var list<string> */
    private const AUDITADOS = [
        'work_category_id', 'name', 'slug', 'geometry_mode', 'routing_profile', 'sort_order', 'is_active',
    ];

    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        return Inertia::render('Admin/Catalogos/Subcategorias', [
            'subcategorias' => WorkSubcategory::query()
                ->with('category:id,name')
                ->orderBy('work_category_id')->orderBy('sort_order')
                ->get()
                ->map(fn (WorkSubcategory $s): array => [
                    'id' => $s->id,
                    'categoria' => $s->category?->name,
                    'work_category_id' => $s->work_category_id,
                    'name' => $s->name,
                    'geometry_mode' => $s->geometry_mode,
                    'routing_profile' => $s->routing_profile,
                    'sort_order' => $s->sort_order,
                    'is_active' => $s->is_active,
                    'en_uso' => $s->isInUse(),
                ]),
            'categorias' => WorkCategory::query()->orderBy('name')->get(['id', 'name']),
            'modos' => [
                WorkSubcategory::MODE_POINT => 'Punto',
                WorkSubcategory::MODE_LINE_ROUTED_ROAD => 'Línea sobre calles (trazado asistido)',
                WorkSubcategory::MODE_LINE_MANUAL_NETWORK => 'Línea de red (trazado manual)',
                WorkSubcategory::MODE_POLYGON => 'Polígono',
            ],
            'perfiles' => ['driving-car' => 'Vehículo', 'foot-walking' => 'Peatonal', 'cycling-regular' => 'Bicicleta'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $this->validar($request);
        $subcategoria = new WorkSubcategory;

        $this->writer->apply(
            model: $subcategoria,
            action: 'catalog.subcategory.created',
            cambio: function () use ($subcategoria, $datos): void {
                $subcategoria->fill($datos);
                $subcategoria->work_category_id = $datos['work_category_id'];
                $subcategoria->geometry_mode = $datos['geometry_mode'];
                $subcategoria->slug = Str::slug($datos['name']);
                $subcategoria->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Subcategoría creada.');
    }

    public function update(Request $request, WorkSubcategory $subcategory): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $this->validar($request, $subcategory);

        try {
            if ((int) $datos['work_category_id'] !== $subcategory->work_category_id) {
                CatalogRules::assertParentCategoryEditable($subcategory);
            }

            // La excepción entre los dos modos de línea vive en la regla, no acá:
            // el controlador pregunta, no decide.
            CatalogRules::assertGeometryModeEditable($subcategory, $datos['geometry_mode']);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['geometry_mode' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $subcategory,
            action: 'catalog.subcategory.updated',
            cambio: function () use ($subcategory, $datos): void {
                $subcategory->fill($datos);
                $subcategory->work_category_id = $datos['work_category_id'];
                $subcategory->geometry_mode = $datos['geometry_mode'];
                $subcategory->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Subcategoría actualizada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?WorkSubcategory $subcategory = null): array
    {
        return $request->validate([
            'work_category_id' => ['required', 'exists:work_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'geometry_mode' => ['required', 'in:'.implode(',', [
                WorkSubcategory::MODE_POINT,
                WorkSubcategory::MODE_LINE_ROUTED_ROAD,
                WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
                WorkSubcategory::MODE_POLYGON,
            ])],
            'routing_profile' => ['nullable', 'in:driving-car,foot-walking,cycling-regular'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ], [], [
            'work_category_id' => 'categoría',
            'name' => 'nombre',
            'geometry_mode' => 'tipo de geometría',
        ]);
    }
}
