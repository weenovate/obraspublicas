<script setup>
/**
 * Botón — wrapper fino sobre `.rml-btn` del RDS.
 *
 * El estilo vive en el CSS del paquete; el componente sólo compone clases y
 * accesibilidad. Así una actualización del RDS se absorbe sin reescribir
 * componentes.
 */
import { computed } from 'vue'

const props = defineProps({
  variant: { type: String, default: 'primary' }, // primary | secondary | accent | ghost | danger
  size: { type: String, default: 'md' }, // sm | md | lg
  as: { type: String, default: 'button' }, // button | a
  href: { type: String, default: null },
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})

const classes = computed(() => [
  'rml-btn',
  `rml-btn-${props.variant}`,
  props.size !== 'md' ? `rml-btn-${props.size}` : null,
])

const tag = computed(() => (props.href ? 'a' : props.as))
</script>

<template>
  <component
    :is="tag"
    :class="classes"
    :href="href"
    :type="tag === 'button' ? type : null"
    :disabled="tag === 'button' ? disabled || loading : null"
    :aria-disabled="disabled || loading ? 'true' : null"
    :aria-busy="loading ? 'true' : null"
  >
    <slot />
  </component>
</template>
