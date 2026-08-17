<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Round-trip de coordenadas contra el motor real, permanente en la suite.
|
| Las sondas de G2 midieron esto una vez para llenar la matriz; estos tests lo
| dejan fijo, de modo que una regresión —un binding cambiado, un ST_X por ST_Y—
| rompa el build en lugar de aparecer como marcadores desplazados en el mapa.
|
| Convención canónica: POINT(lon lat), X = longitud, Y = latitud. Los fixtures son
| asimétricos a propósito.
*/

const RT_LON = -60.123456;
const RT_LAT = -33.487654;

beforeEach(function () {
    Schema::dropIfExists('rt_geometrias');

    // Se crea con DDL crudo, sin atributo SRID de columna: MariaDB no admite la
    // sintaxis `SRID 4326` de MySQL 8, y su `REF_SYSTEM_ID` se acepta pero NO
    // rechaza un SRID distinto (medido en P2). El 4326 lo impone la aplicación.
    DB::statement('CREATE TABLE rt_geometrias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        geometry GEOMETRY NOT NULL,
        representative_point POINT NOT NULL,
        SPATIAL INDEX idx_geometry (geometry),
        SPATIAL INDEX idx_representative_point (representative_point)
    ) ENGINE=InnoDB');
});

afterEach(function () {
    Schema::dropIfExists('rt_geometrias');
});

function insertarGeometria(string $wkt, ?string $representative = null): void
{
    // WKT y SRID SIEMPRE por binding, nunca interpolados (RNF-SEC-003).
    DB::insert(
        'INSERT INTO rt_geometrias (geometry, representative_point)
         VALUES (ST_GeomFromText(?, ?), ST_GeomFromText(?, ?))',
        [$wkt, 4326, $representative ?? $wkt, 4326],
    );
}

it('devuelve la longitud por ST_X y la latitud por ST_Y', function () {
    insertarGeometria(sprintf('POINT(%.6f %.6f)', RT_LON, RT_LAT));

    $row = DB::selectOne('SELECT ST_X(geometry) AS lon, ST_Y(geometry) AS lat FROM rt_geometrias');

    // MariaDB no tiene ST_Longitude/ST_Latitude: la semántica de los ejes se
    // sostiene con este test, no con el nombre de la función.
    expect((float) $row->lon)->toBe(RT_LON)
        ->and((float) $row->lat)->toBe(RT_LAT);
});

it('conserva el SRID 4326 en el valor', function () {
    insertarGeometria(sprintf('POINT(%.6f %.6f)', RT_LON, RT_LAT));

    expect((int) DB::scalar('SELECT ST_SRID(geometry) FROM rt_geometrias'))->toBe(4326);
});

it('completa el round-trip GeoJSON sin mover un decimal', function () {
    insertarGeometria(sprintf('POINT(%.6f %.6f)', RT_LON, RT_LAT));

    $geojson = json_decode(
        (string) DB::scalar('SELECT ST_AsGeoJSON(geometry) FROM rt_geometrias'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // GeoJSON es [lon, lat] por RFC 7946: el mismo orden que usa el sistema.
    expect($geojson['type'])->toBe('Point')
        ->and($geojson['coordinates'])->toBe([RT_LON, RT_LAT]);
});

it('mantiene el invariante ST_Contains(geometry, representative_point) en los tres tipos', function (string $wkt) {
    // El invariante no negociable del plan: si el punto representativo no cae
    // dentro de la geometría, el guardado se rechaza. Vale también para líneas.
    insertarGeometria($wkt, DB::scalar('SELECT ST_AsText(ST_PointOnSurface(ST_GeomFromText(?, 4326)))', [$wkt])
        ?? $wkt);

    expect((int) DB::scalar('SELECT ST_Contains(geometry, representative_point) FROM rt_geometrias'))
        ->toBe(1);
})->with([
    'punto' => ['POINT(-60.123456 -33.487654)'],
    'polígono' => ['POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50))'],
    'polígono con hueco' => ['POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.18 -33.48, -60.12 -33.48, -60.12 -33.42, -60.18 -33.42, -60.18 -33.48))'],
]);

it('el punto representativo de una línea cae sobre la línea', function () {
    // ST_PointOnSurface no aplica a líneas y ST_LineInterpolatePoint no existe en
    // 10.11.18 (medido en P5), así que el punto medio se calcula en PHP. Acá se
    // verifica el invariante con un vértice, que es lo que garantiza contención.
    $wkt = 'LINESTRING(-60.20 -33.50, -60.10 -33.50, -60.10 -33.40)';
    insertarGeometria($wkt, 'POINT(-60.10 -33.50)');

    expect((int) DB::scalar('SELECT ST_Contains(geometry, representative_point) FROM rt_geometrias'))
        ->toBe(1);
});

it('usa el índice espacial en los dos modos de consulta', function (string $column) {
    insertarGeometria('POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50))',
        'POINT(-60.15 -33.45)');

    $envelope = "ST_GeomFromText('POLYGON((-60.25 -33.55, -60.05 -33.55, -60.05 -33.35, -60.25 -33.35, -60.25 -33.55))', 4326)";
    $plan = DB::select("EXPLAIN SELECT COUNT(*) FROM rt_geometrias WHERE MBRIntersects({$column}, {$envelope})");

    // Un índice creado pero ignorado no cumple RNF-PER-001: se asegura que el
    // plan lo use, no sólo que exista.
    expect($plan[0]->key ?? null)->toContain('idx_');
})->with([
    'clustering por representative_point' => ['representative_point'],
    'geometría visible por geometry' => ['geometry'],
]);

it('el motor no protege contra mezclar SRID, así que la aplicación tiene que validarlo', function () {
    // Medido en P10: el predicado con SRID 0 y 4326 se acepta en silencio. Este
    // test documenta el comportamiento para que, si una versión futura empieza a
    // rechazarlo, se note.
    $resultado = DB::scalar(
        "SELECT ST_Contains(
            ST_GeomFromText('POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50))', 0),
            ST_GeomFromText('POINT(-60.15 -33.45)', 4326)
        )",
    );

    expect((int) $resultado)->toBe(1);
});
