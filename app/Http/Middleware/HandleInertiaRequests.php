<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Settings\AppSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * El tema que corresponde aplicar (RF-CFG-004/005).
     *
     * Si el usuario eligió, manda su elección. Si no —o si no hay sesión, como en
     * la pantalla de ingreso— manda el tema predeterminado de la configuración.
     *
     * El seguimiento del dispositivo NO entra acá: es de la Web pública, que no
     * tiene sesión ni preferencia guardada (RF-THE-001), y se resuelve en el
     * cliente.
     */
    public static function themeFor(?User $user): string
    {
        if ($user?->theme_preference !== null) {
            return (string) $user->theme_preference;
        }

        return (string) AppSettings::get(AppSettings::DEFAULT_THEME);
    }

    /**
     * Rutas que NO llevan el tema estampado, porque ahí manda el dispositivo.
     *
     * La Web pública de F4 es el caso real (RF-THE-001): sin sesión no hay
     * preferencia que aplicar, y el sistema operativo del visitante decide. La
     * página de referencia del RDS entra por otro motivo: es la herramienta con
     * la que se revisan los TRES estados del tema, y con el atributo estampado
     * el tercero —«sin elección»— no se podría ver nunca.
     *
     * @var list<string>
     */
    public const RUTAS_SIN_TEMA_ESTAMPADO = ['interno.referencia-rds'];

    /**
     * El tema a estampar en `<html>`, o `null` si esta ruta sigue al dispositivo.
     *
     * `null` NO es «tema claro»: es la ausencia del atributo, que es lo que deja
     * decidir a `prefers-color-scheme`. Poner el atributo en vacío no es lo
     * mismo y rompería el estado.
     */
    public static function stampedThemeFor(Request $request): ?string
    {
        if ($request->routeIs(...self::RUTAS_SIN_TEMA_ESTAMPADO)) {
            return null;
        }

        return self::themeFor($request->user());
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            // Sólo lo que la interfaz necesita. Nunca el hash de la contraseña
            // ni el `remember_token`: el modelo los oculta, pero la lista
            // explícita evita que un `$user->toArray()` futuro los arrastre.
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'must_change_password' => $user->must_change_password,
                    'theme_preference' => $user->theme_preference,
                ],
            ],

            // Lo que el usuario ELIGIÓ, que puede ser nada. Es distinto del tema
            // que se está viendo: si no eligió, manda el predeterminado
            // configurable (RF-CFG-005), y eso lo resuelve `themeFor()`.
            'theme' => [
                'preferencia' => $user?->theme_preference,
                'efectivo' => self::themeFor($user),
                'predeterminado' => AppSettings::get(AppSettings::DEFAULT_THEME),
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
