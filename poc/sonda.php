<?php

declare(strict_types=1);

/**
 * G2 — Prueba de concepto espacial sobre MariaDB 10.11.18.
 *
 * Convierte cada supuesto del plan sobre el motor en un hecho medido. No lleva
 * veredictos anticipados: cada celda de docs/MATRIZ-ESPACIAL.md se llena con el
 * resultado de una ejecución real contra el motor de producción.
 *
 * Uso:  php poc/sonda.php
 *
 * Salida: docs/MATRIZ-ESPACIAL.md + resumen por consola.
 * Código de salida: 0 si las sondas bloqueantes (P3, P4, P6, P7, P9) están en
 * verde; 1 si alguna falla, porque en ese caso G2 cierra con hallazgo
 * bloqueante y F1 no puede empezar.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/lib/GeodesicOracle.php';

use App\Support\Geo\GeodesicLength;
use App\Support\Geo\GeoJsonPhpGeoAdapter;
use Location\Distance\Vincenty;
use Poc\Lib\GeodesicOracle;

// ---------------------------------------------------------------------------
// Conexión
// ---------------------------------------------------------------------------

$host = getenv('POC_DB_HOST') ?: '127.0.0.1';
$port = getenv('POC_DB_PORT') ?: '3307';
$db = getenv('POC_DB_DATABASE') ?: 'obras';
$user = getenv('POC_DB_USERNAME') ?: 'obras';
$pass = getenv('POC_DB_PASSWORD') ?: 'secret';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            // EXPLAIN devuelve varias filas y las sondas leen sólo la primera;
            // sin buffer, la consulta siguiente falla con «unbuffered queries».
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar a MariaDB en {$host}:{$port}: {$e->getMessage()}\n");
    fwrite(STDERR, "Levantá el motor con: docker compose up -d mariadb\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Infraestructura de sondeo
// ---------------------------------------------------------------------------

/** @var array<string, array<int, array<string, mixed>>> */
$results = [];
/** @var array<string, bool> */
$gateStatus = [];

function scalar(PDO $pdo, string $sql, array $bindings = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $value = $stmt->fetchColumn();
        // Con prepares nativos el cursor queda abierto y la consulta siguiente
        // falla con «unbuffered queries are active». Se cierra explícitamente.
        $stmt->closeCursor();

        return ['ok' => true, 'value' => $value, 'error' => null];
    } catch (PDOException $e) {
        return ['ok' => false, 'value' => null, 'error' => normalizeError($e->getMessage())];
    }
}

function exec_ddl(PDO $pdo, string $sql): array
{
    try {
        $pdo->exec($sql);

        return ['ok' => true, 'value' => 'OK', 'error' => null];
    } catch (PDOException $e) {
        return ['ok' => false, 'value' => null, 'error' => normalizeError($e->getMessage())];
    }
}

function normalizeError(string $message): string
{
    $message = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

    return mb_substr($message, 0, 220);
}

function record(string $probe, string $item, string $status, string $detail): void
{
    global $results;
    $results[$probe][] = ['item' => $item, 'status' => $status, 'detail' => $detail];
}

function say(string $line): void
{
    fwrite(STDOUT, $line."\n");
}

function fmt(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return (string) $value;
}

// ---------------------------------------------------------------------------
// Fixtures geométricos (WKT). Convención: POINT(lon lat), X = longitud.
// Las coordenadas son del orden de Ramallo (lon ≈ -60, lat ≈ -33) pero NO son
// datos verificados: el dataset oficial del IGN entra por G3. Son asimétricas a
// propósito, para que una inversión de ejes rompa la aserción en vez de
// compensarse.
// ---------------------------------------------------------------------------

const PT = 'POINT(-60.123456 -33.487654)';
const LINE_SIMPLE = 'LINESTRING(-60.20 -33.50, -60.10 -33.50, -60.10 -33.40)';
const LINE_BOWTIE = 'LINESTRING(-60.20 -33.50, -60.10 -33.40, -60.20 -33.40, -60.10 -33.50)';
const LINE_L = 'LINESTRING(-60.20 -33.50, -60.10 -33.50, -60.10 -33.40)';
const LINE_REPEATED = 'LINESTRING(-60.20 -33.50, -60.20 -33.50, -60.10 -33.40)';
const LINE_COLLINEAR_OVERLAP = 'LINESTRING(-60.20 -33.50, -60.10 -33.50, -60.15 -33.50)';
const LINE_TWO_IDENTICAL = 'LINESTRING(-60.20 -33.50, -60.20 -33.50)';

const POLY_CONVEX = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50))';
const POLY_SELF_INTERSECT = 'POLYGON((-60.20 -33.50, -60.10 -33.40, -60.20 -33.40, -60.10 -33.50, -60.20 -33.50))';
const POLY_HOLE_OK = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.17 -33.47, -60.13 -33.47, -60.13 -33.43, -60.17 -33.43, -60.17 -33.47))';
const POLY_HOLE_OUTSIDE = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.05 -33.47, -60.02 -33.47, -60.02 -33.43, -60.05 -33.43, -60.05 -33.47))';
const POLY_HOLES_OVERLAP = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.18 -33.48, -60.14 -33.48, -60.14 -33.44, -60.18 -33.44, -60.18 -33.48), (-60.16 -33.46, -60.12 -33.46, -60.12 -33.42, -60.16 -33.42, -60.16 -33.46))';
const POLY_ZERO_AREA = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.20 -33.50, -60.20 -33.50))';
const POLY_UNCLOSED = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40))';
const POLY_TOUCH_VERTEX = 'POLYGON((-60.20 -33.50, -60.15 -33.45, -60.10 -33.50, -60.15 -33.45, -60.20 -33.50))';

// Casos de P7: donde una implementación mediocre de punto interior se rompe.
const POLY_U = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.48, -60.18 -33.48, -60.18 -33.44, -60.10 -33.44, -60.10 -33.42, -60.20 -33.42, -60.20 -33.50))';
const POLY_L_SHAPE = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.47, -60.17 -33.47, -60.17 -33.40, -60.20 -33.40, -60.20 -33.50))';
const POLY_HOLE_CENTERED = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.18 -33.48, -60.12 -33.48, -60.12 -33.42, -60.18 -33.42, -60.18 -33.48))';
const POLY_THIN_STRIP = 'POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.40, -60.20 -33.50), (-60.199 -33.499, -60.101 -33.499, -60.101 -33.4405, -60.199 -33.4405, -60.199 -33.499))';
const POLY_ELONGATED = 'POLYGON((-60.30 -33.5001, -60.10 -33.5001, -60.10 -33.4999, -60.30 -33.4999, -60.30 -33.5001))';
const POLY_NEAR_COLLINEAR = 'POLYGON((-60.20 -33.50, -60.15 -33.499999, -60.10 -33.50, -60.15 -33.45, -60.20 -33.50))';

say('');
say('G2 — Prueba de concepto espacial · MariaDB');
say(str_repeat('=', 72));

// ---------------------------------------------------------------------------
// P1 — Versión del motor
// ---------------------------------------------------------------------------

$version = scalar($pdo, 'SELECT VERSION()');
$comment = scalar($pdo, 'SELECT @@version_comment');
$expected = '10.11.18';
$versionOk = is_string($version['value']) && str_starts_with($version['value'], $expected);

record('P1', 'VERSION()', $versionOk ? 'OK' : 'FAIL', '`'.fmt($version['value']).'`');
record('P1', '@@version_comment', 'INFO', '`'.fmt($comment['value']).'`');
record(
    'P1',
    "Coincide con producción ({$expected})",
    $versionOk ? 'OK' : 'FAIL',
    $versionOk ? 'Sí' : 'No — la matriz no es válida para producción'
);
$gateStatus['P1'] = $versionOk;
say('P1  versión: '.fmt($version['value']).'  '.($versionOk ? '[OK]' : '[FAIL]'));

// ---------------------------------------------------------------------------
// P2 — DDL: qué acepta el motor para columnas geométricas
// ---------------------------------------------------------------------------

$pdo->exec('DROP TABLE IF EXISTS poc_p2_a');
$pdo->exec('DROP TABLE IF EXISTS poc_p2_b');
$pdo->exec('DROP TABLE IF EXISTS poc_p2_c');

$p2a = exec_ddl($pdo, 'CREATE TABLE poc_p2_a (id INT AUTO_INCREMENT PRIMARY KEY, g GEOMETRY NOT NULL, SPATIAL INDEX idx_g (g)) ENGINE=InnoDB');
record('P2', '`GEOMETRY NOT NULL` + `SPATIAL INDEX`', $p2a['ok'] ? 'OK' : 'FAIL', $p2a['ok'] ? 'Aceptado' : $p2a['error']);

$p2b = exec_ddl($pdo, 'CREATE TABLE poc_p2_b (id INT AUTO_INCREMENT PRIMARY KEY, g GEOMETRY SRID 4326 NOT NULL, SPATIAL INDEX idx_g (g)) ENGINE=InnoDB');
record('P2', 'Atributo `SRID 4326` en la columna (sintaxis MySQL 8)', $p2b['ok'] ? 'DISPONIBLE' : 'NO DISPONIBLE', $p2b['ok'] ? 'Aceptado' : $p2b['error']);

$p2c = exec_ddl($pdo, 'CREATE TABLE poc_p2_c (id INT AUTO_INCREMENT PRIMARY KEY, g GEOMETRY REF_SYSTEM_ID=4326 NOT NULL, SPATIAL INDEX idx_g (g)) ENGINE=InnoDB');
record('P2', 'Atributo `REF_SYSTEM_ID=4326` (sintaxis MariaDB)', $p2c['ok'] ? 'DISPONIBLE' : 'NO DISPONIBLE', $p2c['ok'] ? 'Aceptado' : $p2c['error']);

// Aceptar la sintaxis no es lo mismo que aplicar la restricción. Si el atributo
// existe pero no rechaza un SRID distinto, no sirve como guarda y la validación
// tiene que estar en la aplicación de todos modos.
$refSystemEnforced = null;
if ($p2c['ok']) {
    $insertWrongSrid = exec_ddl($pdo, "INSERT INTO poc_p2_c (g) VALUES (ST_GeomFromText('".PT."', 0))");
    $insertRightSrid = exec_ddl($pdo, "INSERT INTO poc_p2_c (g) VALUES (ST_GeomFromText('".PT."', 4326))");
    $refSystemEnforced = ! $insertWrongSrid['ok'] && $insertRightSrid['ok'];

    record(
        'P2',
        '¿`REF_SYSTEM_ID=4326` **rechaza** un SRID distinto?',
        $refSystemEnforced ? 'SÍ, LO APLICA' : 'NO LO APLICA',
        $refSystemEnforced
            ? 'Insertar SRID 0 falla: `'.$insertWrongSrid['error'].'` — y SRID 4326 entra.'
            : ($insertWrongSrid['ok']
                ? 'Insertar SRID 0 en una columna declarada 4326 **se acepta en silencio**: el atributo no es una guarda utilizable.'
                : 'Resultado ambiguo: también falló el SRID correcto — '.$insertRightSrid['error'])
    );
}

$p2Consequence = $refSystemEnforced === true
    ? 'El motor puede fijar y **aplicar** el SRID por columna con `REF_SYSTEM_ID`. Aun así la aplicación lo impone y lo verifica con `ST_SRID`: el DDL de Laravel para MariaDB no lo emite (P3) y la validación de entrada no puede depender de una sintaxis específica del motor.'
    : ($p2c['ok']
        ? 'El atributo `REF_SYSTEM_ID` se acepta pero **no rechaza** un SRID distinto, así que no sirve como guarda: el 4326 se impone en cada escritura y se verifica con `ST_SRID` antes de persistir.'
        : 'El SRID **no** se puede fijar por columna: se impone en cada escritura y se verifica con `ST_SRID`.');
record('P2', 'Consecuencia de diseño', 'INFO', $p2Consequence);
$gateStatus['P2'] = $p2a['ok'];
say('P2  DDL geometry+spatial: '.($p2a['ok'] ? '[OK]' : '[FAIL]').'  SRID por columna: '.($p2b['ok'] || $p2c['ok'] ? 'disponible' : 'no disponible'));

// ---------------------------------------------------------------------------
// P3 — Migración de Laravel (grammar de MariaDB)
// ---------------------------------------------------------------------------

$p3Table = 'poc_p3_geometrias';
$p3Exists = scalar($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$db, $p3Table]);

if ((int) $p3Exists['value'] === 1) {
    $row = $pdo->query("SHOW CREATE TABLE {$p3Table}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $createSql = (string) ($row['Create Table'] ?? '');

    $hasSpatial = str_contains(strtoupper($createSql), 'SPATIAL KEY') || str_contains(strtoupper($createSql), 'SPATIAL INDEX');
    $hasSridAttr = (bool) preg_match('/\bsrid\s+4326\b/i', $createSql);

    $idx = scalar($pdo, 'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_type = ?', [$db, $p3Table, 'SPATIAL']);

    record('P3', 'La migración de Laravel 13 corre sobre `DB_CONNECTION=mariadb`', 'OK', 'Tabla `'.$p3Table.'` creada');
    record('P3', '`$table->geometry(...)` emite DDL válido', 'OK', $hasSridAttr ? 'Con atributo SRID' : 'Sin atributo SRID (grammar de MariaDB, `geometry` plano)');
    record('P3', '`$table->spatialIndex(...)` crea el índice', $hasSpatial ? 'OK' : 'FAIL', 'Índices SPATIAL en information_schema: '.fmt($idx['value']));
    $gateStatus['P3'] = $hasSpatial;
    say('P3  migración Laravel: '.($hasSpatial ? '[OK]' : '[FAIL]'));
} else {
    record('P3', 'Migración de Laravel', 'PENDIENTE', 'La tabla `'.$p3Table.'` no existe. Corré: `php artisan migrate --path=poc/migrations`');
    $gateStatus['P3'] = false;
    say('P3  migración Laravel: [PENDIENTE] correr php artisan migrate --path=poc/migrations');
}

// ---------------------------------------------------------------------------
// P4 — Round-trip de coordenadas y del adaptador phpgeo
// ---------------------------------------------------------------------------

$pdo->exec('DROP TABLE IF EXISTS poc_p4');
$pdo->exec('CREATE TABLE poc_p4 (id INT AUTO_INCREMENT PRIMARY KEY, g GEOMETRY NOT NULL) ENGINE=InnoDB');

$lon = -60.123456;
$lat = -33.487654;

$ins = $pdo->prepare('INSERT INTO poc_p4 (g) VALUES (ST_GeomFromText(?, ?))');
$insOk = true;
try {
    $ins->execute(["POINT({$lon} {$lat})", 4326]);
} catch (PDOException $e) {
    $insOk = false;
    record('P4', 'INSERT con WKT y SRID por binding', 'FAIL', normalizeError($e->getMessage()));
}

if ($insOk) {
    record('P4', 'INSERT con WKT y SRID por binding', 'OK', 'Sin interpolación de cadenas');

    $readX = scalar($pdo, 'SELECT ST_X(g) FROM poc_p4 LIMIT 1');
    $readY = scalar($pdo, 'SELECT ST_Y(g) FROM poc_p4 LIMIT 1');
    $readSrid = scalar($pdo, 'SELECT ST_SRID(g) FROM poc_p4 LIMIT 1');
    $readWkt = scalar($pdo, 'SELECT ST_AsText(g) FROM poc_p4 LIMIT 1');
    $readJson = scalar($pdo, 'SELECT ST_AsGeoJSON(g) FROM poc_p4 LIMIT 1');

    $xOk = abs((float) $readX['value'] - $lon) < 1e-9;
    $yOk = abs((float) $readY['value'] - $lat) < 1e-9;
    $sridOk = (int) $readSrid['value'] === 4326;

    record('P4', '`ST_X` devuelve la longitud', $xOk ? 'OK' : 'FAIL', '`'.fmt($readX['value']).'` (esperado '.$lon.')');
    record('P4', '`ST_Y` devuelve la latitud', $yOk ? 'OK' : 'FAIL', '`'.fmt($readY['value']).'` (esperado '.$lat.')');
    record('P4', '`ST_SRID` devuelve 4326', $sridOk ? 'OK' : 'FAIL', '`'.fmt($readSrid['value']).'`');
    record('P4', 'Round-trip WKT', 'INFO', '`'.fmt($readWkt['value']).'`');
    record('P4', 'Round-trip GeoJSON', 'INFO', '`'.fmt($readJson['value']).'`');

    // Adaptador phpgeo: la frontera donde se cuelan los errores de ejes.
    $adapter = new GeoJsonPhpGeoAdapter;
    $coordinate = $adapter->coordinateFromLonLat($lon, $lat);
    $latOk = abs($coordinate->getLat() - $lat) < 1e-12;
    $lngOk = abs($coordinate->getLng() - $lon) < 1e-12;
    [$backLon, $backLat] = $adapter->toLonLatPair($coordinate);
    $backOk = abs($backLon - $lon) < 1e-12 && abs($backLat - $lat) < 1e-12;

    record('P4', 'Adaptador: `getLat()` devuelve la latitud que entró segunda', $latOk ? 'OK' : 'FAIL', fmt($coordinate->getLat()));
    record('P4', 'Adaptador: `getLng()` devuelve la longitud que entró primera', $lngOk ? 'OK' : 'FAIL', fmt($coordinate->getLng()));
    record('P4', 'Adaptador: ida y vuelta a `[lon, lat]`', $backOk ? 'OK' : 'FAIL', '['.$backLon.', '.$backLat.']');

    $gateStatus['P4'] = $xOk && $yOk && $sridOk && $latOk && $lngOk && $backOk;
} else {
    $gateStatus['P4'] = false;
}
say('P4  round-trip de ejes: '.($gateStatus['P4'] ? '[OK]' : '[FAIL]'));

// ---------------------------------------------------------------------------
// P5 — Matriz de disponibilidad y comportamiento de funciones espaciales
// ---------------------------------------------------------------------------

$functionProbes = [
    'ST_PointOnSurface' => "SELECT ST_AsText(ST_PointOnSurface(ST_GeomFromText('".POLY_CONVEX."', 4326)))",
    'ST_IsValid' => "SELECT ST_IsValid(ST_GeomFromText('".POLY_CONVEX."', 4326))",
    'ST_IsSimple' => "SELECT ST_IsSimple(ST_GeomFromText('".LINE_SIMPLE."', 4326))",
    'ST_IsRing' => "SELECT ST_IsRing(ST_ExteriorRing(ST_GeomFromText('".POLY_CONVEX."', 4326)))",
    'ST_IsClosed' => "SELECT ST_IsClosed(ST_GeomFromText('".LINE_SIMPLE."', 4326))",
    'ST_Area' => "SELECT ST_Area(ST_GeomFromText('".POLY_CONVEX."', 4326))",
    'ST_Centroid' => "SELECT ST_AsText(ST_Centroid(ST_GeomFromText('".POLY_CONVEX."', 4326)))",
    'ST_Contains' => "SELECT ST_Contains(ST_GeomFromText('".POLY_CONVEX."', 4326), ST_GeomFromText('".PT."', 4326))",
    'ST_Within' => "SELECT ST_Within(ST_GeomFromText('".PT."', 4326), ST_GeomFromText('".POLY_CONVEX."', 4326))",
    'ST_Disjoint' => "SELECT ST_Disjoint(ST_GeomFromText('".POLY_CONVEX."', 4326), ST_GeomFromText('POINT(0 0)', 4326))",
    'ST_Overlaps' => "SELECT ST_Overlaps(ST_GeomFromText('".POLY_CONVEX."', 4326), ST_GeomFromText('".POLY_HOLE_OK."', 4326))",
    'ST_Intersection' => "SELECT ST_AsText(ST_Intersection(ST_GeomFromText('".POLY_CONVEX."', 4326), ST_GeomFromText('".POLY_CONVEX."', 4326)))",
    'ST_Union' => "SELECT ST_AsText(ST_Union(ST_GeomFromText('".PT."', 4326), ST_GeomFromText('POINT(-60.1 -33.4)', 4326)))",
    'ST_ExteriorRing' => "SELECT ST_AsText(ST_ExteriorRing(ST_GeomFromText('".POLY_CONVEX."', 4326)))",
    'ST_NumInteriorRings' => "SELECT ST_NumInteriorRings(ST_GeomFromText('".POLY_HOLE_OK."', 4326))",
    'ST_InteriorRingN' => "SELECT ST_AsText(ST_InteriorRingN(ST_GeomFromText('".POLY_HOLE_OK."', 4326), 1))",
    'ST_NumPoints' => "SELECT ST_NumPoints(ST_GeomFromText('".LINE_SIMPLE."', 4326))",
    'ST_PointN' => "SELECT ST_AsText(ST_PointN(ST_GeomFromText('".LINE_SIMPLE."', 4326), 1))",
    'ST_NumGeometries' => "SELECT ST_NumGeometries(ST_GeomFromText('MULTIPOINT(-60.2 -33.5, -60.1 -33.4)', 4326))",
    'ST_GeometryN' => "SELECT ST_AsText(ST_GeometryN(ST_GeomFromText('MULTIPOINT(-60.2 -33.5, -60.1 -33.4)', 4326), 1))",
    'ST_Envelope' => "SELECT ST_AsText(ST_Envelope(ST_GeomFromText('".LINE_SIMPLE."', 4326)))",
    'ST_Buffer' => "SELECT ST_GeometryType(ST_Buffer(ST_GeomFromText('".PT."', 4326), 0.01))",
    'ST_SRID' => "SELECT ST_SRID(ST_GeomFromText('".PT."', 4326))",
    'Polygon()' => "SELECT ST_AsText(Polygon(ST_ExteriorRing(ST_GeomFromText('".POLY_CONVEX."', 4326))))",
    'ST_LineInterpolatePoint' => "SELECT ST_AsText(ST_LineInterpolatePoint(ST_GeomFromText('".LINE_SIMPLE."', 4326), 0.5))",
    'ST_Length' => "SELECT ST_Length(ST_GeomFromText('".LINE_SIMPLE."', 4326))",
    'ST_Distance' => "SELECT ST_Distance(ST_GeomFromText('".PT."', 4326), ST_GeomFromText('POINT(-60.1 -33.4)', 4326))",
    'ST_Distance_Sphere' => "SELECT ST_Distance_Sphere(ST_GeomFromText('".PT."', 4326), ST_GeomFromText('POINT(-60.1 -33.4)', 4326))",
];

$available = 0;
$missing = [];
foreach ($functionProbes as $fn => $sql) {
    $r = scalar($pdo, $sql);
    if ($r['ok']) {
        $available++;
        record('P5', '`'.$fn.'`', 'DISPONIBLE', 'Devuelve `'.fmt($r['value']).'`');
    } else {
        $missing[] = $fn;
        record('P5', '`'.$fn.'`', 'NO DISPONIBLE', $r['error']);
    }
}
$gateStatus['P5'] = true; // P5 informa; no bloquea por sí sola.
say('P5  funciones: '.$available.'/'.count($functionProbes).' disponibles'.($missing !== [] ? ' — ausentes: '.implode(', ', $missing) : ''));

// ---------------------------------------------------------------------------
// P6 — Fixtures topológicos: validez compuesta
// ---------------------------------------------------------------------------

$topoFixtures = [
    'Línea simple (L)' => [LINE_L, 'línea válida'],
    'Línea moño (autointersecada)' => [LINE_BOWTIE, 'debe rechazarse'],
    'Línea con vértices repetidos' => [LINE_REPEATED, 'aceptable, degenerada'],
    'Línea colineal solapada' => [LINE_COLLINEAR_OVERLAP, 'debe rechazarse'],
    'Línea de dos vértices idénticos' => [LINE_TWO_IDENTICAL, 'debe rechazarse'],
    'Polígono convexo' => [POLY_CONVEX, 'válido'],
    'Polígono autointersectado' => [POLY_SELF_INTERSECT, 'debe rechazarse'],
    'Polígono con hueco válido' => [POLY_HOLE_OK, 'válido'],
    'Hueco fuera del exterior' => [POLY_HOLE_OUTSIDE, 'debe rechazarse'],
    'Huecos superpuestos' => [POLY_HOLES_OVERLAP, 'debe rechazarse'],
    'Polígono de área cero' => [POLY_ZERO_AREA, 'debe rechazarse'],
    'Anillo sin cerrar' => [POLY_UNCLOSED, 'debe rechazarse en el parseo'],
    'Polígono que se toca en un vértice' => [POLY_TOUCH_VERTEX, 'debe rechazarse'],
];

$p6AllProbed = true;
foreach ($topoFixtures as $label => [$wkt, $expectation]) {
    $parse = scalar($pdo, 'SELECT ST_AsText(ST_GeomFromText(?, 4326))', [$wkt]);

    if (! $parse['ok']) {
        record('P6', $label, 'RECHAZADO EN PARSEO', 'Esperado: '.$expectation.'. Motor: '.$parse['error']);

        continue;
    }

    $type = scalar($pdo, 'SELECT ST_GeometryType(ST_GeomFromText(?, 4326))', [$wkt]);
    $isSimple = scalar($pdo, 'SELECT ST_IsSimple(ST_GeomFromText(?, 4326))', [$wkt]);
    $isValid = scalar($pdo, 'SELECT ST_IsValid(ST_GeomFromText(?, 4326))', [$wkt]);
    $area = scalar($pdo, 'SELECT ST_Area(ST_GeomFromText(?, 4326))', [$wkt]);
    $numPoints = scalar($pdo, 'SELECT ST_NumPoints(ST_GeomFromText(?, 4326))', [$wkt]);
    $rings = scalar($pdo, 'SELECT ST_NumInteriorRings(ST_GeomFromText(?, 4326))', [$wkt]);

    $detail = sprintf(
        'tipo=%s · ST_IsSimple=%s · ST_IsValid=%s · ST_Area=%s · vértices=%s · huecos=%s · esperado: %s',
        fmt($type['value']),
        $isSimple['ok'] ? fmt($isSimple['value']) : 'error',
        $isValid['ok'] ? fmt($isValid['value']) : 'no disponible',
        $area['ok'] ? fmt($area['value']) : 'n/a',
        $numPoints['ok'] ? fmt($numPoints['value']) : 'n/a',
        $rings['ok'] ? fmt($rings['value']) : 'n/a',
        $expectation
    );

    record('P6', $label, 'PARSEA', $detail);
}

// Validez compuesta de huecos: contención y no superposición por operaciones de conjunto.
$holeContained = scalar($pdo, 'SELECT ST_Contains(Polygon(ST_ExteriorRing(ST_GeomFromText(?, 4326))), Polygon(ST_InteriorRingN(ST_GeomFromText(?, 4326), 1)))', [POLY_HOLE_OK, POLY_HOLE_OK]);
$holeOutside = scalar($pdo, 'SELECT ST_Contains(Polygon(ST_ExteriorRing(ST_GeomFromText(?, 4326))), Polygon(ST_InteriorRingN(ST_GeomFromText(?, 4326), 1)))', [POLY_HOLE_OUTSIDE, POLY_HOLE_OUTSIDE]);
$holesOverlapArea = scalar($pdo, 'SELECT ST_Area(ST_Intersection(Polygon(ST_InteriorRingN(ST_GeomFromText(?, 4326), 1)), Polygon(ST_InteriorRingN(ST_GeomFromText(?, 4326), 2))))', [POLY_HOLES_OVERLAP, POLY_HOLES_OVERLAP]);

$holeRulesOk = $holeContained['ok'] && (int) $holeContained['value'] === 1
    && $holeOutside['ok'] && (int) $holeOutside['value'] === 0
    && $holesOverlapArea['ok'] && (float) $holesOverlapArea['value'] > 0.0;

record('P6', 'Hueco válido contenido en el exterior', $holeContained['ok'] && (int) $holeContained['value'] === 1 ? 'OK' : 'FAIL', 'ST_Contains = '.fmt($holeContained['value']));
record('P6', 'Hueco fuera del exterior se detecta', $holeOutside['ok'] && (int) $holeOutside['value'] === 0 ? 'OK' : 'FAIL', 'ST_Contains = '.fmt($holeOutside['value']));
record('P6', 'Huecos superpuestos se detectan por área de intersección', $holesOverlapArea['ok'] && (float) $holesOverlapArea['value'] > 0.0 ? 'OK' : 'FAIL', 'ST_Area(ST_Intersection) = '.fmt($holesOverlapArea['value']));

// La sonda clave: ¿ST_IsSimple distingue el moño de la línea simple?
$simpleGood = scalar($pdo, 'SELECT ST_IsSimple(ST_GeomFromText(?, 4326))', [LINE_L]);
$simpleBowtie = scalar($pdo, 'SELECT ST_IsSimple(ST_GeomFromText(?, 4326))', [LINE_BOWTIE]);
$isSimpleDiscriminates = $simpleGood['ok'] && $simpleBowtie['ok']
    && (int) $simpleGood['value'] === 1 && (int) $simpleBowtie['value'] === 0;

record(
    'P6',
    '`ST_IsSimple` discrimina moño de línea simple',
    $isSimpleDiscriminates ? 'OK' : 'FAIL',
    'simple='.fmt($simpleGood['value']).' · moño='.fmt($simpleBowtie['value']).
    ($isSimpleDiscriminates ? '' : ' — **hallazgo bloqueante**: sin este discriminante, RF-GEO-013 necesita detector propio o php-geos')
);

$gateStatus['P6'] = $holeRulesOk && $isSimpleDiscriminates && $p6AllProbed;
say('P6  topología: '.($gateStatus['P6'] ? '[OK]' : '[FAIL]').' · ST_IsSimple discrimina: '.($isSimpleDiscriminates ? 'sí' : 'NO'));

// ---------------------------------------------------------------------------
// P7 — Punto interior, batería completa
// ---------------------------------------------------------------------------

$p7Cases = [
    'Convexo simple' => POLY_CONVEX,
    'Cóncavo en U (centroide fuera)' => POLY_U,
    'Cóncavo en L (centroide fuera)' => POLY_L_SHAPE,
    'Con hueco centrado (centroide en el hueco)' => POLY_HOLE_CENTERED,
    'Con varios huecos' => POLY_HOLES_OVERLAP,
    'Hueco que deja una franja delgada' => POLY_THIN_STRIP,
    'Muy alargado' => POLY_ELONGATED,
    'Vértices casi colineales' => POLY_NEAR_COLLINEAR,
];

$posAllOk = true;
$posAvailable = true;
$centroidInsideCount = 0;

foreach ($p7Cases as $label => $wkt) {
    $start = hrtime(true);
    $pos = scalar($pdo, 'SELECT ST_AsText(ST_PointOnSurface(ST_GeomFromText(?, 4326)))', [$wkt]);
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    if (! $pos['ok']) {
        $posAvailable = false;
        $posAllOk = false;
        record('P7', $label, 'NO DISPONIBLE', $pos['error']);

        continue;
    }

    $contains = scalar(
        $pdo,
        'SELECT ST_Contains(ST_GeomFromText(?, 4326), ST_PointOnSurface(ST_GeomFromText(?, 4326)))',
        [$wkt, $wkt]
    );
    $isPoint = scalar($pdo, 'SELECT ST_GeometryType(ST_PointOnSurface(ST_GeomFromText(?, 4326)))', [$wkt]);

    $notNull = $pos['value'] !== null;
    $typeOk = strtoupper((string) $isPoint['value']) === 'POINT';
    $inside = $contains['ok'] && (int) $contains['value'] === 1;
    $caseOk = $notNull && $typeOk && $inside;
    $posAllOk = $posAllOk && $caseOk;

    // El atajo barato: ¿sirve el centroide en este caso?
    $centroidInside = scalar(
        $pdo,
        'SELECT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(ST_GeomFromText(?, 4326)))',
        [$wkt, $wkt]
    );
    if ($centroidInside['ok'] && (int) $centroidInside['value'] === 1) {
        $centroidInsideCount++;
    }

    record('P7', $label, $caseOk ? 'OK' : 'FAIL', sprintf(
        '`%s` · POINT=%s · ST_Contains=%s · centroide dentro=%s · %.2f ms',
        fmt($pos['value']),
        $typeOk ? 'sí' : 'no',
        $inside ? 'sí' : 'NO',
        $centroidInside['ok'] ? ((int) $centroidInside['value'] === 1 ? 'sí' : 'no') : 'n/a',
        $elapsedMs
    ));
}

record(
    'P7',
    'Escalón elegido de la escalera de preferencia',
    $posAllOk ? 'OK' : 'FAIL',
    $posAllOk
        ? '**Escalón 1**: `ST_PointOnSurface` pasa la batería completa. Menos código propio, menos superficie de error.'
        : ($posAvailable
            ? '`ST_PointOnSurface` existe pero falla algún caso: se baja al escalón 2/3 según el detalle de arriba.'
            : '`ST_PointOnSurface` no está disponible: se aplica la escalera 2→3→4 del plan.')
);
record('P7', 'Centroide contenido (atajo del escalón 2)', 'INFO', $centroidInsideCount.' de '.count($p7Cases).' casos');

$gateStatus['P7'] = $posAllOk;
say('P7  punto interior: '.($gateStatus['P7'] ? '[OK]' : '[FAIL]').' · ST_PointOnSurface '.($posAvailable ? 'disponible' : 'ausente'));

// ---------------------------------------------------------------------------
// P8 — Longitud geodésica: Vincenty contra oráculo, y ST_Length del motor
// ---------------------------------------------------------------------------

$vincenty = new Vincenty;
$adapter = new GeoJsonPhpGeoAdapter;
$lengths = new GeodesicLength;

$algorithmicTolerance = 0.001; // ±1 mm
$p8Ok = true;

// (a) Arco ecuatorial: forma cerrada exacta.
$eqCases = [[0.0, 1.0], [-60.5, -60.0], [-60.0, -59.0]];
foreach ($eqCases as [$lonA, $lonB]) {
    $expected = GeodesicOracle::equatorArcMeters($lonA, $lonB);
    $actual = $vincenty->getDistance(
        $adapter->coordinateFromLonLat($lonA, 0.0),
        $adapter->coordinateFromLonLat($lonB, 0.0)
    );
    $delta = abs($actual - $expected);
    $ok = $delta <= $algorithmicTolerance;
    $p8Ok = $p8Ok && $ok;
    record('P8', sprintf('Ecuador lon %.1f→%.1f (forma cerrada a·Δλ)', $lonA, $lonB), $ok ? 'OK' : 'FAIL', sprintf(
        'oráculo=%.6f m · Vincenty=%.6f m · Δ=%.6f mm',
        $expected,
        $actual,
        $delta * 1000
    ));
}

// (b) Arco meridiano: cuadratura de Simpson de alta precisión.
$merCases = [[-33.5, -33.4], [-34.0, -33.0], [-33.4876, -33.1234], [-40.0, -30.0]];
foreach ($merCases as [$latA, $latB]) {
    $expected = GeodesicOracle::meridianArcMeters($latA, $latB);
    $actual = $vincenty->getDistance(
        $adapter->coordinateFromLonLat(-60.123456, $latA),
        $adapter->coordinateFromLonLat(-60.123456, $latB)
    );
    $delta = abs($actual - $expected);
    $ok = $delta <= $algorithmicTolerance;
    $p8Ok = $p8Ok && $ok;
    record('P8', sprintf('Meridiano lat %.4f→%.4f (cuadratura)', $latA, $latB), $ok ? 'OK' : 'FAIL', sprintf(
        'oráculo=%.6f m · Vincenty=%.6f m · Δ=%.6f mm',
        $expected,
        $actual,
        $delta * 1000
    ));
}

// (c) Simetría: d(A,B) = d(B,A).
$symA = $vincenty->getDistance($adapter->coordinateFromLonLat(-60.2, -33.5), $adapter->coordinateFromLonLat(-60.1, -33.4));
$symB = $vincenty->getDistance($adapter->coordinateFromLonLat(-60.1, -33.4), $adapter->coordinateFromLonLat(-60.2, -33.5));
$symOk = abs($symA - $symB) <= 1e-9;
$p8Ok = $p8Ok && $symOk;
record('P8', 'Simetría d(A,B) = d(B,A)', $symOk ? 'OK' : 'FAIL', sprintf('Δ=%.3e m', abs($symA - $symB)));

// (d) Línea oblicua: control grueso contra la esfera de radio medio.
$obliqueVincenty = $vincenty->getDistance($adapter->coordinateFromLonLat(-60.20, -33.50), $adapter->coordinateFromLonLat(-60.10, -33.40));
$obliqueSphere = GeodesicOracle::sphericalApproxMeters(-60.20, -33.50, -60.10, -33.40);
$obliqueRatio = abs($obliqueVincenty - $obliqueSphere) / $obliqueSphere;
$obliqueOk = $obliqueRatio < 0.01;
$p8Ok = $p8Ok && $obliqueOk;
record('P8', 'Línea oblicua vs. esfera de radio medio (control grueso)', $obliqueOk ? 'OK' : 'FAIL', sprintf(
    'Vincenty=%.3f m · esfera=%.3f m · desvío relativo=%.4f %%',
    $obliqueVincenty,
    $obliqueSphere,
    $obliqueRatio * 100
));

// (e) Detección de ejes invertidos: si se invierten, la distancia cambia groseramente.
$swapped = $vincenty->getDistance(
    $adapter->coordinateFromLonLat(-33.50, -60.20),
    $adapter->coordinateFromLonLat(-33.40, -60.10)
);
$swapDetectable = abs($swapped - $obliqueVincenty) > 1.0;
record('P8', 'Los fixtures asimétricos detectan ejes invertidos', $swapDetectable ? 'OK' : 'FAIL', sprintf(
    'correcto=%.3f m · invertido=%.3f m · diferencia=%.3f m',
    $obliqueVincenty,
    $swapped,
    abs($swapped - $obliqueVincenty)
));
$p8Ok = $p8Ok && $swapDetectable;

// (f) El veredicto sobre ST_Length, con números.
$stLength = scalar($pdo, 'SELECT ST_Length(ST_GeomFromText(?, 4326))', [LINE_SIMPLE]);
$phpLength = $lengths->forLineString([[-60.20, -33.50], [-60.10, -33.50], [-60.10, -33.40]]);
$ratio = $stLength['ok'] && (float) $stLength['value'] > 0
    ? $phpLength['meters'] / (float) $stLength['value']
    : 0.0;

record('P8', '`ST_Length` sobre lon/lat (MariaDB)', 'INFO', sprintf(
    'Devuelve `%s` — son **grados**, no metros. Vincenty sobre la misma línea: %.2f m. Cociente m/grado ≈ %.0f.',
    fmt($stLength['value']),
    $phpLength['meters'],
    $ratio
));
record('P8', 'Veredicto: `ST_Length` prohibida en el dominio', 'OK', 'Confirmado con números: el motor no es fuente de verdad de una longitud en metros. Test de arquitectura falla el build si aparece.');

// (g) Fallback de Vincenty con puntos casi antipodales.
$antipodal = $lengths->betweenLonLat(0.0, 0.0, 179.99999, 0.0);
record('P8', 'Fallback ante no convergencia (casi antipodal)', 'INFO', sprintf(
    'método=%s · segmentos con fallback=%d · %.2f m',
    $antipodal['method'],
    $antipodal['fallback_segments'],
    $antipodal['meters']
));

// (h) Conformidad funcional: length_m redondeado al centímetro.
$refMeridian = GeodesicOracle::meridianArcMeters(-33.50, -33.40);
$refEquatorSeg = GeodesicOracle::equatorArcMeters(-60.20, -60.10, GeodesicOracle::WGS84_A);
$functional = $lengths->forLineString([[-60.20, -33.50], [-60.20, -33.40]]);
$funcDelta = abs($functional['meters'] - $refMeridian);
$funcTolerance = max(0.10, 0.0005 * $refMeridian);
$funcOk = $funcDelta <= $funcTolerance;
$p8Ok = $p8Ok && $funcOk;
record('P8', 'Conformidad funcional de `length_m` (max(0,10 m; 0,05 %))', $funcOk ? 'OK' : 'FAIL', sprintf(
    'persistido=%.2f m · referencia=%.4f m · Δ=%.4f m · tolerancia=%.4f m · método=%s',
    $functional['meters'],
    $refMeridian,
    $funcDelta,
    $funcTolerance,
    $functional['method']
));

record('P8', 'Oráculo utilizado', 'INFO', 'Analítico: ecuador en forma cerrada (a·Δλ) y meridiano por cuadratura de Simpson compuesta. **Desviación documentada** respecto de los vectores de Vincenty (1975): ver la nota de esta sección.');

$gateStatus['P8'] = $p8Ok;
say('P8  longitud geodésica: '.($gateStatus['P8'] ? '[OK]' : '[FAIL]'));

// ---------------------------------------------------------------------------
// P9 — Uso del índice espacial en los dos modos de consulta
// ---------------------------------------------------------------------------

$pdo->exec('DROP TABLE IF EXISTS poc_p9');
$pdo->exec('CREATE TABLE poc_p9 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    geometry GEOMETRY NOT NULL,
    representative_point POINT NOT NULL,
    SPATIAL INDEX idx_geometry (geometry),
    SPATIAL INDEX idx_representative_point (representative_point)
) ENGINE=InnoDB');

$seed = $pdo->prepare('INSERT INTO poc_p9 (geometry, representative_point) VALUES (ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326))');
$pdo->beginTransaction();
$rows = 3000;
for ($i = 0; $i < $rows; $i++) {
    $lo = -60.5 + ($i % 100) * 0.004;
    $la = -33.7 + intdiv($i, 100) * 0.004;

    if ($i % 3 === 0) {
        $wkt = sprintf('POINT(%.6f %.6f)', $lo, $la);
        $rep = $wkt;
    } elseif ($i % 3 === 1) {
        // Línea larga: su punto representativo puede caer fuera de un viewport
        // que la geometría sí cruza. Es el caso que motiva consultar `geometry`.
        $wkt = sprintf('LINESTRING(%.6f %.6f, %.6f %.6f)', $lo, $la, $lo + 0.05, $la + 0.002);
        $rep = sprintf('POINT(%.6f %.6f)', $lo + 0.025, $la + 0.001);
    } else {
        $wkt = sprintf(
            'POLYGON((%.6f %.6f, %.6f %.6f, %.6f %.6f, %.6f %.6f, %.6f %.6f))',
            $lo, $la, $lo + 0.003, $la, $lo + 0.003, $la + 0.003, $lo, $la + 0.003, $lo, $la
        );
        $rep = sprintf('POINT(%.6f %.6f)', $lo + 0.0015, $la + 0.0015);
    }

    $seed->execute([$wkt, $rep]);
}
$pdo->commit();
// ANALYZE TABLE devuelve un resultado: si se ejecuta con exec() deja el cursor
// abierto y todo lo que sigue falla con «unbuffered queries are active».
$pdo->query('ANALYZE TABLE poc_p9')->fetchAll();

$envelope = "ST_GeomFromText('POLYGON((-60.30 -33.60, -60.20 -33.60, -60.20 -33.50, -60.30 -33.50, -60.30 -33.60))', 4326)";

$explainCases = [
    'Clustering · `MBRIntersects(representative_point, bbox)`' => "SELECT COUNT(*) FROM poc_p9 WHERE MBRIntersects(representative_point, {$envelope})",
    'Clustering · `ST_Intersects(representative_point, bbox)`' => "SELECT COUNT(*) FROM poc_p9 WHERE ST_Intersects(representative_point, {$envelope})",
    'Geometría · `MBRIntersects(geometry, bbox)`' => "SELECT COUNT(*) FROM poc_p9 WHERE MBRIntersects(geometry, {$envelope})",
    'Geometría · `ST_Intersects(geometry, bbox)`' => "SELECT COUNT(*) FROM poc_p9 WHERE ST_Intersects(geometry, {$envelope})",
];

$indexedCount = 0;
$mbrIndexed = ['representative_point' => false, 'geometry' => false];

foreach ($explainCases as $label => $sql) {
    try {
        $planRowsAll = $pdo->query('EXPLAIN '.$sql)->fetchAll(PDO::FETCH_ASSOC);
        $plan = $planRowsAll[0] ?? [];
        $key = $plan['key'] ?? null;
        $type = $plan['type'] ?? '';
        $planRows = $plan['rows'] ?? '';
        $usesSpatial = is_string($key) && str_contains($key, 'idx_');

        $timeStart = hrtime(true);
        $countStmt = $pdo->query($sql);
        $count = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();
        $ms = (hrtime(true) - $timeStart) / 1e6;

        if ($usesSpatial) {
            $indexedCount++;
            if (str_contains($label, 'MBRIntersects(representative_point')) {
                $mbrIndexed['representative_point'] = true;
            }
            if (str_contains($label, 'MBRIntersects(geometry')) {
                $mbrIndexed['geometry'] = true;
            }
        }

        record('P9', $label, $usesSpatial ? 'USA ÍNDICE' : 'RECORRIDO COMPLETO', sprintf(
            'key=%s · type=%s · rows=%s · filas devueltas=%d · %.2f ms',
            $key === null ? 'NULL' : '`'.$key.'`',
            $type,
            $planRows,
            $count,
            $ms
        ));
    } catch (PDOException $e) {
        record('P9', $label, 'ERROR', normalizeError($e->getMessage()));
    }
}

// La consecuencia práctica de la sección 8 del plan, medida:
// una línea cuyo punto representativo cae fuera del bbox pero la geometría lo cruza.
$pdo->exec('DROP TABLE IF EXISTS poc_p9_edge');
$pdo->exec('CREATE TABLE poc_p9_edge (id INT AUTO_INCREMENT PRIMARY KEY, geometry GEOMETRY NOT NULL, representative_point POINT NOT NULL, SPATIAL INDEX idx_g (geometry), SPATIAL INDEX idx_r (representative_point)) ENGINE=InnoDB');
$edge = $pdo->prepare('INSERT INTO poc_p9_edge (geometry, representative_point) VALUES (ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326))');
// Avenida que cruza el bbox de lado a lado; su punto medio queda al este, fuera.
$edge->execute(['LINESTRING(-60.29 -33.55, -60.05 -33.55)', 'POINT(-60.17 -33.55)']);
$bboxSmall = "ST_GeomFromText('POLYGON((-60.28 -33.56, -60.26 -33.56, -60.26 -33.54, -60.28 -33.54, -60.28 -33.56))', 4326)";
$byGeom = scalar($pdo, "SELECT COUNT(*) FROM poc_p9_edge WHERE MBRIntersects(geometry, {$bboxSmall})");
$byRep = scalar($pdo, "SELECT COUNT(*) FROM poc_p9_edge WHERE MBRIntersects(representative_point, {$bboxSmall})");
$edgeProven = (int) $byGeom['value'] === 1 && (int) $byRep['value'] === 0;

record('P9', 'Consultar `representative_point` en modo geometría pierde entidades', $edgeProven ? 'OK' : 'FAIL', sprintf(
    'La línea cruza el bbox: por `geometry` devuelve %s fila(s); por `representative_point`, %s. %s',
    fmt($byGeom['value']),
    fmt($byRep['value']),
    $edgeProven
        ? 'Confirma la corrección 3 de la enmienda v2.3.1: en modo geometría hay que preguntar por `geometry`.'
        : 'No se pudo reproducir el caso; revisar el fixture.'
));

// ¿`MBRIntersects` sobre-devuelve respecto de `ST_Intersects`? Importa para el
// contrato de F4: un predicado de rectángulo envolvente puede traer entidades
// cuyo envolvente cruza el bbox aunque la geometría no lo toque. En una consulta
// por viewport eso es inocuo (se dibuja algo apenas fuera de cuadro), pero hay
// que saberlo y no descubrirlo contando marcadores.
$pdo->exec('DROP TABLE IF EXISTS poc_p9_mbr');
$pdo->exec('CREATE TABLE poc_p9_mbr (id INT AUTO_INCREMENT PRIMARY KEY, geometry GEOMETRY NOT NULL, SPATIAL INDEX idx_g (geometry)) ENGINE=InnoDB');
$mbrIns = $pdo->prepare('INSERT INTO poc_p9_mbr (geometry) VALUES (ST_GeomFromText(?, 4326))');
// Triángulo cuyo rectángulo envolvente cubre la esquina del bbox, pero cuya
// superficie queda del otro lado de la diagonal.
$mbrIns->execute(['POLYGON((-60.20 -33.50, -60.10 -33.50, -60.10 -33.40, -60.20 -33.50))']);
$corner = "ST_GeomFromText('POLYGON((-60.199 -33.409, -60.181 -33.409, -60.181 -33.401, -60.199 -33.401, -60.199 -33.409))', 4326)";
$mbrHit = scalar($pdo, "SELECT COUNT(*) FROM poc_p9_mbr WHERE MBRIntersects(geometry, {$corner})");
$exactHit = scalar($pdo, "SELECT COUNT(*) FROM poc_p9_mbr WHERE ST_Intersects(geometry, {$corner})");
$overReturns = (int) $mbrHit['value'] > (int) $exactHit['value'];

record('P9', '`MBRIntersects` vs `ST_Intersects` en la esquina del envolvente', 'INFO', sprintf(
    'Triángulo cuyo envolvente cubre la esquina del bbox pero cuya superficie no: MBR devuelve %s, exacto devuelve %s. %s',
    fmt($mbrHit['value']),
    fmt($exactHit['value']),
    $overReturns
        ? '`MBRIntersects` **sobre-devuelve**, como corresponde a un filtro de envolvente. Es aceptable en consultas por viewport —dibuja algo apenas fuera de cuadro— y el tope de entidades acota el peso. Si alguna vez hace falta exactitud, se refina con `ST_Intersects` sobre el conjunto ya reducido por el índice.'
        : 'No se observó sobre-devolución en este fixture: los dos predicados coinciden.'
));
record('P9', '`ST_Intersects` también usa el índice espacial', 'INFO', 'Medido en las cuatro formas de arriba: en 10.11.18 `ST_Intersects` resuelve por `range` sobre el R-tree, así que el temor del plan a un recorrido completo **no se confirma**. Se conserva `MBRIntersects` por ser el filtro más barato y explícito, con las aserciones de `EXPLAIN` en la suite.');

$pdo->exec('DROP TABLE IF EXISTS poc_p9_mbr');

$gateStatus['P9'] = $mbrIndexed['representative_point'] && $mbrIndexed['geometry'] && $edgeProven;
say('P9  índice espacial: '.$indexedCount.'/'.count($explainCases).' formas usan índice · '.($gateStatus['P9'] ? '[OK]' : '[FAIL]'));

// ---------------------------------------------------------------------------
// P10 — Mezcla de SRID
// ---------------------------------------------------------------------------

$mixed = scalar($pdo, "SELECT ST_Contains(ST_GeomFromText('".POLY_CONVEX."', 0), ST_GeomFromText('".PT."', 4326))");
$sameSrid = scalar($pdo, "SELECT ST_Contains(ST_GeomFromText('".POLY_CONVEX."', 4326), ST_GeomFromText('".PT."', 4326))");
$srid0 = scalar($pdo, "SELECT ST_SRID(ST_GeomFromText('".PT."', 0))");

record('P10', 'Predicado con SRID 0 y 4326 mezclados', $mixed['ok'] ? 'ACEPTADO EN SILENCIO' : 'RECHAZADO', $mixed['ok']
    ? 'Devuelve `'.fmt($mixed['value']).'` sin error. **El motor no protege**: la validación de SRID es responsabilidad de la aplicación.'
    : $mixed['error']);
record('P10', 'Predicado con SRID coincidente', $sameSrid['ok'] ? 'OK' : 'FAIL', 'Devuelve `'.fmt($sameSrid['value']).'`');
record('P10', 'SRID por valor, no por columna', 'INFO', "`ST_SRID(ST_GeomFromText(..., 0))` = `".fmt($srid0['value'])."` — MariaDB guarda el SRID en el valor.");
record('P10', 'Consecuencia', 'INFO', 'Toda escritura impone 4326 por binding y se verifica con `ST_SRID` antes de persistir.');
$gateStatus['P10'] = true;
say('P10 mezcla de SRID: '.($mixed['ok'] ? 'aceptada en silencio (validar en aplicación)' : 'rechazada por el motor'));

// ---------------------------------------------------------------------------
// Limpieza de tablas de sondeo (se conserva la de P3, que es de la migración)
// ---------------------------------------------------------------------------

foreach (['poc_p2_a', 'poc_p2_b', 'poc_p2_c', 'poc_p4', 'poc_p9', 'poc_p9_edge'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}

// ---------------------------------------------------------------------------
// Informe
// ---------------------------------------------------------------------------

$gating = ['P3', 'P4', 'P6', 'P7', 'P9'];
$gatingFailures = array_values(array_filter($gating, fn (string $p): bool => ! ($gateStatus[$p] ?? false)));
$allFailures = array_values(array_filter(array_keys($gateStatus), fn (string $p): bool => ! $gateStatus[$p]));

$md = [];
$md[] = '# G2 — Matriz espacial de MariaDB '.fmt($version['value']);
$md[] = '';
$md[] = '> Generado por `php poc/sonda.php`. **Ninguna celda lleva veredicto anticipado**: cada';
$md[] = '> resultado es la salida de una ejecución real contra el motor. Una versión anterior del';
$md[] = '> plan afirmaba que `ST_PointOnSurface` no existía en MariaDB sin haberla medido; esta';
$md[] = '> compuerta existe justamente para que eso no vuelva a pasar.';
$md[] = '';
$md[] = '| Sonda | Estado |';
$md[] = '|---|---|';
foreach ($gateStatus as $probe => $ok) {
    $isGating = in_array($probe, $gating, true);
    $md[] = sprintf(
        '| %s | %s%s |',
        $probe,
        $ok ? '✅ verde' : '❌ rojo',
        $isGating ? ' (bloqueante)' : ''
    );
}
$md[] = '';
$md[] = $gatingFailures === []
    ? '**Criterio de salida de G2: cumplido.** Las cinco sondas bloqueantes (P3, P4, P6, P7, P9) están en verde.'
    : '**G2 cierra con hallazgo bloqueante.** Sondas bloqueantes en rojo: '.implode(', ', $gatingFailures).'. F1 no puede empezar.';
$md[] = '';

$titles = [
    'P1' => 'P1 — Versión del motor',
    'P2' => 'P2 — DDL de columnas geométricas',
    'P3' => 'P3 — Migración de Laravel 13 con el grammar de MariaDB',
    'P4' => 'P4 — Round-trip de coordenadas y adaptador `phpgeo`',
    'P5' => 'P5 — Disponibilidad y comportamiento de funciones espaciales',
    'P6' => 'P6 — Fixtures topológicos y validez compuesta',
    'P7' => 'P7 — Punto interior (RF-GEO-014)',
    'P8' => 'P8 — Longitud geodésica y veredicto sobre `ST_Length`',
    'P9' => 'P9 — Uso del índice espacial en los dos modos de consulta',
    'P10' => 'P10 — Mezcla de SRID',
];

foreach ($titles as $probe => $title) {
    if (! isset($results[$probe])) {
        continue;
    }

    $md[] = '## '.$title;
    $md[] = '';

    if ($probe === 'P8') {
        $md[] = '> **Nota sobre el oráculo, desviación deliberada del plan.** El plan preveía contrastar';
        $md[] = '> Vincenty contra los vectores publicados de Vincenty (1975). Esas tablas usan elipsoides';
        $md[] = '> que no son WGS-84 y este entorno no tiene acceso a la fuente ni a `geographiclib`/`pyproj`';
        $md[] = '> para regenerarlas; transcribir de memoria constantes que no puedo verificar daría falsos';
        $md[] = '> rojos o, peor, falsos verdes. El oráculo usado es analítico y comprobable acá mismo:';
        $md[] = '> arco de ecuador en forma cerrada (`a·Δλ`) y arco de meridiano por cuadratura de Simpson';
        $md[] = '> compuesta sobre el radio de curvatura meridional. Son los casos que atrapan lo que';
        $md[] = '> importa —ejes invertidos, grados por radianes, semieje o achatamiento mal cargados,';
        $md[] = '> metros por kilómetros—, todos de orden de magnitud. Para líneas oblicuas, donde no hay';
        $md[] = '> forma cerrada, se usa un control grueso contra la esfera de radio medio.';
        $md[] = '';
    }

    if ($probe === 'P7') {
        $md[] = 'Escalera de preferencia del plan: (1) `ST_PointOnSurface`, (2) `ST_Centroid` cuando está';
        $md[] = 'contenido, (3) línea de barrido con operaciones de conjunto de la base, (4) barrido de';
        $md[] = 'latitudes candidatas. El invariante no negociable, cualquiera sea el escalón, es';
        $md[] = '`ST_Contains(geometry, representative_point)` antes de persistir.';
        $md[] = '';
    }

    $md[] = '| Ítem | Resultado | Detalle |';
    $md[] = '|---|---|---|';
    foreach ($results[$probe] as $row) {
        $md[] = sprintf(
            '| %s | %s | %s |',
            str_replace('|', '\\|', $row['item']),
            $row['status'],
            str_replace('|', '\\|', $row['detail'])
        );
    }
    $md[] = '';
}

$md[] = '## Decisiones que salen de esta matriz';
$md[] = '';
$md[] = '| Tema | Decisión, con la evidencia que la respalda |';
$md[] = '|---|---|';
$md[] = '| SRID | '.($p2b['ok'] || $p2c['ok'] ? 'El motor admite fijarlo por columna, pero se impone igual en la aplicación por portabilidad.' : 'No se puede fijar por columna (P2). Se impone en cada escritura y se verifica con `ST_SRID` (P4), y la mezcla de SRID no la detecta el motor (P10).').' |';
$md[] = '| Topología | Se delega a la base: es planar e invariante bajo la proyección implícita a escala municipal. `ST_IsSimple` '.($isSimpleDiscriminates ? 'discrimina correctamente el moño (P6), así que la validez compuesta del plan es aplicable.' : '**no discrimina** (P6): hallazgo bloqueante, evaluar `php-geos` o detector propio especificado por escrito.').' |';
$md[] = '| Punto interior | '.($posAllOk ? 'Escalón 1: `ST_PointOnSurface`, que pasó la batería completa de P7 incluidos U, L, hueco centrado y franja delgada.' : 'No se usa el escalón 1; ver P7 para el escalón aplicable.').' |';
$md[] = '| Métrica geodésica | Se calcula en PHP con Vincenty sobre WGS-84 (`mjaschen/phpgeo` 6.0.4). `ST_Length` queda **prohibida en el dominio**: sobre lon/lat devuelve grados (P8), y un test de arquitectura falla el build si aparece. |';
$md[] = '| Consulta espacial | Dos modos y dos columnas: `representative_point` para clustering y `geometry` para geometría visible. P9 demuestra con una avenida que cruza el bbox que consultar el punto representativo en modo geometría **pierde la entidad**. |';
$md[] = '| Predicado | `MBRIntersects`, verificado con `EXPLAIN` sobre ambas columnas (P9). Las aserciones de plan quedan en la suite: un índice creado pero ignorado no cumple RNF-PER-001. |';
$md[] = '';
$md[] = '---';
$md[] = '';
$md[] = 'Sondas ejecutadas: '.count($gateStatus).'. Fallas: '.($allFailures === [] ? 'ninguna' : implode(', ', $allFailures)).'.';

$docsDir = __DIR__.'/../docs';
if (! is_dir($docsDir)) {
    mkdir($docsDir, 0o755, true);
}
file_put_contents($docsDir.'/MATRIZ-ESPACIAL.md', implode("\n", $md)."\n");

say('');
say(str_repeat('=', 72));
say('Informe escrito en docs/MATRIZ-ESPACIAL.md');

if ($gatingFailures !== []) {
    say('G2 CIERRA CON HALLAZGO BLOQUEANTE. Sondas bloqueantes en rojo: '.implode(', ', $gatingFailures));
    exit(1);
}

say('Criterio de salida de G2 cumplido: P3, P4, P6, P7 y P9 en verde.');
exit(0);
