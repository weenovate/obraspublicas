import { test, expect } from '@playwright/test'
import { asegurarAdmin, ingresar } from './soporte/usuarios.js'

/**
 * CA-025 · El tema elegido viaja con la cuenta, no con el navegador.
 *
 *   Dado: un usuario que eligió tema oscuro
 *   Cuando: ingresa desde otro navegador
 *   Entonces: ve el tema oscuro, sin haberlo elegido de nuevo.
 *
 * Todo el peso del caso está en «otro navegador»: por eso se usan DOS contextos
 * distintos, que no comparten cookies ni almacenamiento local. Con un solo
 * contexto el test pasaría igual con la preferencia guardada en el cliente, que
 * es exactamente la implementación que este criterio descarta.
 *
 * Se verifica en las dos direcciones —oscuro y de vuelta a claro— para que el
 * caso no pueda pasar por casualidad si el tema oscuro fuera el predeterminado,
 * y para que la corrida deje la cuenta como la encontró.
 */

// La transición de 150 ms haría intermitente cualquier medición de color.
test.use({ reducedMotion: 'reduce' })

/** Un correo por proyecto: la clave del límite de tasa es correo + IP y los seis viewports corren en paralelo. */
function correoDe (testInfo) {
    return `tema-${testInfo.project.name}@ramallo.gob.ar`
}

/** Guarda la preferencia desde el perfil y espera la confirmación del servidor. */
async function elegirTema (page, valor) {
    await page.goto('/perfil')
    await page.getByTestId('selector-tema').selectOption(valor)
    await page.getByRole('button', { name: 'Guardar' }).click()
    // El aviso de éxito es `role="status"`, no `alert`: es información, no una
    // interrupción, y anunciarlo como alerta interrumpiría al lector de pantalla
    // en medio de otra cosa.
    await expect(page.getByRole('status')).toContainText('Perfil actualizado')
}

test('la preferencia de tema se aplica en otro navegador', async ({ browser }, testInfo) => {
    const email = correoDe(testInfo)
    asegurarAdmin(email)

    // ---- Navegador 1: elige oscuro ----
    const primero = await browser.newContext()
    const paginaUno = await primero.newPage()

    await ingresar(paginaUno, email)
    await elegirTema(paginaUno, 'dark')

    // El documento adopta el tema sin esperar a una navegación completa.
    await expect(paginaUno.locator('html')).toHaveAttribute('data-theme', 'dark')

    // ---- Navegador 2: sin cookies ni almacenamiento del primero ----
    const segundo = await browser.newContext()
    const paginaDos = await segundo.newPage()

    // El dispositivo dice claro a propósito: si el tema siguiera al sistema
    // operativo en vez de a la cuenta, acá se vería claro y el caso fallaría.
    await paginaDos.emulateMedia({ colorScheme: 'light' })

    const respuesta = await paginaDos.goto('/login')

    // Antes de ingresar todavía manda el predeterminado del sistema, no la
    // preferencia de nadie.
    expect(await respuesta.text()).toContain('data-theme=')

    await ingresar(paginaDos, email)

    await expect(paginaDos.locator('html')).toHaveAttribute('data-theme', 'dark')

    // Y el atributo viene ESTAMPADO EN EL HTML, no puesto por JavaScript después
    // de montar: si no, habría un destello claro en cada carga.
    const htmlServido = await (await paginaDos.request.get('/admin')).text()
    expect(htmlServido).toContain('data-theme="dark"')

    // ---- Vuelta a claro, para dejar la cuenta como estaba ----
    await elegirTema(paginaDos, 'light')

    const tercero = await browser.newContext()
    const paginaTres = await tercero.newPage()
    await paginaTres.emulateMedia({ colorScheme: 'dark' })

    await ingresar(paginaTres, email)
    await expect(paginaTres.locator('html')).toHaveAttribute('data-theme', 'light')

    await Promise.all([primero.close(), segundo.close(), tercero.close()])
})

test('«usar el predeterminado» deja de fijar el tema en la cuenta', async ({ browser }, testInfo) => {
    // RF-CFG-005: preferencia vacía no significa «seguir al dispositivo» en el
    // backoffice, significa «usar el tema predeterminado configurado».
    const email = `predeterminado-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)

    const contexto = await browser.newContext()
    const page = await contexto.newPage()

    await ingresar(page, email)
    await elegirTema(page, 'dark')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

    await elegirTema(page, '')

    // El predeterminado de fábrica es claro, y con el dispositivo en oscuro la
    // única forma de ver claro es que mande la configuración del sistema.
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/admin')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await contexto.close()
})
