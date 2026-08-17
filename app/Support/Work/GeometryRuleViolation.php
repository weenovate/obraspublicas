<?php

declare(strict_types=1);

namespace App\Support\Work;

use RuntimeException;

/**
 * Una geometría que el dominio no acepta.
 *
 * Es una situación prevista —alguien dibujó mal, o el modo de la subcategoría no
 * coincide con lo que llegó— y no una falla del sistema: los controladores la
 * traducen a un error de validación con mensaje de negocio, no a un 500.
 */
final class GeometryRuleViolation extends RuntimeException {}
