<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\WorkCategory;
use App\Policies\AdminPolicy;
use App\Support\Catalog\CatalogRules;
use App\Support\Catalog\CatalogRuleViolation;
use App\Support\Catalog\CatalogWriter;
use App\Support\Catalog\IconRegistry;
use App\Support\Color\CategoryColorPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Categorías, que en el mapa son capas (RF-CAT-001/002/003/005).
 */
final class CategoryController
{
    /** @var list<string> */
    private const AUDITADOS = ['name', 'slug', 'icon', 'color', 'sort_order', 'is_active'];

    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        return Inertia::render('Admin/Catalogos/Categorias', [
            'categorias' => WorkCategory::query()
                ->withCount('subcategories')
                ->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (WorkCategory $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'sort_order' => $c->sort_order,
                    'is_active' => $c->is_active,
                    'subcategorias' => $c->subcategories_count,
                    // La interfaz necesita saberlo para deshabilitar los campos
                    // que ya no se pueden tocar, en lugar de dejar intentarlo y
                    // fallar al guardar.
                    'en_uso' => $c->isInUse(),
                ]),
            'iconos' => IconRegistry::selectable(),
            'colorSugerido' => CategoryColorPolicy::SUGGESTED,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $this->validar($request);

        $categoria = new WorkCategory;

        $this->writer->apply(
            model: $categoria,
            action: 'catalog.category.created',
            cambio: function () use ($categoria, $datos): void {
                $categoria->fill($datos);
                // El slug se deriva del nombre y después queda congelado si hay
                // obras: no es un campo que el Admin edite a mano.
                $categoria->slug = Str::slug($datos['name']);
                $categoria->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, WorkCategory $category): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $this->validar($request, $category);
        $nuevoSlug = Str::slug($datos['name']);

        try {
            if ($nuevoSlug !== $category->slug) {
                CatalogRules::assertSlugEditable($category);
            }
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $category,
            action: 'catalog.category.updated',
            cambio: function () use ($category, $datos, $nuevoSlug): void {
                $category->fill($datos);
                $category->slug = $nuevoSlug;
                $category->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroy(Request $request, WorkCategory $category): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        try {
            CatalogRules::assertCategoryDeletable($category);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['id' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $category,
            action: 'catalog.category.deleted',
            cambio: fn () => $category->delete(),
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Categoría eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?WorkCategory $category = null): array
    {
        $datos = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                'unique:work_categories,name'.($category !== null ? ','.$category->id : ''),
            ],
            'icon' => ['required', 'string', 'max:64'],
            'color' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nombre', 'icon' => 'icono', 'color' => 'color']);

        try {
            CatalogRules::assertIconSelectable($datos['icon']);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['icon' => $e->getMessage()]);
        }

        // RF-CAT-003: el color tiene que superar el contraste mínimo contra los
        // fondos de LOS DOS temas. Se valida en el servidor, no sólo en el
        // navegador: si no, alcanza con desactivar JavaScript.
        $contraste = CategoryColorPolicy::evaluate($datos['color']);

        if (! $contraste['valido']) {
            throw ValidationException::withMessages([
                'color' => implode(' ', $contraste['problemas']),
            ]);
        }

        return $datos;
    }
}
