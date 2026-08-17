<?php

declare(strict_types=1);

namespace App\Support\Geo;

use InvalidArgumentException;
use Location\Coordinate;
use Location\Ellipsoid;
use Location\Polyline;

/**
 * Única frontera entre la convención canónica del sistema y la de phpgeo.
 *
 * El sistema usa `[longitud, latitud]` en todas sus capas (RFC 7946). phpgeo
 * invierte el orden: `new Coordinate(float $lat, float $lng)`. Esa inversión es
 * el lugar exacto donde se cuelan los errores de ejes, así que se cruza acá y
 * en ningún otro archivo.
 *
 * Un test de arquitectura falla el build si `Location\Coordinate` se instancia
 * fuera de esta clase.
 */
final class GeoJsonPhpGeoAdapter
{
    private readonly Ellipsoid $ellipsoid;

    public function __construct(?Ellipsoid $ellipsoid = null)
    {
        $this->ellipsoid = $ellipsoid ?? Ellipsoid::createDefault('WGS-84');
    }

    /**
     * Construye una coordenada de phpgeo desde el par canónico [lon, lat].
     *
     * El nombre dice el orden de los argumentos, para que la llamada se lea
     * sola y una inversión salte a la vista en la revisión.
     */
    public function coordinateFromLonLat(float $lon, float $lat): Coordinate
    {
        $this->assertLonLatInRange($lon, $lat);

        // phpgeo: latitud primero. Esta es la única línea del sistema que invierte.
        return new Coordinate($lat, $lon, $this->ellipsoid);
    }

    /**
     * Devuelve el par canónico [lon, lat] desde una coordenada de phpgeo.
     *
     * @return array{0: float, 1: float}
     */
    public function toLonLatPair(Coordinate $coordinate): array
    {
        return [$coordinate->getLng(), $coordinate->getLat()];
    }

    /**
     * Construye una polilínea de phpgeo desde coordenadas GeoJSON [[lon, lat], ...].
     *
     * @param  list<array{0?: float|int, 1?: float|int}>  $lonLatPairs
     */
    public function polylineFromGeoJsonCoordinates(array $lonLatPairs): Polyline
    {
        if (count($lonLatPairs) < 2) {
            throw new InvalidArgumentException(
                'Una línea requiere al menos dos vértices; se recibieron '.count($lonLatPairs).'.',
            );
        }

        $polyline = new Polyline;

        foreach ($lonLatPairs as $index => $pair) {
            if (! isset($pair[0], $pair[1])) {
                throw new InvalidArgumentException(
                    "El vértice en la posición {$index} no es un par [lon, lat].",
                );
            }

            $polyline->addPoint($this->coordinateFromLonLat((float) $pair[0], (float) $pair[1]));
        }

        return $polyline;
    }

    /**
     * Devuelve las coordenadas GeoJSON [[lon, lat], ...] de una polilínea.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function toGeoJsonCoordinates(Polyline $polyline): array
    {
        return array_map(
            fn (Coordinate $point): array => $this->toLonLatPair($point),
            $polyline->getPoints(),
        );
    }

    public function ellipsoid(): Ellipsoid
    {
        return $this->ellipsoid;
    }

    private function assertLonLatInRange(float $lon, float $lat): void
    {
        if ($lon < -180.0 || $lon > 180.0) {
            throw new InvalidArgumentException(
                "La longitud {$lon} está fuera del rango [-180, 180]. ¿Se pasaron los ejes invertidos?",
            );
        }

        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException(
                "La latitud {$lat} está fuera del rango [-90, 90]. ¿Se pasaron los ejes invertidos?",
            );
        }
    }
}
