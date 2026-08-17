<script setup>
/**
 * Marcador del backoffice. F1 lo reemplaza por el listado de obras con sus
 * filtros, paginación y acciones.
 */
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlButton from '../../Components/rds/RmlButton.vue'
import RmlBadge from '../../Components/rds/RmlBadge.vue'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)

function logout () {
    router.post('/logout')
}
</script>

<template>
    <main class="rml-container" style="padding-block: var(--rml-space-7)">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h1>Obras Públicas</h1>
            <RmlButton variant="secondary" @click="logout">Cerrar sesión</RmlButton>
        </div>

        <RmlCard title="Sesión iniciada" style="margin-top: var(--rml-space-6)">
            <p>
                {{ user?.name }} ·
                <RmlBadge :tone="user?.role === 'ADMIN' ? 'info' : 'neutral'">
                    {{ user?.role === 'ADMIN' ? 'Administrador' : 'Obras Públicas' }}
                </RmlBadge>
            </p>
            <p class="text-muted" style="margin-top: var(--rml-space-3)">
                El listado de obras, los catálogos y la carga cartográfica llegan en F1,
                una vez cerradas las compuertas G2 y G3.
            </p>
        </RmlCard>
    </main>
</template>
