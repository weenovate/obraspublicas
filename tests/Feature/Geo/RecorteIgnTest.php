<?php

declare(strict_types=1);

use App\Models\Work;
use Illuminate\Support\Facades\DB;

/*
| G3 · El recorte oficial del IGN.
|
| Este archivo es el origen de todas las coordenadas verificadas del proyecto:
| centro y zoom del mapa, viewbox de la geocodificación y los fixtures. Se
| congela por versión, y estos tests son lo que hace que «congelado» signifique
| algo: si alguien lo reemplaza, lo recorta o cambia su sistema de referencia, la
| suite se pone en rojo antes de que el mapa se mueva en silencio.
|
| La validez topológica se comprueba de forma COMPUESTA porque `ST_IsValid` no
| existe en MariaDB 10.11.18 —lo midió la sonda P5 de G2— y esa es la decisión de
| ADR-010.
*/

/** @return array{0: string, 1: array<string, mixed>} WKT y propiedades */
function recorteDeRamallo(): array
{
    $ruta = base_path(config('obras.mapa.dataset'));

    expect(file_exists($ruta))->toBeTrue("No está el recorte del IGN en {$ruta}.");

    $geojson = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
    $geometria = $geojson['features'][0]['geometry'];

    // GeoJSON a WKT respetando el orden que trae el archivo. Si estuviera
    // invertido, el WKT saldría invertido y las aserciones de abajo lo delatan:
    // no se «arregla» acá, se detecta.
    $anillo = fn (array $puntos): string => '('.implode(',', array_map(
        fn (array $par): string => sprintf('%.10F %.10F', $par[0], $par[1]),
        $puntos,
    )).')';

    $poligono = fn (array $anillos): string => '('.implode(',', array_map($anillo, $anillos)).')';

    return [
        'MULTIPOLYGON('.implode(',', array_map($poligono, $geometria['coordinates'])).')',
        $geojson['features'][0]['properties'],
    ];
}

/**
 * Ejecuta una expresión SQL sobre el recorte, disponible como `g`.
 *
 * Se resuelve en una subconsulta y no repitiendo `ST_GeomFromText(:wkt)`: PDO no
 * admite el mismo parámetro nombrado dos veces sin emulación, y además así el
 * polígono se construye una sola vez por consulta.
 */
function sobreElRecorte(string $expresion): mixed
{
    [$wkt] = recorteDeRamallo();

    return DB::selectOne(
        "SELECT {$expresion} AS r FROM (SELECT ST_GeomFromText(:wkt, 4326) AS g) AS recorte",
        ['wkt' => $wkt],
    )->r;
}

it('conserva el archivo exacto que se descargó del IGN', function () {
    // El hash vive en la configuración y en el manifiesto. Si el archivo cambia
    // sin que se actualicen los dos, este test lo dice.
    expect(hash_file('sha256', base_path(config('obras.mapa.dataset'))))
        ->toBe(config('obras.mapa.dataset_sha256'));
});

it('trae una sola entidad, y es el partido', function () {
    [, $propiedades] = recorteDeRamallo();

    expect($propiedades['nam'])->toBe('Ramallo')
        ->and($propiedades['gna'])->toBe('Partido')
        // «Ramallo» es también una localidad dentro del partido: si el filtro
        // hubiera matcheado esa, acá vendría un punto y otro objeto.
        ->and($propiedades['objeto'])->toBe('Departamento')
        ->and($propiedades['in1'])->toBe('06665');
});

it('está en EPSG:4326 con los ejes en [longitud, latitud]', function () {
    expect(sobreElRecorte('ST_SRID(g)'))->toBe(4326);

    // La prueba de fondo: el punto interior tiene que caer sobre Ramallo. Con
    // los ejes invertidos caería en el océano Índico, y ninguna validación de
    // esquema se quejaría.
    $x = (float) sobreElRecorte('ST_X(ST_Centroid(g))');
    $y = (float) sobreElRecorte('ST_Y(ST_Centroid(g))');

    expect($x)->toBeGreaterThan(-61.0)->toBeLessThan(-59.0)
        ->and($y)->toBeGreaterThan(-34.5)->toBeLessThan(-33.0);
});

it('es topológicamente válido por la verificación compuesta', function () {
    expect((bool) sobreElRecorte('ST_IsSimple(g)'))->toBeTrue('El polígono se autointersecta.')
        ->and((bool) sobreElRecorte('ST_IsClosed(ST_ExteriorRing(ST_GeometryN(g, 1)))'))
        ->toBeTrue('El anillo exterior no cierra.')
        ->and((bool) sobreElRecorte('ST_Contains(g, ST_PointOnSurface(g))'))
        ->toBeTrue('El punto interior no está contenido en el polígono.');
});

it('tiene la superficie que corresponde a un partido, no a un rectángulo', function () {
    // Un control de coherencia barato y sorprendentemente efectivo: si alguien
    // sustituyera el polígono por su envolvente, el área saltaría más del doble.
    // El área planar en grados² se convierte a km² con las dimensiones del grado
    // a esta latitud; es aproximada a propósito, acá alcanza el orden de magnitud.
    $gradosCuadrados = (float) sobreElRecorte('ST_Area(g)');
    $km2 = $gradosCuadrados * 110.9 * 92.73;

    expect($km2)->toBeGreaterThan(900.0)->toBeLessThan(1200.0);
});

it('encuadra el mapa con valores que salen del propio recorte', function () {
    [$lonMin, $latMin, $lonMax, $latMax] = config('obras.mapa.bbox');

    $envolvente = sobreElRecorte('ST_AsText(ST_Envelope(g))');

    // Los cuatro valores de la configuración tienen que ser los del polígono,
    // no números parecidos escritos a mano.
    foreach ([$lonMin, $latMin, $lonMax, $latMax] as $valor) {
        expect($envolvente)->toContain(number_format($valor, 6, '.', ''));
    }

    // El centro configurado tiene que estar DENTRO del partido. Un centro fuera
    // —el del bbox de una figura cóncava, por ejemplo— abriría el mapa mirando
    // al partido vecino.
    [$lon, $lat] = config('obras.mapa.centro');
    $dentro = DB::selectOne(
        'SELECT ST_Contains(ST_GeomFromText(:wkt, 4326), ST_GeomFromText(:punto, 4326)) AS r',
        ['wkt' => recorteDeRamallo()[0], 'punto' => sprintf('POINT(%.6F %.6F)', $lon, $lat)],
    )->r;

    expect((bool) $dentro)->toBeTrue('El centro configurado cae fuera del partido.');
});

it('el viewbox de la geocodificación usa el orden que espera Nominatim', function () {
    [$lonMin, $latMin, $lonMax, $latMax] = config('obras.mapa.bbox');

    // Nominatim ordena izquierda, arriba, derecha, abajo: la latitud MAYOR va
    // segunda. Invertirlo devuelve un rectángulo vacío y la geocodificación deja
    // de sesgar sin dar ningún error.
    expect(config('obras.mapa.viewbox_nominatim'))->toBe(sprintf(
        '%s,%s,%s,%s',
        number_format($lonMin, 6, '.', ''),
        number_format($latMax, 6, '.', ''),
        number_format($lonMax, 6, '.', ''),
        number_format($latMin, 6, '.', ''),
    ));
});

it('ubica las obras de prueba dentro del partido', function () {
    // Los fixtures dejaron de ser coordenadas inventadas. Si alguien las mueve a
    // un valor cómodo, este test lo encuentra.
    $obra = Work::factory()->create();

    $dentro = DB::selectOne(
        'SELECT ST_Contains(ST_GeomFromText(:wkt, 4326), w.representative_point) AS r
         FROM works w WHERE w.id = :id',
        ['wkt' => recorteDeRamallo()[0], 'id' => $obra->getKey()],
    )->r;

    expect((bool) $dentro)->toBeTrue('La obra de prueba quedó fuera del partido de Ramallo.');
});
