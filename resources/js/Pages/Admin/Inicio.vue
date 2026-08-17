<script setup>
/**
 * Inicio del backoffice.
 *
 * Usa el mismo marco que el resto: cuando tenía su propio encabezado, era la
 * única pantalla sin navegación, y justo es a la que se llega al ingresar. El
 * listado de obras con sus filtros y acciones la reemplaza en F1-B.
 */
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import RmlCard from '../../Components/rds/RmlCard.vue'
import RmlBadge from '../../Components/rds/RmlBadge.vue'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const esAdmin = computed(() => user.value?.role === 'ADMIN')
</script>

<template>
    <AdminLayout>
        <h1>Inicio</h1>

        <RmlCard title="Sesión iniciada" style="margin-top: var(--rml-space-5)">
            <p>
                {{ user?.name }} ·
                <RmlBadge :tone="esAdmin ? 'info' : 'neutral'">
                    {{ esAdmin ? 'Administrador' : 'Obras Públicas' }}
                </RmlBadge>
            </p>
            <p class="text-muted" style="margin-top: var(--rml-space-3)">
                El listado de obras y la carga cartográfica llegan en F1-B, una vez cerrada la compuerta G3.
            </p>
        </RmlCard>

        <RmlCard v-if="esAdmin" title="Administración" style="margin-top: var(--rml-space-5)">
            <p class="text-secondary">
                Usuarios y sesiones, los cinco catálogos, los campos técnicos y la configuración del sistema
                están disponibles desde el menú.
            </p>
        </RmlCard>
    </AdminLayout>
</template>
