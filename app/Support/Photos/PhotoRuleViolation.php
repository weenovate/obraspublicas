<?php

declare(strict_types=1);

namespace App\Support\Photos;

use RuntimeException;

/**
 * Una fotografía que el dominio no acepta: formato no admitido, demasiado
 * grande, o una obra que ya llegó al máximo de fotos.
 *
 * Como las otras violaciones de regla del proyecto, es una situación prevista y
 * los controladores la traducen a un error de validación, nunca a un 500.
 */
final class PhotoRuleViolation extends RuntimeException {}
