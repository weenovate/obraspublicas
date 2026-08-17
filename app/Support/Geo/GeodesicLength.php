<?php

declare(strict_types=1);

namespace App\Support\Geo;

use Location\Distance\Haversine;
use Location\Distance\Vincenty;
use Location\Exception\NotConvergingException;

/**
 * Longitud geodésica de una línea, calculada en PHP.
 *
 * Regla de arquitectura: la base de datos hace persistencia, indexado y
 * topología planar; toda métrica geodésica se calcula acá. MariaDB trata las
 * coordenadas como un plano cartesiano, así que `ST_Length` sobre lon/lat
 * devuelve grados, no metros (ver P8 en docs/MATRIZ-ESPACIAL.md).
 *
 * Algoritmo: solución inversa de Vincenty sobre WGS-84, segmento por segmento.
 * Vincenty no converge para puntos casi antipodales; a escala municipal no
 * debería ocurrir nunca, y por eso una ocurrencia es una anomalía que hay que
 * poder ver: se cae a Haversine y se persiste el método usado.
 */
final class GeodesicLength
{
    public const METHOD_VINCENTY = 'VINCENTY';

    public const METHOD_HAVERSINE_FALLBACK = 'HAVERSINE_FALLBACK';

    public function __construct(
        private readonly GeoJsonPhpGeoAdapter $adapter = new GeoJsonPhpGeoAdapter,
        private readonly Vincenty $vincenty = new Vincenty,
        private readonly Haversine $haversine = new Haversine,
    ) {}

    /**
     * Calcula la longitud en metros de una línea GeoJSON [[lon, lat], ...].
     *
     * Devuelve también el método efectivamente usado, que debe persistirse en
     * `works.length_calc_method`: nunca se guarda un resultado no convergido
     * como si fuera exacto.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $lonLatPairs
     * @return array{meters: float, method: string, fallback_segments: int}
     */
    public function forLineString(array $lonLatPairs): array
    {
        $polyline = $this->adapter->polylineFromGeoJsonCoordinates($lonLatPairs);
        $points = $polyline->getPoints();

        $meters = 0.0;
        $fallbackSegments = 0;

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $from = $points[$i - 1];
            $to = $points[$i];

            try {
                $meters += $this->vincenty->getDistance($from, $to);
            } catch (NotConvergingException) {
                // Segmento casi antipodal. Se degrada de forma visible, no silenciosa.
                $meters += $this->haversine->getDistance($from, $to);
                $fallbackSegments++;
            }
        }

        return [
            'meters' => round($meters, 2),
            'method' => $fallbackSegments > 0 ? self::METHOD_HAVERSINE_FALLBACK : self::METHOD_VINCENTY,
            'fallback_segments' => $fallbackSegments,
        ];
    }

    /**
     * Distancia entre dos puntos en metros, con el mismo contrato de fallback.
     *
     * @return array{meters: float, method: string, fallback_segments: int}
     */
    public function betweenLonLat(float $fromLon, float $fromLat, float $toLon, float $toLat): array
    {
        return $this->forLineString([[$fromLon, $fromLat], [$toLon, $toLat]]);
    }
}
