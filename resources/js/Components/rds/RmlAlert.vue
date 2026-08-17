<script setup>
/**
 * Alerta — `.rml-alert` del RDS.
 *
 * `role` se elige por tono: un error es `alert` (interrumpe al lector de
 * pantalla), el resto es `status`. Un mensaje de error que no se anuncia es un
 * mensaje que el usuario no lee.
 */
import { computed } from 'vue'

const props = defineProps({
  tone: { type: String, default: 'info' }, // info | success | warning | error
  title: { type: String, default: null },
})

const classes = computed(() => ['rml-alert', `rml-alert-${props.tone}`])
const role = computed(() => (props.tone === 'error' ? 'alert' : 'status'))
</script>

<template>
  <div :class="classes" :role="role">
    <strong v-if="title" class="rml-alert-title">{{ title }}</strong>
    <div class="rml-alert-body"><slot /></div>
  </div>
</template>
