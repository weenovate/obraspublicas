<script setup>
/**
 * Alta y edición de una obra, con su editor cartográfico.
 *
 * Tres cosas que el formulario resuelve ANTES de enviar, para que el servidor no
 * tenga que rechazar algo que se podía anticipar:
 *
 *   - La forma que dibuja el editor sale del `geometry_mode` de la subcategoría
 *     elegida. Cambiar de subcategoría a una de otra forma descarta lo dibujado,
 *     porque una línea no se convierte en un área sola.
 *   - La fecha real de finalización se pide sólo cuando el estado elegido
 *     finaliza, y entonces es obligatoria. Lo gobierna `is_final`, nunca la clave
 *     del estado.
 *   - La versión viaja escondida y vuelve con el envío: si alguien más guardó
 *     mientras tanto, el servidor lo dice y no se pierde lo escrito.
 *
 * Las reglas VIVEN en el servidor (`WorkWriter`); acá se anticipan. Que estén en
 * los dos lados no es duplicación: uno protege el dato y el otro evita hacer
 * llenar un formulario para después rechazarlo.
 */
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlAlert from '../../Components/rds/RmlAlert.vue'
import EditorGeometria from '../../Components/mapa/EditorGeometria.vue'

const props = defineProps({
    obra: { type: Object, default: null },
    subcategorias: { type: Array, required: true },
    estados: { type: Array, required: true },
    mapa: { type: Object, required: true },
})

const editando = computed(() => props.obra !== null)

const form = useForm({
    name: props.obra?.name ?? '',
    description: props.obra?.description ?? '',
    work_subcategory_id: props.obra?.work_subcategory_id ?? (props.subcategorias[0]?.id ?? ''),
    work_status_id: props.obra?.work_status_id ?? (props.estados[0]?.id ?? ''),
    start_date: props.obra?.start_date ?? '',
    estimated_end_date: props.obra?.estimated_end_date ?? '',
    actual_end_date: props.obra?.actual_end_date ?? '',
    street: props.obra?.street ?? '',
    street_number: props.obra?.street_number ?? '',
    locality: props.obra?.locality ?? '',
    geometria: props.obra?.geometria ?? null,
    lock_version: props.obra?.lock_version ?? 0,
})

const confirmandoBaja = ref(false)

const subcategoriaElegida = computed(
    () => props.subcategorias.find((s) => s.id === Number(form.work_subcategory_id)) ?? null,
)

const modo = computed(() => subcategoriaElegida.value?.geometry_mode ?? 'POINT')

const estadoElegido = computed(
    () => props.estados.find((e) => e.id === Number(form.work_status_id)) ?? null,
)

const finaliza = computed(() => estadoElegido.value?.is_final === true)

// Al salir de un estado finalizador la fecha real NO se borra: es un dato
// histórico y el servidor la conserva (ADR-008). Lo que cambia es que deja de
// gobernar la fecha efectiva, y por eso deja de pedirse.
watch(finaliza, (finalizaAhora) => {
    if (finalizaAhora && !form.actual_end_date) {
        form.actual_end_date = new Date().toISOString().slice(0, 10)
    }
})

const errores = computed(() => Object.values(form.errors))

/*
| Sin catálogo no se puede cargar una obra, y hay que DECIRLO.
|
| Esto lo encontró el E2E en una instalación recién migrada: los `<select>` de
| subcategoría y estado quedaban vacíos, `required` bloqueaba el envío por
| validación nativa del navegador y el botón Guardar no hacía absolutamente nada
| —sin mensaje, sin error, sin pista—. Es el peor comportamiento posible: parece
| que la aplicación está rota.
|
| Le pasaría a cualquiera que instale el sistema y entre a cargar la primera obra
| antes de configurar los catálogos.
*/
const faltaCatalogo = computed(() => {
    const faltantes = []

    if (props.subcategorias.length === 0) faltantes.push('subcategorías')
    if (props.estados.length === 0) faltantes.push('estados de obra')

    return faltantes
})

function guardar () {
    if (editando.value) {
        form.put(`/obras/${props.obra.id}`, { preserveScroll: true })
    } else {
        form.post('/obras')
    }
}

function enviarAPapelera () {
    form.delete(`/obras/${props.obra.id}`, { data: { lock_version: props.obra.lock_version } })
}
</script>

<template>
    <AdminLayout>
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1>{{ editando ? `Obra ${obra.code}` : 'Nueva obra' }}</h1>
            <Link href="/obras">Volver al listado</Link>
        </div>

        <RmlAlert
            v-if="faltaCatalogo.length"
            tone="warning"
            style="margin-top: var(--rml-space-5)"
            data-testid="falta-catalogo"
        >
            No se puede cargar una obra todavía: falta configurar
            {{ faltaCatalogo.join(' y ') }}. Un Administrador tiene que crearlos antes,
            desde la sección de catálogos.
        </RmlAlert>

        <RmlAlert v-if="errores.length" tone="error" style="margin-top: var(--rml-space-5)" data-testid="errores">
            <ul style="margin: 0; padding-left: var(--rml-space-5)">
                <li v-for="(mensaje, indice) in errores" :key="indice">{{ mensaje }}</li>
            </ul>
        </RmlAlert>

        <form style="margin-top: var(--rml-space-5)" @submit.prevent="guardar">
            <RmlCard title="Identificación">
                <div class="rml-field">
                    <label class="rml-label" for="obra-nombre">Nombre</label>
                    <input id="obra-nombre" v-model="form.name" class="rml-input" type="text" required maxlength="255">
                </div>

                <div class="rml-field">
                    <label class="rml-label" for="obra-descripcion">Descripción</label>
                    <textarea id="obra-descripcion" v-model="form.description" class="rml-textarea" rows="3" maxlength="5000" />
                </div>

                <div class="flex gap-3 flex-wrap">
                    <div class="rml-field" style="flex: 1 1 16rem">
                        <label class="rml-label" for="obra-subcategoria">Subcategoría</label>
                        <select id="obra-subcategoria" v-model="form.work_subcategory_id" class="rml-select" required>
                            <option v-for="s in subcategorias" :key="s.id" :value="s.id">
                                {{ s.categoria }} · {{ s.name }}
                            </option>
                        </select>
                        <span class="rml-hint">Define qué forma se dibuja en el mapa.</span>
                    </div>

                    <div class="rml-field" style="flex: 1 1 12rem">
                        <label class="rml-label" for="obra-estado">Estado</label>
                        <select id="obra-estado" v-model="form.work_status_id" class="rml-select" required>
                            <option v-for="e in estados" :key="e.id" :value="e.id">{{ e.label }}</option>
                        </select>
                    </div>
                </div>
            </RmlCard>

            <RmlCard title="Fechas" style="margin-top: var(--rml-space-5)">
                <div class="flex gap-3 flex-wrap">
                    <div class="rml-field" style="flex: 1 1 12rem">
                        <label class="rml-label" for="obra-inicio">Inicio</label>
                        <input id="obra-inicio" v-model="form.start_date" class="rml-input" type="date" required>
                    </div>

                    <div class="rml-field" style="flex: 1 1 12rem">
                        <label class="rml-label" for="obra-prevista">Finalización prevista</label>
                        <input id="obra-prevista" v-model="form.estimated_end_date" class="rml-input" type="date" required>
                        <span class="rml-hint">Puede ser futura: es un pronóstico y no se sobrescribe al finalizar.</span>
                    </div>

                    <div v-if="finaliza" class="rml-field" style="flex: 1 1 12rem">
                        <label class="rml-label" for="obra-real">Finalización real</label>
                        <input
                            id="obra-real"
                            v-model="form.actual_end_date"
                            class="rml-input"
                            type="date"
                            required
                            :max="new Date().toISOString().slice(0, 10)"
                            data-testid="fecha-real"
                        >
                        <span class="rml-hint">La obra está en un estado que la da por terminada.</span>
                    </div>
                </div>

                <p v-if="!finaliza && form.actual_end_date" class="rml-hint">
                    La obra tiene registrada una finalización real del {{ form.actual_end_date }}. Se conserva como
                    dato histórico, pero mientras el estado no la dé por terminada no cuenta como fecha de fin.
                </p>
            </RmlCard>

            <RmlCard title="Ubicación en el mapa" style="margin-top: var(--rml-space-5)">
                <EditorGeometria
                    v-model="form.geometria"
                    :modo="modo"
                    :centro="mapa.centro"
                    :zoom="mapa.zoom"
                    :bbox="mapa.bbox"
                    :tiles="mapa.tiles"
                    :partido-url="mapa.partido_url"
                />

                <div class="flex gap-3 flex-wrap" style="margin-top: var(--rml-space-5)">
                    <div class="rml-field" style="flex: 1 1 16rem">
                        <label class="rml-label" for="obra-calle">Calle</label>
                        <input id="obra-calle" v-model="form.street" class="rml-input" type="text" maxlength="255">
                    </div>

                    <div class="rml-field" style="flex: 0 1 8rem">
                        <label class="rml-label" for="obra-numero">Número</label>
                        <input id="obra-numero" v-model="form.street_number" class="rml-input" type="text" maxlength="32">
                    </div>

                    <div class="rml-field" style="flex: 1 1 12rem">
                        <label class="rml-label" for="obra-localidad">Localidad</label>
                        <input id="obra-localidad" v-model="form.locality" class="rml-input" type="text" maxlength="100">
                    </div>
                </div>

                <p class="rml-hint">
                    Partido de Ramallo, provincia de Buenos Aires. La búsqueda de direcciones llega en una
                    etapa próxima; por ahora la ubicación se marca en el mapa.
                </p>
            </RmlCard>

            <div class="flex items-center justify-between flex-wrap gap-3" style="margin-top: var(--rml-space-5)">
                <div class="flex items-center gap-3">
                    <RmlButton
                        type="submit"
                        :loading="form.processing"
                        :disabled="faltaCatalogo.length > 0"
                        data-testid="guardar-obra"
                    >
                        {{ editando ? 'Guardar cambios' : 'Crear obra' }}
                    </RmlButton>
                    <Link href="/obras"><RmlButton type="button" variant="secondary">Cancelar</RmlButton></Link>
                </div>

                <RmlButton
                    v-if="editando"
                    type="button"
                    variant="danger"
                    data-testid="enviar-papelera"
                    @click="confirmandoBaja = true"
                >Enviar a la papelera</RmlButton>
            </div>
        </form>

        <div v-if="confirmandoBaja" class="rml-modal-backdrop" @click.self="confirmandoBaja = false">
            <div class="rml-modal" role="dialog" aria-modal="true" aria-labelledby="titulo-baja">
                <div class="rml-modal-header">
                    <h2 id="titulo-baja">Enviar a la papelera</h2>
                </div>
                <div class="rml-modal-body">
                    <p>
                        La obra {{ obra.code }} deja de verse en el mapa y en el listado, pero no se borra: un
                        Administrador puede restaurarla.
                    </p>
                </div>
                <div class="rml-modal-footer">
                    <RmlButton variant="secondary" @click="confirmandoBaja = false">Cancelar</RmlButton>
                    <RmlButton variant="danger" data-testid="confirmar-papelera" @click="enviarAPapelera">
                        Enviar a la papelera
                    </RmlButton>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
