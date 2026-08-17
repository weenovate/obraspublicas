<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\FieldDefinitionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatusController;
use App\Http\Controllers\Admin\SubcategoryController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BoundaryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkController;
use App\Policies\AdminPolicy;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|---------------------------------------------------------------------------
| Rutas
|---------------------------------------------------------------------------
|
| Lo que existe hoy es el acceso, el perfil propio, los catálogos y la
| configuración. La Web pública, el CRUD de obras y LIVE llegan en F4, F1-B y F5.
|
| Las rutas administrativas llevan `can:` explícito además del `Gate::authorize()`
| del controlador. Es redundante a propósito: la ruta protege incluso si mañana
| alguien agrega un método al controlador y se olvida de autorizar, y el
| controlador protege si la ruta se reorganiza.
|
*/

Route::get('/', fn () => redirect('/login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'sesion.activa'])->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // El cambio de contraseña temporal tiene que ser alcanzable ANTES de que el
    // middleware que lo exige deje pasar a cualquier otra pantalla.
    Route::get('/perfil/password', [ProfileController::class, 'passwordForm'])->name('perfil.password');
    Route::put('/perfil/password', [ProfileController::class, 'passwordUpdate'])->name('perfil.password.update');

    Route::middleware('password.cambiada')->group(function (): void {
        Route::get('/admin', fn () => Inertia::render('Admin/Inicio'))->name('admin.inicio');

        Route::get('/perfil', [ProfileController::class, 'edit'])->name('perfil.edit');
        Route::put('/perfil', [ProfileController::class, 'update'])->name('perfil.update');

        // ---- Obras: los dos roles (matriz de permisos, spec 2.2) ----
        //
        // Sin `can:`: crear y editar obras es precisamente lo que hace el rol
        // Obras Públicas. Lo exclusivo del Admin —restaurar y eliminar
        // definitivamente— es F6 y llega con su propia política.

        Route::get('/obras', [WorkController::class, 'index'])->name('obras.index');
        Route::get('/obras/nueva', [WorkController::class, 'create'])->name('obras.create');
        Route::post('/obras', [WorkController::class, 'store'])->name('obras.store');
        Route::get('/obras/{work}/editar', [WorkController::class, 'edit'])->name('obras.edit');
        Route::put('/obras/{work}', [WorkController::class, 'update'])->name('obras.update');
        Route::delete('/obras/{work}', [WorkController::class, 'destroy'])->name('obras.destroy');

        // El contorno del partido que el editor usa de fondo.
        Route::get('/mapa/partido.geojson', BoundaryController::class)->name('mapa.partido');

        // ---- Sólo Administrador (matriz de permisos, spec 2.2) ----

        Route::middleware('can:'.AdminPolicy::GESTIONAR_USUARIOS)->group(function (): void {
            Route::get('/admin/usuarios', [UserController::class, 'index'])->name('admin.usuarios.index');
            Route::post('/admin/usuarios', [UserController::class, 'store'])->name('admin.usuarios.store');
            Route::put('/admin/usuarios/{user}', [UserController::class, 'update'])->name('admin.usuarios.update');
            Route::post('/admin/usuarios/{user}/desactivar', [UserController::class, 'deactivate'])->name('admin.usuarios.desactivar');
            Route::post('/admin/usuarios/{user}/activar', [UserController::class, 'activate'])->name('admin.usuarios.activar');
            Route::post('/admin/usuarios/{user}/password', [UserController::class, 'resetPassword'])->name('admin.usuarios.password');
            Route::delete('/admin/usuarios/{user}/sesiones/{session}', [UserController::class, 'revokeSession'])->name('admin.usuarios.sesiones.revocar');
        });

        Route::middleware('can:'.AdminPolicy::GESTIONAR_CATALOGOS)->group(function (): void {
            Route::get('/admin/categorias', [CategoryController::class, 'index'])->name('admin.categorias.index');
            Route::post('/admin/categorias', [CategoryController::class, 'store'])->name('admin.categorias.store');
            Route::put('/admin/categorias/{category}', [CategoryController::class, 'update'])->name('admin.categorias.update');
            Route::delete('/admin/categorias/{category}', [CategoryController::class, 'destroy'])->name('admin.categorias.destroy');

            Route::get('/admin/subcategorias', [SubcategoryController::class, 'index'])->name('admin.subcategorias.index');
            Route::post('/admin/subcategorias', [SubcategoryController::class, 'store'])->name('admin.subcategorias.store');
            Route::put('/admin/subcategorias/{subcategory}', [SubcategoryController::class, 'update'])->name('admin.subcategorias.update');

            Route::get('/admin/estados', [StatusController::class, 'index'])->name('admin.estados.index');
            Route::post('/admin/estados', [StatusController::class, 'store'])->name('admin.estados.store');
            Route::put('/admin/estados/{status}', [StatusController::class, 'update'])->name('admin.estados.update');
            Route::delete('/admin/estados/{status}', [StatusController::class, 'destroy'])->name('admin.estados.destroy');

            Route::get('/admin/campos', [FieldDefinitionController::class, 'index'])->name('admin.campos.index');
            Route::post('/admin/campos', [FieldDefinitionController::class, 'store'])->name('admin.campos.store');
            Route::put('/admin/campos/{definition}', [FieldDefinitionController::class, 'update'])->name('admin.campos.update');
            Route::delete('/admin/campos/{definition}', [FieldDefinitionController::class, 'destroy'])->name('admin.campos.destroy');
            Route::post('/admin/campos/{definition}/opciones', [FieldDefinitionController::class, 'storeOption'])->name('admin.campos.opciones.store');
            Route::delete('/admin/campos/opciones/{option}', [FieldDefinitionController::class, 'destroyOption'])->name('admin.campos.opciones.destroy');
        });

        Route::middleware('can:'.AdminPolicy::GESTIONAR_CONFIGURACION)->group(function (): void {
            Route::get('/admin/configuracion', [SettingsController::class, 'index'])->name('admin.configuracion.index');
            Route::put('/admin/configuracion', [SettingsController::class, 'update'])->name('admin.configuracion.update');
        });
    });
});

/*
| Página de referencia del RDS: herramienta de revisión visual interna, no una
| pantalla del producto. No existe en producción.
*/
if (! app()->environment('production')) {
    Route::get('/referencia-rds', fn () => Inertia::render('ReferenciaRds'))
        ->name('interno.referencia-rds');
}
