<script setup>
/**
 * Campos técnicos dinámicos de una obra (RF-DIN-001…005).
 *
 * Los define el Administrador por categoría o por subcategoría, y la obra
 * presenta la unión de ambos alcances. Qué campos aparecen depende de la
 * subcategoría elegida, así que la lista se recarga al cambiarla —recarga
 * PARCIAL: sólo esta propiedad, para no perder lo que la persona ya escribió en
 * el resto del formulario—.
 *
 * CADA TIPO CON SU CONTROL. Un entero con `type="number"` y paso 1, una fecha
 * con el selector nativo, un booleano con el switch del RDS. Usar una caja de
 * texto para todo sería más corto de escribir y peor de usar: el teclado del
 * teléfono cambia, la validación del navegador desaparece y el formato queda
 * librado a lo que cada persona escriba.
 *
 * LA VALIDACIÓN DE VERDAD ESTÁ EN EL SERVIDOR. Lo de acá —`min`, `max`,
 * `required`— es para avisar antes, no para decidir: `WorkFieldValueWriter` es
 * el que manda, porque es el único camino por el que pasan también una consola o
 * una importación futura.
 */
import { computed } from 'vue'

const props = defineProps({
    campos: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    faltantes: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

const hayCampos = computed(() => props.campos.length > 0)

function valor (campo) {
    return props.modelValue[campo.id] ?? campo.valor ?? (campo.data_type === 'BOOLEAN' ? false : '')
}

function actualizar (campo, valorNuevo) {
    emit('update:modelValue', { ...props.modelValue, [campo.id]: valorNuevo })
}

/** El pie de cada campo: la ayuda del Administrador más el rango, si lo hay. */
function ayuda (campo) {
    const partes = []

    if (campo.help_text) partes.push(campo.help_text)

    if (campo.min_value !== null && campo.max_value !== null) {
        partes.push(`Entre ${campo.min_value} y ${campo.max_value}${campo.unit ? ' ' + campo.unit : ''}.`)
    } else if (campo.min_value !== null) {
        partes.push(`Mínimo ${campo.min_value}${campo.unit ? ' ' + campo.unit : ''}.`)
    } else if (campo.max_value !== null) {
        partes.push(`Máximo ${campo.max_value}${campo.unit ? ' ' + campo.unit : ''}.`)
    }

    return partes.join(' ')
}

function etiqueta (campo) {
    return campo.unit ? `${campo.label} (${campo.unit})` : campo.label
}
</script>

<template>
    <div>
        <p v-if="faltantes.length" class="rml-hint" data-testid="campos-faltantes">
            Esta obra no tiene cargados campos que hoy son obligatorios:
            <strong>{{ faltantes.join(', ') }}</strong>. Se pueden completar ahora; no hacía falta
            cuando se cargó.
        </p>

        <p v-if="! hayCampos" class="rml-hint" data-testid="sin-campos">
            Esta subcategoría no tiene campos técnicos definidos. Un Administrador puede agregarlos
            desde la sección de campos técnicos.
        </p>

        <div v-else data-testid="campos-tecnicos">
            <div v-for="campo in campos" :key="campo.id" class="rml-field">
                <template v-if="campo.data_type === 'BOOLEAN'">
                    <label class="rml-switch">
                        <input
                            type="checkbox"
                            :checked="Boolean(valor(campo))"
                            :data-testid="`campo-${campo.code}`"
                            @change="actualizar(campo, $event.target.checked)"
                        >
                        <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                        <span>{{ etiqueta(campo) }}<template v-if="campo.is_required"> *</template></span>
                    </label>
                </template>

                <template v-else>
                    <label class="rml-label" :for="`campo-${campo.code}`">
                        {{ etiqueta(campo) }}<template v-if="campo.is_required"> *</template>
                    </label>

                    <select
                        v-if="campo.data_type === 'SELECT'"
                        :id="`campo-${campo.code}`"
                        class="rml-select"
                        :value="valor(campo)"
                        :required="campo.is_required"
                        :data-testid="`campo-${campo.code}`"
                        @change="actualizar(campo, $event.target.value)"
                    >
                        <option value="">Sin especificar</option>
                        <option v-for="opcion in campo.opciones" :key="opcion.id" :value="opcion.id">
                            {{ opcion.label }}
                        </option>
                    </select>

                    <textarea
                        v-else-if="campo.data_type === 'LONG_TEXT'"
                        :id="`campo-${campo.code}`"
                        class="rml-textarea"
                        rows="3"
                        :value="valor(campo)"
                        :required="campo.is_required"
                        :data-testid="`campo-${campo.code}`"
                        @input="actualizar(campo, $event.target.value)"
                    />

                    <input
                        v-else
                        :id="`campo-${campo.code}`"
                        class="rml-input"
                        :type="campo.data_type === 'DATE' ? 'date' : (campo.data_type === 'INTEGER' || campo.data_type === 'DECIMAL' ? 'number' : 'text')"
                        :step="campo.data_type === 'INTEGER' ? '1' : (campo.data_type === 'DECIMAL' ? 'any' : undefined)"
                        :min="campo.min_value ?? undefined"
                        :max="campo.max_value ?? undefined"
                        :value="valor(campo)"
                        :required="campo.is_required"
                        :data-testid="`campo-${campo.code}`"
                        @input="actualizar(campo, $event.target.value)"
                    >
                </template>

                <span v-if="ayuda(campo)" class="rml-hint">{{ ayuda(campo) }}</span>
            </div>
        </div>
    </div>
</template>
