# Recorte del IGN — partido de Ramallo

Compuerta **G3**. Este archivo es el origen de todas las coordenadas verificadas del
proyecto: centro y zoom por omisión del mapa, viewbox de sesgo para la
geocodificación y las coordenadas de los fixtures de la suite.

**Se congela por versión.** Si mañana el IGN publica un recorte distinto, entra como
un archivo nuevo con su propia fecha y su propio hash, y este queda en el
historial. No se edita en el lugar: media aplicación se apoya en estas coordenadas
y un cambio silencioso movería el mapa sin que nadie lo notara.

---

## Procedencia

| | |
|---|---|
| Archivo | `ramallo-partido-20260817.geojson` |
| SHA-256 | `c4bb3568b5035d70596c317465389daf4e5c1041aab45cc744d3555dedcb36b4` |
| Servicio | WFS del Instituto Geográfico Nacional — `https://wms.ign.gob.ar/geoserver/ows` |
| Capa | `ign:departamento` · «División político administrativa de segundo orden que incluye partido y comuna» |
| Filtro | `CQL_FILTER=nam='Ramallo'` |
| Formato pedido | `application/json`, `srsName=EPSG:4326` |
| Descargado | 2026-08-17T21:18:33Z |
| Publicado | **No lo expone el servicio** (ver abajo) |
| Licencia | Ley 27.275 de acceso a la información pública, según declara el propio servicio |

Atributos que trae la entidad:

```
gid=1274  objeto=Departamento  fna=Partido de Ramallo  gna=Partido
nam=Ramallo  in1=06665  fdc=ARBA - Gerencia de Servicios Catastrales  sag=IGN
```

`in1` es el código INDEC **declarado por la fuente**; se registra tal cual, no se
valida contra otra lista. `fdc` importa: el IGN **publica** el dato, pero el
origen catastral es de **ARBA**. Cuando alguien pregunte por qué un límite no
coincide con otro mapa, la respuesta empieza acá.

### Sobre la fecha de publicación

El servicio no la expone. La capa declara sólo un `Abstract` descriptivo y el
keyword `orden:60`; el `updateSequence` del encabezado es un contador interno de
GeoServer que cambia con cualquier reconfiguración, no una fecha del dato.

Queda registrada la limitación en lugar de un valor inventado: lo que hace
auditable y reproducible al archivo es la URL exacta, el filtro, el momento de
descarga y el hash, y eso sí está completo. Si más adelante se obtiene la fecha
—ficha de Capas SIG, catálogo de metadatos del IGN o el espejo en datos.gob.ar—
se agrega acá sin volver a descargar nada, porque el hash permite comprobar que
es el mismo archivo.

---

## Sistema de referencia

Se pidió y se recibió **EPSG:4326**; el GeoJSON lo declara explícitamente
(`urn:ogc:def:crs:EPSG::4326`) y la base lo confirma con `ST_SRID`. **No hubo
transformación**: no hay reproyección que documentar ni error que arrastre.

El orden de ejes se verificó por valor, no por confianza: el primer número de cada
par es la longitud (≈ −60) y el segundo la latitud (≈ −33). WFS 2.0.0 con
`EPSG:4326` a veces devuelve `lat, lon`, y una inversión pone a Ramallo en el
océano Índico sin que ninguna validación de esquema se queje.

---

## Geometría, medida contra MariaDB 10.11.18

| Verificación | Resultado |
|---|---|
| Entidades | 1 |
| Tipo | `MULTIPOLYGON` con **1** polígono |
| Anillos interiores (huecos) | 0 |
| Vértices | 2.390 |
| `ST_SRID` | 4326 |
| `ST_IsSimple` | sí |
| Anillo exterior cerrado | sí |
| `ST_Contains(g, ST_PointOnSurface(g))` | sí |
| `ST_Contains(g, ST_Centroid(g))` | sí |
| Tamaño en disco | 58 KB |

**La validez topológica se comprueba de forma compuesta.** `ST_IsValid` **no
existe** en este build de MariaDB —lo midió la sonda P5 de G2— así que la
verificación combina simplicidad, cierre del anillo y contención de un punto
interior, que es lo que ADR-010 dejó decidido.

Los 58 KB entran sin problema en el navegador: **no se deriva ninguna versión
simplificada**. Si en F4 el peso llegara a molestar, la simplificada sería un
archivo aparte y este seguiría siendo el dato canónico.

---

## Valores derivados

Salen de acá y no se escriben a mano en ningún otro lado. Viven en
`config/obras.php`, bajo `mapa`.

| Valor | | Por qué |
|---|---|---|
| bbox | `-60.313175, -33.827512, -59.808429, -33.350769` | Envolvente del partido, en `[lon_min, lat_min, lon_max, lat_max]` |
| Centro | `[-60.057506, -33.587186]` | **Centroide**, no el centro del bbox: está comprobado dentro del polígono |
| Zoom por omisión | `11` | Manda la altura: 52,9 km en 1080 px piden ~49 m/px, y a esta latitud —un grado de longitud son ~92,7 km— eso cae en z ≈ 11,4 |
| Viewbox de geocodificación | `-60.313175,-33.350769,-59.808429,-33.827512` | El orden de Nominatim es `izquierda,arriba,derecha,abajo`, distinto del bbox |
| Punto de los fixtures | `[-60.057506, -33.587186]` | El mismo centroide, verificado interior |

El zoom es el **respaldo**: la carga normal hace `fitBounds` sobre el bbox real.
Se usa cuando no hay bounds a mano —una pantalla LIVE arrancando, un enlace
compartido sin recorte—.

Se eligió el centroide y no `ST_PointOnSurface` porque acá el segundo devuelve un
punto apoyado sobre el borde sur del partido: contenido, sí, pero pésimo centro de
mapa. Para la geometría de cada obra sigue mandando `ST_PointOnSurface`
(ADR-009): ahí lo que se necesita es un punto garantizado dentro de figuras que
pueden ser cóncavas, no uno estéticamente centrado.

---

## Cómo se vuelve a obtener

```bash
bash scripts/obtener-recorte-ign.sh
```

El script resuelve el nombre de la capa contra el `GetCapabilities`, descarga,
verifica que venga una sola entidad poligonal, comprueba el orden de ejes contra
el rango esperado e imprime el hash. Falla ruidosamente en cada uno de esos
puntos en lugar de dejar pasar un archivo silenciosamente distinto.
