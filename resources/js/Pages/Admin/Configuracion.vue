<script setup>
/**
 * Configuración funcional (RF-CFG-001/002/003).
 *
 * El formulario se dibuja a partir de las definiciones tipadas del servidor, no
 * de una lista escrita acá: agregar una opción es agregarla en `AppSettings`, y
 * esta pantalla la muestra sola. Eso también garantiza lo que el spec pide por
 * omisión —no hay claves libres— porque no existe ningún campo «otro».
 *
 * Los mínimos y máximos se declaran en el control además de validarse en el
 * servidor. El control ayuda a no equivocarse; el servidor es el que decide.
 */
import { reactive } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlAlert from '../../Components/rds/RmlAlert.vue'

const props = defineProps({
    definiciones: { type: Array, required: true },
    valores: { type: Object, required: true },
})

const ETIQUETAS_ENUM = { light: 'Claro', dark: 'Oscuro' }

const form = useForm({ valores: reactive({ ...props.valores }) })

function errorDe (clave) {
    return form.errors[`valores.${clave}`]
}

function guardar () {
    form.put('/admin/configuracion', { preserveScroll: true })
}
</script>

<template>
    <AdminLayout>
        <h1>Configuración</h1>
        <p class="text-secondary" style="margin-top: var(--rml-space-2)">
            Opciones de funcionamiento del sistema. Las credenciales y claves de servicios externos no se
            configuran acá: se inyectan por entorno.
        </p>

        <RmlCard style="margin-top: var(--rml-space-5)">
            <RmlAlert v-if="form.errors.valores" tone="error" style="margin-bottom: var(--rml-space-4)">
                {{ form.errors.valores }}
            </RmlAlert>

            <form @submit.prevent="guardar">
                <div v-for="definicion in definiciones" :key="definicion.key" class="rml-field"
                     style="margin-top: var(--rml-space-4)">
                    <label class="rml-label" :for="`cfg-${definicion.key}`">{{ definicion.label }}</label>

                    <select v-if="definicion.data_type === 'ENUM'" :id="`cfg-${definicion.key}`"
                            v-model="form.valores[definicion.key]" class="rml-select">
                        <option v-for="opcion in definicion.allowed" :key="opcion" :value="opcion">
                            {{ ETIQUETAS_ENUM[opcion] ?? opcion }}
                        </option>
                    </select>

                    <input v-else-if="definicion.data_type === 'INTEGER'" :id="`cfg-${definicion.key}`"
                           v-model.number="form.valores[definicion.key]" class="rml-input" type="number"
                           :min="definicion.min ?? undefined" :max="definicion.max ?? undefined">

                    <input v-else :id="`cfg-${definicion.key}`" v-model="form.valores[definicion.key]"
                           class="rml-input" type="text">

                    <span v-if="definicion.help" class="rml-hint">{{ definicion.help }}</span>
                    <span v-if="definicion.data_type === 'INTEGER' && definicion.min !== null" class="rml-hint">
                        Entre {{ definicion.min }} y {{ definicion.max }}.
                    </span>
                    <span v-if="errorDe(definicion.key)" class="rml-error-text">{{ errorDe(definicion.key) }}</span>
                </div>

                <div class="flex items-center gap-3" style="margin-top: var(--rml-space-5)">
                    <RmlButton type="submit" :loading="form.processing">Guardar configuración</RmlButton>
                </div>
            </form>
        </RmlCard>
    </AdminLayout>
</template>
