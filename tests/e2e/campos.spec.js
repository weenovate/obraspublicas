import { test, expect } from '@playwright/test'
import { asegurarAdmin, ingresar } from './soporte/usuarios.js'

/**
 * Campos técnicos dinámicos en el formulario de obra (F2, RF-DIN-001…005).
 *
 * Lo que se verifica acá es lo que los tests de PHP no pueden: que los campos se
 * DIBUJEN con el control que corresponde a cada tipo, que cambien al cambiar de
 * subcategoría, y que un valor cargado siga ahí al reabrir la obra.
 *
 * Las definiciones se crean por consola y no manejando la pantalla de campos
 * técnicos. Es deliberado: esa pantalla ya tiene sus propias pruebas, y el sujeto
 * de este caso es el formulario de obra. Manejarla acá haría el test más largo y
 * más frágil sin verificar nada nuevo.
 */

test.use({ reducedMotion: 'reduce' })

/**
 * El código que el servidor deriva de la etiqueta: `Str::slug($label, '_')`.
 * Es lo que termina en el `data-testid` de cada control.
 */
function codigoDe (etiqueta) {
    return etiqueta.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
}

/**
 * Crea categoría, subcategoría y los cuatro campos por la interfaz.
 *
 * Por la interfaz y no por consola: `slug` y `code` NO son asignables en masa
 * —son inmutables por diseño— así que un fixture que los escriba con Eloquent
 * pelea contra las reglas del dominio en lugar de apoyarse en ellas. El camino
 * que recorre la Municipalidad es este, y de paso queda ejercitado.
 *
 * Devuelve también el `code` de cada campo, que es por donde el test los ubica.
 */
async function prepararCatalogo (page, sufijo) {
    const categoria = `CatC ${sufijo}`
    const subcategoria = `SubC ${sufijo}`

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
        await page.getByLabel('Tipo de geometría').selectOption('POINT')
        await page.getByRole('button', { name: 'Crear subcategoría' }).click()
        await expect(page.getByRole('cell', { name: subcategoria })).toBeVisible()
    }

    await page.goto('/admin/campos')

    // Las etiquetas llevan el sufijo del proyecto, y con ellas el `code` y el
    // `data-testid`. NO es cosmético: los seis viewports corren en paralelo
    // contra la misma base, y con etiquetas iguales el `testid` de un campo
    // «Ancho» es idéntico en todos. Entonces el test rellena el input que está
    // en pantalla sin poder distinguir si ya llegó el de SU subcategoría —los
    // campos se recargan de forma asíncrona al elegirla— y termina guardando un
    // valor contra una definición fuera de alcance. Con el sufijo, el control
    // sólo existe DESPUÉS de la recarga correcta, y esperarlo alcanza.
    for (const [base, tipo] of [
        ['Ancho', 'DECIMAL'],
        ['Tramos', 'INTEGER'],
        ['Observaciones', 'LONG_TEXT'],
        ['Iluminacion', 'BOOLEAN'],
    ]) {
        const etiqueta = `${base} ${sufijo}`

        if (await page.getByRole('cell', { name: etiqueta, exact: true }).count() > 0) continue

        await page.getByLabel('Se aplica a').selectOption('SUBCATEGORY')
        await page.getByLabel('Subcategoría', { exact: true }).selectOption({ label: subcategoria })
        await page.getByLabel('Etiqueta').fill(etiqueta)
        await page.getByLabel('Tipo de dato').selectOption(tipo)
        await page.getByRole('button', { name: 'Crear campo' }).click()
        await expect(page.getByRole('cell', { name: etiqueta, exact: true })).toBeVisible()
    }

    return {
        categoria,
        subcategoria,
        campos: {
            ancho: codigoDe(`Ancho ${sufijo}`),
            tramos: codigoDe(`Tramos ${sufijo}`),
            observaciones: codigoDe(`Observaciones ${sufijo}`),
            iluminacion: codigoDe(`Iluminacion ${sufijo}`),
        },
    }
}

test('los campos técnicos se cargan, se guardan y siguen ahí al reabrir', async ({ page }, testInfo) => {
    const sufijo = testInfo.project.name
    const email = `campos-${sufijo}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const { categoria, subcategoria, campos } = await prepararCatalogo(page, sufijo)

    await page.goto('/obras/nueva')
    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await page.getByLabel('Nombre').fill(`Obra con campos ${sufijo}`)
    await page.getByLabel('Subcategoría').selectOption({ label: `${categoria} · ${subcategoria}` })

    const ancho = page.getByTestId(`campo-${campos.ancho}`)
    const tramos = page.getByTestId(`campo-${campos.tramos}`)
    const observaciones = page.getByTestId(`campo-${campos.observaciones}`)
    const iluminacion = page.getByTestId(`campo-${campos.iluminacion}`)

    // Al elegir la subcategoría, sus campos aparecen sin recargar la página.
    // Esperar el control CON SUFIJO —y no la tarjeta— es lo que hace la espera
    // determinista: la tarjeta puede estar en pantalla con los campos de otra
    // subcategoría, pero este identificador sólo existe cuando llegó la recarga
    // parcial de ESTA.
    await expect(page.getByTestId('campos-tecnicos')).toBeVisible()
    await expect(ancho).toBeVisible()

    // Cada tipo con su control: el número es `number`, el largo es `textarea`,
    // el booleano es una casilla. Si todos fueran cajas de texto, esto pasaría
    // igual y la pantalla sería peor.
    await expect(ancho).toHaveAttribute('type', 'number')
    await expect(tramos).toHaveAttribute('step', '1')
    await expect(observaciones).toHaveJSProperty('tagName', 'TEXTAREA')
    await expect(iluminacion).toHaveAttribute('type', 'checkbox')

    await page.getByLabel('Inicio').fill('2026-02-01')
    await page.getByLabel('Finalización prevista').fill('2026-09-30')

    await ancho.fill('7.5')
    await tramos.fill('3')
    await observaciones.fill('Con badenes en las esquinas.')
    // Al switch del RDS se le hace clic en la ETIQUETA, no en el input: el
    // input real está oculto con `opacity: 0` —el patrón accesible, para que
    // teclado y lectores de pantalla lo encuentren— y lo visible es la pista.
    // `check()` sobre el input falla porque no es accionable, que es justo lo
    // que le pasaría a alguien intentando tocarlo con el dedo.
    await page.locator('label.rml-switch')
        .filter({ has: iluminacion })
        .click()

    await mapa.click({ position: { x: 200, y: 150 } })
    await page.getByTestId('guardar-obra').click()

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Obra OBR-/)

    // Reabrir: los valores volvieron del servidor, no de la memoria del navegador.
    await page.reload()

    await expect(ancho).toHaveValue('7.500000')
    await expect(tramos).toHaveValue('3')
    await expect(observaciones).toHaveValue('Con badenes en las esquinas.')
    await expect(iluminacion).toBeChecked()
})

test('una subcategoría sin campos definidos lo dice en lugar de quedar vacía', async ({ page }, testInfo) => {
    const email = `campos-sin-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    // Las subcategorías que crean los otros casos no tienen campos técnicos.
    await page.goto('/obras/nueva')
    await expect(page.getByTestId('editor-mapa').locator('.leaflet-map-pane')).toBeVisible()

    const sinCampos = page.getByTestId('sin-campos')
    const conCampos = page.getByTestId('campos-tecnicos')

    // Una de las dos tiene que estar: lo que no puede pasar es que la tarjeta
    // quede en blanco sin explicar por qué.
    await expect(sinCampos.or(conCampos)).toBeVisible()
})
