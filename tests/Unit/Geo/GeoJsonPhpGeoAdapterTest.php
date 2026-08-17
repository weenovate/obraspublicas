<?php

declare(strict_types=1);

use App\Support\Geo\GeoJsonPhpGeoAdapter;

/*
| El adaptador es la única frontera con phpgeo, que invierte la convención de
| ejes. Los fixtures son ASIMÉTRICOS a propósito: longitud ≈ -60 y latitud ≈ -33
| son inconfundibles, así que un intercambio rompe la aserción en vez de
| compensarse. Con valores simétricos (por ejemplo -33 y -33) un bug de ejes
| pasaría los tests.
*/

const LON = -60.123456;
const LAT = -33.487654;

it('construye la coordenada con la longitud primero y la latitud segunda', function () {
    $coordinate = (new GeoJsonPhpGeoAdapter)->coordinateFromLonLat(LON, LAT);

    // phpgeo guarda latitud primero: si el adaptador se equivoca, estas dos
    // aserciones fallan al mismo tiempo y el mensaje dice exactamente qué pasó.
    expect($coordinate->getLng())->toBe(LON)
        ->and($coordinate->getLat())->toBe(LAT);
});

it('vuelve al par canónico [lon, lat] sin perder nada', function () {
    $adapter = new GeoJsonPhpGeoAdapter;

    [$lon, $lat] = $adapter->toLonLatPair($adapter->coordinateFromLonLat(LON, LAT));

    expect($lon)->toBe(LON)->and($lat)->toBe(LAT);
});

it('construye una polilínea conservando el orden de los vértices', function () {
    $adapter = new GeoJsonPhpGeoAdapter;
    $coordinates = [[-60.20, -33.50], [-60.10, -33.50], [-60.10, -33.40]];

    $polyline = $adapter->polylineFromGeoJsonCoordinates($coordinates);

    expect($adapter->toGeoJsonCoordinates($polyline))->toBe($coordinates);
});

it('rechaza una longitud fuera de rango, que es como se ve un intercambio de ejes', function () {
    // Si alguien pasa (lat, lon) en lugar de (lon, lat) con estas coordenadas, la
    // latitud -60.12 sigue siendo válida pero la longitud -33.48 también, así que
    // el rango no alcanza para atrapar todos los casos: por eso además existe el
    // test de arquitectura que prohíbe instanciar Coordinate fuera del adaptador.
    // Lo que sí se atrapa es el caso grosero.
    expect(fn () => (new GeoJsonPhpGeoAdapter)->coordinateFromLonLat(-200.0, -33.5))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new GeoJsonPhpGeoAdapter)->coordinateFromLonLat(-60.1, -95.0))
        ->toThrow(InvalidArgumentException::class);
});

it('rechaza una línea de menos de dos vértices', function () {
    expect(fn () => (new GeoJsonPhpGeoAdapter)->polylineFromGeoJsonCoordinates([[-60.1, -33.4]]))
        ->toThrow(InvalidArgumentException::class);
});
