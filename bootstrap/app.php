<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Audit\AuditsDeniedAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Las cabeceras de seguridad se aplican a toda respuesta web, no sólo a
        // las autenticadas: la Web pública también las necesita.
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);

        // Detrás del proxy de cPanel, sin esto Laravel no ve el HTTPS real y la
        // cookie segura y la redirección forzada dejan de funcionar.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            // Hace efectiva la revocación: una fila marcada como revocada no
            // desconecta a nadie hasta que alguien la revisa en cada petición.
            'sesion.activa' => EnsureSessionIsActive::class,
            // Bloquea toda la aplicación hasta cambiar la contraseña temporal.
            'password.cambiada' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda denegación de autorización queda registrada, salte donde salte
        // (CA-014). Ver App\Support\Audit\AuditsDeniedAccess.
        //
        // Los dos pasos son necesarios y el primero es el que no se ve venir:
        // Laravel ignora `AuthorizationException` de fábrica —está en la lista
        // interna `$internalDontReport`— y con ella ignorada el callback de
        // abajo nunca se ejecuta. `stopIgnoring()` la saca de esa lista.
        //
        // El `->stop()` cierra el círculo: una denegación no es una falla de la
        // aplicación, así que después de auditarla no se la manda también al log
        // de errores. El callback está tipado a `AuthorizationException` a
        // propósito —si tomara `Throwable`, ese `stop()` silenciaría el registro
        // de TODAS las excepciones—.
        $exceptions->stopIgnoring(AuthorizationException::class);

        $exceptions->report(function (AuthorizationException $e): void {
            app(AuditsDeniedAccess::class)->handle($e, request());
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
