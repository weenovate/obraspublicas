<?php

declare(strict_types=1);

namespace App\Support\Users;

use RuntimeException;

/**
 * La operación habría dejado al sistema sin ningún Administrador activo
 * (RF-AUT-005).
 *
 * Se traduce a un error de validación con mensaje de negocio, no a un 500: es
 * una situación prevista, no una falla.
 */
final class LastAdminException extends RuntimeException {}
