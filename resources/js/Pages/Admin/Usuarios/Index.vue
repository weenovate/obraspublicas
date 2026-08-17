<script setup>
/**
 * Usuarios (RF-USR-001/003).
 *
 * Cada acción destructiva —desactivar, revocar, reponer contraseña— pasa por una
 * confirmación explícita: son operaciones que expulsan a alguien de su sesión, y
 * un clic accidental en la fila equivocada tiene consecuencias reales.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import RmlCard from '../../../Components/rds/RmlCard.vue'
import RmlButton from '../../../Components/rds/RmlButton.vue'
import RmlBadge from '../../../Components/rds/RmlBadge.vue'
import RmlAlert from '../../../Components/rds/RmlAlert.vue'

const props = defineProps({
    usuarios: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    roles: { type: Array, required: true },
})

const mostrarAlta = ref(false)

const alta = useForm({ name: '', email: '', role: 'OBRAS_PUBLICAS', password: '' })
const passwordForm = useForm({ password: '' })
const reponiendo = ref(null)

function crear () {
    alta.post('/admin/usuarios', {
        preserveScroll: true,
        onSuccess: () => { alta.reset(); mostrarAlta.value = false },
    })
}

function desactivar (usuario) {
    if (! confirm(`¿Desactivar a ${usuario.name}? Se van a cerrar todas sus sesiones.`)) return
    router.post(`/admin/usuarios/${usuario.id}/desactivar`, {}, { preserveScroll: true })
}

function activar (usuario) {
    router.post(`/admin/usuarios/${usuario.id}/activar`, {}, { preserveScroll: true })
}

function reponerPassword () {
    passwordForm.post(`/admin/usuarios/${reponiendo.value.id}/password`, {
        preserveScroll: true,
        onSuccess: () => { passwordForm.reset(); reponiendo.value = null },
    })
}
</script>

<template>
    <AdminLayout>
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1>Usuarios</h1>
            <RmlButton @click="mostrarAlta = ! mostrarAlta">
                {{ mostrarAlta ? 'Cancelar' : 'Nuevo usuario' }}
            </RmlButton>
        </div>

        <RmlCard v-if="mostrarAlta" title="Nuevo usuario" style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="Object.keys(alta.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(alta.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="crear">
                <div class="rml-field">
                    <label class="rml-label" for="alta-nombre">Nombre</label>
                    <input id="alta-nombre" v-model="alta.name" class="rml-input" type="text" required>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="alta-email">Correo electrónico</label>
                    <input id="alta-email" v-model="alta.email" class="rml-input" type="email" required>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="alta-rol">Rol</label>
                    <select id="alta-rol" v-model="alta.role" class="rml-select">
                        <option v-for="rol in roles" :key="rol" :value="rol">
                            {{ rol === 'ADMIN' ? 'Administrador' : 'Obras Públicas' }}
                        </option>
                    </select>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="alta-password">Contraseña temporal</label>
                    <input id="alta-password" v-model="alta.password" class="rml-input" type="text" required>
                    <span class="rml-hint">
                        Mínimo 12 caracteres. Se le va a pedir que la cambie al ingresar.
                    </span>
                </div>

                <RmlButton type="submit" :loading="alta.processing" style="margin-top: var(--rml-space-5)">
                    Crear usuario
                </RmlButton>
            </form>
        </RmlCard>

        <RmlCard v-if="reponiendo" title="Reponer contraseña" style="margin-top: var(--rml-space-5)">
            <p class="text-secondary" style="margin-bottom: var(--rml-space-4)">
                Se le va a asignar una contraseña temporal a <strong>{{ reponiendo.name }}</strong>,
                y se le va a exigir cambiarla al ingresar.
            </p>
            <form class="flex items-center gap-3 flex-wrap" @submit.prevent="reponerPassword">
                <input v-model="passwordForm.password" class="rml-input" type="text" required
                       placeholder="Contraseña temporal" style="flex: 1 1 18rem">
                <RmlButton type="submit" :loading="passwordForm.processing">Asignar</RmlButton>
                <RmlButton variant="secondary" @click="reponiendo = null">Cancelar</RmlButton>
            </form>
        </RmlCard>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <table class="rml-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Sesiones</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="usuario in usuarios.data" :key="usuario.id">
                        <td>{{ usuario.name }}</td>
                        <td>{{ usuario.email }}</td>
                        <td>{{ usuario.role === 'ADMIN' ? 'Administrador' : 'Obras Públicas' }}</td>
                        <td>
                            <RmlBadge :tone="usuario.is_active ? 'success' : 'neutral'">
                                {{ usuario.is_active ? 'Activo' : 'Desactivado' }}
                            </RmlBadge>
                            <RmlBadge v-if="usuario.must_change_password" tone="warning">
                                Contraseña temporal
                            </RmlBadge>
                        </td>
                        <td>{{ usuario.sesiones_activas }}</td>
                        <td class="flex gap-2 flex-wrap">
                            <RmlButton variant="ghost" size="sm" @click="reponiendo = usuario">
                                Contraseña
                            </RmlButton>
                            <RmlButton v-if="usuario.is_active" variant="ghost" size="sm"
                                       @click="desactivar(usuario)">Desactivar</RmlButton>
                            <RmlButton v-else variant="ghost" size="sm" @click="activar(usuario)">
                                Activar
                            </RmlButton>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="! usuarios.data.length" class="rml-empty" style="margin-top: var(--rml-space-4)">
                <strong class="rml-empty-title">No hay usuarios que coincidan</strong>
            </div>
        </RmlCard>
    </AdminLayout>
</template>
