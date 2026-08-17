/**
 * Tema claro/oscuro — tres estados, no dos.
 *
 * 1. `light`  → elección explícita del usuario: `data-theme="light"`.
 * 2. `dark`   → elección explícita del usuario: `data-theme="dark"`.
 * 3. `system` → sin atributo: manda `prefers-color-scheme` del dispositivo.
 *
 * El tercer estado es el que se olvida, y es el que hace que `color-scheme`
 * tenga que declararse por estado (ver `resources/css/rds-theme.css`): con
 * `data-theme` ausente el navegador decide, y con atributo explícito manda el
 * usuario incluso si contradice al sistema operativo.
 *
 * En el backoffice la fuente de verdad es `theme_preference` del usuario
 * (RF-CFG-004) y el backend la estampa antes de la primera pintura, así que acá
 * sólo se maneja el cambio en caliente. En la Web pública, sin sesión, la fuente
 * es `localStorage` (RF-THE-001).
 */

const STORAGE_KEY = 'rml-theme'
export const THEMES = ['light', 'dark', 'system']

/** Aplica el tema al documento. `system` quita el atributo, no lo pone en vacío. */
export function applyTheme(theme) {
  const root = document.documentElement

  if (theme === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', theme)
  }

  return theme
}

/** Lee la preferencia guardada; `system` si no hay nada o el valor es inválido. */
export function storedTheme() {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY)

    return THEMES.includes(value) ? value : 'system'
  } catch {
    // Almacenamiento bloqueado (modo privado, políticas del kiosco): no es un
    // error que deba romper la página, simplemente no hay preferencia guardada.
    return 'system'
  }
}

export function setTheme(theme) {
  const next = THEMES.includes(theme) ? theme : 'system'

  try {
    window.localStorage.setItem(STORAGE_KEY, next)
  } catch {
    // Ver arriba: sin almacenamiento el tema se aplica igual, sólo no persiste.
  }

  return applyTheme(next)
}

/** El tema que efectivamente se está viendo, resolviendo `system`. */
export function effectiveTheme() {
  const attribute = document.documentElement.getAttribute('data-theme')
  if (attribute === 'light' || attribute === 'dark') return attribute

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}
