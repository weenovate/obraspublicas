<script setup>
/**
 * Subcategorías (RF-CAT-004).
 *
 * El modo geométrico es lo que decide qué se puede dibujar, así que una vez que
 * hay obras queda congelado —salvo entre los dos modos de línea, que comparten
 * la misma geometría almacenada y sólo cambian cómo se la traza—. La pantalla
 * refleja esa excepción en lugar de bloquear el selector entero: bloquearlo
 * completo sería más simple de escribir y le mentiría al usuario.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import RmlCard from '../../../Components/rds/RmlCard.vue'
import RmlButton from '../../../Components/rds/RmlButton.vue'
import RmlBadge from '../../../Components/rds/RmlBadge.vue'
import RmlAlert from '../../../Components/rds/RmlAlert.vue'

const props = defineProps({
    subcategorias: { type: Array, required: true },
    categorias: { type: Array, required: true },
    modos: { type: Object, required: true },
    perfiles: { type: Object, required: true },
})

const MODOS_LINEA = ['LINE_ROUTED_ROAD', 'LINE_MANUAL_NETWORK']

const editando = ref(null)
const form = useForm({
    work_category_id: props.categorias[0]?.id ?? null,
    name: '',
    geometry_mode: 'POINT',
    routing_profile: null,
    sort_order: 0,
    is_active: true,
})

/* Con obras cargadas sólo quedan disponibles los modos a los que sí se puede
   migrar: el mismo que ya tiene, y el otro modo de línea si es una línea. */
const modosDisponibles = computed(() => {
    if (! editando.value?.en_uso) return props.modos

    const actual = editando.value.geometry_mode
    const permitidos = MODOS_LINEA.includes(actual) ? MODOS_LINEA : [actual]

    return Object.fromEntries(Object.entries(props.modos).filter(([clave]) => permitidos.includes(clave)))
})

const esLinea = computed(() => MODOS_LINEA.includes(form.geometry_mode))

function editar (subcategoria) {
    editando.value = subcategoria
    Object.assign(form, {
        work_category_id: subcategoria.work_category_id,
        name: subcategoria.name,
        geometry_mode: subcategoria.geometry_mode,
        routing_profile: subcategoria.routing_profile,
        sort_order: subcategoria.sort_order,
        is_active: subcategoria.is_active,
    })
}

function cancelar () {
    editando.value = null
    form.reset()
    form.clearErrors()
}

function guardar () {
    if (editando.value) {
        form.put(`/admin/subcategorias/${editando.value.id}`, {
            preserveScroll: true,
            onSuccess: cancelar,
        })
    } else {
        form.post('/admin/subcategorias', { preserveScroll: true, onSuccess: () => form.reset() })
    }
}
</script>

<template>
    <AdminLayout>
        <h1>Subcategorías</h1>
        <p class="text-secondary" style="margin-top: var(--rml-space-2)">
            Cada subcategoría define cómo se dibuja la obra en el mapa: un punto, una línea o un polígono.
        </p>

        <RmlCard :title="editando ? `Editar «${editando.name}»` : 'Nueva subcategoría'"
                 style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="Object.keys(form.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(form.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="sub-categoria">Categoría</label>
                    <select id="sub-categoria" v-model="form.work_category_id" class="rml-select"
                            :disabled="editando?.en_uso">
                        <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">
                            {{ categoria.name }}
                        </option>
                    </select>
                    <span v-if="editando?.en_uso" class="rml-hint">
                        No se puede mover de categoría mientras tenga obras, incluidas las de la papelera.
                    </span>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="sub-nombre">Nombre</label>
                    <input id="sub-nombre" v-model="form.name" class="rml-input" type="text" required>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="sub-modo">Tipo de geometría</label>
                    <select id="sub-modo" v-model="form.geometry_mode" class="rml-select">
                        <option v-for="(etiqueta, clave) in modosDisponibles" :key="clave" :value="clave">
                            {{ etiqueta }}
                        </option>
                    </select>
                    <span v-if="editando?.en_uso" class="rml-hint">
                        Con obras cargadas sólo se admite cambiar entre los dos modos de línea: cualquier otro
                        cambio invalidaría las geometrías ya dibujadas.
                    </span>
                </div>

                <div v-if="esLinea" class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="sub-perfil">Perfil de trazado</label>
                    <select id="sub-perfil" v-model="form.routing_profile" class="rml-select">
                        <option :value="null">Sin perfil</option>
                        <option v-for="(etiqueta, clave) in perfiles" :key="clave" :value="clave">
                            {{ etiqueta }}
                        </option>
                    </select>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="sub-orden">Orden</label>
                    <input id="sub-orden" v-model.number="form.sort_order" class="rml-input" type="number" min="0">
                </div>

                <label class="rml-switch" style="margin-top: var(--rml-space-4)">
                    <input v-model="form.is_active" type="checkbox">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Disponible para cargar obras</span>
                </label>

                <div class="flex items-center gap-3" style="margin-top: var(--rml-space-5)">
                    <RmlButton type="submit" :loading="form.processing">
                        {{ editando ? 'Guardar cambios' : 'Crear subcategoría' }}
                    </RmlButton>
                    <RmlButton v-if="editando" variant="secondary" @click="cancelar">Cancelar</RmlButton>
                </div>
            </form>
        </RmlCard>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <table class="rml-table">
                <thead>
                    <tr><th>Categoría</th><th>Nombre</th><th>Geometría</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="subcategoria in subcategorias" :key="subcategoria.id">
                        <td>{{ subcategoria.categoria }}</td>
                        <td>
                            {{ subcategoria.name }}
                            <RmlBadge v-if="subcategoria.en_uso" tone="info">En uso</RmlBadge>
                        </td>
                        <td>{{ modos[subcategoria.geometry_mode] }}</td>
                        <td>{{ subcategoria.is_active ? 'Activa' : 'Inactiva' }}</td>
                        <td><RmlButton variant="ghost" size="sm" @click="editar(subcategoria)">Editar</RmlButton></td>
                    </tr>
                </tbody>
            </table>

            <div v-if="! subcategorias.length" class="rml-empty" style="margin-top: var(--rml-space-4)">
                <strong class="rml-empty-title">Todavía no hay subcategorías</strong>
                <span>Sin al menos una, no se puede cargar ninguna obra.</span>
            </div>
        </RmlCard>
    </AdminLayout>
</template>
