<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|---------------------------------------------------------------------------
| Rutas de F0
|---------------------------------------------------------------------------
|
| Lo que existe todavía es la autenticación mínima y la página de referencia
| del sistema de diseño. La Web pública, el backoffice y LIVE llegan en F4, F1
| y F5.
|
*/

Route::get('/', fn () => redirect('/login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Marcador del backoffice: F1 lo reemplaza por el listado de obras.
    Route::get('/admin', fn () => Inertia::render('Admin/Inicio'))->name('admin.inicio');
});

/*
| Página de referencia del RDS: es una herramienta de revisión visual interna,
| no una pantalla del producto. La ruta no existe en producción.
*/
if (! app()->environment('production')) {
    Route::get('/referencia-rds', fn () => Inertia::render('ReferenciaRds'))
        ->name('interno.referencia-rds');
}
