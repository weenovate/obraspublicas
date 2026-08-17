import { existsSync } from 'node:fs'
import { defineConfig, devices } from '@playwright/test'

/**
 * Chromium: se usa el preinstalado del entorno si está, y si no el que instala
 * Playwright. Así la configuración funciona igual en una máquina de desarrollo
 * con el navegador provisto y en CI, donde se instala con
 * `npx playwright install chromium`.
 */
const CHROMIUM_PREINSTALADO = '/opt/pw-browsers/chromium'
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH
    ?? (existsSync(CHROMIUM_PREINSTALADO) ? CHROMIUM_PREINSTALADO : undefined)

/**
 * Arnés E2E. Se instala en F0 con un caso de humo para que las fases siguientes
 * agreguen casos en vez de montar el arnés tarde, que es cuando ya nadie tiene
 * tiempo de hacerlo bien.
 *
 * El navegador es el Chromium preinstalado del entorno: no se descarga nada
 * (PLAYWRIGHT_BROWSERS_PATH ya apunta a /opt/pw-browsers).
 *
 * La matriz de viewports sale de RNF-UI-001 y RNF-COM-001. Los del kiosco son
 * provisorios hasta cerrar G4: sin conocer la resolución nativa, el escalado del
 * sistema y la distancia de visualización, cualquier ajuste fino es adivinanza.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !! process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['github'], ['list']] : 'list',

    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8123',
        launchOptions: { executablePath },
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        // Web pública en móvil.
        { name: 'movil-360', use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 800 } } },
        // Backoffice en tablet.
        { name: 'tablet-768', use: { ...devices['Desktop Chrome'], viewport: { width: 768, height: 1024 } } },
        // Mínimo para edición cartográfica.
        { name: 'edicion-1024', use: { ...devices['Desktop Chrome'], viewport: { width: 1024, height: 768 } } },
        // Escritorio.
        { name: 'escritorio-1920', use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } } },
        // Kiosco: 1080p y 4K con relación de píxeles 1 y 2 (provisorio, G4).
        {
            name: 'kiosco-1080p',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 },
        },
        {
            name: 'kiosco-4k',
            use: { ...devices['Desktop Chrome'], viewport: { width: 3840, height: 2160 }, deviceScaleFactor: 2 },
        },
    ],

    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8123',
        url: 'http://127.0.0.1:8123/login',
        reuseExistingServer: ! process.env.CI,
        timeout: 60_000,
    },
})
