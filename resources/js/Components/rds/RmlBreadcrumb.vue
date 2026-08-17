<script setup>
/**
 * Migas — `.rml-crumbs` del RDS.
 *
 * El último elemento no es un enlace y lleva `aria-current="page"`: enlazar a la
 * página en la que ya se está es ruido para quien navega con lector de pantalla.
 */
defineProps({
  items: { type: Array, required: true }, // [{ label, href? }]
})
</script>

<template>
  <nav class="rml-crumbs" aria-label="Ruta de navegación">
    <ol>
      <li v-for="(item, index) in items" :key="index">
        <a v-if="item.href && index < items.length - 1" :href="item.href">{{ item.label }}</a>
        <span v-else aria-current="page">{{ item.label }}</span>
      </li>
    </ol>
  </nav>
</template>
