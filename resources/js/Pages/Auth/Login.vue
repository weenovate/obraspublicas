<script setup>
/**
 * Ingreso. Pantalla mínima de F0, con el RDS ya aplicado.
 *
 * La respuesta del servidor es uniforme a propósito: nunca dice si el correo
 * existe. Por eso el error se muestra una sola vez y sin distinguir causas.
 */
import { useForm } from '@inertiajs/vue3'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlAlert from '../../Components/rds/RmlAlert.vue'

const form = useForm({ email: '', password: '' })

function submit () {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <main class="rml-container" style="max-width: 26rem; padding-block: var(--rml-space-8)">
        <h1 style="margin-bottom: var(--rml-space-2)">Obras Públicas</h1>
        <p class="text-secondary" style="margin-bottom: var(--rml-space-6)">
            Municipalidad de Ramallo
        </p>

        <RmlCard title="Ingresar">
            <RmlAlert
                v-if="form.errors.email"
                tone="error"
                style="margin-bottom: var(--rml-space-4)"
            >
                {{ form.errors.email }}
            </RmlAlert>

            <form @submit.prevent="submit">
                <div class="rml-field">
                    <label class="rml-label" for="email">Correo electrónico</label>
                    <input
                        id="email"
                        v-model="form.email"
                        class="rml-input"
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                <div class="rml-field" style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" for="password">Contraseña</label>
                    <input
                        id="password"
                        v-model="form.password"
                        class="rml-input"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <RmlButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    style="margin-top: var(--rml-space-5); width: 100%"
                >
                    {{ form.processing ? 'Verificando…' : 'Ingresar' }}
                </RmlButton>
            </form>

            <p class="rml-hint" style="margin-top: var(--rml-space-4)">
                Si olvidaste tu contraseña, pedile al Administrador que te asigne una
                temporal: el sistema no envía correos de recuperación.
            </p>
        </RmlCard>
    </main>
</template>
