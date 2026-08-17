<script setup>
/** Tarjeta — `.rml-card` del RDS, con encabezado y pie opcionales. */
defineProps({
  title: { type: String, default: null },
  as: { type: String, default: 'section' },
})
</script>

<template>
  <component :is="as" class="rml-card">
    <header v-if="title || $slots.header" class="rml-card-header">
      <slot name="header">
        <h3>{{ title }}</h3>
      </slot>
    </header>
    <div class="rml-card-body">
      <slot />
    </div>
    <footer v-if="$slots.footer" class="rml-card-footer">
      <slot name="footer" />
    </footer>
  </component>
</template>
