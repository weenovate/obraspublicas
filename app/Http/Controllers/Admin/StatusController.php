<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\WorkStatus;
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
 * Estados de obra (RF-OBR-008/009).
 *
 * `is_final` es lo delicado: gobierna la semántica de las fechas, así que sólo se
 * puede tocar mientras el estado no tenga obras.
 */
final class StatusController
{
    /** @var list<string> */
    private const AUDITADOS = ['key', 'label', 'is_final', 'is_system', 'color', 'sort_order', 'is_active'];

    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        return Inertia::render('Admin/Catalogos/Estados', [
            'estados' => WorkStatus::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (WorkStatus $e): array => [
                    'id' => $e->id,
                    'key' => $e->key,
                    'label' => $e->label,
                    'is_final' => $e->is_final,
                    'is_system' => $e->is_system,
                    'sort_order' => $e->sort_order,
                    'is_active' => $e->is_active,
                    'en_uso' => $e->isInUse(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'is_final' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['label' => 'etiqueta']);

        $estado = new WorkStatus;

        $this->writer->apply(
            model: $estado,
            action: 'catalog.status.created',
            cambio: function () use ($estado, $datos): void {
                $estado->fill($datos);
                // La clave se deriva una sola vez y no vuelve a cambiar nunca.
                $estado->key = Str::upper(Str::slug($datos['label'], '_'));
                $estado->is_final = (bool) ($datos['is_final'] ?? false);
                $estado->is_system = false;
                $estado->is_active = true;
                $estado->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Estado creado.');
    }

    public function update(Request $request, WorkStatus $status): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        $datos = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'is_final' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ], [], ['label' => 'etiqueta']);

        $nuevoIsFinal = (bool) ($datos['is_final'] ?? $status->is_final);

        try {
            if ($nuevoIsFinal !== $status->is_final) {
                CatalogRules::assertIsFinalEditable($status);
            }
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['is_final' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $status,
            action: 'catalog.status.updated',
            cambio: function () use ($status, $datos, $nuevoIsFinal): void {
                // La etiqueta se edita siempre; la clave interna nunca.
                $status->fill($datos);
                $status->is_final = $nuevoIsFinal;
                $status->save();
            },
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Estado actualizado.');
    }

    public function destroy(Request $request, WorkStatus $status): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CATALOGOS);

        try {
            CatalogRules::assertStatusDeletable($status);
        } catch (CatalogRuleViolation $e) {
            throw ValidationException::withMessages(['id' => $e->getMessage()]);
        }

        $this->writer->apply(
            model: $status,
            action: 'catalog.status.deleted',
            cambio: fn () => $status->delete(),
            atributos: self::AUDITADOS,
            actor: $request->user(),
        );

        return back()->with('success', 'Estado eliminado.');
    }
}
