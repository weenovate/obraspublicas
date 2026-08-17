<script setup>
/**
 * Marco del backoffice: navegación, identidad del usuario y avisos.
 *
 * La navegación se arma según el rol: lo que un usuario Obras Públicas no puede
 * hacer tampoco se le muestra. No es un control de seguridad —de eso se ocupan
 * las políticas y el `can:` de las rutas— sino de honestidad: ofrecer un enlace
 * que va a devolver 403 es una promesa que la aplicación no puede cumplir.
 */
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import RmlAlert from '../Components/rds/RmlAlert.vue'
import RmlButton from '../Components/rds/RmlButton.vue'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const esAdmin = computed(() => user.value?.role === 'ADMIN')
const flash = computed(() => page.props.flash ?? {})

const enlaces = computed(() => {
    // Obras está para los dos roles: es lo que hace el rol Obras Públicas, y la
    // matriz del spec (2.2) se lo permite igual que al Administrador.
    const base = [
        { href: '/admin', label: 'Inicio' },
        { href: '/obras', label: 'Obras' },
    ]

    if (esAdmin.value) {
        base.push(
            { href: '/admin/usuarios', label: 'Usuarios' },
            { href: '/admin/categorias', label: 'Categorías' },
            { href: '/admin/subcategorias', label: 'Subcategorías' },
            { href: '/admin/estados', label: 'Estados' },
            { href: '/admin/campos', label: 'Campos técnicos' },
            { href: '/admin/configuracion', label: 'Configuración' },
        )
    }

    return base
})

function logout () {
    router.post('/logout')
}
</script>

<template>
    <div>
        <header
            class="rml-container flex items-center justify-between flex-wrap gap-3"
            style="padding-block: var(--rml-space-4); border-bottom: 1px solid var(--rml-border-subtle)"
        >
            <strong style="font-family: var(--rml-font-display)">Obras Públicas · Ramallo</strong>

            <nav class="flex items-center gap-3 flex-wrap" aria-label="Secciones">
                <a
                    v-for="enlace in enlaces"
                    :key="enlace.href"
                    :href="enlace.href"
                    :aria-current="page.url.startsWith(enlace.href) && enlace.href !== '/admin' ? 'page' : undefined"
                >{{ enlace.label }}</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="/perfil">{{ user?.name }}</a>
                <RmlButton variant="secondary" size="sm" @click="logout">Salir</RmlButton>
            </div>
        </header>

        <main class="rml-container" style="padding-block: var(--rml-space-6)">
            <RmlAlert v-if="flash.success" tone="success" style="margin-bottom: var(--rml-space-5)">
                {{ flash.success }}
            </RmlAlert>
            <RmlAlert v-if="flash.error" tone="error" style="margin-bottom: var(--rml-space-5)">
                {{ flash.error }}
            </RmlAlert>

            <slot />
        </main>
    </div>
</template>
