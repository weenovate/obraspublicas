<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuthSession;
use App\Models\User;
use App\Policies\AdminPolicy;
use App\Support\Auth\SessionRegistry;
use App\Support\Users\LastAdminException;
use App\Support\Users\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Gestión de usuarios (RF-USR-001, RF-USR-003, RF-AUT-004/005).
 *
 * Exclusivo del Administrador. Cada acción pasa por `Gate::authorize()`, y toda
 * denegación queda auditada por el manejador de excepciones (CA-014).
 *
 * La lógica de dominio —el último Admin, la cascada de revocación, la atomicidad
 * con la auditoría— vive en `UserManager`, no acá: un controlador es un traductor
 * entre HTTP y el dominio, y estas reglas tienen que valer aunque mañana se las
 * invoque desde un comando.
 */
final class UserController
{
    public function __construct(
        private readonly UserManager $users,
        private readonly SessionRegistry $sessions,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $usuarios = User::query()
            ->when($request->string('buscar')->isNotEmpty(), function ($q) use ($request): void {
                $termino = '%'.$request->string('buscar').'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $termino)->orWhere('email', 'like', $termino));
            })
            ->when($request->string('rol')->isNotEmpty(), fn ($q) => $q->where('role', $request->string('rol')))
            ->orderBy('name')
            ->paginate($request->integer('por_pagina', 25))
            ->withQueryString()
            ->through(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'is_active' => $u->is_active,
                'must_change_password' => $u->must_change_password,
                'last_login_at' => $u->last_login_at?->diffForHumans(),
                'sesiones_activas' => $u->authSessions()->whereNull('revoked_at')->count(),
            ]);

        return Inertia::render('Admin/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => $request->only('buscar', 'rol'),
            'roles' => [User::ROLE_ADMIN, User::ROLE_OBRAS_PUBLICAS],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:'.User::ROLE_ADMIN.','.User::ROLE_OBRAS_PUBLICAS],
            'password' => ['required', Password::min(12)],
        ], [], [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'role' => 'rol',
            'password' => 'contraseña temporal',
        ]);

        $this->users->create(
            name: $datos['name'],
            email: $datos['email'],
            role: $datos['role'],
            temporaryPassword: $datos['password'],
            actor: $request->user(),
        );

        return back()->with('success', 'Usuario creado. Va a tener que cambiar la contraseña al ingresar.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:'.User::ROLE_ADMIN.','.User::ROLE_OBRAS_PUBLICAS],
        ], [], ['name' => 'nombre', 'role' => 'rol']);

        $user->forceFill(['name' => $datos['name']])->save();

        if ($user->role !== $datos['role']) {
            $this->conMensajeDeNegocio(
                fn () => $this->users->changeRole($user, $datos['role'], $request->user()),
                'role',
            );
        }

        return back()->with('success', 'Usuario actualizado.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $this->conMensajeDeNegocio(
            fn () => $this->users->deactivate($user, $request->user()),
            'is_active',
        );

        return back()->with('success', 'Usuario desactivado y sesiones revocadas.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $this->users->activate($user, $request->user());

        return back()->with('success', 'Usuario activado.');
    }

    /**
     * Contraseña temporal repuesta por el Admin (RF-AUT-004).
     *
     * No hay recuperación automática por email en la versión 1, así que éste es
     * el único camino cuando alguien pierde su contraseña.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        $datos = $request->validate([
            'password' => ['required', Password::min(12)],
        ], [], ['password' => 'contraseña temporal']);

        $this->users->changePassword(
            user: $user,
            newPassword: $datos['password'],
            actor: $request->user(),
            temporary: true,
        );

        return back()->with('success', 'Contraseña temporal asignada. Se pedirá cambiarla al ingresar.');
    }

    /** Revocación de una sesión concreta (RF-USR-003, CA-024). */
    public function revokeSession(Request $request, User $user, AuthSession $session): RedirectResponse
    {
        Gate::authorize(AdminPolicy::GESTIONAR_USUARIOS);

        abort_unless($session->user_id === $user->getKey(), 404);

        $this->sessions->revoke($session, AuthSession::REASON_ADMIN_REVOKED, $request->user());

        return back()->with('success', 'Sesión revocada.');
    }

    /**
     * Traduce una violación de regla de dominio a un error de validación con
     * mensaje de negocio, en lugar de dejar salir un 500. Es una situación
     * prevista —el último Admin— no una falla del sistema.
     */
    private function conMensajeDeNegocio(callable $accion, string $campo): void
    {
        try {
            $accion();
        } catch (LastAdminException $e) {
            throw ValidationException::withMessages([$campo => $e->getMessage()]);
        }
    }
}
