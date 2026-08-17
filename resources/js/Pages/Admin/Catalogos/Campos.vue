<script setup>
/**
 * Campos técnicos dinámicos (RF-DIN-001…005).
 *
 * Dos decisiones del spec se ven directamente en esta pantalla:
 *
 *   · El código técnico se deriva de la etiqueta la primera vez y después no
 *     cambia nunca (RF-DIN-003), así que se muestra pero no se edita.
 *   · El tipo de dato queda congelado en cuanto hay valores cargados
 *     (RF-DIN-004): reinterpretar un texto como número rompería lo guardado.
 *
 * Desactivar no borra: los valores históricos siguen visibles en las obras que
 * ya los tienen (RF-CAT-005).
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import RmlCard from '../../../Components/rds/RmlCard.vue'
import RmlButton from '../../../Components/rds/RmlButton.vue'
import RmlBadge from '../../../Components/rds/RmlBadge.vue'
import RmlAlert from '../../../Components/rds/RmlAlert.vue'

const props = defineProps({
    campos: { type: Array, required: true },
    categorias: { type: Array, required: true },
    subcategorias: { type: Array, required: true },
    tipos: { type: Object, required: true },
})

const editando = ref(null)
const form = useForm({
    scope_type: 'CATEGORY',
    scope_id: props.categorias[0]?.id ?? null,
    label: '',
    help_text: '',
    data_type: 'TEXT',
    unit: '',
    min_value: null,
    max_value: null,
    is_required: false,
    public_visible: false,
    live_visible: false,
    sort_order: 0,
    is_active: true,
})

const opcionForm = useForm({ label: '', sort_order: 0 })
const agregandoOpcionA = ref(null)

/* El alcance elige entre dos listas distintas, no entre dos ids del mismo
   universo: una categoría 3 y una subcategoría 3 no son lo mismo. */
const alcances = computed(() => (form.scope_type === 'CATEGORY' ? props.categorias : props.subcategorias))

const esNumerico = computed(() => ['INTEGER', 'DECIMAL'].includes(form.data_type))
const esSeleccion = computed(() => form.data_type === 'SELECT')

function nombreDelAlcance (campo) {
    const lista = campo.scope_type === 'CATEGORY' ? props.categorias : props.subcategorias

    return lista.find((a) => a.id === campo.scope_id)?.name ?? '—'
}

function editar (campo) {
    editando.value = campo
    Object.assign(form, {
        scope_type: campo.scope_type,
        scope_id: campo.scope_id,
        label: campo.label,
        help_text: campo.help_text ?? '',
        data_type: campo.data_type,
        unit: campo.unit ?? '',
        min_value: campo.min_value,
        max_value: campo.max_value,
        is_required: campo.is_required,
        public_visible: campo.public_visible,
        live_visible: campo.live_visible,
        sort_order: campo.sort_order ?? 0,
        is_active: campo.is_active,
    })
}

function cancelar () {
    editando.value = null
    form.reset()
    form.clearErrors()
}

function guardar () {
    if (editando.value) {
        form.put(`/admin/campos/${editando.value.id}`, { preserveScroll: true, onSuccess: cancelar })
    } else {
        form.post('/admin/campos', { preserveScroll: true, onSuccess: () => form.reset() })
    }
}

function eliminar (campo) {
    router.delete(`/admin/campos/${campo.id}`, { preserveScroll: true })
}

function agregarOpcion (campo) {
    opcionForm.post(`/admin/campos/${campo.id}/opciones`, {
        preserveScroll: true,
        onSuccess: () => { opcionForm.reset(); agregandoOpcionA.value = null },
    })
}

function eliminarOpcion (opcion) {
    router.delete(`/admin/campos/opciones/${opcion.id}`, { preserveScroll: true })
}
</script>

<template>
    <AdminLayout>
        <h1>Campos técnicos</h1>
        <p class="text-secondary" style="margin-top: var(--rml-space-2)">
            Datos propios de cada categoría o subcategoría. Se definen una vez y aparecen en el formulario de obra.
        </p>

        <RmlCard :title="editando ? `Editar «${editando.label}»` : 'Nuevo campo'"
                 style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="Object.keys(form.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(form.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="campo-alcance-tipo">Se aplica a</label>
                    <select id="campo-alcance-tipo" v-model="form.scope_type" class="rml-select"
                            :disabled="!! editando">
                        <option value="CATEGORY">Una categoría entera</option>
                        <option value="SUBCATEGORY">Una subcategoría</option>
                    </select>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="campo-alcance">
                        {{ form.scope_type === 'CATEGORY' ? 'Categoría' : 'Subcategoría' }}
                    </label>
                    <select id="campo-alcance" v-model="form.scope_id" class="rml-select" :disabled="!! editando">
                        <option v-for="alcance in alcances" :key="alcance.id" :value="alcance.id">
                            {{ alcance.name }}
                        </option>
                    </select>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="campo-etiqueta">Etiqueta</label>
                    <input id="campo-etiqueta" v-model="form.label" class="rml-input" type="text" required>
                    <span v-if="editando" class="rml-hint">
                        Código técnico: <code>{{ editando.code }}</code>. No cambia aunque cambie la etiqueta.
                    </span>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="campo-tipo">Tipo de dato</label>
                    <select id="campo-tipo" v-model="form.data_type" class="rml-select"
                            :disabled="editando?.tiene_valores">
                        <option v-for="(etiqueta, clave) in tipos" :key="clave" :value="clave">{{ etiqueta }}</option>
                    </select>
                    <span v-if="editando?.tiene_valores" class="rml-hint">
                        Ya hay obras con este campo cargado: cambiar el tipo dejaría esos valores sin sentido.
                    </span>
                </div>

                <div v-if="esNumerico" class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="campo-unidad">Unidad</label>
                    <input id="campo-unidad" v-model="form.unit" class="rml-input" type="text"
                           placeholder="m, m², kg…">
                </div>

                <div v-if="esNumerico" class="flex gap-3" style="margin-top: var(--rml-space-4)">
                    <div class="rml-field" style="flex: 1">
                        <label class="rml-label" for="campo-min">Mínimo</label>
                        <input id="campo-min" v-model.number="form.min_value" class="rml-input" type="number"
                               step="any">
                    </div>
                    <div class="rml-field" style="flex: 1">
                        <label class="rml-label" for="campo-max">Máximo</label>
                        <input id="campo-max" v-model.number="form.max_value" class="rml-input" type="number"
                               step="any">
                    </div>
                </div>

                <p v-if="esSeleccion" class="rml-hint" style="margin-top: var(--rml-space-4)">
                    Las opciones de la lista se cargan abajo, una vez creado el campo.
                </p>

                <label class="rml-switch" style="margin-top: var(--rml-space-4)">
                    <input v-model="form.is_required" type="checkbox">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Obligatorio al cargar la obra</span>
                </label>

                <label class="rml-switch" style="margin-top: var(--rml-space-3)">
                    <input v-model="form.public_visible" type="checkbox">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Visible en la Web pública</span>
                </label>

                <label class="rml-switch" style="margin-top: var(--rml-space-3)">
                    <input v-model="form.live_visible" type="checkbox">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Visible en la pantalla LIVE</span>
                </label>
                <p class="rml-hint" style="margin-top: var(--rml-space-2)">
                    Los dos empiezan apagados: un campo se publica cuando alguien lo decide, no por omisión.
                </p>

                <label v-if="editando" class="rml-switch" style="margin-top: var(--rml-space-4)">
                    <input v-model="form.is_active" type="checkbox">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Activo</span>
                </label>

                <div class="flex items-center gap-3" style="margin-top: var(--rml-space-5)">
                    <RmlButton type="submit" :loading="form.processing">
                        {{ editando ? 'Guardar cambios' : 'Crear campo' }}
                    </RmlButton>
                    <RmlButton v-if="editando" variant="secondary" @click="cancelar">Cancelar</RmlButton>
                </div>
            </form>
        </RmlCard>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <table class="rml-table">
                <thead>
                    <tr>
                        <th>Etiqueta</th><th>Código</th><th>Alcance</th><th>Tipo</th>
                        <th>Publicación</th><th>Estado</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="campo in campos" :key="campo.id">
                        <tr>
                            <td>
                                {{ campo.label }}
                                <RmlBadge v-if="campo.is_required" tone="warning">Obligatorio</RmlBadge>
                            </td>
                            <td><code>{{ campo.code }}</code></td>
                            <td>{{ nombreDelAlcance(campo) }}</td>
                            <td>{{ tipos[campo.data_type] }}</td>
                            <td>
                                <RmlBadge v-if="campo.public_visible" tone="info">Web</RmlBadge>
                                <RmlBadge v-if="campo.live_visible" tone="info">LIVE</RmlBadge>
                                <span v-if="! campo.public_visible && ! campo.live_visible">Interno</span>
                            </td>
                            <td>{{ campo.is_active ? 'Activo' : 'Inactivo' }}</td>
                            <td class="flex gap-2">
                                <RmlButton variant="ghost" size="sm" @click="editar(campo)">Editar</RmlButton>
                                <RmlButton v-if="! campo.tiene_valores" variant="ghost" size="sm"
                                           @click="eliminar(campo)">
                                    Eliminar
                                </RmlButton>
                            </td>
                        </tr>

                        <tr v-if="campo.data_type === 'SELECT'">
                            <td colspan="7">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-secondary">Opciones:</span>
                                    <span v-for="opcion in campo.opciones" :key="opcion.id"
                                          class="flex items-center gap-2">
                                        <RmlBadge tone="neutral">{{ opcion.label }}</RmlBadge>
                                        <RmlButton variant="ghost" size="sm" @click="eliminarOpcion(opcion)">
                                            Quitar
                                        </RmlButton>
                                    </span>
                                    <span v-if="! campo.opciones.length" class="rml-hint">Todavía ninguna.</span>
                                    <RmlButton variant="ghost" size="sm"
                                               @click="agregandoOpcionA = agregandoOpcionA === campo.id ? null : campo.id">
                                        Agregar opción
                                    </RmlButton>
                                </div>

                                <form v-if="agregandoOpcionA === campo.id" class="flex items-center gap-3"
                                      style="margin-top: var(--rml-space-3)" @submit.prevent="agregarOpcion(campo)">
                                    <div class="rml-field" style="flex: 1">
                                        <label class="rml-label" :for="`opcion-${campo.id}`">Etiqueta de la opción</label>
                                        <input :id="`opcion-${campo.id}`" v-model="opcionForm.label"
                                               class="rml-input" type="text" required>
                                    </div>
                                    <RmlButton type="submit" size="sm" :loading="opcionForm.processing">
                                        Agregar
                                    </RmlButton>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <div v-if="! campos.length" class="rml-empty" style="margin-top: var(--rml-space-4)">
                <strong class="rml-empty-title">Todavía no hay campos técnicos</strong>
                <span>Las obras se cargan igual: los campos técnicos son opcionales.</span>
            </div>
        </RmlCard>
    </AdminLayout>
</template>
