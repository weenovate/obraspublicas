<?php

declare(strict_types=1);

namespace Poc\Lib;

/**
 * Oráculo de distancias geodésicas, independiente de phpgeo.
 *
 * DESVIACIÓN RESPECTO DEL PLAN, DELIBERADA Y DOCUMENTADA
 * ------------------------------------------------------
 * El plan preveía contrastar Vincenty contra «los vectores publicados de
 * Vincenty (1975)». Esos vectores existen, pero las tablas del paper usan
 * elipsoides que no son WGS-84 (Hayford / Bessel según el caso) y este entorno
 * no tiene acceso de red a la fuente ni a `geographiclib`/`pyproj` para
 * regenerarlos. Transcribir de memoria números que no puedo verificar sería
 * peor que no tener oráculo: un test que compara contra una constante
 * equivocada da falsos rojos o, peor, falsos verdes.
 *
 * Por eso el oráculo es analítico y comprobable acá mismo, sobre WGS-84:
 *
 *   1. Arco ecuatorial: la geodésica entre dos puntos de latitud 0 es el propio
 *      ecuador, un círculo de radio `a`. La distancia es exactamente a·Δλ.
 *      Forma cerrada, sin aproximación.
 *   2. Arco meridiano: la geodésica entre dos puntos de igual longitud es el
 *      meridiano. Su longitud es la integral del radio de curvatura meridional
 *      M(φ) = a(1−e²)/(1−e²·sen²φ)^{3/2}, que se evalúa por cuadratura de
 *      Simpson compuesta con paso tan fino que el error de truncamiento queda
 *      muchos órdenes de magnitud por debajo del milímetro.
 *
 * Estos dos casos son justamente los que atrapan los errores que importan:
 * ejes invertidos, grados usados como radianes, semieje o achatamiento mal
 * cargados, y confusión metros/kilómetros. Todos se manifiestan en órdenes de
 * magnitud, no en milímetros.
 *
 * Para líneas oblicuas no hay forma cerrada, así que se usa un control más
 * grueso: la distancia esférica sobre el radio medio, que debe coincidir con
 * Vincenty dentro del ~0,5 % esperable entre esfera y elipsoide. No prueba
 * exactitud milimétrica, pero descarta errores gruesos.
 */
final class GeodesicOracle
{
    /** Semieje mayor WGS-84, en metros. */
    public const WGS84_A = 6378137.0;

    /** Inverso del achatamiento WGS-84. */
    public const WGS84_INV_F = 298.257223563;

    /**
     * Longitud exacta del arco de ecuador entre dos longitudes, en metros.
     *
     * La geodésica sobre el ecuador es el ecuador mismo: círculo de radio `a`.
     */
    public static function equatorArcMeters(
        float $lonFrom,
        float $lonTo,
        float $a = self::WGS84_A
    ): float {
        $deltaLon = abs($lonTo - $lonFrom);

        // Se toma el arco menor, igual que hace un cálculo de distancia.
        if ($deltaLon > 180.0) {
            $deltaLon = 360.0 - $deltaLon;
        }

        return $a * deg2rad($deltaLon);
    }

    /**
     * Longitud del arco de meridiano entre dos latitudes, en metros.
     *
     * Integra M(φ) = a(1−e²)/(1−e²·sen²φ)^{3/2} por Simpson compuesta.
     * Con $intervals = 200000 el error de truncamiento es del orden de 1e-20 m
     * para un integrando tan suave: irrelevante frente al milímetro.
     */
    public static function meridianArcMeters(
        float $latFrom,
        float $latTo,
        float $a = self::WGS84_A,
        float $invF = self::WGS84_INV_F,
        int $intervals = 200000
    ): float {
        if ($intervals % 2 !== 0) {
            $intervals++; // Simpson requiere un número par de subintervalos.
        }

        $f = 1.0 / $invF;
        $eSquared = $f * (2.0 - $f); // e² = 2f − f²

        $from = deg2rad(min($latFrom, $latTo));
        $to = deg2rad(max($latFrom, $latTo));

        if ($from === $to) {
            return 0.0;
        }

        $h = ($to - $from) / $intervals;

        $meridionalRadius = static function (float $phi) use ($a, $eSquared): float {
            $s = sin($phi);
            $w = 1.0 - $eSquared * $s * $s;

            return $a * (1.0 - $eSquared) / ($w * sqrt($w));
        };

        $sum = $meridionalRadius($from) + $meridionalRadius($to);

        for ($i = 1; $i < $intervals; $i++) {
            $phi = $from + $i * $h;
            $sum += $meridionalRadius($phi) * ($i % 2 === 0 ? 2.0 : 4.0);
        }

        return $sum * $h / 3.0;
    }

    /**
     * Distancia esférica (gran círculo) sobre el radio medio del elipsoide.
     *
     * Control grueso para líneas oblicuas: no da exactitud milimétrica, pero
     * detecta errores de orden de magnitud, de unidad y de ejes.
     */
    public static function sphericalApproxMeters(
        float $lonFrom,
        float $latFrom,
        float $lonTo,
        float $latTo,
        float $a = self::WGS84_A,
        float $invF = self::WGS84_INV_F
    ): float {
        $f = 1.0 / $invF;
        $b = $a * (1.0 - $f);
        // Radio medio aritmético, la misma definición que usa phpgeo.
        $radius = ($a + $a + $b) / 3.0;

        $phi1 = deg2rad($latFrom);
        $phi2 = deg2rad($latTo);
        $deltaPhi = $phi2 - $phi1;
        $deltaLambda = deg2rad($lonTo - $lonFrom);

        $sinHalfPhi = sin($deltaPhi / 2.0);
        $sinHalfLambda = sin($deltaLambda / 2.0);

        $h = $sinHalfPhi * $sinHalfPhi
            + cos($phi1) * cos($phi2) * $sinHalfLambda * $sinHalfLambda;

        return 2.0 * $radius * asin(min(1.0, sqrt($h)));
    }
}
