<script setup>
/**
 * Listado de obras.
 *
 * El orden es `updated_at DESC, id DESC` —lo último tocado primero, con desempate
 * estable—: es lo que necesita quien está cargando obras, y es el mismo orden que
 * F5 va a recorrer en LIVE, así que conviene que sea uno solo.
 */
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlBadge from '../../Components/rds/RmlBadge.vue'

const props = defineProps({
    obras: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    estados: { type: Array, required: true },
    subcategorias: { type: Array, required: true },
})

const buscar = ref(props.filtros.buscar ?? '')
const estado = ref(props.filtros.estado ?? '')
const subcategoria = ref(props.filtros.subcategoria ?? '')

let temporizador = null

watch([buscar, estado, subcategoria], () => {
    // Se espera a que la persona deje de escribir: sin esto cada tecla dispara
    // una consulta y el listado parpadea.
    clearTimeout(temporizador)
    temporizador = setTimeout(aplicar, 300)
})

function aplicar () {
    router.get('/obras', {
        buscar: buscar.value || undefined,
        estado: estado.value || undefined,
        subcategoria: subcategoria.value || undefined,
    }, { preserveState: true, replace: true })
}

function formatearFecha (iso) {
    const [anio, mes, dia] = iso.split('-')

    return `${dia}/${mes}/${anio}`
}
</script>

<template>
    <AdminLayout>
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1>Obras</h1>
            <Link href="/obras/nueva"><RmlButton>Nueva obra</RmlButton></Link>
        </div>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <div class="flex items-center gap-3 flex-wrap">
                <div class="rml-field" style="flex: 1 1 16rem; margin: 0">
                    <label class="rml-label" for="filtro-buscar">Buscar</label>
                    <input
                        id="filtro-buscar"
                        v-model="buscar"
                        class="rml-input"
                        type="search"
                        placeholder="Nombre o código"
                        data-testid="filtro-buscar"
                    >
                </div>

                <div class="rml-field" style="flex: 0 1 12rem; margin: 0">
                    <label class="rml-label" for="filtro-estado">Estado</label>
                    <select id="filtro-estado" v-model="estado" class="rml-select">
                        <option value="">Todos</option>
                        <option v-for="e in estados" :key="e.id" :value="e.id">{{ e.label }}</option>
                    </select>
                </div>

                <div class="rml-field" style="flex: 0 1 14rem; margin: 0">
                    <label class="rml-label" for="filtro-subcategoria">Subcategoría</label>
                    <select id="filtro-subcategoria" v-model="subcategoria" class="rml-select">
                        <option value="">Todas</option>
                        <option v-for="s in subcategorias" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
            </div>
        </RmlCard>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <div v-if="obras.data.length === 0" class="rml-empty">
                <p class="rml-empty-title">Todavía no hay obras cargadas</p>
                <p>Las que cargues acá son las que van a verse en el mapa público y en las pantallas.</p>
                <Link href="/obras/nueva" style="display: inline-block; margin-top: var(--rml-space-4)">
                    <RmlButton>Cargar la primera</RmlButton>
                </Link>
            </div>

            <table v-else class="rml-table" data-testid="tabla-obras">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Subcategoría</th>
                        <th>Estado</th>
                        <th>Inicio</th>
                        <th>Finalización</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="obra in obras.data" :key="obra.id">
                        <td><code>{{ obra.code }}</code></td>
                        <td>{{ obra.name }}</td>
                        <td>{{ obra.subcategoria }}</td>
                        <td><RmlBadge>{{ obra.estado }}</RmlBadge></td>
                        <td>{{ formatearFecha(obra.start_date) }}</td>
                        <td>{{ formatearFecha(obra.effective_end_date) }}</td>
                        <td>
                            <Link :href="`/obras/${obra.id}/editar`">Editar</Link>
                        </td>
                    </tr>
                </tbody>
            </table>

            <nav v-if="obras.links.length > 3" class="rml-pagination" aria-label="Paginación">
                <Link
                    v-for="enlace in obras.links"
                    :key="enlace.label"
                    class="rml-page-link"
                    :href="enlace.url ?? ''"
                    :aria-current="enlace.active ? 'page' : undefined"
                    :aria-disabled="enlace.url ? undefined : 'true'"
                    v-html="enlace.label"
                />
            </nav>
        </RmlCard>
    </AdminLayout>
</template>
