<?php

declare(strict_types=1);

namespace App\Support\Work;

use RuntimeException;

/**
 * Dos personas editaron la misma obra y la segunda llegó con una versión vieja.
 *
 * No es un error de quien guarda: es información. El registro cambió entre que
 * se abrió el formulario y se envió, y la única respuesta honesta es decirlo y
 * mostrar lo que hay ahora, en lugar de pisar en silencio el trabajo del otro
 * —que es lo que hace un `UPDATE` sin guarda de versión—.
 */
final class ConcurrentEditException extends RuntimeException
{
    public function __construct(
        public readonly int $versionEsperada,
        public readonly int $versionActual,
    ) {
        parent::__construct(
            'Alguien más editó esta obra mientras la estabas modificando '
            ."(tenías la versión {$versionEsperada} y ya va por la {$versionActual}). "
            .'Revisá los datos actuales antes de volver a guardar: si guardaras ahora, '
            .'se perderían los cambios de la otra persona.',
        );
    }
}
