import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { applyTheme, storedTheme } from './theme'

// El backend estampa `data-theme` antes de la primera pintura para el
// backoffice; en la Web pública, sin sesión, la preferencia vive en
// `localStorage` y se aplica acá antes de montar.
if (! document.documentElement.hasAttribute('data-theme')) {
    applyTheme(storedTheme())
}

// Recién con la primera pintura hecha se habilitan las transiciones de tema: así
// el cambio manual se ve suave y la carga inicial no hace un desvanecido.
requestAnimationFrame(() => document.body.classList.add('rml-theme-transitions'))

const appName = import.meta.env.VITE_APP_NAME ?? 'Obras Públicas Ramallo'

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })

        return pages[`./Pages/${name}.vue`]
    },
    setup ({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    progress: {
        // El color de la barra sale del token de acción resuelto en tiempo real,
        // así sigue al tema en lugar de quedar clavado.
        color: getComputedStyle(document.documentElement)
            .getPropertyValue('--rml-action')
            .trim() || undefined,
    },
})
