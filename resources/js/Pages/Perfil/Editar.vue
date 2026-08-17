<script setup>
/**
 * Perfil propio (RF-USR-002, RF-CFG-004).
 *
 * El correo y el rol se muestran pero no se editan: los administra el Admin, y
 * dejarlos editables permitiría que alguien se auto-promoviera.
 *
 * El tema tiene TRES opciones en pantalla y dos en base: «Usar el predeterminado»
 * guarda vacío, que es lo que activa el respaldo configurable (RF-CFG-005).
 */
import { computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlBadge from '../../Components/rds/RmlBadge.vue'

const props = defineProps({
    temaPredeterminado: { type: String, required: true },
    sesiones: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)

const form = useForm({
    name: user.value?.name ?? '',
    theme_preference: user.value?.theme_preference ?? '',
})

function guardar () {
    form.transform((datos) => ({
        ...datos,
        // Cadena vacía en el formulario significa «sin preferencia» en la base.
        theme_preference: datos.theme_preference === '' ? null : datos.theme_preference,
    })).put('/perfil', {
        preserveScroll: true,
        // El tema se aplica al documento en cuanto el servidor lo confirma, sin
        // esperar a la próxima navegación.
        onSuccess: () => router.reload({ only: ['auth', 'theme'] }),
    })
}
</script>

<template>
    <AdminLayout>
        <h1>Mi perfil</h1>

        <RmlCard title="Datos" style="margin-top: var(--rml-space-5)">
            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="perfil-nombre">Nombre</label>
                    <input id="perfil-nombre" v-model="form.name" class="rml-input" type="text" required>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="perfil-email">Correo electrónico</label>
                    <input id="perfil-email" class="rml-input" type="email" :value="user?.email" disabled>
                    <span class="rml-hint">Lo administra un Administrador.</span>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="perfil-tema">Tema</label>
                    <select id="perfil-tema" v-model="form.theme_preference" class="rml-select"
                            data-testid="selector-tema">
                        <option value="">Usar el predeterminado ({{ temaPredeterminado === 'dark' ? 'oscuro' : 'claro' }})</option>
                        <option value="light">Claro</option>
                        <option value="dark">Oscuro</option>
                    </select>
                    <span class="rml-hint">
                        Se guarda en tu cuenta: al ingresar desde otro navegador se aplica igual.
                    </span>
                </div>

                <RmlButton type="submit" :loading="form.processing" style="margin-top: var(--rml-space-5)">
                    Guardar
                </RmlButton>
            </form>
        </RmlCard>

        <RmlCard title="Sesiones abiertas" style="margin-top: var(--rml-space-6)">
            <table class="rml-table">
                <thead>
                    <tr>
                        <th>Dispositivo</th>
                        <th>Dirección IP</th>
                        <th>Última actividad</th>
                        <th>Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sesion in sesiones" :key="sesion.id">
                        <td>
                            {{ sesion.dispositivo }}
                            <RmlBadge v-if="sesion.es_esta" tone="info">Esta sesión</RmlBadge>
                        </td>
                        <td>{{ sesion.ip }}</td>
                        <td>{{ sesion.ultima_actividad }}</td>
                        <td>{{ sesion.persistente ? 'Pantalla LIVE' : 'Normal' }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="rml-hint" style="margin-top: var(--rml-space-4)">
                Para cerrar una sesión de otro dispositivo, cambiá tu contraseña o pedíselo a un Administrador.
            </p>
        </RmlCard>

        <RmlCard title="Contraseña" style="margin-top: var(--rml-space-6)">
            <RmlButton variant="secondary" as="a" href="/perfil/password">Cambiar contraseña</RmlButton>
        </RmlCard>
    </AdminLayout>
</template>
