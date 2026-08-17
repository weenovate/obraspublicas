<?php

declare(strict_types=1);

/*
| Tests de arquitectura: convierten en verificaciones las reglas que, sin un
| guardián automático, se cumplen las primeras semanas y después se erosionan.
|
| No usan la API de expectativas de arquitectura de Pest para todo: varias de
| estas reglas son sobre CONTENIDO de archivos (qué función se llama, desde
| dónde), y eso se verifica leyendo el código.
*/

/**
 * Devuelve el código PHP sin comentarios ni docblocks.
 *
 * Sin esto, estas reglas dan falsos positivos contra su propia documentación: el
 * docblock de `User` dice «nunca `$guarded = []`» y el comentario de la conexión
 * de auditoría nombra `registrarIntentoFallido`. Un test de arquitectura tiene
 * que mirar el código, no la prosa que lo explica.
 */
function codigoSinComentarios(string $php): string
{
    $codigo = '';

    foreach (token_get_all($php) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $codigo .= is_array($token) ? $token[1] : $token;
    }

    return $codigo;
}

/** @return array<string, string> ruta relativa => contenido */
function archivosPhpDeLaApp(): array
{
    $base = dirname(__DIR__, 2);
    $archivos = [];

    foreach (['app', 'database', 'routes', 'config'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("{$base}/{$dir}", FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $archivos[str_replace("{$base}/", '', $file->getPathname())] = codigoSinComentarios(
                (string) file_get_contents($file->getPathname()),
            );
        }
    }

    return $archivos;
}

it('sólo el adaptador instancia Location\Coordinate', function () {
    // phpgeo recibe (lat, lng): invertido respecto de la convención del sistema.
    // Es la frontera exacta donde se cuelan los errores de ejes, así que se cruza
    // en un solo archivo y en ningún otro.
    $infractores = [];

    foreach (archivosPhpDeLaApp() as $ruta => $contenido) {
        if (str_ends_with($ruta, 'app/Support/Geo/GeoJsonPhpGeoAdapter.php')) {
            continue;
        }

        if (preg_match('/new\s+Coordinate\s*\(|new\s+\\\\?Location\\\\Coordinate\s*\(/', $contenido) === 1) {
            $infractores[] = $ruta;
        }
    }

    expect($infractores)->toBe([], 'Instanciar Coordinate fuera del adaptador reintroduce el riesgo de ejes invertidos.');
});

it('nadie usa ST_Length en el código de dominio', function () {
    // P8 lo midió con números: sobre lon/lat devuelve grados, no metros. La base
    // nunca es fuente de verdad de una longitud. El único lugar donde puede
    // aparecer es la sonda que lo demuestra.
    $infractores = [];

    foreach (archivosPhpDeLaApp() as $ruta => $contenido) {
        if (preg_match('/\bST_Length\s*\(/i', $contenido) === 1) {
            $infractores[] = $ruta;
        }
    }

    expect($infractores)->toBe([], 'ST_Length devuelve grados sobre coordenadas geográficas: la métrica se calcula en PHP.');
});

it('el SQL espacial no concatena entrada del usuario', function () {
    // El WKT y el SRID van siempre por binding (RNF-SEC-003). Se busca el patrón
    // de interpolación de variables dentro de una llamada a ST_GeomFromText.
    $infractores = [];

    foreach (archivosPhpDeLaApp() as $ruta => $contenido) {
        if (preg_match('/ST_GeomFromText\s*\(\s*[\'"][^\'"]*\$/i', $contenido) === 1) {
            $infractores[] = $ruta;
        }

        if (preg_match('/ST_GeomFromText\s*\(\s*\$/i', $contenido) === 1) {
            $infractores[] = $ruta;
        }
    }

    expect($infractores)->toBe([], 'El WKT y el SRID van por binding, nunca interpolados.');
});

it('registrarIntentoFallido se usa sólo para fallos y denegaciones', function () {
    // El nombre del método dice qué admite, y esta lista blanca lo hace
    // verificable: usarlo para una operación exitosa es un error de diseño, no un
    // detalle de estilo.
    $permitidos = [
        'app/Http/Controllers/Auth/LoginController.php',
        'app/Support/Audit/AuditRecorder.php',
    ];

    $infractores = [];

    foreach (archivosPhpDeLaApp() as $ruta => $contenido) {
        if (in_array($ruta, $permitidos, true)) {
            continue;
        }

        if (str_contains($contenido, 'registrarIntentoFallido')) {
            $infractores[] = $ruta;
        }
    }

    expect($infractores)->toBe([], 'Si hace falta auditar acá, es una operación exitosa: usá registrar() y hacelo transaccional.');
});

it('ningún modelo usa $guarded vacío', function () {
    // Mass assignment (RNF-SEC-003): `$fillable` explícito, siempre.
    $infractores = [];

    foreach (archivosPhpDeLaApp() as $ruta => $contenido) {
        if (! str_starts_with($ruta, 'app/Models/')) {
            continue;
        }

        if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $contenido) === 1) {
            $infractores[] = $ruta;
        }
    }

    expect($infractores)->toBe([]);
});

it('la conexión de base es mariadb, no mysql', function () {
    // D1. El grammar de MariaDB de Laravel emite DDL geométrico distinto del de
    // MySQL 8, y toda la matriz espacial se midió sobre MariaDB.
    expect(config('database.default'))->toBe('mariadb');
});

it('el driver de hashing es Argon2id', function () {
    expect(config('hashing.driver'))->toBe('argon2id');
});

it('no queda rastro de Tailwind en el proyecto', function () {
    // D4: el RDS es la única capa de estilos, y sus utilidades se llaman igual
    // que las de Tailwind. Cargar ambos deja la resolución al orden de
    // importación del bundler.
    $base = dirname(__DIR__, 2);
    $package = json_decode((string) file_get_contents("{$base}/package.json"), true, flags: JSON_THROW_ON_ERROR);

    $dependencias = array_keys(array_merge(
        $package['dependencies'] ?? [],
        $package['devDependencies'] ?? [],
    ));

    $tailwind = array_values(array_filter(
        $dependencias,
        fn (string $nombre): bool => str_contains($nombre, 'tailwind'),
    ));

    expect($tailwind)->toBe([])
        ->and(file_get_contents("{$base}/resources/css/app.css"))->not->toContain('tailwind');
});

it('la sesión está configurada como exige RNF-SEC-001', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.encrypt'))->toBeTrue();
});
