<?php

declare(strict_types=1);

namespace App\Support\Work;

use RuntimeException;

/**
 * Una obra que el dominio no acepta: fechas incoherentes, estado finalizador sin
 * fecha real, geometría que la base rechaza al verificar el invariante.
 *
 * Como `CatalogRuleViolation` y `GeometryRuleViolation`, es una situación
 * prevista: los controladores la traducen a un error de validación con mensaje
 * de negocio, nunca a un 500.
 */
final class WorkRuleViolation extends RuntimeException {}
