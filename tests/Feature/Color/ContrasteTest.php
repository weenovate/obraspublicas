<?php

declare(strict_types=1);

use App\Support\Color\CategoryColorPolicy;
use App\Support\Color\ContrastRatio;
use Symfony\Component\Process\Process;

/*
| Contraste (RF-CAT-003, RNF-ACC-001).
|
| Hay dos implementaciones de la misma fórmula WCAG y eso es deliberado: una en
| PHP, que valida el color al guardarlo, y otra en Node, que verifica los tokens
| del sistema de diseño en tiempo de build. Son dos momentos y dos lenguajes.
|
| El riesgo de tener dos es que deriven. Estos tests son el amarre: comparan las
| dos sobre los mismos pares, y comparan los fondos que la política usa contra
| los tokens CSS de los que salieron.
*/

/** @return list<array{0: string, 1: string}> */
function paresDeContraste(): array
{
    return [
        // Los dos fondos de la política, contra el verde y el naranja de marca.
        ['#75C932', '#F7F9F6'],
        ['#75C932', '#161A14'],
        ['#F7911F', '#F7F9F6'],
        ['#F7911F', '#161A14'],
        // Extremos: el par de contraste máximo y el mínimo posible.
        ['#FFFFFF', '#000000'],
        ['#808080', '#808080'],
        // Notación corta y mayúsculas mezcladas, que es donde una de las dos
        // implementaciones podría normalizar distinto.
        ['#abc', '#DEF'],
        // Justo alrededor del codo de la fórmula (0.03928), donde el canal pasa
        // de la rama lineal a la exponencial: si una implementación se equivoca
        // de rama, la diferencia aparece acá y en ningún otro lado.
        ['#0A0A0A', '#0B0B0B'],
    ];
}

it('mide igual que la implementación de Node sobre los mismos pares', function (string $fg, string $bg) {
    $proceso = new Process(['node', 'scripts/rds-contraste.mjs', '--par', $fg, $bg], base_path());
    $proceso->run();

    expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

    $deNode = (float) trim($proceso->getOutput());
    $dePhp = ContrastRatio::between($fg, $bg);

    // 1e-9 sobre una escala de 1 a 21: cualquier diferencia real de fórmula
    // —una rama distinta, un coeficiente cambiado, un redondeo temprano— produce
    // una diferencia varios órdenes de magnitud mayor que ésta.
    expect(abs($deNode - $dePhp))->toBeLessThan(1e-9);
})->with(paresDeContraste());

it('mide los extremos conocidos de la escala', function () {
    // Anclas independientes de la implementación: blanco sobre negro es 21:1 y
    // un color contra sí mismo es 1:1, por definición de la fórmula.
    expect(ContrastRatio::between('#FFFFFF', '#000000'))->toEqualWithDelta(21.0, 1e-9)
        ->and(ContrastRatio::between('#75C932', '#75C932'))->toEqualWithDelta(1.0, 1e-9);
});

it('trata la notación corta como la larga', function () {
    expect(ContrastRatio::between('#abc', '#fff'))
        ->toEqualWithDelta(ContrastRatio::between('#aabbcc', '#ffffff'), 1e-12);
});

it('rechaza lo que no es un color hexadecimal', function () {
    expect(ContrastRatio::isValidHex('#12345'))->toBeFalse()
        ->and(ContrastRatio::isValidHex('rojo'))->toBeFalse()
        ->and(ContrastRatio::isValidHex('#75C932'))->toBeTrue()
        ->and(ContrastRatio::isValidHex('75C932'))->toBeTrue();
});

it('mide contra los mismos fondos que declaran los tokens del RDS', function () {
    // La política repite los dos `--rml-surface-page` como constantes porque el
    // servidor no parsea CSS. Si alguien cambia el token y no la constante, la
    // validación de RF-CAT-003 estaría midiendo contra un fondo que ya no
    // existe, y nadie se daría cuenta hasta ver un color ilegible en el mapa.
    $claro = file_get_contents(base_path('resources/rds/css/tokens/colors.css'));
    $oscuro = file_get_contents(base_path('resources/css/rds-dark.css'));

    expect(tokenDeclarado($claro, '--rml-surface-page', $claro))
        ->toBe(strtolower(CategoryColorPolicy::LIGHT_SURFACE))
        ->and(tokenDeclarado($oscuro, '--rml-surface-page', $claro))
        ->toBe(strtolower(CategoryColorPolicy::DARK_SURFACE));
});

/**
 * Última declaración de un token, resuelta un nivel si apunta a otro token.
 *
 * En el tema oscuro `--rml-surface-page` se declara como
 * `var(--rml-neutral-900)`, y esa escala vive en el paquete original: por eso la
 * referencia se resuelve contra `$paleta` y no contra el mismo archivo.
 */
function tokenDeclarado(string $css, string $token, string $paleta): string
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $paleta = (string) preg_replace('#/\*.*?\*/#s', '', $paleta);

    $valor = ultimaDeclaracion($css, $token);
    expect($valor)->not->toBeNull("El token {$token} no está declarado.");

    if (preg_match('/var\(\s*(--[\w-]+)\s*\)/', $valor, $referencia) === 1) {
        $valor = ultimaDeclaracion($css.$paleta, $referencia[1]) ?? $valor;
    }

    return strtolower(trim($valor));
}

function ultimaDeclaracion(string $css, string $token): ?string
{
    $encontradas = [];
    preg_match_all('/'.preg_quote($token, '/').'\s*:\s*([^;}]+)/', $css, $encontradas);

    return $encontradas[1] === [] ? null : trim(end($encontradas[1]));
}

it('propone un color inicial que su propia política acepta', function () {
    // Abrir el formulario de categoría nueva con un color ya rechazado sería una
    // trampa: el usuario lo guardaría sin tocarlo y recibiría un error.
    expect(CategoryColorPolicy::evaluate(CategoryColorPolicy::SUGGESTED)['valido'])->toBeTrue();
});

it('rechaza un color que se pierde sobre alguno de los dos fondos', function () {
    // Un gris muy claro se ve bien en oscuro y desaparece en claro. Que un color
    // sirva en un tema no alcanza: RF-CAT-003 exige los dos.
    $evaluacion = CategoryColorPolicy::evaluate('#F2F4F1');

    expect($evaluacion['valido'])->toBeFalse()
        ->and($evaluacion['contra_claro'])->toBeLessThan(ContrastRatio::AA_UI)
        ->and($evaluacion['contra_oscuro'])->toBeGreaterThan(ContrastRatio::AA_UI)
        ->and($evaluacion['problemas'])->toHaveCount(1);
});

it('recomienda el color de texto que más contrasta con el elegido', function () {
    expect(CategoryColorPolicy::evaluate('#161A14')['texto_recomendado'])
        ->toBe(CategoryColorPolicy::LIGHT_TEXT)
        ->and(CategoryColorPolicy::evaluate('#F7911F')['texto_recomendado'])
        ->toBe(CategoryColorPolicy::DARK_TEXT);
});
