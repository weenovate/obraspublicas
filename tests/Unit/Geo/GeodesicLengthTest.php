<?php

declare(strict_types=1);

use App\Support\Geo\GeodesicLength;
use App\Support\Geo\GeoJsonPhpGeoAdapter;
use Location\Distance\Vincenty;
use Poc\Lib\GeodesicOracle;

require_once __DIR__.'/../../../poc/lib/GeodesicOracle.php';

/*
| Dos tolerancias, y se reportan por separado a propósito:
|
|   CONFORMIDAD ALGORÍTMICA (±1 mm) — Vincenty contra un oráculo independiente.
|   Un fallo acá apunta a la librería o al adaptador.
|
|   CONFORMIDAD FUNCIONAL (max(0,10 m; 0,05 %)) — el valor que se persiste,
|   redondeado al centímetro. Un fallo acá con la algorítmica en verde apunta al
|   redondeo o a la agregación.
|
| Sobre el oráculo: es analítico, no las tablas publicadas de Vincenty (1975).
| Ver la nota extensa en poc/lib/GeodesicOracle.php y en docs/MATRIZ-ESPACIAL.md.
*/

const TOLERANCIA_ALGORITMICA_METROS = 0.001;

it('coincide con el arco de ecuador en forma cerrada, dentro de 1 mm', function (float $lonA, float $lonB) {
    $adapter = new GeoJsonPhpGeoAdapter;
    $vincenty = new Vincenty;

    $medido = $vincenty->getDistance(
        $adapter->coordinateFromLonLat($lonA, 0.0),
        $adapter->coordinateFromLonLat($lonB, 0.0),
    );

    expect(abs($medido - GeodesicOracle::equatorArcMeters($lonA, $lonB)))
        ->toBeLessThanOrEqual(TOLERANCIA_ALGORITMICA_METROS);
})->with([
    [0.0, 1.0],
    [-60.5, -60.0],
    [-60.0, -59.0],
]);

it('coincide con el arco de meridiano por cuadratura, dentro de 1 mm', function (float $latA, float $latB) {
    $adapter = new GeoJsonPhpGeoAdapter;
    $vincenty = new Vincenty;

    $medido = $vincenty->getDistance(
        $adapter->coordinateFromLonLat(-60.123456, $latA),
        $adapter->coordinateFromLonLat(-60.123456, $latB),
    );

    expect(abs($medido - GeodesicOracle::meridianArcMeters($latA, $latB)))
        ->toBeLessThanOrEqual(TOLERANCIA_ALGORITMICA_METROS);
})->with([
    [-33.50, -33.40],
    [-34.00, -33.00],
    [-33.4876, -33.1234],
    [-40.00, -30.00],
]);

it('es simétrica: la distancia de ida es la de vuelta', function () {
    $adapter = new GeoJsonPhpGeoAdapter;
    $vincenty = new Vincenty;

    $a = $adapter->coordinateFromLonLat(-60.20, -33.50);
    $b = $adapter->coordinateFromLonLat(-60.10, -33.40);

    expect(abs($vincenty->getDistance($a, $b) - $vincenty->getDistance($b, $a)))
        ->toBeLessThanOrEqual(1e-9);
});

it('los fixtures asimétricos delatan un intercambio de ejes', function () {
    $adapter = new GeoJsonPhpGeoAdapter;
    $vincenty = new Vincenty;

    $correcta = $vincenty->getDistance(
        $adapter->coordinateFromLonLat(-60.20, -33.50),
        $adapter->coordinateFromLonLat(-60.10, -33.40),
    );

    $invertida = $vincenty->getDistance(
        $adapter->coordinateFromLonLat(-33.50, -60.20),
        $adapter->coordinateFromLonLat(-33.40, -60.10),
    );

    // Si la diferencia fuera pequeña, el fixture no serviría como red: este test
    // verifica la calidad del fixture, no sólo el resultado.
    expect(abs($correcta - $invertida))->toBeGreaterThan(1.0);
});

it('cumple la conformidad funcional de length_m sobre un segmento meridiano', function () {
    $referencia = GeodesicOracle::meridianArcMeters(-33.50, -33.40);
    $tolerancia = max(0.10, 0.0005 * $referencia);

    $resultado = (new GeodesicLength)->forLineString([[-60.20, -33.50], [-60.20, -33.40]]);

    expect(abs($resultado['meters'] - $referencia))->toBeLessThanOrEqual($tolerancia)
        ->and($resultado['method'])->toBe(GeodesicLength::METHOD_VINCENTY)
        ->and($resultado['fallback_segments'])->toBe(0);
});

it('suma los segmentos de una polilínea', function () {
    $lengths = new GeodesicLength;

    $tramoA = $lengths->betweenLonLat(-60.20, -33.50, -60.10, -33.50);
    $tramoB = $lengths->betweenLonLat(-60.10, -33.50, -60.10, -33.40);
    $completa = $lengths->forLineString([[-60.20, -33.50], [-60.10, -33.50], [-60.10, -33.40]]);

    expect(abs($completa['meters'] - ($tramoA['meters'] + $tramoB['meters'])))
        ->toBeLessThanOrEqual(0.02); // dos redondeos al centímetro
});

it('cae a Haversine sin converger y lo deja registrado en el método', function () {
    // Puntos casi antipodales: a escala municipal no puede pasar, y por eso una
    // ocurrencia es una anomalía que hay que poder ver. Nunca se guarda un
    // resultado no convergido como si fuera exacto.
    $resultado = (new GeodesicLength)->betweenLonLat(0.0, 0.0, 179.99999, 0.0);

    expect($resultado['method'])->toBe(GeodesicLength::METHOD_HAVERSINE_FALLBACK)
        ->and($resultado['fallback_segments'])->toBe(1)
        ->and($resultado['meters'])->toBeGreaterThan(0.0);
});
