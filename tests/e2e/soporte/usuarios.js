import { execFileSync } from 'node:child_process'

/**
 * Alta de usuarios para los casos E2E.
 *
 * Los E2E corren contra la base de DESARROLLO a través de `php artisan serve`,
 * no contra la de tests: no hay `RefreshDatabase` que prepare el terreno. El
 * usuario se crea con el mismo comando que usaría la Municipalidad
 * (`obras:crear-admin`), así que de paso queda ejercitado el camino real de
 * RF-AUT-002.
 *
 * La contraseña viaja por variable de entorno y nunca como argumento: en la
 * lista de procesos la vería cualquiera con acceso a la máquina.
 */

export const PASSWORD_E2E = 'una-contrasena-de-doce-o-mas'

/** Crea el usuario si no existe. Idempotente: la contraseña es siempre la misma. */
export function asegurarAdmin (email, nombre = 'Administradora E2E') {
    try {
        execFileSync(
            'php',
            ['artisan', 'obras:crear-admin', `--email=${email}`, `--name=${nombre}`, '--no-interaction'],
            { env: { ...process.env, ADMIN_INITIAL_PASSWORD: PASSWORD_E2E }, stdio: 'pipe' }
        )
    } catch (error) {
        const salida = `${error.stdout ?? ''}${error.stderr ?? ''}`

        // Ya existía de una corrida anterior: sirve igual, porque la contraseña
        // es la misma constante. Cualquier otra falla sí tiene que romper.
        if (! salida.includes('ya está en uso')) throw error
    }
}

/** Ingresa y espera a estar dentro del backoffice. */
export async function ingresar (page, email) {
    await page.goto('/login')
    await page.getByLabel('Correo electrónico').fill(email)
    await page.getByLabel('Contraseña').fill(PASSWORD_E2E)
    await page.getByRole('button', { name: /Ingresar|Verificando/ }).click()
    await page.waitForURL(/\/admin/)
}
