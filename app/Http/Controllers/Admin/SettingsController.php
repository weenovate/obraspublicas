<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Policies\AdminPolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Settings\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Configuración funcional (RF-CFG-001/002/003).
 *
 * Nótese lo que NO hay acá: ningún campo para claves de API, contraseñas ni
 * credenciales. Esos se inyectan por variables de entorno y no se editan desde la
 * interfaz (RF-CFG-003). Si alguna vez aparece un formulario para cargar una
 * clave de proveedor, es un error de diseño, no una mejora.
 */
final class SettingsController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CONFIGURACION);

        $definiciones = [];
        foreach (AppSettings::definitions() as $key => $definicion) {
            $definiciones[] = [
                'key' => $key,
                'label' => $definicion->label,
                'help' => $definicion->help,
                'data_type' => $definicion->dataType,
                'min' => $definicion->min,
                'max' => $definicion->max,
                'allowed' => $definicion->allowed,
            ];
        }

        return Inertia::render('Admin/Configuracion', [
            'definiciones' => $definiciones,
            'valores' => AppSettings::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_CONFIGURACION);

        $enviados = $request->input('valores', []);

        if (! is_array($enviados) || $enviados === []) {
            throw ValidationException::withMessages(['valores' => 'No se recibió ninguna opción.']);
        }

        // Claves desconocidas se rechazan enteras en lugar de ignorarse: guardar
        // en silencio lo que sí se reconoce y descartar el resto deja al usuario
        // creyendo que cambió algo que no cambió.
        $conocidas = array_keys(AppSettings::definitions());
        $desconocidas = array_diff(array_keys($enviados), $conocidas);

        if ($desconocidas !== []) {
            throw ValidationException::withMessages([
                'valores' => 'Opciones desconocidas: '.implode(', ', $desconocidas).'.',
            ]);
        }

        DB::transaction(function () use ($enviados, $request): void {
            $antes = [];
            $despues = [];

            foreach ($enviados as $key => $valor) {
                try {
                    $antes[$key] = AppSettings::set($key, $valor);
                    $despues[$key] = AppSettings::get($key);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(["valores.{$key}" => $e->getMessage()]);
                }
            }

            // Un solo evento con todo el cambio: la configuración se edita como
            // un formulario, y auditar clave por clave partiría en ocho eventos
            // lo que el usuario vivió como una sola acción (RF-CFG-002).
            $this->audit->registrar(
                action: 'settings.updated',
                entityType: 'app_settings',
                before: $antes,
                after: $despues,
                actor: $request->user(),
            );
        });

        return back()->with('success', 'Configuración actualizada.');
    }
}
