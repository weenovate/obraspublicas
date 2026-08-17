import { test, expect } from '@playwright/test'
import { asegurarAdmin, ingresar } from './soporte/usuarios.js'

/**
 * Las pantallas del backoffice, en los DOS temas.
 *
 * El Definition of Done pide que toda pantalla nueva se revise en claro y en
 * oscuro. Revisarla a ojo una vez no impide que la próxima edición meta un color
 * literal o un fondo transparente, así que la revisión se automatiza: cada
 * pantalla se abre en los dos temas y se comprueba que el fondo pintado sea
 * exactamente el token de superficie, que es lo que se rompe cuando alguien
 * escribe un color a mano.
 */

test.use({ reducedMotion: 'reduce' })

const PANTALLAS = [
    ['/admin', 'Inicio'],
    ['/obras', 'Obras'],
    ['/obras/nueva', 'Nueva obra'],
    ['/admin/usuarios', 'Usuarios'],
    ['/admin/categorias', 'Categorías'],
    ['/admin/subcategorias', 'Subcategorías'],
    ['/admin/estados', 'Estados'],
    ['/admin/campos', 'Campos técnicos'],
    ['/admin/configuracion', 'Configuración'],
    ['/perfil', 'Mi perfil'],
]

/** `#161a14` → `rgb(22, 26, 20)`, el formato en que el navegador reporta colores. */
function aRgb (hex) {
    const n = hex.replace('#', '')
    const full = n.length === 3 ? n.split('').map((c) => c + c).join('') : n

    return `rgb(${parseInt(full.slice(0, 2), 16)}, ${parseInt(full.slice(2, 4), 16)}, ${parseInt(full.slice(4, 6), 16)})`
}

test('cada pantalla del backoffice se ve en los dos temas', async ({ page }, testInfo) => {
    const email = `backoffice-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)

    await ingresar(page, email)

    for (const tema of ['light', 'dark']) {
        // Se fuerza el tema en el documento en vez de guardarlo en el perfil: acá
        // lo que se revisa es cómo se PINTAN las pantallas, no de dónde sale la
        // preferencia —eso tiene su propio caso, `tema-por-usuario.spec.js`—.
        await page.evaluate((valor) => document.documentElement.setAttribute('data-theme', valor), tema)

        for (const [ruta, titulo] of PANTALLAS) {
            await page.goto(ruta)
            await page.evaluate((valor) => document.documentElement.setAttribute('data-theme', valor), tema)

            await expect(page.getByRole('heading', { name: titulo, level: 1 })).toBeVisible()

            const superficie = await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--rml-surface-page').trim()
            )

            // Se espera el valor FINAL: el cambio de tema tiene una transición, y
            // leer una sola vez justo después devuelve un color intermedio de la
            // animación. Un fondo transparente, en cambio, haría que la página
            // tome el del navegador y el tema se vería a medias.
            await expect
                .poll(() => page.evaluate(() => getComputedStyle(document.body).backgroundColor),
                    { message: `${ruta} en tema ${tema}` })
                .toBe(aRgb(superficie))
        }
    }
})

test('el menú no ofrece lo que el rol no puede hacer', async ({ page }, testInfo) => {
    // No es un control de seguridad —de eso se ocupan las políticas y el `can:`—
    // sino de honestidad: ofrecer un enlace que va a devolver 403 es una promesa
    // que la aplicación no puede cumplir.
    const email = `backoffice-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)

    await ingresar(page, email)

    const navegacion = page.getByRole('navigation', { name: 'Secciones' })

    for (const [, titulo] of PANTALLAS.filter(([ruta]) => ruta.startsWith('/admin/'))) {
        // `exact` porque «Categorías» también aparece dentro de «Subcategorías».
        await expect(navegacion.getByRole('link', { name: titulo, exact: true })).toBeVisible()
    }
})
