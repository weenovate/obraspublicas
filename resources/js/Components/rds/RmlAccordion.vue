<script setup>
/**
 * Acordeón — `.rml-acc-*` del RDS.
 *
 * Se implementa con `<button>` dentro del encabezado y `aria-expanded`, no con
 * `<details>`: hace falta controlar la apertura desde el estado de la
 * aplicación y `<details>` no permite el modo de un solo panel abierto.
 */
import { ref } from 'vue'

const props = defineProps({
  items: { type: Array, required: true }, // [{ id, label }]
  single: { type: Boolean, default: false },
})

const open = ref(new Set())

function toggle(id) {
  const next = new Set(props.single ? [] : open.value)
  if (open.value.has(id)) next.delete(id)
  else next.add(id)
  open.value = next
}
</script>

<template>
  <div class="rml-acc">
    <div v-for="item in items" :key="item.id" class="rml-acc-item">
      <h3 class="rml-acc-heading">
        <button
          class="rml-acc-trigger"
          type="button"
          :aria-expanded="open.has(item.id) ? 'true' : 'false'"
          :aria-controls="`acc-panel-${item.id}`"
          @click="toggle(item.id)"
        >
          {{ item.label }}
        </button>
      </h3>
      <div
        v-show="open.has(item.id)"
        :id="`acc-panel-${item.id}`"
        class="rml-acc-panel"
        role="region"
      >
        <slot :name="item.id" />
      </div>
    </div>
  </div>
</template>
