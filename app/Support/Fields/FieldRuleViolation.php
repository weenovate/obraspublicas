<?php

declare(strict_types=1);

namespace App\Support\Fields;

use RuntimeException;

/**
 * Un valor de campo técnico que el dominio no acepta: del tipo equivocado, fuera
 * del rango declarado, o una opción que no pertenece a ese campo.
 *
 * Como `GeometryRuleViolation` y `WorkRuleViolation`, es una situación prevista
 * —alguien llenó mal un formulario— y los controladores la traducen a un error
 * de validación con mensaje de negocio, nunca a un 500.
 */
final class FieldRuleViolation extends RuntimeException {}
