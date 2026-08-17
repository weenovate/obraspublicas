# Arquitectura y decisiones (ADR)

Cada decisión lleva el problema que resuelve, la alternativa que se descartó y
**por qué la alternativa era incorrecta**. Ese último punto es el que impide que
una decisión ya resuelta se reintroduzca meses después con buena intención.

Las que corrigen versiones anteriores del plan están marcadas como tales.

---

## Panorama

```
Apache + PHP-FPM 8.4 (HTTPS obligatorio, cPanel MultiPHP)
└── Laravel 13
    ├── /            Web pública   → Vue 3 (sin login) → GET /api/publico/*  [allowlist + bbox]
    ├── /admin       Backoffice    → Inertia 2 + Vue 3 (sesión Laravel, políticas por rol)
    ├── /live        LIVE kiosco   → Inertia 2 + Vue 3 (sesión persistente revocable)
    ├── Estilos      RDS (tokens --rml-* + clases .rml-*) · claro y oscuro · puente Leaflet
    ├── Servicios    Geometría · Código · Enrutado ORS · Geocodificación
    │                Visibilidad · Auditoría · Fotos (cola database) · Caché versionado
    └── MariaDB 10.11.18 — persistencia, topología planar e índices SPATIAL
```

---

## ADR-001 · MariaDB 10.11.18 como único motor soportado (D1)

**Contexto.** La especificación fija MySQL 8.4 LTS. El entorno productivo
confirmado es MariaDB 10.11.18 sobre AlmaLinux + cPanel, con la salida literal de
`SELECT VERSION()` registrada en la compuerta G1.

**Decisión.** MariaDB 10.11.18 es el único motor soportado. `DB_CONNECTION=mariadb`,
no `mysql`: Laravel 11+ trae un grammar dedicado para MariaDB y el DDL geométrico
que emite es distinto.

**Por qué importa más de lo que parece.** No es un cambio de proveedor con la
misma API. MySQL 8 admite `GEOMETRY SRID 4326` como restricción de columna y
`ST_Length` con unidades; MariaDB no tiene lo primero y su `ST_Length` sobre
lon/lat devuelve grados. Toda la estrategia geométrica cambió por esto, y por eso
existe la compuerta G2.

**Consecuencia operativa.** Desarrollo, CI y producción corren el mismo 10.11.18.
Un 10.11.x distinto invalidaría celdas de `MATRIZ-ESPACIAL.md`, porque parte de
ellas dependen de qué funciones trae el build.

---

## ADR-002 · La base hace topología; la métrica se calcula en PHP

**Decisión.** La base de datos hace persistencia, indexado y topología planar.
Toda métrica geodésica se calcula en PHP. **La base nunca es fuente de verdad de
una longitud, distancia o área en metros.**

**Evidencia.** P8 de la matriz: `ST_Length` sobre la misma línea devuelve `0.2`
—grados— mientras Vincenty devuelve metros. Confiar en el motor produciría
`length_m` incorrecto en silencio, el peor modo de falla posible para RF-GEO-011.

**Por qué la topología sí se delega.** La simplicidad de una línea, la validez de
un anillo y la contención de un hueco son propiedades **planares**, invariantes
bajo la proyección plate carrée que MariaDB aplica implícitamente. A escala de un
partido esa proyección es un homeomorfismo, así que una autointersección
detectada en el plano es exactamente una autointersección en el terreno. Ninguna
de esas funciones devuelve una magnitud en metros.

**Guardián.** `ST_Length` está prohibida en el código de dominio y un test de
arquitectura falla el build si aparece.

---

## ADR-003 · `[longitud, latitud]` como convención canónica

**Decisión.** `[lon, lat]` en todas las capas (RFC 7946). `POINT(lon lat)` hacia
la base, con WKT y SRID **siempre por binding**. `ST_X` es longitud, `ST_Y` es
latitud.

**El problema real.** `phpgeo` invierte la convención: `new Coordinate($lat, $lng)`.
Es la frontera exacta donde se cuelan los errores de ejes, y un error de ejes no
da excepción: da una obra dibujada en otro continente o una longitud plausible
pero equivocada.

**Decisión de diseño.** Una única clase, `GeoJsonPhpGeoAdapter`, concentra toda
conversión, con métodos cuyo nombre dice el orden de los argumentos
(`coordinateFromLonLat`). Un test de arquitectura falla el build si
`Location\Coordinate` se instancia en cualquier otro archivo.

**Fixtures asimétricos.** Longitud ≈ −60 y latitud ≈ −33 son inconfundibles: un
intercambio rompe la aserción en vez de compensarse. Con valores simétricos
(−33 y −33) un bug de ejes pasaría los tests, y el test daría una falsa seguridad
peor que no tenerlo.

---

## ADR-004 · Atomicidad de la auditoría (corrige la v2.2)

**Lo que estaba mal.** La v2.2 proponía escribir la auditoría por una **segunda
conexión** con usuario restringido, para conseguir inmutabilidad. Eso es
incorrecto: una conexión distinta tiene su propia transacción, así que quedan dos
modos de falla, ambos silenciosos.

- Si la transacción de negocio se revierte, el evento **queda escrito** y la
  bitácora afirma un cambio que nunca ocurrió.
- Si la escritura de auditoría falla, el negocio **puede confirmar igual** y el
  cambio queda sin registrar, incumpliendo RF-AUD-001.

**Decisión.** La auditoría de cambios de negocio se escribe en la **misma conexión
y la misma transacción** que el cambio. La atomicidad pasa a ser estructural: o
quedan los dos, o no queda ninguno.

**La inmutabilidad no necesitaba una segunda conexión**, porque para insertar en
la misma transacción sólo se necesita el privilegio `INSERT`. Tres capas:

1. **Privilegios de tabla**: `INSERT` y `SELECT` sobre `audit_events`, sin
   `UPDATE` ni `DELETE`. cPanel otorga `ALL` desde su interfaz, así que el ajuste
   fino va por SQL con un usuario privilegiado. Es un ítem verificable del
   runbook; si el hosting no lo permite, queda como riesgo residual documentado.
2. **Disparadores** `BEFORE UPDATE` y `BEFORE DELETE` con `SIGNAL SQLSTATE '45000'`.
3. **Guardas de aplicación**: el modelo lanza excepción ante `update` y `delete`.

**La excepción, acotada.** El camino no transaccional existe sólo para intentos
**fallidos o denegados**, donde por definición no hay transacción de negocio:
login fallido, denegación de autorización (CA-014) y rechazo por límite de tasa.

**Corrección de la v2.3.** La v2.3 incluía «revocación de sesión» en esa lista, y
estaba mal: una revocación exitosa **es** un cambio de negocio —modifica
`auth_sessions`— así que debe ser atómica con su auditoría. Lo mismo vale para
todo lo que tiene éxito: **login exitoso**, cierre de sesión, cambio de
contraseña, desactivación de usuario y revocación iniciada por el Admin.

**Fe de erratas.** El login exitoso se registra con `registrar()` y **después** de
crear la sesión y regenerar su identificador. Escribirlo antes afirmaría un
ingreso que todavía puede fallar.

**Ampliación de F0.** Un intento denegado **sí** puede ocurrir dentro de una
transacción ajena: una denegación de autorización que salta en medio de una
actualización que después se revierte. Con `AUDIT_INDEPENDENT_CONNECTION`
configurada, `registrarIntentoFallido()` escribe por una conexión aparte y el
evento sobrevive al rollback. Sin configurar, avisa por log al detectar una
transacción abierta. Esto **no** reintroduce el error de la v2.2, porque aplica
sólo a eventos que no acompañan ningún cambio de negocio.

---

## ADR-005 · Publicación inmediata y caché: un presupuesto, no una contradicción

**La tensión aparente.** RF-BO-007 exige publicar en la misma transacción;
RF-BO-010 da hasta 60 s en Web y 30 s en LIVE; RF-WEB-007 y RF-LIV-004 fijan
sondeos de 60 s y 30 s.

**Cómo se resuelve.** Son dos cosas distintas. **Publicación** es un hecho de la
base: al confirmar, la obra ya es pública. Los 30 y 60 segundos son el
**presupuesto de propagación** hacia clientes con la pantalla ya abierta, y se
consumen con dos sumandos: frescura del caché y sondeo del cliente.

**Lo que estaba mal en la v2.2.** TTL de 60 s **más** sondeo de 60 s da hasta 120 s
en el peor caso: el doble del presupuesto. La corrección es que el caché sea la
**red de seguridad** y no el mecanismo de propagación.

| Superficie | Invalidación | TTL | Sondeo | Peor caso | Presupuesto |
|---|---|---|---|---|---|
| Web pública | Sincrónica al commit | 30 s | 30 s | 30 s | 60 s ✔ |
| LIVE | Sincrónica al commit | sin caché | 15 s | 15 s | 30 s ✔ |

**Invalidación sin Redis.** Los drivers `database` y `file` no soportan etiquetas,
así que no se puede invalidar por tag. Se usa **clave de versión**: las claves
incluyen `works:v{N}` y la invalidación incrementa `N` en una fila. Atómico, O(1),
sin recorrer claves. El incremento va **siempre después del commit**, nunca dentro
de la transacción.

La clave combina `works:v{N}` **más la versión de visibilidad**. Sin ese segundo
componente CA-021 falla: el Admin oculta un campo y la respuesta cacheada sigue
incluyéndolo.

---

## ADR-006 · Snapshot completo del bbox, no protocolo incremental (corrige la v2.3)

**Lo que estaba mal.** La v2.3 proponía un delta con marca de agua
(`?desde=<watermark>`) que devolvía obras modificadas e ids dados de baja. El
error es sutil y vale explicarlo, porque suena eficiente: el conjunto que el
cliente tiene en pantalla está delimitado por **bbox + filtros + versión de
visibilidad**, y un delta calculado sólo por tiempo **no puede informar las
salidas de ese conjunto**. Quedan al menos cuatro caminos a obra fantasma:

1. **La obra se mueve** fuera del bbox. Cambió, así que el delta debería
   mencionarla, pero la respuesta está filtrada por bbox y la excluye: el marcador
   queda dibujado en la posición vieja.
2. **Deja de cumplir un filtro** (cambia de estado o subcategoría): sale por
   exclusión, no por aviso.
3. **Cambia la visibilidad** de un campo: el cliente conserva los valores de obras
   no modificadas, así que el dato oculto sigue en pantalla (incumple CA-021).
4. **Borrado definitivo**: no queda fila que reportar, salvo agregar una tabla de
   tumbas, que es complejidad nueva sólo para sostener el delta.

**Decisión.** El endpoint devuelve el **snapshot completo** del conjunto
`(bbox, filtros, versión de visibilidad)` y el cliente **reemplaza su conjunto
entero**. Lo que no viene en la respuesta, no está. Es una corrección de
correctitud, no de rendimiento.

**El costo se controla** con `ETag` e `If-None-Match` —el sondeo sin cambios
responde 304 sin cuerpo, que es el caso mayoritario—, bbox encajado a grilla para
compartir caché entre paneos cercanos, tope de entidades y seis decimales de
precisión (~11 cm, la que el propio spec muestra).

**Costo asumido y escalón de salida.** La versión global se incrementa ante
cualquier escritura, así que una edición invalida el `ETag` de todos los bbox. Con
10.000 obras y el ritmo de edición de un municipio eso es sostenible y se prefiere
por simplicidad. Si alguna vez no lo fuera, el escalón documentado es una
**versión espacial por celda de grilla**. No se implementa sin evidencia.

---

## ADR-007 · Dos modos de consulta espacial y dos columnas (corrige la v2.3)

**Lo que estaba mal.** La v2.3 consultaba `representative_point` en los dos modos.
Produce un error visible: el punto representativo de una avenida larga puede caer
**fuera** del viewport mientras la geometría lo cruza de lado a lado. La línea
desaparece justo cuando el usuario se acerca a mirarla.

| Modo | Umbral | Columna | Predicado |
|---|---|---|---|
| Clustering | Bajo el umbral de geometría | `representative_point` | `MBRIntersects(representative_point, bbox)` |
| Geometría visible | Al superarlo | **`geometry`** | `MBRIntersects(geometry, bbox)` |

**Medido en P9**, con una avenida que cruza el bbox: por `geometry` devuelve 1
fila; por `representative_point`, 0.

En clustering, en cambio, `representative_point` es la columna **correcta** y no
una aproximación: el conglomerado se ancla en un punto por definición (RF-WEB-009).

**Hallazgos de P9 que ajustan el plan.**

- `ST_Intersects` **también** usa el índice R-tree en 10.11.18 (`type=range`): el
  temor a un recorrido completo no se confirma. Se conserva `MBRIntersects` por ser
  el filtro más barato y explícito, con aserciones de `EXPLAIN` en la suite.
- `MBRIntersects` **sobre-devuelve** en la esquina del envolvente (1 vs 0 en el
  fixture). Es aceptable en consultas por viewport —dibuja algo apenas fuera de
  cuadro— y el tope de entidades acota el peso. Si hiciera falta exactitud, se
  refina con `ST_Intersects` sobre el conjunto ya reducido por el índice.

---

## ADR-008 · Fecha efectiva por `CASE`, materializada (corrige la v2.3)

**El problema del modelo original.** Una sola columna `end_date` que significa dos
cosas según el estado pierde el pronóstico al finalizar, no distingue una fecha
real conservada de una prevista cuando la obra vuelve atrás, y ata la regla a la
clave fija `COMPLETED`, que RF-OBR-009 permite no usar.

**Decisión.** Dos columnas más una bandera de catálogo:

| Columna | Obligatoriedad | Regla |
|---|---|---|
| `estimated_end_date` | Siempre | ≥ `start_date`. **Nunca se sobrescribe** al finalizar. |
| `actual_end_date` | **Obligatoria** con `is_final = true` | ≥ `start_date` y ≤ hoy. Con `is_final = false` **puede conservarse como valor histórico** y no participa de la fecha efectiva. |

`work_statuses.is_final` gobierna toda regla de finalización; nunca se compara
contra la clave `COMPLETED`.

**Lo que estaba mal en la v2.3.** Proponía `COALESCE(actual_end_date,
estimated_end_date)`. Como `actual_end_date` **se conserva** al salir de un estado
finalizador, `COALESCE` devolvería esa fecha real histórica aunque la obra ya no
esté terminada: exactamente el error que motivó separar las columnas. La expresión
correcta es:

```sql
CASE WHEN work_statuses.is_final THEN works.actual_end_date
     ELSE works.estimated_end_date END
```

**Materializada, no calculada.** Evaluar ese `CASE` sobre un join en cada filtro
impide usar un índice y choca con RNF-PER-001 con 10.000 obras. `works` lleva una
columna real `effective_end_date DATE NOT NULL`, recalculada en cada guardado, y
el filtro por rango queda plano e indexable.

**Como es dato derivado, necesita guardas**: test de invariante y comando
`obras:verificar-integridad`. El único camino que podría desincronizarla es cambiar
`is_final` de un estado en uso, y por eso está prohibido.

**`CANCELLED` no es finalizador**: una obra cancelada no se terminó, así que
conserva la semántica de fecha prevista. Eso **no restringe el flujo**: RF-OBR-007
no define máquina de estados y una obra cancelada puede volver a cualquier estado.

---

## ADR-009 · Punto interior: `ST_PointOnSurface` (resuelto por medición)

**Lo que estaba mal.** La v2 del plan afirmaba que `ST_PointOnSurface` no existía
en MariaDB. Está documentada, y darla por ausente sin medirla es el error que la
compuerta G2 existe para evitar.

**Medición (P7).** Existe y pasa los ocho casos de la batería, incluidos cóncavo
en U y en L con centroide fuera, hueco centrado donde el centroide cae en el
hueco, y hueco que deja una franja delgada como única región válida.

**Decisión.** Escalón 1 de la escalera: `ST_PointOnSurface`. Menos código propio,
menos superficie de error. Los escalones 2 a 4 quedan documentados para el caso de
que una versión futura del motor cambie el comportamiento.

**Invariante no negociable**, cualquiera sea el escalón: antes de persistir se
verifica `ST_Contains(geometry, representative_point)`; si falla, el guardado se
rechaza. Vale también para líneas.

---

## ADR-010 · Validez topológica compuesta

**Medición (P5).** `ST_IsValid` **no está disponible** en 10.11.18.
`ST_LineInterpolatePoint` tampoco.

**Decisión.** La validez de RF-GEO-013 se descompone en primitivas medidas y
verificadas en P6: `ST_IsSimple` para líneas y anillos, `ST_NumPoints` para
vértices mínimos, `ST_Area > 0`, contención de huecos por
`ST_Contains(Polygon(ST_ExteriorRing(g)), Polygon(ST_InteriorRingN(g, n)))`, y no
superposición entre huecos por área nula de `ST_Intersection` par a par, acotada
por un máximo configurable de huecos.

**El riesgo que se descartó.** `ST_IsSimple` tiene historial de defectos. P6 la
sometió a las variantes degeneradas y **discrimina correctamente** el moño de la
línea simple, así que no hay hallazgo bloqueante y no hace falta `php-geos` ni un
detector propio.

El punto medio de una línea se calcula en PHP por longitud geodésica acumulada,
porque `ST_LineInterpolatePoint` no existe.

---

## ADR-011 · SRID impuesto por la aplicación (resuelto por medición)

**Medición (P2 y P10).** La sintaxis `GEOMETRY SRID 4326` de MySQL 8 **no parsea**.
El atributo propio de MariaDB `REF_SYSTEM_ID=4326` **se acepta pero no rechaza**
un SRID distinto: insertar SRID 0 en una columna declarada 4326 se acepta en
silencio. Y un predicado que mezcla SRID 0 y 4326 tampoco da error.

**Decisión.** El SRID no se declara en la columna, porque el atributo que existe no
es una guarda utilizable. Se impone 4326 en cada escritura por binding y se
verifica con `ST_SRID` antes de persistir. La validación de SRID es
responsabilidad de la aplicación, y no puede depender de una sintaxis específica
del motor.

---

## ADR-012 · Longitud geodésica: Vincenty con fallback persistido

**Decisión.** Solución inversa de Vincenty sobre WGS-84
(`a = 6 378 137 m`, `1/f = 298.257223563`), segmento por segmento, con
`mjaschen/phpgeo` 6.0.4. No se escribe Vincenty a mano.

Se descartó «Haversine sobre elipsoide» por contradictorio: **Haversine es
esférico, Vincenty elipsoidal**.

**Dos tolerancias, reportadas por separado**, porque un fallo en cada una apunta a
un lugar distinto:

| Tolerancia | Contra qué | Cota |
|---|---|---|
| Conformidad algorítmica | Oráculo independiente | ±1 mm |
| Conformidad funcional | `length_m` persistido, al centímetro | `max(0,10 m; 0,05 %)` |

**Desviación documentada sobre el oráculo.** El plan preveía los vectores
publicados de Vincenty (1975). Esas tablas usan elipsoides que no son WGS-84 y
este entorno no tiene acceso a la fuente ni a `geographiclib`/`pyproj` para
regenerarlas; transcribir constantes no verificables daría falsos rojos o, peor,
falsos verdes. El oráculo es **analítico**: arco de ecuador en forma cerrada
(`a·Δλ`) y arco de meridiano por cuadratura de Simpson compuesta sobre el radio de
curvatura meridional. Cubre justamente los errores que importan —ejes invertidos,
grados por radianes, semieje mal cargado, metros por kilómetros—, todos de orden
de magnitud. Para líneas oblicuas, donde no hay forma cerrada, se usa un control
grueso contra la esfera de radio medio.

**Fallback persistido.** Vincenty no converge para puntos casi antipodales; a
escala municipal no debería ocurrir nunca, y por eso una ocurrencia es una
anomalía que hay que poder ver: `works.length_calc_method` guarda `VINCENTY` o
`HAVERSINE_FALLBACK`, se registra en log con `request_id`, en el evento de
auditoría y como métrica de alerta, y el backoffice marca la longitud como
aproximada. **No se publica** en Web ni LIVE: la tabla 6.3 no contempla el campo.

---

## ADR-013 · RDS como única capa de estilos, sin Tailwind (D4)

**Decisión.** El Ramallo Design System es la única capa de estilos. Se descarta
Tailwind.

**Por qué no conviven.** El RDS ya trae reset, tokens, componentes y utilidades, y
sus utilidades se llaman **exactamente igual** que las de Tailwind con semántica
propia: `.flex`, `.items-center`, `.justify-between`, `.gap-2…5`, `.mt-2…7`,
`.text-secondary`, `.text-muted`. Cargar ambos deja la resolución al orden de
importación del bundler, con diferencias visuales que aparecen y desaparecen según
el build. Es el tipo de problema que consume días y no deja rastro.

**Estructura.** El paquete vive intacto en `resources/rds/`, preservando la
jerarquía `css/` + `fonts/` porque `tokens/fonts.css` referencia `../../fonts/…`.
Las extensiones propias viven en `resources/css/`, separadas para que siempre se
sepa qué es del paquete y qué es nuestro.

**Orden de importación**, que no es arbitrario: paquete → tema oscuro →
correcciones de accesibilidad → `color-scheme` → puente de Leaflet → componentes
de aplicación. Las correcciones de accesibilidad van después del tema oscuro
porque también corrigen tokens de ese tema y tienen que ganar.

---

## ADR-014 · `color-scheme` por estado de tema (corrige la v2.2)

**Lo que estaba mal.** `color-scheme: light dark` en `:root` de forma
incondicional. Con `data-theme="light"` explícito en un dispositivo configurado en
oscuro, ese valor autoriza al navegador a pintar los controles nativos —campos de
fecha, desplegables, barras de desplazamiento— en oscuro, contradiciendo la
elección del usuario.

**Decisión.** Se declara por estado:

```css
:root[data-theme='light'] { color-scheme: light; }
:root[data-theme='dark']  { color-scheme: dark; }
:root:not([data-theme])   { color-scheme: light dark; }
```

Los controles nativos siguen siempre al tema **efectivo**. Se verifica en
Playwright con los tres estados, incluidos los dos cruzados, que es lo único que
demuestra que la elección del usuario gana.

**Corolario del tema oscuro.** El tema se construye redefiniendo **sólo aliases
semánticos**; las escalas de marca no se tocan. Ningún color se define únicamente
dentro de un media query, y el verificador de contraste exige que el bloque de la
elección explícita y el del media query declaren los mismos tokens: si uno se
actualiza y el otro no, el tema oscuro se comporta distinto según cómo se llegó a
él, y eso no se encuentra mirando una pantalla.

---

## ADR-015 · El RDS entregado no cumple AA: corrección por extensión

**Hallazgo.** Medido con `npm run rds:contraste`, el RDS tal como fue entregado
falla WCAG 2.2 AA en varios pares del **tema claro**. Los dos más serios afectan
cada pantalla del backoffice.

| Par | Medido | Mínimo |
|---|---|---|
| Texto blanco sobre botón primario (`green-600`) | 3,18:1 | 4,5:1 |
| Texto blanco sobre superficie de marca (`green-500`) | 2,07:1 | 4,5:1 |
| Borde de campos (`neutral-300`) sobre tarjeta | 1,76:1 | 3:1 |
| Anillo de foco (`cyan-200`) sobre tarjeta | 1,30:1 | 3:1 |
| `text-muted` sobre página / hundido | 4,39 / 4,08:1 | 4,5:1 |
| Borde del botón secundario sobre hundido | 2,79:1 | 3:1 |

**Decisión.** Se corrige en `resources/css/rds-a11y.css`, con tres criterios:

1. **Ninguna escala de marca se toca.** Los aliases se mueven a otros pasos de las
   mismas escalas: el verde sigue siendo el verde de Ramallo, un paso más oscuro
   donde hace falta.
2. **El verde exacto se conserva donde es decorativo.** En lugar de oscurecer la
   banda de marca, el texto encima pasa a oscuro: 8,52:1 sobre el mismo `green-500`.
3. **Tres tokens nuevos**, porque el paquete usa un solo `--rml-text-on-brand` para
   el botón verde y el naranja y no hay un color de texto que cumpla sobre los dos:
   `--rml-text-on-accent`, `--rml-text-on-surface-brand`, `--rml-focus-ring-color`.

**Pendiente de la Municipalidad.** El ajuste cambia levemente el aspecto de
botones y bordes. Los números están en `DESIGN-SYSTEM.md` para que la decisión sea
informada: aceptar el ajuste o revisar la paleta de origen. Lo que no es opcional
es cumplir RNF-ACC-001.

---

## ADR-016 · Política de secretos por categoría

No todos los «secretos» son secretos, y tratarlos igual lleva a exponer uno o a
complicar sin motivo otro.

**(a) Secretos de backend — nunca salen del servidor.** Clave de ORS, credenciales
de base, `APP_KEY`, correo, destino y clave pública de backups. Sólo en `.env`
fuera del webroot; nunca en la base funcional, nunca en `app_settings`, nunca en
auditoría (RF-CFG-003), nunca en el repositorio.

**(b) Configuración de Nominatim — no es secreto, es cumplimiento.** La instancia
pública no usa API key; lo que importa es la configuración que su política exige:
User-Agent y correo de contacto identificatorios, límite de 1 req/s, TTL de caché
y viewbox. El correo es institucional y va en `.env` por ambiente.

**(c) Token público restringido de teselas — puede ser público, con condiciones.**
RF-GEO-016 es condicional y varios proveedores emiten tokens diseñados para el
cliente, algunos prohibiendo el proxy en sus términos. Orden de preferencia: proxy
desde el backend si el proveedor lo permite; si no, token público **sólo si**
cumple las seis condiciones: restringido por dominios autorizados, con cuota
configurada, distinto por ambiente, rotable con procedimiento probado, documentado
como público por diseño, e inyectado por variable de entorno sin committearse. Un
token que no cumpla las seis no se usa en cliente. La atribución se muestra siempre.

---

## ADR-017 · Backups: dos credenciales y lifecycle del proveedor (corrige la v2.3)

**Lo que estaba mal.** La v2.3 decía «sólo credenciales de escritura» y a la vez
«verificación de que el objeto subido es legible» y «retención 30 días». Las tres
cosas no pueden convivir si el servidor no puede leer ni borrar.

**Decisión.** Destino externo obligatorio, en cuenta distinta del hosting, con
**dos credenciales separadas**:

| Credencial | Dónde vive | Permisos | Para qué |
|---|---|---|---|
| Escritura habitual | En el VPS, en `.env` | `PutObject` únicamente | Subir el backup diario. Un atacante con acceso al servidor **no puede leer ni destruir** el historial. |
| Lectura para restauración | **Fuera del VPS**, en la Municipalidad | `GetObject`, `ListBucket` | Restaurar, desde staging o una máquina de operaciones. Nunca en el `.env` de producción. |
| Lifecycle del bucket | Configuración del proveedor | Expira a los 30 días | Aplica RNF-SEC-008 **sin que el servidor borre**, y por eso la credencial de escritura no lleva `DeleteObject`. |

**Cifrado del lado del cliente** con `age`: el VPS guarda sólo la clave pública, así
que no puede descifrar sus propios backups. La clave privada la custodia la
Municipalidad, con dos custodios nombrados.

**Verificación de integridad coherente con una credencial de sólo escritura:** la
suma de comprobación se calcula localmente antes de subir y se confirma en la
respuesta del `PutObject`. Que el objeto **se pueda recuperar y descifrar** se
comprueba en la restauración trimestral, que es el único momento en que las dos
custodias están en juego. Una prueba que no ejercita ambas no prueba nada: si nadie
puede leer o nadie puede descifrar, el backup no existe.

---

## ADR-018 · `public/build` por release, nunca compartido

**Decisión.** Sólo `.env` y `storage` se comparten entre releases. `public/build`
va **dentro de cada release**.

**Por qué.** Vite emite nombres con hash más un `manifest.json`, y ese par es una
unidad indivisible. Un `build` compartido serviría el manifiesto de un release
contra los assets de otro, con fallos intermitentes, y rompería el rollback justo
cuando más se lo necesita. Por release, repuntar `current` restaura de forma
atómica el manifiesto **y** sus assets.

**Corolario: esquema expansivo.** Para que el rollback sea seguro, se agrega y no
se rompe: renombrados y borrados de columnas en dos releases, para que el código
anterior siga funcionando contra el esquema nuevo.

**No se ejecuta `storage:link`.** Las fotos son privadas y se sirven por
controlador con URL firmada; un symlink de `storage` en el document root abriría lo
que RNF-SEC-005 quiere cerrado.

---

## ADR-019 · Fotos: la obra se publica antes de que las fotos estén listas

**Tensión aparente.** La sección 14 del spec dice que «el alta sólo se confirma
cuando todos los archivos obligatorios finalizaron»; RF-BO-007 exige publicación
inmediata.

**Resolución.** **Ninguna foto es obligatoria.** La obra se publica de inmediato y
cada foto aparece al llegar a `READY`, lo que además incrementa la versión de
caché. Una falla de procesamiento no invalida datos ya guardados, que es
exactamente lo que esa sección pide.

Una foto `PENDING` o `FAILED` **nunca** se publica. El job es idempotente por id:
reprocesar sobrescribe los derivados sin duplicar filas ni archivos.

---

## ADR-020 · Tests contra MariaDB, no SQLite

**Decisión.** La suite corre contra MariaDB 10.11.18, incluso a costa de
necesitar un servicio en CI.

**Por qué.** Buena parte de lo que hay que verificar es específica del motor: DDL
geométrico, uso real del índice SPATIAL en el plan de consulta, disparadores de
inmutabilidad y comportamiento de `ST_*`. Un test verde sobre SQLite no dice nada
sobre producción, y da una confianza que no corresponde.

---

## ADR-021 · La preferencia de tema se alinea con el spec: dos valores y un respaldo configurable

**Contexto.** F0 implementó `users.theme_preference` como
`enum('light','dark','system')` con `system` por omisión, donde `system` sigue al
dispositivo. El spec dice otra cosa: RF-CFG-004 define LIGHT o DARK, y RF-CFG-005
dice que si la preferencia falta se usa **el tema predeterminado configurado**, que
es un valor de `app_settings`, no el del sistema operativo del usuario.

**Decisión.** `theme_preference` pasa a `enum('light','dark')` **nullable**, sin
valor por omisión. Vacío significa «no eligió», y entonces manda
`app_settings.default_theme`. Los usuarios existentes reciben `light` en la
migración, que es lo que RF-CFG-005 pide. La Web pública **conserva** el
seguimiento del dispositivo: eso es RF-THE-001 y no estaba en discusión.

**Alternativa descartada: dejar `system` y tratarlo como el respaldo.** Parece
inofensivo y ahorra una migración, pero borra una distinción que el spec necesita.
Con `system`, la Municipalidad no puede fijar el tema con el que se ve el sistema:
cada pantalla haría lo que dijera su sistema operativo, y la opción de
configuración quedaría sin efecto para todos los que nunca tocaron su perfil —que
son la mayoría—. Peor: no habría forma de distinguir «quiero seguir al
dispositivo» de «no elegí nada», y esas dos cosas necesitan respuestas distintas.

**Consecuencia operativa.** El backoffice estampa `data-theme` **siempre**, y las
superficies que siguen al dispositivo se declaran por nombre de ruta en
`HandleInertiaRequests::RUTAS_SIN_TEMA_ESTAMPADO`. Hoy hay una —la página de
referencia del RDS, que existe justamente para revisar los tres estados—; en F4 se
suman las rutas de la Web pública. `null` ahí significa **ausencia del atributo**,
no tema claro: con `data-theme=""` el selector del tema oscuro por preferencia del
dispositivo dejaría de aplicar.

---

## ADR-022 · Auditar una denegación exige sacar `AuthorizationException` de la lista de ignorados

**Contexto.** CA-014 pide que todo intento denegado quede registrado. El registro
se engancha en el manejador de excepciones y no en un middleware porque una
denegación puede saltar en cualquier punto: un `Gate::authorize()` en el
controlador, una policy en un form request, un `can:` en la ruta. Un middleware
sólo vería el último caso.

**El detalle que costó un test.** Laravel ignora `AuthorizationException` de
fábrica: está en `$internalDontReport`, junto con `ValidationException` y
`HttpException`. Con la excepción ignorada, `report()` retorna antes de ejecutar
ningún callback, así que el registro **nunca se ejecutaba** y la única señal era un
test rojo. Hacen falta dos pasos: `stopIgnoring(AuthorizationException::class)`
para que llegue al manejador, y `->stop()` en el callback para que después de
auditarla no se la mande también al log de errores —un 403 es un evento de
seguridad, no una falla de la aplicación—.

**Por qué el callback está tipado y no toma `Throwable`.** Porque `->stop()` corta
el reporte de todo lo que el callback maneje. Con `Throwable`, ese `stop()`
silenciaría el log de **todas** las excepciones de la aplicación, incluidos los
errores reales. El tipo del primer parámetro es lo que delimita el alcance.

**Qué NO se registra.** La ruta, el método, el nombre de ruta y el actor. Nunca el
cuerpo, la cadena de consulta ni la respuesta: una bitácora que copia lo que el
usuario no tenía permiso de ver convierte el registro de seguridad en la filtración
que quería evitar. Hay un test que lo verifica con datos reconocibles.

---

## ADR-023 · Los mensajes del framework se traducen en el repositorio, no se dejan al idioma por omisión

**Contexto.** La aplicación corre con `APP_LOCALE=es`, y Laravel sólo trae el juego
de mensajes de validación en inglés. Sin un `lang/es/`, el traductor no encuentra
la clave y devuelve **la clave misma**: el usuario ve `validation.required` en el
formulario de alta de usuarios.

**Decisión.** `lang/es/validation.php`, `auth.php`, `passwords.php` y
`pagination.php` versionados, con un test que envía un formulario inválido y falla
si algún mensaje contiene `validation.`.

**Alternativa descartada: traer un paquete de traducciones.** Agrega una dependencia
—y su cadena de actualizaciones— para cuatro archivos de texto que no cambian, y
deja la redacción de los mensajes que ve el vecino en manos de un tercero. Los
nombres de campo, además, se pasan por llamada: «contraseña temporal» y
«contraseña» son cosas distintas para quien está mirando la pantalla, aunque el
campo se llame igual en las dos.

---

## Notas del entorno de construcción

Dos limitaciones del entorno donde se ejecutó esta iteración, que **no** afectan al
producto y quedan registradas para que nadie las confunda con decisiones:

- La política de egreso bloquea el CDN de blobs de Docker Hub
  (`production.cloudfront.docker.com`, 403). La imagen `mariadb:10.11.18` se
  obtuvo por un espejo permitido (`mirror.gcr.io`). `docker-compose.yml` referencia
  la imagen canónica, que es lo correcto en cualquier entorno con acceso normal.
- Las descargas *dist* de Composer desde `api.github.com` también dan 403, así que
  `phpstan/phpstan` —el único paquete del lock sin `source`— se obtuvo por git y se
  precargó en el caché local de Composer. El `composer.lock` versionado es
  canónico: en CI la instalación normal funciona sin intervención.
