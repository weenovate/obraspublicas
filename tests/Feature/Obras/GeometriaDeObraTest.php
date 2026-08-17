<?php

declare(strict_types=1);

use App\Models\WorkSubcategory;
use App\Support\Work\GeometryRuleViolation;
use App\Support\Work\WorkGeometry;
use Illuminate\Support\Facades\DB;

/*
| F1-B · La geometría de una obra, antes de tocar la base (RF-GEO-005/014).
|
| La mitad de este archivo son reglas de dominio que se verifican en PHP —tipo
| contra modo, ejes, anillos cerrados, clics repetidos—. La otra mitad es la que
| importa de verdad y NO se puede simular: el punto representativo que elige
| `WorkGeometry` tiene que satisfacer `ST_Contains(geometry, punto)` EN MARIADB,
| que es donde se guarda y donde se consulta.
|
| Esa segunda mitad existe porque la primera intuición estaba mal. El punto medio
| aritmético de un segmento parece obviamente contenido en él, y sobre 200
| segmentos medidos lo estuvo sólo 54 veces: la división por dos redondea en
| binario y el resultado no cae sobre ninguno de los puntos que el predicado
| reconoce. Un vértice, en cambio, es el mismo double que ya está almacenado, y
| pasó las 200.
*/

/** El punto representativo, resuelto contra el motor cuando lo calcula la base. */
function puntoRepresentativoWkt(WorkGeometry $geometria): string
{
    if (! $geometria->puntoLoCalculaLaBase()) {
        return $geometria->representativePointWkt;
    }

    return (string) DB::selectOne(
        'SELECT ST_AsText(ST_PointOnSurface(ST_GeomFromText(:wkt, 4326))) AS r',
        ['wkt' => $geometria->wkt],
    )->r;
}

/** ¿La base acepta el punto elegido como interior de la geometría? */
function elMotorLoContiene(WorkGeometry $geometria): bool
{
    return (bool) DB::selectOne(
        'SELECT ST_Contains(ST_GeomFromText(:g, 4326), ST_GeomFromText(:p, 4326)) AS r',
        ['g' => $geometria->wkt, 'p' => puntoRepresentativoWkt($geometria)],
    )->r;
}

function subcategoriaConModo(string $modo): WorkSubcategory
{
    return WorkSubcategory::factory()->create(['geometry_mode' => $modo]);
}

/*
|---------------------------------------------------------------------------
| El invariante, medido contra el motor
|---------------------------------------------------------------------------
*/

it('elige un punto representativo que MariaDB acepta como contenido', function (string $modo, array $coordenadas, string $tipo) {
    $geometria = WorkGeometry::desdeGeoJson(
        ['type' => $tipo, 'coordinates' => $coordenadas],
        subcategoriaConModo($modo),
    );

    expect(elMotorLoContiene($geometria))->toBeTrue(
        "El punto representativo de {$geometria->wkt} no quedó contenido en la geometría.",
    );
})->with([
    'punto' => [
        WorkSubcategory::MODE_POINT,
        [-60.0575, -33.5872],
        'Point',
    ],

    // El caso que rompe con el punto medio aritmético: dos vértices y nada más.
    'línea de dos vértices' => [
        WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
        [[-60.0575, -33.5872], [-60.0475, -33.5772]],
        'LineString',
    ],

    'línea de varios vértices' => [
        WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
        [[-60.0575, -33.5872], [-60.0525, -33.5822], [-60.0475, -33.5772], [-60.0425, -33.5762]],
        'LineString',
    ],

    // Una U: el centroide de esta línea cae en el hueco, fuera de la geometría.
    'línea en U' => [
        WorkSubcategory::MODE_LINE_ROUTED_ROAD,
        [[-60.08, -33.50], [-60.08, -33.45], [-60.06, -33.45], [-60.06, -33.50]],
        'LineString',
    ],

    'polígono convexo' => [
        WorkSubcategory::MODE_POLYGON,
        [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.06, -33.58], [-60.06, -33.59]]],
        'Polygon',
    ],

    // Cóncavo: acá el centroide se va afuera y `ST_PointOnSurface` no (ADR-009).
    'polígono cóncavo' => [
        WorkSubcategory::MODE_POLYGON,
        [[
            [-60.08, -33.50], [-60.04, -33.50], [-60.04, -33.46], [-60.07, -33.46],
            [-60.07, -33.48], [-60.06, -33.48], [-60.06, -33.46], [-60.08, -33.46], [-60.08, -33.50],
        ]],
        'Polygon',
    ],
]);

it('deja que la base calcule el punto del polígono y lo escribe para los demás', function () {
    $poligono = WorkGeometry::desdeGeoJson([
        'type' => 'Polygon',
        'coordinates' => [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.06, -33.59]]],
    ], subcategoriaConModo(WorkSubcategory::MODE_POLYGON));

    $linea = WorkGeometry::desdeGeoJson([
        'type' => 'LineString',
        'coordinates' => [[-60.0575, -33.5872], [-60.0475, -33.5772]],
    ], subcategoriaConModo(WorkSubcategory::MODE_LINE_MANUAL_NETWORK));

    expect($poligono->puntoLoCalculaLaBase())->toBeTrue()
        ->and($linea->puntoLoCalculaLaBase())->toBeFalse()
        // El de la línea es uno de sus vértices, textualmente: es la única forma
        // de que el double coincida bit a bit con el almacenado.
        ->and($linea->wkt)->toContain(trim($linea->representativePointWkt, 'POINT()'));
});

it('confirma que el punto medio aritmético NO habría servido y un vértice sí', function () {
    // Este test no prueba código propio: prueba el hecho del motor sobre el que
    // se tomó la decisión de elegir un vértice. Si una versión futura de MariaDB
    // dejara de comportarse así, conviene enterarse acá.
    //
    // Se miden 200 segmentos y no uno, porque con uno solo la conclusión sale al
    // revés la mayoría de las veces: el punto medio A VECES queda contenido. Que
    // funcione a veces es precisamente el problema —un invariante que se cumple
    // el 27 % de las veces no es un invariante— y sólo se ve en volumen.
    $fila = DB::selectOne(<<<'SQL'
        WITH RECURSIVE n(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < 199)
        SELECT SUM(medio) AS medios, SUM(vertice) AS vertices, COUNT(*) AS total FROM (
          SELECT
            ST_Contains(
              ST_GeomFromText(CONCAT('LINESTRING(', x1, ' ', y1, ',', x2, ' ', y2, ')'), 4326),
              ST_GeomFromText(CONCAT('POINT(', (x1 + x2) / 2, ' ', (y1 + y2) / 2, ')'), 4326)
            ) AS medio,
            ST_Contains(
              ST_GeomFromText(CONCAT('LINESTRING(', x1, ' ', y1, ',', x2, ' ', y2, ')'), 4326),
              ST_GeomFromText(CONCAT('POINT(', x1, ' ', y1, ')'), 4326)
            ) AS vertice
          FROM (
            SELECT -60.3 + i * 0.0013 AS x1, -33.8 + i * 0.0021 AS y1,
                   -60.1 + i * 0.0007 AS x2, -33.4 + i * 0.0011 AS y2
            FROM n
          ) segmentos
        ) medidas
    SQL);

    expect((int) $fila->total)->toBe(200)
        ->and((int) $fila->vertices)->toBe(200, 'Un vértice tiene que estar contenido siempre.')
        ->and((int) $fila->medios)->toBeLessThan(200, 'El punto medio aritmético falló en algún segmento.');

    // Y el caso concreto donde el motor se contradice consigo mismo: la
    // distancia da exactamente cero y el predicado dice que no está.
    $contradiccion = DB::selectOne(
        'SELECT ST_Distance(ST_GeomFromText(:l, 4326), ST_GeomFromText(:p, 4326)) AS distancia,
                ST_Contains(ST_GeomFromText(:l2, 4326), ST_GeomFromText(:p2, 4326)) AS dentro',
        [
            'l' => 'LINESTRING(-60.08 -33.50,-60.06 -33.45)', 'p' => 'POINT(-60.07 -33.475)',
            'l2' => 'LINESTRING(-60.08 -33.50,-60.06 -33.45)', 'p2' => 'POINT(-60.07 -33.475)',
        ],
    );

    expect((float) $contradiccion->distancia)->toBe(0.0)
        ->and((bool) $contradiccion->dentro)->toBeFalse();
});

it('mide la longitud de la línea en metros y registra el método', function () {
    $geometria = WorkGeometry::desdeGeoJson([
        'type' => 'LineString',
        // Un grado de latitud ≈ 110,9 km; este tramo es de una centésima.
        'coordinates' => [[-60.0575, -33.5872], [-60.0575, -33.5772]],
    ], subcategoriaConModo(WorkSubcategory::MODE_LINE_ROUTED_ROAD));

    expect($geometria->lengthMeters)->toBeGreaterThan(1_100.0)->toBeLessThan(1_120.0)
        ->and($geometria->lengthCalcMethod)->toBe('VINCENTY');
});

it('no le pone longitud ni método a lo que no es una línea', function (string $modo, string $tipo, array $coordenadas) {
    $geometria = WorkGeometry::desdeGeoJson(
        ['type' => $tipo, 'coordinates' => $coordenadas],
        subcategoriaConModo($modo),
    );

    expect($geometria->lengthMeters)->toBeNull()
        ->and($geometria->lengthCalcMethod)->toBeNull();
})->with([
    [WorkSubcategory::MODE_POINT, 'Point', [-60.0575, -33.5872]],
    [WorkSubcategory::MODE_POLYGON, 'Polygon', [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.06, -33.59]]]],
]);

it('toma el vértice más cercano a la mitad del RECORRIDO, no al medio de la lista', function () {
    // Cinco vértices, cuatro de ellos apretados al principio: el vértice «del
    // medio» por índice es el tercero, que está a pocos metros del arranque. La
    // mitad del recorrido está mucho más adelante.
    $geometria = WorkGeometry::desdeGeoJson([
        'type' => 'LineString',
        'coordinates' => [
            [-60.0600, -33.5872],
            [-60.0599, -33.5872],
            [-60.0598, -33.5872],
            [-60.0597, -33.5872],
            [-60.0000, -33.5872],
        ],
    ], subcategoriaConModo(WorkSubcategory::MODE_LINE_MANUAL_NETWORK));

    // Ninguno de los cinco está cerca de la mitad geométrica, así que gana el
    // menos malo: el último vértice antes del tramo largo o el final. Lo que se
    // verifica es que NO eligió el tercero por ser el del medio de la lista.
    expect($geometria->representativePointWkt)->not->toContain('-60.0598000000');
});

/*
|---------------------------------------------------------------------------
| Las reglas que se rechazan antes de llegar al motor
|---------------------------------------------------------------------------
*/

it('rechaza una geometría cuyo tipo no es el de la subcategoría', function () {
    $subcategoria = subcategoriaConModo(WorkSubcategory::MODE_POINT);
    $subcategoria->name = 'Bacheo';

    expect(fn () => WorkGeometry::desdeGeoJson(
        ['type' => 'LineString', 'coordinates' => [[-60.05, -33.58], [-60.04, -33.57]]],
        $subcategoria,
    ))->toThrow(GeometryRuleViolation::class, 'se dibuja como un punto');
});

it('acepta cualquiera de los dos modos de línea con la misma LineString', function (string $modo) {
    $geometria = WorkGeometry::desdeGeoJson([
        'type' => 'LineString',
        'coordinates' => [[-60.05, -33.58], [-60.04, -33.57]],
    ], subcategoriaConModo($modo));

    // Es la contracara de la excepción de inmutabilidad de F1-A: los dos modos
    // de línea son intercambiables porque persisten exactamente lo mismo.
    expect($geometria->tipo)->toBe('LineString');
})->with(WorkSubcategory::LINE_MODES);

it('atrapa los ejes invertidos cuando la latitud se sale de rango', function () {
    // Una longitud de −120 pasada como latitud excede los 90 grados y se cae acá.
    expect(fn () => WorkGeometry::desdeGeoJson(
        ['type' => 'Point', 'coordinates' => [-33.5872, -120.4567]],
        subcategoriaConModo(WorkSubcategory::MODE_POINT),
    ))->toThrow(GeometryRuleViolation::class, 'fuera del rango');
});

it('NO puede atrapar por rango una inversión a la latitud de Ramallo', function () {
    // Este test documenta un límite real, medido, en lugar de simular una
    // protección que no existe: invertir los ejes de un punto de Ramallo produce
    // [-33.5872, -60.0575], y −60 es una latitud PERFECTAMENTE VÁLIDA —cae en el
    // pasaje de Drake—. Ninguna verificación de rango puede distinguirla.
    $geometria = WorkGeometry::desdeGeoJson(
        ['type' => 'Point', 'coordinates' => [-33.5872, -60.0575]],
        subcategoriaConModo(WorkSubcategory::MODE_POINT),
    );

    expect($geometria->wkt)->toContain('-33.5872');

    // Lo que sí la distingue es el territorio: el punto invertido cae fuera del
    // partido. La verificación contra los límites municipales es de F3; hasta
    // entonces, esta es la línea que NO está cubierta, y conviene que esté
    // escrita como tal y no ausente.
    [$lonMin, $latMin, $lonMax, $latMax] = config('obras.mapa.bbox');
    $dentroDelBbox = $lonMin <= -33.5872 && $lonMax >= -33.5872
        && $latMin <= -60.0575 && $latMax >= -60.0575;

    expect($dentroDelBbox)->toBeFalse();
});

it('rechaza una geometría vacía', function () {
    expect(fn () => WorkGeometry::desdeGeoJson(
        ['type' => 'LineString', 'coordinates' => []],
        subcategoriaConModo(WorkSubcategory::MODE_LINE_MANUAL_NETWORK),
    ))->toThrow(GeometryRuleViolation::class, 'llegó vacía');
});

it('rechaza una línea de un solo punto', function () {
    expect(fn () => WorkGeometry::desdeGeoJson(
        ['type' => 'LineString', 'coordinates' => [[-60.05, -33.58]]],
        subcategoriaConModo(WorkSubcategory::MODE_LINE_MANUAL_NETWORK),
    ))->toThrow(GeometryRuleViolation::class, 'al menos dos puntos');
});

it('rechaza dos vértices consecutivos iguales', function () {
    // El clic repetido del editor. Sin esta regla queda un tramo de longitud
    // cero, que no rompe nada pero ensucia la métrica de RF-GEO-011.
    expect(fn () => WorkGeometry::desdeGeoJson([
        'type' => 'LineString',
        'coordinates' => [[-60.05, -33.58], [-60.05, -33.58], [-60.04, -33.57]],
    ], subcategoriaConModo(WorkSubcategory::MODE_LINE_MANUAL_NETWORK)))
        ->toThrow(GeometryRuleViolation::class, 'consecutivos iguales');
});

it('rechaza un polígono que no cierra', function () {
    expect(fn () => WorkGeometry::desdeGeoJson([
        'type' => 'Polygon',
        'coordinates' => [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.055, -33.585]]],
    ], subcategoriaConModo(WorkSubcategory::MODE_POLYGON)))
        ->toThrow(GeometryRuleViolation::class, 'no cierra');
});

it('rechaza un polígono con menos de tres vértices distintos', function () {
    expect(fn () => WorkGeometry::desdeGeoJson([
        'type' => 'Polygon',
        'coordinates' => [[[-60.06, -33.59], [-60.05, -33.59], [-60.06, -33.59]]],
    ], subcategoriaConModo(WorkSubcategory::MODE_POLYGON)))
        ->toThrow(GeometryRuleViolation::class, 'al menos tres vértices');
});

it('conserva un polígono con hueco y lo deja válido para el motor', function () {
    $geometria = WorkGeometry::desdeGeoJson([
        'type' => 'Polygon',
        'coordinates' => [
            [[-60.08, -33.50], [-60.04, -33.50], [-60.04, -33.46], [-60.08, -33.46], [-60.08, -33.50]],
            [[-60.07, -33.49], [-60.06, -33.49], [-60.06, -33.48], [-60.07, -33.48], [-60.07, -33.49]],
        ],
    ], subcategoriaConModo(WorkSubcategory::MODE_POLYGON));

    // Dos anillos en el WKT y el punto interior fuera del hueco: la verificación
    // compuesta de ADR-010, aplicada a una geometría de obra.
    expect(substr_count($geometria->wkt, '('))->toBe(3)
        ->and(elMotorLoContiene($geometria))->toBeTrue();
});

it('escribe coordenadas sin notación científica', function () {
    // Un valor muy chico en notación científica produce un WKT que el motor
    // rechaza. Con `%.10F` no ocurre nunca; con `%s` o `(string)` sí.
    $geometria = WorkGeometry::desdeGeoJson(
        ['type' => 'Point', 'coordinates' => [0.0000001, -33.5872]],
        subcategoriaConModo(WorkSubcategory::MODE_POINT),
    );

    expect($geometria->wkt)->not->toContain('E-')->not->toContain('e-');

    $srid = DB::selectOne('SELECT ST_SRID(ST_GeomFromText(:wkt, 4326)) AS r', ['wkt' => $geometria->wkt])->r;

    expect((int) $srid)->toBe(4326);
});

it('rechaza una coordenada que no es un par de números', function () {
    expect(fn () => WorkGeometry::desdeGeoJson(
        ['type' => 'Point', 'coordinates' => ['x', 'y']],
        subcategoriaConModo(WorkSubcategory::MODE_POINT),
    ))->toThrow(GeometryRuleViolation::class, 'mal formada');
});
