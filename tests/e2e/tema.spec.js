import { test, expect } from '@playwright/test'

// Se emula «movimiento reducido» en toda la suite de tema: el CSS respeta esa
// preferencia y desactiva la transición, así cada medición de color devuelve el
// valor final en lugar de uno intermedio de la animación.
test.use({ reducedMotion: 'reduce' })

/**
 * Los TRES estados del tema, incluidos los dos cruzados.
 *
 * Esta es la verificación de la corrección de la sección 9 del plan. Una versión
 * anterior declaraba `color-scheme: light dark` en `:root` de forma
 * incondicional, y con eso un usuario que eligió claro explícitamente veía los
 * controles nativos —campos de fecha, desplegables, barras de desplazamiento— en
 * oscuro si su sistema operativo estaba en oscuro. La elección del usuario tiene
 * que ganar, y sólo se puede comprobar probando los casos cruzados.
 */

const PAGINA = '/referencia-rds'

async function esquemaEfectivo (page) {
    return page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)
}

async function fondoDePagina (page) {
    return page.evaluate(() => getComputedStyle(document.body).backgroundColor)
}

/** El token que gobierna el fondo, que cambia al instante y sin animación. */
async function tokenDeSuperficie (page) {
    return page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue('--rml-surface-page').trim()
    )
}

/** `#161a14` → `rgb(22, 26, 20)`, el formato en que el navegador reporta colores. */
function aRgb (hex) {
    const n = hex.replace('#', '')
    const full = n.length === 3 ? n.split('').map((c) => c + c).join('') : n

    return `rgb(${parseInt(full.slice(0, 2), 16)}, ${parseInt(full.slice(2, 4), 16)}, ${parseInt(full.slice(4, 6), 16)})`
}

test.describe('sin elección explícita, manda el dispositivo', () => {
    test('dispositivo en claro', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'light' })
        await page.goto(PAGINA)

        await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/)
        expect(await esquemaEfectivo(page)).toBe('light dark')
        await expect(page.getByTestId('effective-theme')).toHaveText('light')
    })

    test('dispositivo en oscuro', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'dark' })
        await page.goto(PAGINA)

        expect(await esquemaEfectivo(page)).toBe('light dark')
        await expect(page.getByTestId('effective-theme')).toHaveText('dark')
    })
})

test('claro explícito gana sobre un dispositivo en oscuro', async ({ page }) => {
    // El caso cruzado que el bug de `color-scheme` incondicional rompía.
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto(PAGINA)

    await page.getByRole('button', { name: 'Claro' }).click()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await esquemaEfectivo(page)).toBe('light')
    await expect(page.getByTestId('effective-theme')).toHaveText('light')
})

test('oscuro explícito gana sobre un dispositivo en claro', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto(PAGINA)

    await page.getByRole('button', { name: 'Oscuro' }).click()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    expect(await esquemaEfectivo(page)).toBe('dark')
    await expect(page.getByTestId('effective-theme')).toHaveText('dark')
})

test('el tema elegido sobrevive a recargar la página', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto(PAGINA)

    await page.getByRole('button', { name: 'Oscuro' }).click()

    // Se espera el valor final: el cambio de tema tiene una transición de 150 ms,
    // así que leer una sola vez justo después del clic devuelve un color
    // intermedio de la animación y el test sería intermitente.
    const fondoOscuro = await tokenDeSuperficie(page)
    await expect.poll(() => fondoDePagina(page)).toBe(aRgb(fondoOscuro))

    await page.reload()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    await expect.poll(() => fondoDePagina(page)).toBe(aRgb(fondoOscuro))
})

test('los dos temas pintan fondos distintos y ambos son opacos', async ({ page }) => {
    await page.goto(PAGINA)

    await page.getByRole('button', { name: 'Claro' }).click()
    const claro = aRgb(await tokenDeSuperficie(page))
    await expect.poll(() => fondoDePagina(page)).toBe(claro)

    await page.getByRole('button', { name: 'Oscuro' }).click()
    const oscuro = aRgb(await tokenDeSuperficie(page))
    await expect.poll(() => fondoDePagina(page)).toBe(oscuro)

    expect(claro).not.toBe(oscuro)
    // Un fondo transparente haría que la página tome el del navegador y el tema
    // se vería a medias.
    expect(claro).not.toContain('rgba(0, 0, 0, 0)')
    expect(oscuro).not.toContain('rgba(0, 0, 0, 0)')
})

test('los controles de Leaflet quedan legibles en oscuro', async ({ page }) => {
    // Sin el puente `rds-leaflet.css`, Leaflet pinta sus controles blancos con su
    // propio CSS y en oscuro quedan como un parche brillante (RF-THE-002). Acá se
    // verifica que las reglas del puente estén cargadas y resuelvan a tokens.
    await page.goto(PAGINA)
    await page.getByRole('button', { name: 'Oscuro' }).click()

    const { fondo, texto } = await page.evaluate(() => {
        const sonda = document.createElement('div')
        sonda.className = 'leaflet-container'
        const barra = document.createElement('div')
        barra.className = 'leaflet-bar'
        const enlace = document.createElement('a')
        barra.appendChild(enlace)
        sonda.appendChild(barra)
        document.body.appendChild(sonda)

        const estilo = getComputedStyle(enlace)
        const resultado = { fondo: estilo.backgroundColor, texto: estilo.color }
        sonda.remove()

        return resultado
    })

    // En oscuro el control no puede quedar blanco.
    expect(fondo).not.toBe('rgb(255, 255, 255)')
    expect(texto).not.toBe('rgb(0, 0, 0)')
})
