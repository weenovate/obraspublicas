# Sistema de diseño — Ramallo Design System (RDS)

## Procedencia y autorización

| Dato | Valor |
|---|---|
| Paquete recibido | `ramallodesignsystem.zip` |
| SHA-256 del zip | `290984154a61f797b884bda79227859ce1693e0a9f08ea43ca7f488b6748e2f1` |
| Ubicación en el repositorio | `resources/rds/` (intacto) |
| Extensiones propias | `resources/css/rds-*.css` |
| Tipografías | Poppins e Inter, **SIL OFL 1.1**, `resources/rds/fonts/OFL.txt` conservado |

### Compuerta G5 — pendiente

El README del paquete dice que los tokens, el CSS y los componentes son del
proyecto de la Municipalidad y que se reutilicen «según lo acordado con el
municipio». Eso **no** es una autorización: es una remisión a un acuerdo.

**Falta la autorización escrita** de la Municipalidad para usar el RDS en esta
plataforma, con quién la otorga, fecha y alcance —incluido si cubre el uso de la
identidad en la **Web pública**, que es lo que va a ver la ciudadanía—. Es de
esfuerzo bajo, pero debe estar cerrada **antes de habilitar la URL pública**.

| Campo | A completar |
|---|---|
| Otorgada por | |
| Cargo | |
| Fecha | |
| Alcance | |
| ¿Cubre la Web pública? | |

Las tipografías van por otra vía y no dependen de G5: la SIL OFL 1.1 permite la
redistribución embebida.

---

## Qué trae el paquete

753 líneas de CSS, inspeccionadas completas. Es sólido, y su mecanismo de
re-tematización por aliases semánticos es justo lo que este proyecto necesitaba.

| Elemento | Contenido |
|---|---|
| Tokens | `--rml-*`: escalas green/cyan/orange/neutral 50–900, aliases semánticos, tipografía, espaciado de 4 pt, radios, sombras con tinte verdoso, movimiento |
| Base | Reset y `:focus-visible` con anillo de foco |
| Componentes | `.rml-btn`, `.rml-card`, `.rml-badge`, `.rml-field`/`input`/`select`/`textarea`/`hint`, `.rml-alert`, `.rml-table`, `.rml-tabs`, `.rml-acc-*`, `.rml-crumbs`, `.rml-search`, `.rml-toast-*`, `.rml-grid`, `.rml-container` |
| Tipografía | Poppins (display) e Inter (cuerpo), self-hosted, subsets `latin` y `latin-ext`, `font-display: swap` |
| React | Siete primitivos como referencia — **no se usan**: el proyecto es Vue |

### Nota sobre las tipografías

Los cuatro pesos de Inter son **el mismo binario**, y eso es correcto: Inter es una
fuente **variable** (tiene tablas `fvar`, `gvar`, `HVAR`, `MVAR`, `STAT`, `avar`),
así que un archivo por subset sirve 400, 500, 600 y 700. Vite lo deduplica y el
navegador lo descarga una sola vez. Poppins sí trae un archivo por peso.

Se verificó parseando la cabecera WOFF2 de los dos archivos, no por inspección
visual: son 14 declaraciones `@font-face` sobre 8 binarios distintos.

**Mejora posible, no urgente:** declarar Inter con un solo `@font-face` y
`font-weight: 100 900` en lugar de cuatro bloques. Funciona igual como está.

---

## Hueco 1: el paquete no traía tema oscuro

Verificado: **cero** ocurrencias de `prefers-color-scheme`, `data-theme` o
`color-scheme`. Todos los tokens viven en un `:root` con valores claros. El spec
exige los dos temas (RF-THE-001/002, RF-CFG-004/005, RF-LIV-012, CA-025).

**Construido en `resources/css/rds-dark.css`**, con estas reglas:

1. Se redefinen **sólo los aliases semánticos**. Las escalas de marca no se tocan:
   los aliases se recomponen hacia otros pasos de las mismas escalas, así la
   identidad se conserva y el paquete queda extendido, no modificado.
2. Dos selectores para cubrir los tres estados: `:root[data-theme='dark']` para la
   elección explícita y `@media (prefers-color-scheme: dark)` con guarda
   `:root:not([data-theme='light'])` para la del dispositivo.
3. Ningún color se define únicamente dentro de un media query.
4. El contraste se **mide**, no se estima.

**Única adición a las escalas:** un paso `--rml-neutral-950: #0D0F0C`, porque en
oscuro la superficie hundida tiene que leerse por debajo de la página y el paquete
no trae un escalón así. Se agrega en nuestro archivo, no en el del paquete.

---

## Hueco 2: faltaban componentes de aplicación

El RDS cubre el vocabulario de un sitio institucional, no de una aplicación de
gestión con mapa. En `resources/css/rds-app.css`, todo con tokens `--rml-*`:

modal con foco atrapado (imprescindible para el tipeo exacto de RF-DEL-004) ·
paginación y encabezados ordenables · switch, checkbox y radio · estado vacío
(RF-LIV-011) · skeletons · pila de toasts (el paquete trae las clases, no la
lógica) · layout de mapa con panel que no tapa la geometría al encuadrar
(RF-WEB-011) · leyenda de capas · escala tipográfica del kiosco.

En `resources/css/rds-leaflet.css`, el puente con Leaflet: sin él, en oscuro los
controles del mapa quedan blancos y los popups ilegibles, incumpliendo RF-THE-002.

---

## Hueco 3, el que no estaba previsto: el tema claro no cumplía AA

**Este es un hallazgo para la Municipalidad.** El RDS tal como fue entregado falla
WCAG 2.2 AA en varios pares del tema claro. No es una opinión: son mediciones de
`npm run rds:contraste`, que compara cada par y falla el build por debajo del
mínimo.

| Par | Antes | Mínimo | Después | Dónde afectaba |
|---|---|---|---|---|
| Texto blanco sobre `action` (`green-600` `#5EA128`) | **3,18:1** | 4,5:1 | 4,96:1 con `green-700` | Todo botón primario |
| Texto sobre `surface-brand` (`green-500` `#75C932`) | **2,07:1** | 4,5:1 | 8,52:1 con texto oscuro | Bandas y superficies de marca |
| `border` de campos (`neutral-300` `#C2C9BD`) sobre tarjeta | **1,76:1** | 3:1 | 4,65:1 con `neutral-500` | Borde de todo campo de formulario |
| Anillo de foco (`cyan-200` `#A1E1F7`) sobre tarjeta | **1,30:1** | 3:1 | 4,92:1 con `cyan-800` | Navegación por teclado |
| `text-muted` (`neutral-500`) sobre página / hundido | **4,39 / 4,08:1** | 4,5:1 | 6,78 / 6,30:1 con `neutral-600` | Metadatos y leyendas |
| `action` como borde sobre hundido | **2,79:1** | 3:1 | 4,35:1 | Borde del botón secundario |
| Texto oscuro sobre `accent-active` (`orange-700`) | **3,46:1** | 4,5:1 | se aclara a `orange-300` | Botón naranja presionado |
| Texto oscuro sobre `surface-brand` oscuro (`green-700`) | **3,55:1** | 4,5:1 | 5,54:1 con `green-600` | Bandas de marca en tema oscuro |

El botón primario y el borde de los campos son los dos más serios: afectan cada
pantalla del backoffice. El anillo de foco es el que usa quien navega por teclado.

### Cómo se corrigió, y qué queda a decisión de la Municipalidad

En `resources/css/rds-a11y.css`, con tres criterios:

1. **Ninguna escala de marca se toca.** El verde sigue siendo el verde de Ramallo,
   un paso más oscuro donde hace falta.
2. **El verde de marca exacto se conserva donde es decorativo.** En lugar de
   oscurecer la banda, el texto encima pasa a oscuro.
3. **Tres tokens nuevos**, porque el paquete usa un solo `--rml-text-on-brand` para
   el botón verde y el naranja, y no existe un color de texto que cumpla AA sobre
   los dos a la vez:

| Token nuevo | Para qué |
|---|---|
| `--rml-text-on-accent` | Texto sobre rellenos naranjas |
| `--rml-text-on-surface-brand` | Texto sobre la superficie de marca decorativa |
| `--rml-focus-ring-color` | El trazo sólido del anillo de foco, el que debe dar 3:1 |

**Decisión pendiente.** El ajuste cambia levemente el aspecto: el botón primario
queda un paso más oscuro, el borde de los campos más marcado, el anillo de foco
más definido. La Municipalidad decide con estos números si acepta el ajuste o
prefiere revisar la paleta de origen. Lo que no es opcional es cumplir RNF-ACC-001.

---

## Componentes Vue

Los siete primitivos del paquete se portaron a Vue 3 SFC como **wrappers finos**
sobre las mismas clases `.rml-*`. No se instaló React.

| Componente | Qué agrega sobre las clases del paquete |
|---|---|
| `RmlButton` | Variantes, estado de carga con `aria-busy`, y `<a>` o `<button>` según haya `href` |
| `RmlCard` | Encabezado y pie opcionales |
| `RmlBadge` | Tonos semánticos |
| `RmlAlert` | `role="alert"` para errores y `status` para el resto |
| `RmlTabs` | Patrón ARIA completo: `tablist`, `aria-selected`, flechas, Home y End |
| `RmlAccordion` | `aria-expanded` y modo de un solo panel abierto |
| `RmlBreadcrumb` | El último elemento no es enlace y lleva `aria-current="page"` |

**El estilo vive en el CSS del RDS**; el componente sólo compone clases y
accesibilidad. Es lo que permite absorber una actualización del paquete sin
reescribir componentes.

---

## Verificadores

Los tres fallan el build. Corren en CI y se pueden correr a mano.

```bash
npm run rds:lint       # ningún color, sombra ni tipografía literal fuera de un token
npm run rds:contraste  # 76 pares en AA, en los dos temas
npm run build          # compila
npm run rds:fuentes    # 14 @font-face emitidos, en el manifiesto y sin URLs rotas
```

`rds:contraste` verifica además una cosa que se rompe sola con el tiempo: que el
bloque de la elección explícita y el del media query declaren **exactamente los
mismos tokens**. Si uno se actualiza y el otro no, el tema oscuro se comporta
distinto según cómo se llegó a él, y es el tipo de bug que nadie encuentra mirando
una pantalla.

`rds:fuentes` incluye los tres subsets que el layout precarga con `Vite::asset()`:
si falta uno, no se degrada la fuente, la página devuelve 500.

---

## Revisión visual

`/referencia-rds` junta todos los componentes en una pantalla, con los tres
estados de tema. Sirve para lo que un script no puede: mirar. El verificador dice
que un par cumple 4,96:1, pero no dice si el botón se ve bien ni si el borde del
campo quedó demasiado duro.

La ruta **no existe en producción**.

Checklist de la revisión: botones en las cuatro variantes y en los estados
deshabilitado y cargando · campos, incluido el control nativo de fecha, que sigue a
`color-scheme` · tabla, paginación y encabezados ordenables · pestañas y acordeón
por teclado · alertas en los cuatro tonos · estado vacío y skeletons · controles de
Leaflet · banda de marca. Todo en claro, en oscuro y en «del dispositivo».

---

## Cómo absorber una actualización del paquete

1. Reemplazar `resources/rds/` completo y registrar el hash nuevo acá.
2. Correr `npm run rds:contraste`. Si aparecen pares nuevos por debajo de AA, se
   corrigen en `rds-a11y.css`, nunca en el paquete.
3. Correr `npm run rds:fuentes` tras `npm run build`.
4. Revisar `/referencia-rds` en los dos temas.
5. Si el paquete incorpora su propio tema oscuro, comparar con `rds-dark.css` y
   quedarse con el del paquete donde coincida, dejando en la extensión sólo lo que
   siga faltando.

Nunca se editan archivos dentro de `resources/rds/`. Si algo del paquete necesita
cambiar, se sobreescribe desde `resources/css/` y se anota acá por qué.
