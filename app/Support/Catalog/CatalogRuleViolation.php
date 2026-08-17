<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use RuntimeException;

/**
 * Una regla de catálogo rechazó el cambio.
 *
 * El mensaje está escrito en lenguaje de negocio y en español de Argentina
 * (RNF-LOC-001): quien lo lee es la persona que estaba editando el catálogo, no
 * quien escribió el código. Se traduce a un error de validación sobre el campo
 * correspondiente, no a un 500.
 */
final class CatalogRuleViolation extends RuntimeException {}
