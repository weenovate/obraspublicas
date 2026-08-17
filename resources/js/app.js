import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
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

/*
 * El tema del backoffice sigue a la cuenta en CADA visita, no sólo en la carga
 * completa (RF-CFG-004/005, CA-025).
 *
 * Hace falta porque Inertia no recarga el documento: al ingresar reemplaza el
 * componente sobre el mismo `<html>`, que sigue con el atributo que estampó la
 * pantalla de ingreso —donde todavía no había usuario—. Sin esto, quien eligió
 * oscuro entra y ve claro hasta la próxima navegación completa.
 *
 * Vive acá y no en un layout a propósito: el tema es del documento, y atarlo a
 * un componente haría que cualquier página fuera de ese layout lo pierda.
 *
 * El evento es `success` y no `navigate` porque también tiene que cubrir las
 * recargas parciales —el perfil pide sólo `auth` y `theme` al guardar—, que no
 * cambian de página y por lo tanto no navegan.
 */
router.on('success', (evento) => {
    const tema = evento.detail?.page?.props?.theme?.efectivo

    if (tema) applyTheme(tema)
})

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
