<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Definición de una opción de configuración.
 *
 * Es lo que hace que la configuración sea TIPADA y no un diccionario de claves
 * libres (RF-CFG-001): cada opción declara su tipo, su valor por omisión y su
 * rango, y nada que no esté declarado acá se puede guardar.
 */
final class SettingDefinition
{
    /**
     * @param  list<string>|null  $allowed  Valores admitidos, para `ENUM`
     */
    public function __construct(
        public readonly string $key,
        public readonly string $dataType,
        public readonly mixed $default,
        public readonly string $label,
        public readonly string $help = '',
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?array $allowed = null,
    ) {}
}
