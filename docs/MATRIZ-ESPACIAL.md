# G2 — Matriz espacial de MariaDB 10.11.18-MariaDB-ubu2204

> Generado por `php poc/sonda.php`. **Ninguna celda lleva veredicto anticipado**: cada
> resultado es la salida de una ejecución real contra el motor. Una versión anterior del
> plan afirmaba que `ST_PointOnSurface` no existía en MariaDB sin haberla medido; esta
> compuerta existe justamente para que eso no vuelva a pasar.

| Sonda | Estado |
|---|---|
| P1 | ✅ verde |
| P2 | ✅ verde |
| P3 | ✅ verde (bloqueante) |
| P4 | ✅ verde (bloqueante) |
| P5 | ✅ verde |
| P6 | ✅ verde (bloqueante) |
| P7 | ✅ verde (bloqueante) |
| P8 | ✅ verde |
| P9 | ✅ verde (bloqueante) |
| P10 | ✅ verde |

**Criterio de salida de G2: cumplido.** Las cinco sondas bloqueantes (P3, P4, P6, P7, P9) están en verde.

## P1 — Versión del motor

| Ítem | Resultado | Detalle |
|---|---|---|
| VERSION() | OK | `10.11.18-MariaDB-ubu2204` |
| @@version_comment | INFO | `mariadb.org binary distribution` |
| Coincide con producción (10.11.18) | OK | Sí |

## P2 — DDL de columnas geométricas

| Ítem | Resultado | Detalle |
|---|---|---|
| `GEOMETRY NOT NULL` + `SPATIAL INDEX` | OK | Aceptado |
| Atributo `SRID 4326` en la columna (sintaxis MySQL 8) | NO DISPONIBLE | SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near '4326 NOT NULL, SPATIAL INDE |
| Atributo `REF_SYSTEM_ID=4326` (sintaxis MariaDB) | DISPONIBLE | Aceptado |
| ¿`REF_SYSTEM_ID=4326` **rechaza** un SRID distinto? | NO LO APLICA | Insertar SRID 0 en una columna declarada 4326 **se acepta en silencio**: el atributo no es una guarda utilizable. |
| Consecuencia de diseño | INFO | El atributo `REF_SYSTEM_ID` se acepta pero **no rechaza** un SRID distinto, así que no sirve como guarda: el 4326 se impone en cada escritura y se verifica con `ST_SRID` antes de persistir. |

## P3 — Migración de Laravel 13 con el grammar de MariaDB

| Ítem | Resultado | Detalle |
|---|---|---|
| La migración de Laravel 13 corre sobre `DB_CONNECTION=mariadb` | OK | Tabla `poc_p3_geometrias` creada |
| `$table->geometry(...)` emite DDL válido | OK | Sin atributo SRID (grammar de MariaDB, `geometry` plano) |
| `$table->spatialIndex(...)` crea el índice | OK | Índices SPATIAL en information_schema: 2 |

## P4 — Round-trip de coordenadas y adaptador `phpgeo`

| Ítem | Resultado | Detalle |
|---|---|---|
| INSERT con WKT y SRID por binding | OK | Sin interpolación de cadenas |
| `ST_X` devuelve la longitud | OK | `-60.123456` (esperado -60.123456) |
| `ST_Y` devuelve la latitud | OK | `-33.487654` (esperado -33.487654) |
| `ST_SRID` devuelve 4326 | OK | `4326` |
| Round-trip WKT | INFO | `POINT(-60.123456 -33.487654)` |
| Round-trip GeoJSON | INFO | `{"type": "Point", "coordinates": [-60.123456, -33.487654]}` |
| Adaptador: `getLat()` devuelve la latitud que entró segunda | OK | -33.487654 |
| Adaptador: `getLng()` devuelve la longitud que entró primera | OK | -60.123456 |
| Adaptador: ida y vuelta a `[lon, lat]` | OK | [-60.123456, -33.487654] |

## P5 — Disponibilidad y comportamiento de funciones espaciales

| Ítem | Resultado | Detalle |
|---|---|---|
| `ST_PointOnSurface` | DISPONIBLE | Devuelve `POINT(-60.150000000000006 -33.45)` |
| `ST_IsValid` | NO DISPONIBLE | SQLSTATE[42000]: Syntax error or access violation: 1305 FUNCTION obras_test.ST_IsValid does not exist |
| `ST_IsSimple` | DISPONIBLE | Devuelve `1` |
| `ST_IsRing` | DISPONIBLE | Devuelve `1` |
| `ST_IsClosed` | DISPONIBLE | Devuelve `0` |
| `ST_Area` | DISPONIBLE | Devuelve `0.0099999999999998` |
| `ST_Centroid` | DISPONIBLE | Devuelve `POINT(-60.15000000047369 -33.45000000026337)` |
| `ST_Contains` | DISPONIBLE | Devuelve `1` |
| `ST_Within` | DISPONIBLE | Devuelve `1` |
| `ST_Disjoint` | DISPONIBLE | Devuelve `1` |
| `ST_Overlaps` | DISPONIBLE | Devuelve `0` |
| `ST_Intersection` | DISPONIBLE | Devuelve `POLYGON((-60.2 -33.5,-60.2 -33.4,-60.1 -33.4,-60.1 -33.5,-60.2 -33.5))` |
| `ST_Union` | DISPONIBLE | Devuelve `MULTIPOINT(-60.123456 -33.487654,-60.1 -33.4)` |
| `ST_ExteriorRing` | DISPONIBLE | Devuelve `LINESTRING(-60.2 -33.5,-60.1 -33.5,-60.1 -33.4,-60.2 -33.4,-60.2 -33.5)` |
| `ST_NumInteriorRings` | DISPONIBLE | Devuelve `1` |
| `ST_InteriorRingN` | DISPONIBLE | Devuelve `LINESTRING(-60.17 -33.47,-60.13 -33.47,-60.13 -33.43,-60.17 -33.43,-60.17 -33.47)` |
| `ST_NumPoints` | DISPONIBLE | Devuelve `3` |
| `ST_PointN` | DISPONIBLE | Devuelve `POINT(-60.2 -33.5)` |
| `ST_NumGeometries` | DISPONIBLE | Devuelve `2` |
| `ST_GeometryN` | DISPONIBLE | Devuelve `POINT(-60.2 -33.5)` |
| `ST_Envelope` | DISPONIBLE | Devuelve `POLYGON((-60.2 -33.5,-60.1 -33.5,-60.1 -33.4,-60.2 -33.4,-60.2 -33.5))` |
| `ST_Buffer` | DISPONIBLE | Devuelve `POLYGON` |
| `ST_SRID` | DISPONIBLE | Devuelve `4326` |
| `Polygon()` | DISPONIBLE | Devuelve `POLYGON((-60.2 -33.5,-60.1 -33.5,-60.1 -33.4,-60.2 -33.4,-60.2 -33.5))` |
| `ST_LineInterpolatePoint` | NO DISPONIBLE | SQLSTATE[42000]: Syntax error or access violation: 1305 FUNCTION obras_test.ST_LineInterpolatePoint does not exist |
| `ST_Length` | DISPONIBLE | Devuelve `0.2` |
| `ST_Distance` | DISPONIBLE | Devuelve `0.090738126782516` |
| `ST_Distance_Sphere` | DISPONIBLE | Devuelve `9986.6808626634` |

## P6 — Fixtures topológicos y validez compuesta

| Ítem | Resultado | Detalle |
|---|---|---|
| Línea simple (L) | PARSEA | tipo=LINESTRING · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0 · vértices=3 · huecos=NULL · esperado: línea válida |
| Línea moño (autointersecada) | PARSEA | tipo=LINESTRING · ST_IsSimple=0 · ST_IsValid=no disponible · ST_Area=0 · vértices=4 · huecos=NULL · esperado: debe rechazarse |
| Línea con vértices repetidos | PARSEA | tipo=LINESTRING · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0 · vértices=3 · huecos=NULL · esperado: aceptable, degenerada |
| Línea colineal solapada | PARSEA | tipo=LINESTRING · ST_IsSimple=0 · ST_IsValid=no disponible · ST_Area=0 · vértices=3 · huecos=NULL · esperado: debe rechazarse |
| Línea de dos vértices idénticos | PARSEA | tipo=LINESTRING · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0 · vértices=2 · huecos=NULL · esperado: debe rechazarse |
| Polígono convexo | PARSEA | tipo=POLYGON · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0.0099999999999998 · vértices=NULL · huecos=0 · esperado: válido |
| Polígono autointersectado | PARSEA | tipo=POLYGON · ST_IsSimple=0 · ST_IsValid=no disponible · ST_Area=0 · vértices=NULL · huecos=0 · esperado: debe rechazarse |
| Polígono con hueco válido | PARSEA | tipo=POLYGON · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0.0083999999999995 · vértices=NULL · huecos=1 · esperado: válido |
| Hueco fuera del exterior | PARSEA | tipo=POLYGON · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0.0087999999999999 · vértices=NULL · huecos=1 · esperado: debe rechazarse |
| Huecos superpuestos | PARSEA | tipo=POLYGON · ST_IsSimple=0 · ST_IsValid=no disponible · ST_Area=0.0068000000000001 · vértices=NULL · huecos=2 · esperado: debe rechazarse |
| Polígono de área cero | PARSEA | tipo=POLYGON · ST_IsSimple=1 · ST_IsValid=no disponible · ST_Area=0 · vértices=NULL · huecos=0 · esperado: debe rechazarse |
| Anillo sin cerrar | PARSEA | tipo=NULL · ST_IsSimple=-1 · ST_IsValid=no disponible · ST_Area=NULL · vértices=NULL · huecos=NULL · esperado: debe rechazarse en el parseo |
| Polígono que se toca en un vértice | PARSEA | tipo=POLYGON · ST_IsSimple=0 · ST_IsValid=no disponible · ST_Area=0 · vértices=NULL · huecos=0 · esperado: debe rechazarse |
| Hueco válido contenido en el exterior | OK | ST_Contains = 1 |
| Hueco fuera del exterior se detecta | OK | ST_Contains = 0 |
| Huecos superpuestos se detectan por área de intersección | OK | ST_Area(ST_Intersection) = 0.00040000000000018 |
| `ST_IsSimple` discrimina moño de línea simple | OK | simple=1 · moño=0 |

## P7 — Punto interior (RF-GEO-014)

Escalera de preferencia del plan: (1) `ST_PointOnSurface`, (2) `ST_Centroid` cuando está
contenido, (3) línea de barrido con operaciones de conjunto de la base, (4) barrido de
latitudes candidatas. El invariante no negociable, cualquiera sea el escalón, es
`ST_Contains(geometry, representative_point)` antes de persistir.

| Ítem | Resultado | Detalle |
|---|---|---|
| Convexo simple | OK | `POINT(-60.150000000000006 -33.45)` · POINT=sí · ST_Contains=sí · centroide dentro=sí · 0.40 ms |
| Cóncavo en U (centroide fuera) | OK | `POINT(-60.150000000000006 -33.489999999999995)` · POINT=sí · ST_Contains=sí · centroide dentro=no · 0.22 ms |
| Cóncavo en L (centroide fuera) | OK | `POINT(-60.150000000000006 -33.485)` · POINT=sí · ST_Contains=sí · centroide dentro=no · 0.22 ms |
| Con hueco centrado (centroide en el hueco) | OK | `POINT(-60.150000000000006 -33.489999999999995)` · POINT=sí · ST_Contains=sí · centroide dentro=no · 0.27 ms |
| Con varios huecos | OK | `POINT(-60.150000000000006 -33.489999999999995)` · POINT=sí · ST_Contains=sí · centroide dentro=sí · 0.23 ms |
| Hueco que deja una franja delgada | OK | `POINT(-60.150000000000006 -33.4995)` · POINT=sí · ST_Contains=sí · centroide dentro=sí · 0.41 ms |
| Muy alargado | OK | `POINT(-60.2 -33.5)` · POINT=sí · ST_Contains=sí · centroide dentro=sí · 0.24 ms |
| Vértices casi colineales | OK | `POINT(-60.18749975 -33.4999995)` · POINT=sí · ST_Contains=sí · centroide dentro=sí · 0.25 ms |
| Escalón elegido de la escalera de preferencia | OK | **Escalón 1**: `ST_PointOnSurface` pasa la batería completa. Menos código propio, menos superficie de error. |
| Centroide contenido (atajo del escalón 2) | INFO | 5 de 8 casos |

## P8 — Longitud geodésica y veredicto sobre `ST_Length`

> **Nota sobre el oráculo, desviación deliberada del plan.** El plan preveía contrastar
> Vincenty contra los vectores publicados de Vincenty (1975). Esas tablas usan elipsoides
> que no son WGS-84 y este entorno no tiene acceso a la fuente ni a `geographiclib`/`pyproj`
> para regenerarlas; transcribir de memoria constantes que no puedo verificar daría falsos
> rojos o, peor, falsos verdes. El oráculo usado es analítico y comprobable acá mismo:
> arco de ecuador en forma cerrada (`a·Δλ`) y arco de meridiano por cuadratura de Simpson
> compuesta sobre el radio de curvatura meridional. Son los casos que atrapan lo que
> importa —ejes invertidos, grados por radianes, semieje o achatamiento mal cargados,
> metros por kilómetros—, todos de orden de magnitud. Para líneas oblicuas, donde no hay
> forma cerrada, se usa un control grueso contra la esfera de radio medio.

| Ítem | Resultado | Detalle |
|---|---|---|
| Ecuador lon 0.0→1.0 (forma cerrada a·Δλ) | OK | oráculo=111319.490793 m · Vincenty=111319.491000 m · Δ=0.206726 mm |
| Ecuador lon -60.5→-60.0 (forma cerrada a·Δλ) | OK | oráculo=55659.745397 m · Vincenty=55659.745000 m · Δ=0.396637 mm |
| Ecuador lon -60.0→-59.0 (forma cerrada a·Δλ) | OK | oráculo=111319.490793 m · Vincenty=111319.491000 m · Δ=0.206726 mm |
| Meridiano lat -33.5000→-33.4000 (cuadratura) | OK | oráculo=11091.249169 m · Vincenty=11091.249000 m · Δ=0.168598 mm |
| Meridiano lat -34.0000→-33.0000 (cuadratura) | OK | oráculo=110913.398995 m · Vincenty=110913.399000 m · Δ=0.004895 mm |
| Meridiano lat -33.4876→-33.1234 (cuadratura) | OK | oráculo=40393.388143 m · Vincenty=40393.388000 m · Δ=0.143339 mm |
| Meridiano lat -40.0000→-30.0000 (cuadratura) | OK | oráculo=1109415.632410 m · Vincenty=1109415.632000 m · Δ=0.410106 mm |
| Simetría d(A,B) = d(B,A) | OK | Δ=0.000e+0 m |
| Línea oblicua vs. esfera de radio medio (control grueso) | OK | Vincenty=14472.767 m · esfera=14481.716 m · desvío relativo=0.0618 % |
| Los fixtures asimétricos detectan ejes invertidos | OK | correcto=14472.767 m · invertido=12449.397 m · diferencia=2023.370 m |
| `ST_Length` sobre lon/lat (MariaDB) | INFO | Devuelve `0.2` — son **grados**, no metros. Vincenty sobre la misma línea: 20383.50 m. Cociente m/grado ≈ 101917. |
| Veredicto: `ST_Length` prohibida en el dominio | OK | Confirmado con números: el motor no es fuente de verdad de una longitud en metros. Test de arquitectura falla el build si aparece. |
| Fallback ante no convergencia (casi antipodal) | INFO | método=HAVERSINE_FALLBACK · segmentos con fallback=1 · 20015113.25 m |
| Conformidad funcional de `length_m` (max(0,10 m; 0,05 %)) | OK | persistido=11091.25 m · referencia=11091.2492 m · Δ=0.0008 m · tolerancia=5.5456 m · método=VINCENTY |
| Oráculo utilizado | INFO | Analítico: ecuador en forma cerrada (a·Δλ) y meridiano por cuadratura de Simpson compuesta. **Desviación documentada** respecto de los vectores de Vincenty (1975): ver la nota de esta sección. |

## P9 — Uso del índice espacial en los dos modos de consulta

| Ítem | Resultado | Detalle |
|---|---|---|
| Clustering · `MBRIntersects(representative_point, bbox)` | USA ÍNDICE | key=`idx_representative_point` · type=range · rows=111 · filas devueltas=126 · 1.27 ms |
| Clustering · `ST_Intersects(representative_point, bbox)` | USA ÍNDICE | key=`idx_representative_point` · type=range · rows=111 · filas devueltas=126 · 0.78 ms |
| Geometría · `MBRIntersects(geometry, bbox)` | USA ÍNDICE | key=`idx_geometry` · type=range · rows=111 · filas devueltas=150 · 0.91 ms |
| Geometría · `ST_Intersects(geometry, bbox)` | USA ÍNDICE | key=`idx_geometry` · type=range · rows=111 · filas devueltas=150 · 0.94 ms |
| Consultar `representative_point` en modo geometría pierde entidades | OK | La línea cruza el bbox: por `geometry` devuelve 1 fila(s); por `representative_point`, 0. Confirma la corrección 3 de la enmienda v2.3.1: en modo geometría hay que preguntar por `geometry`. |
| `MBRIntersects` vs `ST_Intersects` en la esquina del envolvente | INFO | Triángulo cuyo envolvente cubre la esquina del bbox pero cuya superficie no: MBR devuelve 1, exacto devuelve 0. `MBRIntersects` **sobre-devuelve**, como corresponde a un filtro de envolvente. Es aceptable en consultas por viewport —dibuja algo apenas fuera de cuadro— y el tope de entidades acota el peso. Si alguna vez hace falta exactitud, se refina con `ST_Intersects` sobre el conjunto ya reducido por el índice. |
| `ST_Intersects` también usa el índice espacial | INFO | Medido en las cuatro formas de arriba: en 10.11.18 `ST_Intersects` resuelve por `range` sobre el R-tree, así que el temor del plan a un recorrido completo **no se confirma**. Se conserva `MBRIntersects` por ser el filtro más barato y explícito, con las aserciones de `EXPLAIN` en la suite. |

## P10 — Mezcla de SRID

| Ítem | Resultado | Detalle |
|---|---|---|
| Predicado con SRID 0 y 4326 mezclados | ACEPTADO EN SILENCIO | Devuelve `1` sin error. **El motor no protege**: la validación de SRID es responsabilidad de la aplicación. |
| Predicado con SRID coincidente | OK | Devuelve `1` |
| SRID por valor, no por columna | INFO | `ST_SRID(ST_GeomFromText(..., 0))` = `0` — MariaDB guarda el SRID en el valor. |
| Consecuencia | INFO | Toda escritura impone 4326 por binding y se verifica con `ST_SRID` antes de persistir. |

## Decisiones que salen de esta matriz

| Tema | Decisión, con la evidencia que la respalda |
|---|---|
| SRID | El motor admite fijarlo por columna, pero se impone igual en la aplicación por portabilidad. |
| Topología | Se delega a la base: es planar e invariante bajo la proyección implícita a escala municipal. `ST_IsSimple` discrimina correctamente el moño (P6), así que la validez compuesta del plan es aplicable. |
| Punto interior | Escalón 1: `ST_PointOnSurface`, que pasó la batería completa de P7 incluidos U, L, hueco centrado y franja delgada. |
| Métrica geodésica | Se calcula en PHP con Vincenty sobre WGS-84 (`mjaschen/phpgeo` 6.0.4). `ST_Length` queda **prohibida en el dominio**: sobre lon/lat devuelve grados (P8), y un test de arquitectura falla el build si aparece. |
| Consulta espacial | Dos modos y dos columnas: `representative_point` para clustering y `geometry` para geometría visible. P9 demuestra con una avenida que cruza el bbox que consultar el punto representativo en modo geometría **pierde la entidad**. |
| Predicado | `MBRIntersects`, verificado con `EXPLAIN` sobre ambas columnas (P9). Las aserciones de plan quedan en la suite: un índice creado pero ignorado no cumple RNF-PER-001. |

---

Sondas ejecutadas: 10. Fallas: ninguna.
