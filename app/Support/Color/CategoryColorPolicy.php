<?php

declare(strict_types=1);

namespace App\Support\Color;

/**
 * Reglas de color para las categorías (RF-CAT-003).
 *
 * El color de una categoría no es decorativo: pinta el marcador en el mapa y el
 * distintivo en las fichas, sobre los fondos de los DOS temas. Un color que se ve
 * bien en claro puede desaparecer en oscuro, y al revés.
 *
 * QUÉ EXIGE Y QUÉ SÓLO ADVIERTE, que no es lo mismo:
 *
 *   - Contra los fondos de página de ambos temas se exige 3:1. Es el umbral de
 *     WCAG 1.4.11 para un elemento gráfico que transporta información, y el
 *     color de la categoría lo es: distingue una capa de otra.
 *   - Que el marcador lleve texto encima es otra historia: ahí se necesita 4,5:1
 *     contra el color elegido, y como el sistema puede poner texto claro u
 *     oscuro según convenga, se informa cuál corresponde en lugar de rechazar.
 *
 * Los fondos son los tokens `--rml-surface-page` de cada tema, que están fijados
 * en `resources/rds/css/tokens/colors.css` y `resources/css/rds-dark.css`. Se
 * repiten acá como constantes porque el servidor no parsea CSS, y un test
 * verifica que sigan coincidiendo: si alguien cambia el token y no esta
 * constante, la validación estaría midiendo contra un fondo que ya no existe.
 */
final class CategoryColorPolicy
{
    /** `--rml-surface-page` del tema claro. */
    public const LIGHT_SURFACE = '#F7F9F6';

    /** `--rml-surface-page` del tema oscuro (`--rml-neutral-900`). */
    public const DARK_SURFACE = '#161A14';

    /** Texto claro y oscuro disponibles para dibujar encima del color. */
    public const LIGHT_TEXT = '#FFFFFF';

    public const DARK_TEXT = '#161A14';

    /**
     * Color propuesto al crear una categoría nueva.
     *
     * Vive acá y no en el formulario porque el código de la aplicación no lleva
     * colores literales —lo verifica `scripts/rds-lint.mjs`— y porque un valor
     * inicial que no superara esta misma política sería una trampa: el usuario
     * abriría el formulario con un color ya rechazado. Un test lo comprueba.
     */
    public const SUGGESTED = '#497D1F';

    /**
     * Evalúa un color de categoría contra los dos temas.
     *
     * @return array{
     *     valido: bool,
     *     contra_claro: float,
     *     contra_oscuro: float,
     *     minimo: float,
     *     texto_recomendado: string,
     *     problemas: list<string>
     * }
     */
    public static function evaluate(string $color): array
    {
        $contraClaro = ContrastRatio::between($color, self::LIGHT_SURFACE);
        $contraOscuro = ContrastRatio::between($color, self::DARK_SURFACE);

        $problemas = [];

        if ($contraClaro < ContrastRatio::AA_UI) {
            $problemas[] = sprintf(
                'Sobre el fondo del tema claro da %.2f:1 y el mínimo es %.1f:1. En el mapa se vería lavado.',
                $contraClaro,
                ContrastRatio::AA_UI,
            );
        }

        if ($contraOscuro < ContrastRatio::AA_UI) {
            $problemas[] = sprintf(
                'Sobre el fondo del tema oscuro da %.2f:1 y el mínimo es %.1f:1.',
                $contraOscuro,
                ContrastRatio::AA_UI,
            );
        }

        // Qué color de texto conviene encima del marcador, si lleva texto.
        $conBlanco = ContrastRatio::between(self::LIGHT_TEXT, $color);
        $conNegro = ContrastRatio::between(self::DARK_TEXT, $color);

        return [
            'valido' => $problemas === [],
            'contra_claro' => round($contraClaro, 2),
            'contra_oscuro' => round($contraOscuro, 2),
            'minimo' => ContrastRatio::AA_UI,
            'texto_recomendado' => $conBlanco >= $conNegro ? self::LIGHT_TEXT : self::DARK_TEXT,
            'problemas' => $problemas,
        ];
    }
}
