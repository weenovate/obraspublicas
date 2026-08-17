/**
 * El único lugar del cliente que conoce el orden de los ejes de Leaflet.
 *
 * El proyecto es `[longitud, latitud]` de punta a punta: RFC 7946, lo que emite
 * el editor, lo que valida `WorkGeometry` y lo que guarda la base (ADR-003).
 * Leaflet usa `[lat, lng]`, que es el orden contrario.
 *
 * En PHP esa frontera es `GeoJsonPhpGeoAdapter`, y un test de arquitectura
 * verifica que `Location\Coordinate` no se instancie en ningún otro lado. Acá
 * cumple el mismo papel: si `L.latLng`, `L.marker`, `setLatLng` o `getLatLng`
 * aparecen fuera de `resources/js/mapa/`, el orden se decidió dos veces, y la
 * segunda vez va a estar mal en algún caso de borde que nadie mire.
 *
 * La inversión es simétrica y silenciosa: no hay ningún error que avise. Un mapa
 * con los ejes cambiados dibuja Ramallo en el océano Índico sin quejarse.
 */

/** `[lon, lat]` → el par que espera Leaflet. */
export function aLeaflet ([lon, lat]) {
    return [lat, lon]
}

/** `LatLng` de Leaflet → el par canónico del proyecto. */
export function desdeLeaflet (latLng) {
    return [latLng.lng, latLng.lat]
}

/** @param {Array<[number, number]>} pares */
export function aLeafletVarios (pares) {
    return pares.map(aLeaflet)
}

/**
 * Redondea a la precisión que se persiste.
 *
 * Seis decimales son ~11 cm en el ecuador: de sobra para una obra municipal, y
 * suficientemente pocos como para que dos clics en el mismo píxel no generen
 * vértices distintos que después el servidor rechace como duplicados.
 */
export function redondear ([lon, lat]) {
    return [Number(lon.toFixed(6)), Number(lat.toFixed(6))]
}
