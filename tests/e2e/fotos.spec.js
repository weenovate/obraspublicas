import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { asegurarAdmin, ingresar } from './soporte/usuarios.js'

/**
 * El ciclo de una fotografía, en el navegador (F2).
 *
 * Los tests de PHP verifican el procesamiento y las reglas; lo que no pueden
 * verificar es que alguien pueda subir una foto y VERLA aparecer. Acá se
 * recorre el camino real: elegir el archivo, esperar a que se procese y
 * comprobar que la miniatura se dibuja.
 *
 * La cola corre por cron en producción, así que en el E2E se la ejecuta a mano
 * después de subir. Es exactamente lo que hace el cron cada minuto, sin la
 * espera: el arnés no debería tardar un minuto por foto.
 */

test.use({ reducedMotion: 'reduce' })

/** Procesa la cola una vez, como hace el cron. */
function procesarLaCola () {
    execFileSync('php', ['artisan', 'queue:work', '--stop-when-empty', '--max-time=30'], { stdio: 'pipe' })
}

async function asegurarSubcategoria (page, sufijo) {
    const categoria = `CatF ${sufijo}`
    const subcategoria = `SubF ${sufijo}`

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

    return { categoria, subcategoria }
}

/** Crea una obra y devuelve la URL de su edición, que es donde vive la galería. */
async function obraNueva (page, sufijo) {
    const { categoria, subcategoria } = await asegurarSubcategoria(page, sufijo)

    await page.goto('/obras/nueva')
    const mapa = page.getByTestId('editor-mapa')
    await expect(mapa.locator('.leaflet-map-pane')).toBeVisible()

    await page.getByLabel('Nombre').fill(`Obra con fotos ${sufijo}`)
    await page.getByLabel('Subcategoría').selectOption({ label: `${categoria} · ${subcategoria}` })
    await page.getByLabel('Inicio').fill('2026-02-01')
    await page.getByLabel('Finalización prevista').fill('2026-09-30')
    await mapa.click({ position: { x: 200, y: 150 } })
    await page.getByTestId('guardar-obra').click()

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^Obra OBR-/)

    return page.url()
}

/** Un JPEG mínimo pero real: tiene que poder abrirlo GD del lado del servidor. */
const JPEG_ROJO_1PX = Buffer.from(
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
    + 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
    + 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
    'base64',
)

test('se sube una foto, se procesa y aparece en la galería', async ({ page }, testInfo) => {
    const sufijo = `foto ${testInfo.project.name}`
    const email = `fotos-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    const urlEdicion = await obraNueva(page, sufijo)

    // Antes de subir nada, la galería lo dice en lugar de quedar vacía.
    await expect(page.getByText('Todavía no hay fotos')).toBeVisible()
    await expect(page.getByTestId('contador-fotos')).toContainText('0 de')

    await page.getByTestId('entrada-foto').setInputFiles({
        name: 'obra.jpg',
        mimeType: 'image/jpeg',
        buffer: JPEG_ROJO_1PX,
    })

    // Recién subida: existe, todavía no está lista. La obra ya está guardada
    // igual, que es lo que sostiene ADR-019.
    await expect(page.getByTestId('galeria')).toBeVisible()
    await expect(page.getByTestId('contador-fotos')).toContainText('1 de')
    // Acotado a la galería: el mensaje de éxito también dice «procesando», y
    // sin acotar el locator resuelve a dos elementos.
    await expect(page.getByTestId('galeria').getByText('Procesando')).toBeVisible()

    procesarLaCola()

    await page.goto(urlEdicion)

    // Ya procesada: la miniatura se dibuja de verdad, con su URL firmada.
    await expect(page.getByTestId('galeria').getByText('Lista')).toBeVisible()

    const miniatura = page.getByTestId('galeria').locator('img').first()
    await expect(miniatura).toBeVisible()
    await expect(miniatura).toHaveAttribute('src', /signature=/)

    // Y la imagen CARGA: un `src` firmado que devuelva 403 se vería igual de
    // bien en el DOM y estaría roto en pantalla.
    //
    // Hay que traerla a la vista y esperar. La miniatura lleva `loading="lazy"`,
    // así que en un viewport angosto queda debajo del pliegue y el navegador no
    // la descarga hasta que se acerca —`toBeVisible` no alcanza, porque el
    // elemento está en el DOM y visible sin haber cargado el archivo—. Sin esto
    // el caso pasa en escritorio y falla en móvil, que fue exactamente lo que
    // ocurrió.
    await miniatura.scrollIntoViewIfNeeded()
    await expect
        .poll(() => miniatura.evaluate((img) => img.complete && img.naturalWidth > 0),
            { message: 'la miniatura nunca terminó de cargar' })
        .toBe(true)
})

test('la foto se puede quitar de la obra', async ({ page }, testInfo) => {
    const sufijo = `quitar ${testInfo.project.name}`
    const email = `fotos-quitar-${testInfo.project.name}@ramallo.gob.ar`
    asegurarAdmin(email)
    await ingresar(page, email)

    await obraNueva(page, sufijo)

    await page.getByTestId('entrada-foto').setInputFiles({
        name: 'obra.jpg',
        mimeType: 'image/jpeg',
        buffer: JPEG_ROJO_1PX,
    })

    await expect(page.getByTestId('contador-fotos')).toContainText('1 de')

    await page.getByTestId('galeria').getByRole('button', { name: 'Quitar' }).click()

    await expect(page.getByTestId('contador-fotos')).toContainText('0 de')
    await expect(page.getByText('Todavía no hay fotos')).toBeVisible()
})
