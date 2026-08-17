<script setup>
/**
 * Pestañas — `.rml-tabs` del RDS, con el patrón ARIA completo.
 *
 * El paquete trae las clases pero no la semántica: sin `role="tablist"`,
 * `aria-selected` y navegación por flechas, un lector de pantalla no anuncia que
 * esto es un grupo de pestañas y el teclado no funciona (RNF-ACC-001).
 */
import { ref, computed } from 'vue'

const props = defineProps({
  tabs: { type: Array, required: true }, // [{ id, label }]
  modelValue: { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

const internal = ref(props.modelValue ?? props.tabs[0]?.id ?? null)
const active = computed(() => props.modelValue ?? internal.value)

function select(id) {
  internal.value = id
  emit('update:modelValue', id)
}

function onKeydown(event, index) {
  const last = props.tabs.length - 1
  let next = null

  if (event.key === 'ArrowRight') next = index === last ? 0 : index + 1
  else if (event.key === 'ArrowLeft') next = index === 0 ? last : index - 1
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = last
  else return

  event.preventDefault()
  select(props.tabs[next].id)
  event.currentTarget.parentElement?.children[next]?.focus()
}
</script>

<template>
  <div class="rml-tabs">
    <div class="rml-tablist" role="tablist">
      <button
        v-for="(tab, index) in tabs"
        :key="tab.id"
        :id="`tab-${tab.id}`"
        class="rml-tab"
        :class="{ 'rml-tab-active': active === tab.id }"
        role="tab"
        type="button"
        :aria-selected="active === tab.id ? 'true' : 'false'"
        :aria-controls="`panel-${tab.id}`"
        :tabindex="active === tab.id ? 0 : -1"
        @click="select(tab.id)"
        @keydown="onKeydown($event, index)"
      >
        {{ tab.label }}
      </button>
    </div>

    <div
      v-for="tab in tabs"
      v-show="active === tab.id"
      :key="`panel-${tab.id}`"
      :id="`panel-${tab.id}`"
      class="rml-tabpanel"
      role="tabpanel"
      :aria-labelledby="`tab-${tab.id}`"
      tabindex="0"
    >
      <slot :name="tab.id" />
    </div>
  </div>
</template>
