import { test, expect } from '@playwright/test'
import { asegurarAdmin, ingresar } from './soporte/usuarios.js'

/**
 * El editor cartográfico, dibujando de verdad (F1-B).
 *
 * Los tests de PHP verifican que una geometría bien formada se guarde y cumpla
 * el invariante; lo que no pueden verificar es que ALGUIEN PUEDA DIBUJARLA. Un
 * clic que no agrega un vértice, un mapa que no llega a montarse o un botón de
 * deshacer que borra todo son defectos que sólo se ven acá.
 *
 * El catálogo se prepara por la interfaz y no por un seeder: es el camino que va
 * a recorrer la Municipalidad, y de paso las pantallas de F1-A quedan ejercitadas
 * en el mismo recorrido. Los nombres llevan el del proyecto de Playwright porque
 * los seis viewports corren en paralelo contra la misma base de desarrollo.
 */

test.use({ reducedMotion: 'reduce' })

/**
 * Crea la categoría y la subcategoría del modo pedido, si no existen.
 *
 * La búsqueda de la fila NO es exacta a propósito: en cuanto el catálogo tiene
 * obras cargadas, la celda pasa a leerse «Cat … En uso», y una coincidencia
 * exacta dejaría de encontrarla en la segunda corrida —que es cuando el catálogo
 * ya existe y hay que reutilizarlo—.
 */
async function asegurarSubcategoria (page, sufijo, modo) {
    const categoria = `Cat ${sufijo}`
    const subcategoria = `Sub ${sufijo}`

    await page.goto('/admin/categorias')

    if (await page.getByRole('cell', { name: categoria }).count() === 0) {
        await page.getByLabel('Nombre').first().fill(categoria)
        await page.getByRole('button', { name: 'Crear categoría' }).click()
        await expect(page.getByRole('cell', { name: categoria })).toBeVisible()
    }

    await page.goto('/admin/subcategorias')

    if (await page.getByRole('cell', { name: subcategoria }).count() === 0) {
        await page.getByLabel('Categoría').selectOption({ label: categoria })
        await page.getByLabel('Nombre').first().fill(subcategoria)
        await page.getByLabel('Tipo de geometría').selectOption(modo)
        await page.getByRole('button', { name: 'Crear subcategoría' }).click()
        await expect(page.getByRole('cell', { name: subcategoria })).toBeVisible()
    }

    return { categoria, subcategoria }
}

/** Completa lo que no es geometría. Las fechas van en el formato del control. */
async function completarDatos (page, nombre, { categoria, subcategoria }) {
    await page.getByLabel('Nombre').fill(nombre)
    // La opción se lee «Categoría · Subcategoría», que es como la arma el
    // formulario para distinguir dos subcategorías homónimas de rubros distintos.
    await page.getByLabel('Subcategoría').selectOption({ label: `${categoria} · ${subcategoria}` })
    await page.getByLabel('Inicio').fill('2026-02-01')
    await page.getByLabel('Finalización prevista').fill('2026-09-30')
}

test('se dibuja un punto en el mapa y la obra queda cargada', async ({ page }, testInfo) => {
    const sufijo = `punto ${testInfo.project.name}`
    const email = `obras-punto-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const catalogo = await asegurarSubcategoria(page, sufijo, 'POINT')

    await page.goto('/obras/nueva')

    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa).toBeVisible()
    // Leaflet monta las capas de forma asíncrona: sin esperar el panel, el clic
    // llega antes de que exista el manejador y no agrega nada.
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await completarDatos(page, `Obra de prueba ${sufijo}`, catalogo)

    await expect(page.getByTestId('estado-geometria')).toHaveText(/Falta marcar/)

    await mapa.click({ position: { x: 200, y: 150 } })

    await expect(page.getByTestId('estado-geometria')).toHaveText(/Ubicación marcada/)

    await page.getByTestId('guardar-obra').click()

    // Al crear se redirige a la edición, con el código ya asignado.
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Obra OBR-\d{4}-\d{4,}$/)
    await expect(page.getByText(/creada\./)).toBeVisible()
})

test('se dibuja una línea punto por punto y se puede deshacer el último', async ({ page }, testInfo) => {
    const sufijo = `linea ${testInfo.project.name}`
    const email = `obras-linea-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const catalogo = await asegurarSubcategoria(page, sufijo, 'LINE_MANUAL_NETWORK')

    await page.goto('/obras/nueva')

    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await completarDatos(page, `Red de agua ${sufijo}`, catalogo)

    // Un recorrido con quiebre, no tres clics alineados: una línea perfectamente
    // horizontal tiene caja de alto cero y el navegador la considera invisible,
    // así que el trazo existiría y la aserción fallaría igual.
    for (const [x, y] of [[120, 120], [180, 170], [240, 130]]) {
        await mapa.click({ position: { x, y } })
    }

    await expect(page.getByTestId('estado-geometria')).toHaveText(/3 puntos marcados\./)
    // El trazo se dibuja de verdad, no sólo el contador.
    await expect(mapa.locator('path.leaflet-interactive')).toBeVisible()

    await page.getByTestId('deshacer-vertice').click()
    await expect(page.getByTestId('estado-geometria')).toHaveText(/2 puntos marcados\./)

    await page.getByTestId('guardar-obra').click()

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Obra OBR-/)

    // La longitud se calculó en el servidor y volvió con la obra.
    await expect(page.getByTestId('estado-geometria')).toHaveText(/2 puntos marcados\./)
})

test('un polígono incompleto no se puede guardar, y completo sí', async ({ page }, testInfo) => {
    const sufijo = `poli ${testInfo.project.name}`
    const email = `obras-poli-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const catalogo = await asegurarSubcategoria(page, sufijo, 'POLYGON')

    await page.goto('/obras/nueva')

    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await completarDatos(page, `Plaza ${sufijo}`, catalogo)

    // Dos esquinas: todavía no es un área.
    await mapa.click({ position: { x: 140, y: 120 } })
    await mapa.click({ position: { x: 220, y: 120 } })

    await expect(page.getByTestId('estado-geometria')).toHaveText(/faltan 1/)

    await page.getByTestId('guardar-obra').click()

    // El servidor rechaza y la persona no pierde lo que escribió.
    await expect(page.getByTestId('errores')).toBeVisible()
    await expect(page.getByLabel('Nombre')).toHaveValue(`Plaza ${sufijo}`)

    await mapa.click({ position: { x: 220, y: 200 } })
    await expect(page.getByTestId('estado-geometria')).toHaveText(/3 puntos marcados\./)

    await page.getByTestId('guardar-obra').click()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Obra OBR-/)
})

test('la obra editada vuelve con su geometría dibujada y se envía a la papelera', async ({ page }, testInfo) => {
    const sufijo = `ciclo ${testInfo.project.name}`
    const email = `obras-ciclo-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const catalogo = await asegurarSubcategoria(page, sufijo, 'POINT')

    await page.goto('/obras/nueva')
    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await completarDatos(page, `Obra del ciclo ${sufijo}`, catalogo)
    await mapa.click({ position: { x: 200, y: 150 } })
    await page.getByTestId('guardar-obra').click()

    const titulo = page.getByRole('heading', { level: 1 })
    await expect(titulo).toHaveText(/^Obra OBR-/)
    const codigo = (await titulo.textContent()).replace('Obra ', '').trim()

    // Se vuelve a abrir: la geometría guardada aparece dibujada, no vacía.
    await page.goto('/obras')
    await page.getByRole('row', { name: new RegExp(codigo) }).getByRole('link', { name: 'Editar' }).click()

    await expect(page.getByTestId('editor-mapa').locator('.leaflet-map-pane')).toBeVisible()
    await expect(page.getByTestId('estado-geometria')).toHaveText(/Ubicación marcada/)

    await page.getByTestId('enviar-papelera').click()
    await page.getByTestId('confirmar-papelera').click()

    await expect(page.getByText(/se envió a la papelera/)).toBeVisible()
    await expect(page.getByRole('row', { name: new RegExp(codigo) })).toHaveCount(0)
})
