<?php

declare(strict_types=1);

namespace App\Support\Color;

use InvalidArgumentException;

/**
 * Relación de contraste WCAG 2.2.
 *
 * El Admin elige el color de cada categoría en hexadecimal libre (RF-CAT-003), y
 * ese color termina dibujado sobre los fondos de los DOS temas. Verificarlo en el
 * navegador no alcanza: la validación tiene que estar donde se guarda, o alcanza
 * con desactivar JavaScript para meter un color ilegible.
 *
 * La misma fórmula vive en `scripts/rds-contraste.mjs`, que verifica los tokens
 * del sistema de diseño en tiempo de build. Son dos implementaciones porque son
 * dos momentos distintos —build y guardado— y dos lenguajes; un test compara las
 * dos sobre los mismos pares para que no deriven.
 */
final class ContrastRatio
{
    /** Texto normal, WCAG 2.2 nivel AA (1.4.3). */
    public const AA_TEXT = 4.5;

    /** Componentes de interfaz y bordes que identifican un control (1.4.11). */
    public const AA_UI = 3.0;

    /**
     * Relación de contraste entre dos colores, de 1 a 21.
     *
     * @param  string  $foreground  Hexadecimal, con o sin `#`, de 3 o 6 dígitos
     * @param  string  $background  Ídem
     */
    public static function between(string $foreground, string $background): float
    {
        $l1 = self::relativeLuminance($foreground);
        $l2 = self::relativeLuminance($background);

        [$hi, $lo] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * Luminancia relativa según la definición de WCAG.
     */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::toRgb($hex);

        $channel = static function (int $value): float {
            $s = $value / 255;

            return $s <= 0.03928
                ? $s / 12.92
                : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toRgb(string $hex): array
    {
        $normalized = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-f]{3}$/i', $normalized) === 1) {
            $normalized = $normalized[0].$normalized[0]
                .$normalized[1].$normalized[1]
                .$normalized[2].$normalized[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $normalized) !== 1) {
            throw new InvalidArgumentException(
                "«{$hex}» no es un color hexadecimal válido. Se espera #RGB o #RRGGBB.",
            );
        }

        return [
            (int) hexdec(substr($normalized, 0, 2)),
            (int) hexdec(substr($normalized, 2, 2)),
            (int) hexdec(substr($normalized, 4, 2)),
        ];
    }

    public static function isValidHex(string $hex): bool
    {
        try {
            self::toRgb($hex);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
