import { test, expect } from '@playwright/test'

/**
 * Caso de humo de F0: que el sistema de diseño llegue al navegador de verdad.
 *
 * No alcanza con que el build pase: un `@font-face` roto o un CSS que no se
 * carga degradan en silencio, y eso se descubre mirando una captura de
 * producción semanas después.
 */

test('la pantalla de ingreso carga con el RDS aplicado', async ({ page }) => {
    const response = await page.goto('/login')

    expect(response?.status()).toBe(200)
    await expect(page.getByRole('heading', { name: 'Obras Públicas' })).toBeVisible()
    await expect(page.getByLabel('Correo electrónico')).toBeVisible()
    await expect(page.getByLabel('Contraseña')).toBeVisible()

    // Los tokens del RDS están resueltos: si el CSS no cargó, esto viene vacío.
    const token = await page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue('--rml-action').trim()
    )
    expect(token).not.toBe('')

    // La tipografía es la del paquete, no la del sistema.
    const familia = await page.evaluate(() => getComputedStyle(document.body).fontFamily)
    expect(familia).toContain('Inter')
})

test('las tipografías self-hosted se descargan de verdad', async ({ page }) => {
    const fuentes = []
    page.on('response', (response) => {
        if (response.url().endsWith('.woff2')) fuentes.push({ url: response.url(), status: response.status() })
    })

    await page.goto('/login')
    await page.evaluate(() => document.fonts.ready)

    // Ninguna 404: un @font-face roto degrada a la fuente del sistema sin avisar.
    expect(fuentes.length).toBeGreaterThan(0)
    expect(fuentes.filter((f) => f.status !== 200)).toEqual([])
})

test('las cabeceras de seguridad llegan al navegador', async ({ page }) => {
    const response = await page.goto('/login')
    const headers = response?.headers() ?? {}

    expect(headers['x-content-type-options']).toBe('nosniff')
    expect(headers['x-frame-options']).toBe('DENY')
    expect(headers['content-security-policy']).toContain("default-src 'self'")
})

test('el formulario responde con error uniforme ante credenciales inválidas', async ({ page }, testInfo) => {
    await page.goto('/login')

    // El correo lleva el nombre del proyecto porque la clave del límite de tasa es
    // correo + IP: con los seis viewports corriendo en paralelo desde la misma IP,
    // un correo compartido agota el límite de 5 por minuto y el sexto proyecto
    // recibe el 429 en lugar del error de credenciales. Eso sería el control
    // funcionando, no un fallo, pero haría intermitente al test.
    await page.getByLabel('Correo electrónico').fill(`nadie-${testInfo.project.name}@ramallo.gob.ar`)
    await page.getByLabel('Contraseña').fill('incorrecta')
    await page.getByRole('button', { name: /Ingresar|Verificando/ }).click()

    await expect(page.getByRole('alert')).toContainText('Las credenciales no son correctas')
})
