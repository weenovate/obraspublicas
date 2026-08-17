<script setup>
/**
 * Categorías (RF-CAT-001/002/003).
 *
 * Los campos que ya no se pueden cambiar se muestran deshabilitados con el motivo
 * a la vista, en lugar de dejar intentarlo y fallar al guardar: el usuario tiene
 * que entender por qué antes de escribir, no después.
 */
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import RmlCard from '../../../Components/rds/RmlCard.vue'
import RmlButton from '../../../Components/rds/RmlButton.vue'
import RmlBadge from '../../../Components/rds/RmlBadge.vue'
import RmlAlert from '../../../Components/rds/RmlAlert.vue'

const props = defineProps({
    categorias: { type: Array, required: true },
    iconos: { type: Object, required: true },
    // Lo propone el servidor: es el mismo que la política de color considera
    // válido, y en el código de la aplicación no van colores literales.
    colorSugerido: { type: String, required: true },
})

const editando = ref(null)
const form = useForm({
    name: '',
    icon: Object.keys(props.iconos)[0],
    color: props.colorSugerido,
    sort_order: 0,
    is_active: true,
})

function editar (categoria) {
    editando.value = categoria
    form.defaults({ ...categoria }).reset()
    Object.assign(form, {
        name: categoria.name,
        icon: categoria.icon,
        color: categoria.color,
        sort_order: categoria.sort_order,
        is_active: categoria.is_active,
    })
}

function guardar () {
    if (editando.value) {
        form.put(`/admin/categorias/${editando.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { editando.value = null; form.reset() },
        })
    } else {
        form.post('/admin/categorias', { preserveScroll: true, onSuccess: () => form.reset() })
    }
}
</script>

<template>
    <AdminLayout>
        <h1>Categorías</h1>
        <p class="text-secondary" style="margin-top: var(--rml-space-2)">
            Cada categoría es una capa del mapa. El color se verifica contra los dos temas antes de guardar.
        </p>

        <RmlCard :title="editando ? `Editar «${editando.name}»` : 'Nueva categoría'"
                 style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="Object.keys(form.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(form.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="cat-nombre">Nombre</label>
                    <input id="cat-nombre" v-model="form.name" class="rml-input" type="text" required>
                    <span v-if="editando?.en_uso" class="rml-hint">
                        La dirección web (<code>{{ editando.slug }}</code>) ya no cambia: hay obras cargadas
                        y los enlaces compartidos dejarían de funcionar.
                    </span>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="cat-icono">Icono</label>
                    <select id="cat-icono" v-model="form.icon" class="rml-select">
                        <option v-for="(etiqueta, clave) in iconos" :key="clave" :value="clave">{{ etiqueta }}</option>
                    </select>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="cat-color">Color</label>
                    <input id="cat-color" v-model="form.color" class="rml-input" type="color">
                    <span class="rml-hint">Tiene que distinguirse sobre el fondo claro y sobre el oscuro.</span>
                </div>

                <div class="flex items-center gap-3" style="margin-top: var(--rml-space-5)">
                    <RmlButton type="submit" :loading="form.processing">
                        {{ editando ? 'Guardar cambios' : 'Crear categoría' }}
                    </RmlButton>
                    <RmlButton v-if="editando" variant="secondary" @click="editando = null; form.reset()">
                        Cancelar
                    </RmlButton>
                </div>
            </form>
        </RmlCard>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <table class="rml-table">
                <thead>
                    <tr><th>Nombre</th><th>Color</th><th>Subcategorías</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="categoria in categorias" :key="categoria.id">
                        <td>
                            {{ categoria.name }}
                            <RmlBadge v-if="categoria.en_uso" tone="info">En uso</RmlBadge>
                        </td>
                        <td>
                            <span class="rml-legend-swatch" :style="{ background: categoria.color }"></span>
                            <code>{{ categoria.color }}</code>
                        </td>
                        <td>{{ categoria.subcategorias }}</td>
                        <td>{{ categoria.is_active ? 'Activa' : 'Inactiva' }}</td>
                        <td><RmlButton variant="ghost" size="sm" @click="editar(categoria)">Editar</RmlButton></td>
                    </tr>
                </tbody>
            </table>

            <div v-if="! categorias.length" class="rml-empty" style="margin-top: var(--rml-space-4)">
                <strong class="rml-empty-title">Todavía no hay categorías</strong>
                <span>Creá la primera para poder cargar obras.</span>
            </div>
        </RmlCard>
    </AdminLayout>
</template>
