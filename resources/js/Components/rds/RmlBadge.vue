<script setup>
/** Insignia — `.rml-badge` del RDS. */
import { computed } from 'vue'

const props = defineProps({
  tone: { type: String, default: 'neutral' }, // neutral | success | warning | error | info
})

const classes = computed(() => ['rml-badge', `rml-badge-${props.tone}`])
</script>

<template>
  <span :class="classes"><slot /></span>
</template>
