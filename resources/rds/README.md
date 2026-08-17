# Ramallo Design System (RDS)

Sistema de diseño del sitio de la **Municipalidad de Ramallo**, empaquetado para
reutilizar en otros proyectos conservando el mismo look and feel: tokens de
diseño, tipografía (incluida), paleta de color y clases de componentes.

Todo está basado en **CSS con variables (`--rml-*`)**, sin frameworks ni build:
funciona en HTML plano, y también en React, Vue, Astro, WordPress, etc.

## Contenido del paquete

```
ramallo-design-system/
├── css/
│   ├── rds.css          ← ÚNICO archivo a enlazar (importa todo lo demás)
│   ├── base.css         ← reset/base sobre elementos nativos
│   ├── components.css    ← clases del sistema (.rml-*)
│   └── tokens/          ← variables: fonts, colors, typography, spacing, radius, elevation, motion
├── fonts/               ← Poppins + Inter (.woff2) + licencia OFL
├── react/               ← primitivos React (referencia) + su README
├── demo/
│   └── index.html       ← abrilo en el navegador para ver todo el sistema
└── README.md            ← este archivo
```

## Cómo usarlo

1. Copiá las carpetas **`css/`** y **`fonts/`** a tu proyecto (respetando que
   `fonts/` quede como hermana de `css/`, porque `tokens/fonts.css` referencia
   `../../fonts/…`).
2. Enlazá el único punto de entrada:

   ```html
   <link rel="stylesheet" href="css/rds.css" />
   ```

   o desde tu propio CSS / bundler:

   ```css
   @import 'css/rds.css';
   ```

3. Usá las clases en tu markup:

   ```html
   <button class="rml-btn rml-btn-primary">Enviar</button>
   <span class="rml-badge rml-badge-success">Activo</span>

   <div class="rml-card" style="position:relative">
     <span class="rml-card-accent rml-accent-green"></span>
     <div class="rml-card-body">
       <h3>Título</h3>
       <p class="text-secondary">Descripción de la tarjeta.</p>
     </div>
   </div>

   <div class="rml-field">
     <label class="rml-label">Nombre</label>
     <input class="rml-input" placeholder="Tu nombre" />
   </div>
   ```

   La referencia visual completa está en **`demo/index.html`**.

## Tokens de diseño

Todo se controla con variables CSS bajo `:root`. Las principales familias:

| Familia | Prefijo | Ejemplos |
|---|---|---|
| Color (escalas) | `--rml-{green,cyan,orange,neutral}-{50…900}` | `--rml-green-500` (marca), `--rml-cyan-700` |
| Color (semántico) | `--rml-text-*`, `--rml-surface-*`, `--rml-border-*`, `--rml-action-*`, `--rml-accent-*` | `--rml-text-strong`, `--rml-surface-card` |
| Feedback | `--rml-{success,warning,error,info}[-bg/-fg]` | `--rml-error-bg` |
| Tipografía | `--rml-font-*`, `--rml-text-*`, `--rml-weight-*`, `--rml-tracking-*` | `--rml-text-h2`, `--rml-font-display` |
| Espaciado | `--rml-space-0…9` (escala de 4pt) | `--rml-space-5` |
| Radios | `--rml-radius-{sm,md,lg,xl,pill}` | `--rml-radius-lg` |
| Sombras | `--rml-shadow-{xs…xl}` (tinte verdoso) | `--rml-shadow-md` |
| Movimiento | `--rml-duration-*`, `--rml-ease-*` | `--rml-duration-normal` |

Podés usarlos directamente en tu propio CSS:

```css
.mi-caja {
  padding: var(--rml-space-5);
  border-radius: var(--rml-radius-lg);
  box-shadow: var(--rml-shadow-md);
  color: var(--rml-text-strong);
}
```

## Clases de componentes disponibles

`rml-container` · `rml-section` · `rml-grid` / `rml-grid-2/3/4` · `rml-btn` (+ `-primary/-accent/-secondary/-ghost/-danger`, `-sm/-lg/-full`) · `rml-iconbtn` · `rml-card` (+ `-body`, `-interactive`, `-accent` con `rml-accent-green/cyan/orange`) · `rml-badge` (+ colores y estados) · `rml-field` / `rml-label` / `rml-input` / `rml-select` / `rml-textarea` / `rml-hint` · `rml-alert` (+ `-info/-success/-warning/-error`) · `rml-table` / `rml-table-wrap` · `rml-section-head` / `rml-kicker` · `rml-prose` · `rml-crumbs` · `rml-acc-*` (acordeón) · `rml-tabs` / `rml-tab` · `rml-search` · `rml-toast-*` · `rml-media-ph` (placeholder de imagen con gradiente de marca). Además utilidades: `.flex`, `.items-center`, `.justify-between`, `.gap-2…5`, `.mt-2…7`, `.mb-2…5`, `.text-secondary`, `.text-muted`.

## Re-tematizar (usar otra marca)

Editá **`css/tokens/colors.css`**: cambiá los pasos `500` (valores de marca) y sus
escalas, o los aliases semánticos (`--rml-action`, `--rml-accent`, etc.). Todo el
resto del sistema se actualiza solo, porque los componentes referencian los tokens.
Para otra tipografía, reemplazá los `.woff2` de `fonts/` y ajustá
`css/tokens/fonts.css` + `--rml-font-display/body` en `typography.css`.

## Componentes React (opcional)

En `react/` están los primitivos (`Button`, `Card`, `Badge`, `Alert`,
`Breadcrumb`, `Accordion`, `Tabs`) como **referencia**. Son wrappers finos sobre
las clases `.rml-*`. Ver `react/README.md` (incluye cómo adaptar `next/link` si tu
proyecto no es Next.js). Si no usás React, ignorá esa carpeta.

## Fuentes y licencias

- **Poppins** e **Inter** se incluyen en `fonts/` bajo **SIL Open Font License 1.1**
  (`fonts/OFL.txt`), que permite su uso y redistribución embebida. Subsets `latin`
  y `latin-ext` (cubren el español completo).
- Los tokens, el CSS y los componentes son del proyecto de la Municipalidad de
  Ramallo; reutilizalos según lo acordado con el municipio.
