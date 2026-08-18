<script setup>
/**
 * Galería de fotografías de una obra (F2, ADR-019).
 *
 * MUESTRA LOS TRES ESTADOS, no sólo las fotos listas. Quien carga necesita ver
 * las que se están procesando y las que fallaron: son las que tiene que
 * reintentar. Una galería que sólo mostrara las publicables escondería
 * justamente lo que hay que atender, y la persona creería que la foto se perdió.
 *
 * LA OBRA NO ESPERA. Se sube y la foto entra en `PENDING`; el formulario de la
 * obra sigue funcionando igual. Es lo que permite que la publicación sea
 * inmediata aunque el procesamiento tarde.
 *
 * EL SONDEO SE APAGA SOLO. Mientras haya fotos procesándose se consulta cada
 * pocos segundos, y en cuanto no queda ninguna se corta. Sondear para siempre
 * gastaría batería y peticiones sin que cambie nada.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import RmlButton from '../rds/RmlButton.vue'
import RmlBadge from '../rds/RmlBadge.vue'

const props = defineProps({
    obraId: { type: Number, required: true },
    fotos: { type: Array, default: () => [] },
    maximo: { type: Number, default: 10 },
})

const entrada = ref(null)
const ampliada = ref(null)
const form = useForm({ foto: null })

const SEGUNDOS_DE_SONDEO = 4000
let temporizador = null

const procesando = computed(() => props.fotos.filter((f) => f.status === 'PENDING').length)
const lugarLibre = computed(() => props.fotos.length < props.maximo)

const ETIQUETAS = {
    PENDING: { texto: 'Procesando', tono: 'info' },
    READY: { texto: 'Lista', tono: 'success' },
    FAILED: { texto: 'Falló', tono: 'error' },
}

/*
| Mientras haya fotos en proceso se recarga sólo esta propiedad. `only` evita
| traer de nuevo el mapa, los catálogos y todo lo demás: la galería se actualiza
| sin que el formulario se sacuda ni se pierda lo que la persona está escribiendo.
*/
watch(procesando, (cuantas) => {
    clearInterval(temporizador)

    if (cuantas === 0) return

    temporizador = setInterval(() => {
        router.reload({ only: ['fotos'], preserveScroll: true, preserveState: true })
    }, SEGUNDOS_DE_SONDEO)
}, { immediate: true })

onBeforeUnmount(() => clearInterval(temporizador))

function elegir (evento) {
    const archivo = evento.target.files?.[0]

    if (! archivo) return

    form.foto = archivo
    form.post(`/obras/${props.obraId}/fotos`, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); if (entrada.value) entrada.value.value = '' },
    })
}

function reintentar (foto) {
    router.post(`/fotos/${foto.id}/reintentar`, {}, { preserveScroll: true })
}

function quitar (foto) {
    router.delete(`/fotos/${foto.id}`, { preserveScroll: true })
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between flex-wrap gap-3">
            <p class="rml-hint" style="margin: 0" data-testid="contador-fotos">
                {{ fotos.length }} de {{ maximo }} fotos
                <template v-if="procesando > 0"> · {{ procesando }} procesándose</template>
            </p>

            <div>
                <input
                    ref="entrada"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    style="display: none"
                    data-testid="entrada-foto"
                    @change="elegir"
                >
                <RmlButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    :loading="form.processing"
                    :disabled="! lugarLibre"
                    data-testid="agregar-foto"
                    @click="entrada.click()"
                >Agregar foto</RmlButton>
            </div>
        </div>

        <p v-if="! lugarLibre" class="rml-hint" style="margin-top: var(--rml-space-2)">
            La obra llegó al máximo de fotos. Quitá alguna para agregar otra.
        </p>

        <p v-if="form.errors.foto" class="rml-error-text" style="margin-top: var(--rml-space-2)">
            {{ form.errors.foto }}
        </p>

        <div v-if="fotos.length === 0" class="rml-empty" style="margin-top: var(--rml-space-4)">
            <p class="rml-empty-title">Todavía no hay fotos</p>
            <p>Las fotos aparecen en el mapa público cuando terminan de procesarse.</p>
        </div>

        <ul v-else class="rml-galeria" data-testid="galeria">
            <li v-for="foto in fotos" :key="foto.id" class="rml-galeria-item">
                <button
                    v-if="foto.url_thumb"
                    type="button"
                    class="rml-galeria-miniatura"
                    :aria-label="`Ampliar ${foto.original_filename}`"
                    @click="ampliada = foto"
                >
                    <img :src="foto.url_thumb" :alt="foto.caption || foto.original_filename" loading="lazy">
                </button>

                <div v-else class="rml-galeria-miniatura rml-galeria-sin-imagen">
                    <span aria-hidden="true">{{ foto.status === 'FAILED' ? '!' : '…' }}</span>
                </div>

                <div class="rml-galeria-datos">
                    <RmlBadge :tone="ETIQUETAS[foto.status].tono">{{ ETIQUETAS[foto.status].texto }}</RmlBadge>
                    <span class="rml-hint">{{ foto.original_filename }}</span>

                    <p v-if="foto.failure_reason" class="rml-error-text" :data-testid="`motivo-${foto.id}`">
                        {{ foto.failure_reason }}
                    </p>

                    <div class="flex items-center gap-2" style="margin-top: var(--rml-space-2)">
                        <RmlButton
                            v-if="foto.se_puede_reintentar"
                            type="button"
                            variant="secondary"
                            size="sm"
                            :data-testid="`reintentar-${foto.id}`"
                            @click="reintentar(foto)"
                        >Reintentar</RmlButton>

                        <RmlButton
                            type="button"
                            variant="ghost"
                            size="sm"
                            :data-testid="`quitar-${foto.id}`"
                            @click="quitar(foto)"
                        >Quitar</RmlButton>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="ampliada" class="rml-modal-backdrop" @click.self="ampliada = null">
            <div class="rml-modal" role="dialog" aria-modal="true" :aria-label="ampliada.original_filename">
                <div class="rml-modal-body" style="padding: 0">
                    <img :src="ampliada.url_large" :alt="ampliada.caption || ampliada.original_filename" style="width: 100%; display: block">
                </div>
                <div class="rml-modal-footer">
                    <RmlButton variant="secondary" @click="ampliada = null">Cerrar</RmlButton>
                </div>
            </div>
        </div>
    </div>
</template>
