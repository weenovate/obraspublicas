<script setup>
/**
 * Cambio de contraseña propia (RF-AUT-003/004).
 *
 * Es también la pantalla que bloquea a quien tiene una contraseña temporal: hasta
 * que elija una propia, no puede ir a ninguna otra parte.
 */
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlAlert from '../../Components/rds/RmlAlert.vue'

const page = usePage()
const debeCambiar = computed(() => page.props.auth?.user?.must_change_password ?? false)

const form = useForm({ current_password: '', password: '', password_confirmation: '' })

function guardar () {
    form.put('/perfil/password', { onFinish: () => form.reset('current_password', 'password', 'password_confirmation') })
}
</script>

<template>
    <main class="rml-container" style="max-width: 30rem; padding-block: var(--rml-space-7)">
        <h1 style="margin-bottom: var(--rml-space-5)">Cambiar contraseña</h1>

        <RmlAlert v-if="debeCambiar" tone="warning" title="Tenés una contraseña temporal"
                  style="margin-bottom: var(--rml-space-5)">
            La eligió otra persona, así que hay que reemplazarla antes de seguir.
        </RmlAlert>

        <RmlCard>
            <RmlAlert v-if="Object.keys(form.errors).length" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ Object.values(form.errors).join(' ') }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div class="rml-field">
                    <label class="rml-label" for="actual">Contraseña actual</label>
                    <input id="actual" v-model="form.current_password" class="rml-input" type="password"
                           autocomplete="current-password" required>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="nueva">Contraseña nueva</label>
                    <input id="nueva" v-model="form.password" class="rml-input" type="password"
                           autocomplete="new-password" required>
                    <span class="rml-hint">Mínimo 12 caracteres.</span>
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="repetir">Repetir contraseña nueva</label>
                    <input id="repetir" v-model="form.password_confirmation" class="rml-input" type="password"
                           autocomplete="new-password" required>
                </div>

                <RmlButton type="submit" :loading="form.processing" style="margin-top: var(--rml-space-5); width: 100%">
                    Guardar contraseña
                </RmlButton>
            </form>

            <p class="rml-hint" style="margin-top: var(--rml-space-4)">
                Al cambiarla se cierran tus otras sesiones abiertas.
            </p>
        </RmlCard>
    </main>
</template>
