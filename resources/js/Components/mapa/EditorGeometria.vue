<script setup>
/**
 * Editor cartográfico manual (RF-GEO-001…005, F1-B).
 *
 * Dibuja punto, línea o polígono según el modo de la subcategoría y emite
 * GeoJSON en `[longitud, latitud]`. La conversión de ejes NO está acá: está en
 * `mapa/ejes.js`, que es el único módulo que conoce el orden de Leaflet.
 *
 * Se dibuja sin plugin de terceros a propósito. `leaflet-draw` traería su propia
 * interfaz, sus propios colores y su propio orden de coordenadas, y las tres
 * cosas hay que gobernarlas: el trazo sale de tokens del RDS, los textos están
 * en castellano y el orden de ejes tiene una sola frontera. Lo que hace falta
 * —agregar vértice, mover vértice, deshacer, limpiar— son cuatro manejadores.
 *
 * El trazado asistido sobre calles (`LINE_ROUTED_ROAD`, ORS) llega en F3. Hasta
 * entonces los dos modos de línea se dibujan igual, a mano, y eso es exacto: lo
 * que cambia entre ellos es si se ofrece asistencia, no lo que se guarda.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import { crearMapa, encuadrar, estiloDeTrazo, iconoDeVertice, posicionDe } from '../../mapa/mapa.js'
import { aLeaflet, aLeafletVarios, desdeLeaflet, redondear } from '../../mapa/ejes.js'
import RmlButton from '../rds/RmlButton.vue'

const props = defineProps({
    modelValue: { type: Object, default: null },
    modo: { type: String, required: true },
    centro: { type: Array, required: true },
    zoom: { type: Number, default: 11 },
    bbox: { type: Array, default: null },
    tiles: { type: Object, default: null },
    partidoUrl: { type: String, default: null },
    readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const contenedor = ref(null)
const mapa = shallowRef(null)
const capaTrazo = shallowRef(null)
const marcadores = shallowRef([])
const vertices = ref([])
const listo = ref(false)

const esPunto = computed(() => props.modo === 'POINT')
const esPoligono = computed(() => props.modo === 'POLYGON')
const esLinea = computed(() => !esPunto.value && !esPoligono.value)

const minimoVertices = computed(() => (esPunto.value ? 1 : esPoligono.value ? 3 : 2))
const completa = computed(() => vertices.value.length >= minimoVertices.value)

const instruccion = computed(() => {
    if (props.readonly) return 'Vista de la geometría guardada.'
    if (esPunto.value) return 'Hacé clic en el mapa para ubicar la obra. Podés arrastrar el punto para corregirlo.'
    if (esPoligono.value) return 'Hacé clic para marcar cada esquina; con tres alcanza. El área se cierra sola.'
    return 'Hacé clic para marcar el recorrido, punto por punto. Arrastrá cualquiera para corregirlo.'
})

/*
| Estado → GeoJSON. El polígono cierra el anillo repitiendo el primer punto,
| como exige RFC 7946; en pantalla no se ve porque Leaflet ya lo cierra solo.
*/
function aGeoJson () {
    if (!completa.value) return null

    if (esPunto.value) {
        return { type: 'Point', coordinates: vertices.value[0] }
    }

    if (esPoligono.value) {
        return { type: 'Polygon', coordinates: [[...vertices.value, vertices.value[0]]] }
    }

    return { type: 'LineString', coordinates: [...vertices.value] }
}

function emitir () {
    emit('update:modelValue', aGeoJson())
}

function redibujar () {
    if (!mapa.value) return

    marcadores.value.forEach((m) => m.remove())
    marcadores.value = []

    if (capaTrazo.value) {
        capaTrazo.value.remove()
        capaTrazo.value = null
    }

    if (vertices.value.length === 0) return

    if (!esPunto.value && vertices.value.length >= 2) {
        const pares = aLeafletVarios(vertices.value)
        capaTrazo.value = esPoligono.value
            ? L.polygon(pares, estiloDeTrazo())
            : L.polyline(pares, estiloDeTrazo())
        capaTrazo.value.addTo(mapa.value)
    }

    marcadores.value = vertices.value.map((par, indice) => {
        const esExtremo = esLinea.value && (indice === 0 || indice === vertices.value.length - 1)
        const marcador = L.marker(aLeaflet(par), {
            icon: iconoDeVertice(esPunto.value || esExtremo),
            draggable: !props.readonly,
            keyboard: true,
            title: esPunto.value ? 'Ubicación de la obra' : `Vértice ${indice + 1}`,
        })

        marcador.on('drag', () => {
            vertices.value[indice] = redondear(posicionDe(marcador))
            if (capaTrazo.value) capaTrazo.value.setLatLngs(aLeafletVarios(vertices.value))
        })
        marcador.on('dragend', emitir)

        return marcador.addTo(mapa.value)
    })
}

function agregarVertice (evento) {
    if (props.readonly) return

    const par = redondear(desdeLeaflet(evento.latlng))

    if (esPunto.value) {
        vertices.value = [par]
    } else {
        const ultimo = vertices.value[vertices.value.length - 1]

        // Dos clics en el mismo lugar dan un tramo de longitud cero, que el
        // servidor rechaza. Se ignora acá para que el error no llegue después de
        // completar todo el formulario.
        if (ultimo && ultimo[0] === par[0] && ultimo[1] === par[1]) return

        vertices.value = [...vertices.value, par]
    }

    redibujar()
    emitir()
}

function deshacer () {
    vertices.value = vertices.value.slice(0, -1)
    redibujar()
    emitir()
}

function limpiar () {
    vertices.value = []
    redibujar()
    emitir()
}

/** GeoJSON entrante → estado. El anillo cerrado vuelve a abrirse para editarlo. */
function desdeGeoJson (geojson) {
    if (!geojson?.coordinates) return []
    if (geojson.type === 'Point') return [geojson.coordinates]
    if (geojson.type === 'LineString') return [...geojson.coordinates]

    if (geojson.type === 'Polygon') {
        const anillo = [...(geojson.coordinates[0] ?? [])]
        const primero = anillo[0]
        const ultimo = anillo[anillo.length - 1]

        if (primero && ultimo && primero[0] === ultimo[0] && primero[1] === ultimo[1]) anillo.pop()

        return anillo
    }

    return []
}

function encuadrarEnLaGeometria () {
    if (!mapa.value) return

    encuadrar(mapa.value, vertices.value)
}

onMounted(async () => {
    let partido = null

    if (props.partidoUrl) {
        try {
            const respuesta = await fetch(props.partidoUrl)
            if (respuesta.ok) partido = await respuesta.json()
        } catch {
            // El contorno es contexto, no un requisito: si no llega, el editor
            // sigue siendo usable y no tiene sentido bloquear el alta por eso.
        }
    }

    mapa.value = crearMapa(contenedor.value, {
        centro: props.centro,
        zoom: props.zoom,
        bbox: props.bbox,
        tiles: props.tiles,
        partido,
    })

    mapa.value.on('click', agregarVertice)

    vertices.value = desdeGeoJson(props.modelValue)
    redibujar()
    encuadrarEnLaGeometria()
    listo.value = true
})

onBeforeUnmount(() => {
    mapa.value?.remove()
    mapa.value = null
})

// Cambiar de subcategoría cambia la forma: lo dibujado deja de ser válido y se
// descarta explícitamente, en lugar de reinterpretar los vértices como otra cosa.
watch(() => props.modo, () => {
    if (vertices.value.length > 0) limpiar()
})
</script>

<template>
    <div>
        <p class="rml-hint" style="margin-bottom: var(--rml-space-2)">{{ instruccion }}</p>

        <div
            ref="contenedor"
            class="rml-editor-mapa"
            role="application"
            :aria-label="`Editor cartográfico. ${instruccion}`"
            data-testid="editor-mapa"
        />

        <div class="flex items-center gap-3 flex-wrap" style="margin-top: var(--rml-space-3)">
            <RmlButton
                v-if="!readonly"
                type="button"
                variant="secondary"
                size="sm"
                :disabled="vertices.length === 0"
                data-testid="deshacer-vertice"
                @click="deshacer"
            >Deshacer último punto</RmlButton>

            <RmlButton
                v-if="!readonly"
                type="button"
                variant="secondary"
                size="sm"
                :disabled="vertices.length === 0"
                data-testid="limpiar-geometria"
                @click="limpiar"
            >Borrar todo</RmlButton>

            <span class="rml-hint" data-testid="estado-geometria" aria-live="polite">
                <template v-if="esPunto">
                    {{ completa ? 'Ubicación marcada.' : 'Falta marcar la ubicación.' }}
                </template>
                <template v-else>
                    {{ vertices.length }} {{ vertices.length === 1 ? 'punto marcado' : 'puntos marcados' }}<template v-if="!completa">, faltan {{ minimoVertices - vertices.length }}</template>.
                </template>
            </span>
        </div>

        <p v-if="!tiles?.url_template && listo" class="rml-hint" style="margin-top: var(--rml-space-2)">
            Todavía no hay proveedor de teselas configurado: el fondo es el contorno oficial del partido.
            Se puede dibujar igual, y las calles aparecen cuando se active el servicio.
        </p>
    </div>
</template>
