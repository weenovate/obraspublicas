<script setup>
/**
 * Estados (RF-OBR-008/009).
 *
 * «Finaliza la obra» es la casilla delicada: gobierna la semántica de las fechas,
 * así que se deshabilita en cuanto el estado tiene obras.
 */
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import RmlCard from '../../../Components/rds/RmlCard.vue'
import RmlButton from '../../../Components/rds/RmlButton.vue'
import RmlBadge from '../../../Components/rds/RmlBadge.vue'
import RmlAlert from '../../../Components/rds/RmlAlert.vue'

defineProps({ estados: { type: Array, required: true } })

const editando = ref(null)
const form = useForm({ label: '', is_final: false, sort_order: 0, is_active: true })

function editar (estado) {
    editando.value = estado
    Object.assign(form, {
        label: estado.label,
        is_final: estado.is_final,
        sort_order: estado.sort_order,
        is_active: estado.is_active,
    })
}

function guardar () {
    if (editando.value) {
        form.put(`/admin/estados/${editando.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { editando.value = null; form.reset() },
        })
    } else {
        form.post('/admin/estados', { preserveScroll: true, onSuccess: () => form.reset() })
    }
}
</script>

<template>
    <AdminLayout>
        <h1>Estados</h1>
        <p class="text-secondary" style="margin-top: var(--rml-space-2)">
            Los cinco estados base no se eliminan; podés cambiarles la etiqueta o desactivarlos.
        </p>

        <RmlCard :title="editando ? `Editar «${editando.label}»` : 'Nuevo estado'"
                 style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="Object.keys(form.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(form.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="est-label">Etiqueta</label>
                    <input id="est-label" v-model="form.label" class="rml-input" type="text" required>
                </div>

                <label class="rml-switch" style="margin-top: var(--rml-space-4)">
                    <input v-model="form.is_final" type="checkbox" :disabled="editando?.en_uso">
                    <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                    <span>Este estado finaliza la obra</span>
                </label>
                <p v-if="editando?.en_uso" class="rml-hint" style="margin-top: var(--rml-space-2)">
                    No se puede cambiar mientras tenga obras: cambiaría el significado de las fechas ya cargadas.
                </p>
                <p v-else class="rml-hint" style="margin-top: var(--rml-space-2)">
                    Con esta opción, la obra exige una fecha de finalización real y no puede ser futura.
                </p>

                <div class="flex items-center gap-3" style="margin-top: var(--rml-space-5)">
                    <RmlButton type="submit" :loading="form.processing">
                        {{ editando ? 'Guardar cambios' : 'Crear estado' }}
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
                    <tr><th>Etiqueta</th><th>Clave</th><th>Finaliza</th><th>Origen</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="estado in estados" :key="estado.id">
                        <td>{{ estado.label }}</td>
                        <td><code>{{ estado.key }}</code></td>
                        <td>
                            <RmlBadge :tone="estado.is_final ? 'success' : 'neutral'">
                                {{ estado.is_final ? 'Sí' : 'No' }}
                            </RmlBadge>
                        </td>
                        <td>{{ estado.is_system ? 'Del sistema' : 'Propio' }}</td>
                        <td>{{ estado.is_active ? 'Activo' : 'Inactivo' }}</td>
                        <td><RmlButton variant="ghost" size="sm" @click="editar(estado)">Editar</RmlButton></td>
                    </tr>
                </tbody>
            </table>
        </RmlCard>
    </AdminLayout>
</template>
