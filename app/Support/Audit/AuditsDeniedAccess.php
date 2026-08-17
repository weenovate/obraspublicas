<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * Registra toda denegación de autorización (CA-014, RF-AUD-001).
 *
 * Se engancha en el manejador de excepciones y no en un middleware porque una
 * denegación puede saltar en cualquier punto: un `Gate::authorize()` en el
 * controlador, una policy en un form request, un `can:` en la ruta. Un middleware
 * sólo vería el último caso.
 *
 * DOS CUIDADOS QUE HACEN LA DIFERENCIA:
 *
 *   1. Va por `registrarIntentoFallido()`. Un intento denegado no tiene cambio de
 *      negocio que confirmar, y además puede saltar DENTRO de una transacción que
 *      después se revierte —una denegación en medio de una actualización—. Con
 *      `AUDIT_INDEPENDENT_CONNECTION` configurada, el evento sobrevive a ese
 *      rollback; sin ella, `AuditRecorder` avisa por log.
 *
 *   2. El evento registra la ruta y el actor, pero NO el contenido de la
 *      respuesta ni los datos que se intentaban ver. CA-014 pide exactamente eso:
 *      «queda registrado sin exponer datos». Una bitácora que copia lo que el
 *      usuario no tenía permiso de ver convierte al registro de seguridad en la
 *      filtración que quería evitar.
 */
final class AuditsDeniedAccess
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Throwable $e, Request $request): void
    {
        if (! $this->isDenial($e)) {
            return;
        }

        $user = $request->user();

        $this->audit->registrarIntentoFallido(
            action: 'authz.denied',
            metadata: [
                'metodo' => $request->method(),
                // Se registra la ruta, no la consulta ni el cuerpo: ahí es donde
                // viajarían los datos que el usuario no podía ver.
                'ruta' => '/'.ltrim($request->path(), '/'),
                'nombre_de_ruta' => $request->route()?->getName(),
                'rol' => $user?->role,
            ],
            actor: $user,
        );
    }

    private function isDenial(Throwable $e): bool
    {
        return $e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException;
    }
}
