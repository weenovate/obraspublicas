<?php

declare(strict_types=1);

namespace App\Support\Work;

use App\Models\WorkSubcategory;
use App\Support\Geo\GeodesicLength;
use App\Support\Geo\GeoJsonPhpGeoAdapter;
use InvalidArgumentException;

/**
 * La geometría de una obra, ya validada y lista para persistir (RF-GEO-005/014).
 *
 * Recibe GeoJSON —`[longitud, latitud]`, RFC 7946, la convención canónica del
 * proyecto (ADR-003)— y devuelve el WKT, el punto representativo y, para las
 * líneas, la longitud geodésica con el método que se usó.
 *
 * TRES DECISIONES, Y LAS TRES SALEN DE MEDIR EL MOTOR, NO DE SUPONERLO:
 *
 *   1. EL PUNTO REPRESENTATIVO DE UNA LÍNEA ES UN VÉRTICE SUYO. En MariaDB
 *      10.11.18 `ST_PointOnSurface` y `ST_Centroid` devuelven NULL sobre
 *      `LINESTRING`, así que el motor no puede darlo. Y calcularlo a mano es
 *      peor de lo que parece: sobre 200 segmentos medidos, el punto medio
 *      aritmético quedó contenido en la línea sólo 54 veces, y un vértice las
 *      200. En los casos que fallan el motor llega a contradecirse —`ST_Distance`
 *      devuelve exactamente 0 y `ST_Contains` e `ST_Intersects` devuelven falso
 *      igual—, porque la división por dos redondea en binario y el punto que
 *      resulta no es ninguno de los que el predicado reconoce sobre el segmento.
 *      Un vértice, en cambio, es el mismo double que ya está guardado. Se elige
 *      el más cercano a la mitad del recorrido.
 *
 *   2. EL DE UN POLÍGONO ES `ST_PointOnSurface`, que sí existe y sí funciona,
 *      incluso en figuras cóncavas donde el centroide se va afuera (ADR-009).
 *      Lo calcula la base al persistir, así que acá el punto queda en `null` y
 *      `WorkWriter` arma la expresión con el WKT por binding.
 *
 *   3. EL DE UN PUNTO ES EL PUNTO. No hay nada que elegir.
 *
 * El invariante `ST_Contains(geometry, representative_point)` se verifica contra
 * la base antes de confirmar, en `WorkWriter`: acá se elige un candidato con
 * fundamento, allá se comprueba que efectivamente lo cumple.
 */
final class WorkGeometry
{
    /** @var array<string, string> Modo de la subcategoría → tipo GeoJSON exigido */
    private const TIPO_POR_MODO = [
        WorkSubcategory::MODE_POINT => 'Point',
        WorkSubcategory::MODE_LINE_ROUTED_ROAD => 'LineString',
        WorkSubcategory::MODE_LINE_MANUAL_NETWORK => 'LineString',
        WorkSubcategory::MODE_POLYGON => 'Polygon',
    ];

    /** @var array<string, string> Cómo se lee cada modo en un mensaje de error */
    private const NOMBRE_POR_MODO = [
        WorkSubcategory::MODE_POINT => 'un punto',
        WorkSubcategory::MODE_LINE_ROUTED_ROAD => 'una línea',
        WorkSubcategory::MODE_LINE_MANUAL_NETWORK => 'una línea',
        WorkSubcategory::MODE_POLYGON => 'un polígono',
    ];

    /**
     * @param  string  $wkt  Geometría lista para `ST_GeomFromText(..., 4326)`
     * @param  string|null  $representativePointWkt  `POINT` explícito, o `null`
     *                                               si lo calcula la base
     * @param  float|null  $lengthMeters  Sólo líneas
     * @param  string|null  $lengthCalcMethod  `VINCENTY` o `HAVERSINE_FALLBACK`
     */
    private function __construct(
        public readonly string $tipo,
        public readonly string $wkt,
        public readonly ?string $representativePointWkt,
        public readonly ?float $lengthMeters,
        public readonly ?string $lengthCalcMethod,
    ) {}

    /**
     * @param  array<string, mixed>  $geojson  Objeto `geometry` de GeoJSON
     *
     * @throws GeometryRuleViolation
     */
    public static function desdeGeoJson(array $geojson, WorkSubcategory $subcategoria): self
    {
        $esperado = self::TIPO_POR_MODO[$subcategoria->geometry_mode]
            ?? throw new GeometryRuleViolation(
                "La subcategoría «{$subcategoria->name}» tiene un modo geométrico desconocido.",
            );

        $tipo = is_string($geojson['type'] ?? null) ? $geojson['type'] : '';

        if ($tipo !== $esperado) {
            $legible = self::NOMBRE_POR_MODO[$subcategoria->geometry_mode];

            throw new GeometryRuleViolation(
                "«{$subcategoria->name}» se dibuja como {$legible}, y llegó una geometría "
                ."de tipo «{$tipo}». No se convierte sola: cambiar de forma cambia el dato.",
            );
        }

        $coordenadas = $geojson['coordinates'] ?? null;

        if (! is_array($coordenadas) || $coordenadas === []) {
            throw new GeometryRuleViolation('La geometría llegó vacía: hay que dibujarla en el mapa.');
        }

        // Sin rama `default`: llegado acá, `$tipo` es igual a `$esperado`, y
        // `$esperado` sólo puede ser uno de los tres valores de `TIPO_POR_MODO`.
        // Una rama inalcanzable «por las dudas» hace creer que hay un caso más y
        // esconde el día en que se agregue un modo de verdad —ahí este `match`
        // tiene que romper ruidosamente, no devolver un mensaje genérico—.
        return match ($tipo) {
            'Point' => self::punto($coordenadas),
            'LineString' => self::linea($coordenadas),
            'Polygon' => self::poligono($coordenadas),
        };
    }

    /** @param array<int, mixed> $par */
    private static function punto(array $par): self
    {
        $punto = self::parValido($par);
        $wkt = self::wktDelPar($punto);

        return new self('Point', "POINT({$wkt})", "POINT({$wkt})", null, null);
    }

    /** @param array<int, mixed> $pares */
    private static function linea(array $pares): self
    {
        $puntos = array_map(self::parValido(...), array_values($pares));

        if (count($puntos) < 2) {
            throw new GeometryRuleViolation('Una línea necesita al menos dos puntos.');
        }

        if (self::tieneRepetidosConsecutivos($puntos)) {
            throw new GeometryRuleViolation(
                'La línea tiene dos puntos consecutivos iguales. Suele ser un clic repetido: '
                .'borralo antes de guardar, porque un tramo de longitud cero distorsiona el total.',
            );
        }

        $medida = app(GeodesicLength::class)->forLineString($puntos);

        return new self(
            'LineString',
            'LINESTRING('.implode(',', array_map(self::wktDelPar(...), $puntos)).')',
            'POINT('.self::wktDelPar(self::verticeCentral($puntos)).')',
            $medida['meters'],
            $medida['method'],
        );
    }

    /** @param array<int, mixed> $anillos */
    private static function poligono(array $anillos): self
    {
        $anillosValidados = [];

        foreach (array_values($anillos) as $indice => $anillo) {
            if (! is_array($anillo)) {
                throw new GeometryRuleViolation('El polígono tiene un anillo mal formado.');
            }

            $puntos = array_map(self::parValido(...), array_values($anillo));

            // GeoJSON exige el anillo cerrado y con cuatro posiciones como
            // mínimo: tres vértices más la repetición del primero.
            if (count($puntos) < 4) {
                throw new GeometryRuleViolation('Un polígono necesita al menos tres vértices distintos.');
            }

            if ($puntos[0] !== $puntos[count($puntos) - 1]) {
                throw new GeometryRuleViolation(
                    $indice === 0
                        ? 'El polígono no cierra: el último punto tiene que coincidir con el primero.'
                        : 'Uno de los huecos del polígono no cierra.',
                );
            }

            $anillosValidados[] = '('.implode(',', array_map(self::wktDelPar(...), $puntos)).')';
        }

        // Para el polígono el punto interior lo calcula la base con
        // `ST_PointOnSurface`, la función que P7 midió funcionando incluso en
        // formas cóncavas y con huecos. Se devuelve `null` y no la expresión SQL
        // ya armada: el WKT viaja SIEMPRE por binding (RNF-SEC-003), y una clase
        // que devuelve fragmentos de SQL con datos adentro es justo la que hace
        // que un día alguien los concatene.
        return new self('Polygon', 'POLYGON('.implode(',', $anillosValidados).')', null, null, null);
    }

    /** ¿El punto representativo hay que pedírselo a la base en lugar de escribirlo? */
    public function puntoLoCalculaLaBase(): bool
    {
        return $this->representativePointWkt === null;
    }

    /**
     * El vértice más cercano a la mitad del recorrido.
     *
     * Se mide sobre la longitud acumulada y no sobre la cantidad de vértices:
     * una línea con veinte puntos juntos en una esquina y dos en el resto tiene
     * su vértice «número del medio» en la esquina, que no es la mitad de nada.
     *
     * @param  list<array{0: float, 1: float}>  $puntos
     * @return array{0: float, 1: float}
     */
    private static function verticeCentral(array $puntos): array
    {
        $medidor = app(GeodesicLength::class);

        $acumulado = [0.0];
        $total = 0.0;

        for ($i = 1, $n = count($puntos); $i < $n; $i++) {
            $total += $medidor->betweenLonLat(
                $puntos[$i - 1][0], $puntos[$i - 1][1],
                $puntos[$i][0], $puntos[$i][1],
            )['meters'];

            $acumulado[] = $total;
        }

        $mitad = $total / 2;
        $mejor = 0;

        foreach ($acumulado as $indice => $distancia) {
            if (abs($distancia - $mitad) < abs($acumulado[$mejor] - $mitad)) {
                $mejor = $indice;
            }
        }

        return $puntos[$mejor];
    }

    /** @param list<array{0: float, 1: float}> $puntos */
    private static function tieneRepetidosConsecutivos(array $puntos): bool
    {
        for ($i = 1, $n = count($puntos); $i < $n; $i++) {
            if ($puntos[$i] === $puntos[$i - 1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function parValido(mixed $par): array
    {
        if (! is_array($par) || count($par) < 2 || ! is_numeric($par[0]) || ! is_numeric($par[1])) {
            throw new GeometryRuleViolation('La geometría tiene una coordenada mal formada.');
        }

        $lon = (float) $par[0];
        $lat = (float) $par[1];

        // El adaptador es el único lugar del proyecto que conoce el orden de los
        // ejes, y su verificación de rango es la que atrapa un `[lat, lon]`
        // invertido antes de que llegue a la base (ADR-003).
        try {
            app(GeoJsonPhpGeoAdapter::class)->assertLonLatInRange($lon, $lat);
        } catch (InvalidArgumentException $e) {
            throw new GeometryRuleViolation($e->getMessage());
        }

        return [$lon, $lat];
    }

    /** @param array{0: float, 1: float} $par */
    private static function wktDelPar(array $par): string
    {
        // Precisión fija: sin notación científica, que WKT no acepta, y con
        // suficientes decimales para no mover el punto al redondear.
        return sprintf('%.10F %.10F', $par[0], $par[1]);
    }
}
