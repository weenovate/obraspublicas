import L from 'leaflet'
import { aLeaflet, desdeLeaflet } from './ejes.js'

/**
 * Creación del mapa base, compartida por el editor de F1-B y por lo que venga.
 *
 * DOS COSAS QUE NO SON OBVIAS:
 *
 * 1. LOS COLORES SALEN DE LOS TOKENS, LEÍDOS EN TIEMPO DE EJECUCIÓN. Leaflet
 *    dibuja las geometrías en SVG con colores que se pasan por JavaScript, así
 *    que no hay hoja de estilo que los resuelva: hay que leer el token con
 *    `getComputedStyle`. Es lo que permite que el mismo trazo sea legible en los
 *    dos temas sin un `if (oscuro)` en el código (RF-THE-002), y lo que hace que
 *    el lint del RDS no encuentre un solo literal acá.
 *
 * 2. SIN PROVEEDOR DE TESELAS EL MAPA IGUAL FUNCIONA. La cuenta de teselas es
 *    una dependencia externa que el municipio contrata antes de F3
 *    (docs/INFRAESTRUCTURA.md), y el servidor público de OSM no es una opción
 *    (spec 11.2). Mientras no esté, el fondo es el contorno del partido —el
 *    recorte del IGN que cerró G3—, que alcanza para ubicarse y para dibujar.
 *    La alternativa sería un mapa gris sin referencias, o teselas de OSM que
 *    después habría que sacar.
 */

/** Lee un token del RDS ya resuelto para el tema vigente. */
export function token (nombre, elemento = document.documentElement) {
    return getComputedStyle(elemento).getPropertyValue(nombre).trim()
}

/**
 * @param {HTMLElement} contenedor
 * @param {object} opciones  `centro` y `bbox` en `[lon, lat]`, `tiles`, `partido`
 */
export function crearMapa (contenedor, opciones = {}) {
    const { centro, zoom, bbox, tiles, partido } = opciones

    const mapa = L.map(contenedor, {
        center: aLeaflet(centro),
        zoom: zoom ?? 11,
        // El foco de teclado sobre el contenedor tiene que existir: el mapa es
        // navegable y el puente CSS ya le dibuja el anillo (RNF-ACC-001).
        keyboard: true,
        zoomControl: true,
    })

    if (tiles?.url_template) {
        L.tileLayer(tiles.url_template, {
            attribution: tiles.attribution ?? '',
            minZoom: tiles.min_zoom ?? 11,
            maxZoom: tiles.max_zoom ?? 18,
        }).addTo(mapa)
    }

    if (partido) {
        L.geoJSON(partido, {
            interactive: false,
            style: {
                color: token('--rml-border'),
                weight: 2,
                // Con teselas el contorno es una referencia sobre el mapa; sin
                // ellas es el mapa, y necesita cuerpo para no ser una línea
                // flotando en el vacío.
                fillColor: token('--rml-surface-sunken'),
                fillOpacity: tiles?.url_template ? 0 : 1,
            },
        }).addTo(mapa)
    }

    if (bbox) {
        const [lonMin, latMin, lonMax, latMax] = bbox
        encuadrar(mapa, [[lonMin, latMin], [lonMax, latMax]], { padding: [0, 0] })
    }

    return mapa
}

/**
 * Dónde está un marcador, en `[lon, lat]`.
 *
 * Existe para que ningún componente tenga que llamar a `getLatLng()`: cada vez
 * que alguien lee un `LatLng` a mano vuelve a decidir el orden de los ejes, y esa
 * segunda decisión es la que un día sale al revés. `scripts/ejes-lint.mjs` lo
 * verifica.
 */
export function posicionDe (marcador) {
    return desdeLeaflet(marcador.getLatLng())
}

/** Encuadra el mapa sobre una lista de pares `[lon, lat]`. */
export function encuadrar (mapa, pares, opciones = {}) {
    if (pares.length === 0) return

    if (pares.length === 1) {
        mapa.setView(aLeaflet(pares[0]), opciones.zoomDePuntoUnico ?? 16)

        return
    }

    mapa.fitBounds(L.latLngBounds(pares.map(aLeaflet)), { padding: opciones.padding ?? [40, 40] })
}

/** Estilo de la geometría que se está editando, siempre desde tokens. */
export function estiloDeTrazo () {
    return {
        color: token('--rml-accent'),
        weight: 4,
        opacity: 1,
        fillColor: token('--rml-accent'),
        fillOpacity: 0.2,
    }
}

/**
 * Marcador de vértice.
 *
 * `divIcon` y no una imagen: el punto se pinta con CSS y por lo tanto con
 * tokens, y cambia de color con el tema sin recargar ningún archivo.
 */
export function iconoDeVertice (esExtremo = false) {
    return L.divIcon({
        className: esExtremo ? 'rml-vertice rml-vertice-extremo' : 'rml-vertice',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    })
}
