<script setup>
/**
 * Página de referencia del RDS — revisión visual de F0.
 *
 * Sirve para lo que un script de contraste no puede: mirar. El verificador dice
 * que un par cumple 4,96:1, pero no dice si el botón se ve bien, si el borde del
 * campo quedó demasiado duro o si los controles de Leaflet siguen legibles en
 * oscuro. Esta página junta todo en una pantalla para poder alternar los temas y
 * verlo.
 *
 * No se publica: la ruta existe sólo fuera de producción.
 */
import { ref, onMounted } from 'vue'
import RmlButton from '../Components/rds/RmlButton.vue'
import RmlCard from '../Components/rds/RmlCard.vue'
import RmlBadge from '../Components/rds/RmlBadge.vue'
import RmlAlert from '../Components/rds/RmlAlert.vue'
import RmlTabs from '../Components/rds/RmlTabs.vue'
import RmlAccordion from '../Components/rds/RmlAccordion.vue'
import RmlBreadcrumb from '../Components/rds/RmlBreadcrumb.vue'
import { setTheme, storedTheme, effectiveTheme, THEMES } from '../theme'

const theme = ref('system')
const effective = ref('light')
const switchOn = ref(true)

function choose (value) {
    theme.value = setTheme(value)
    effective.value = effectiveTheme()
}

onMounted(() => {
    theme.value = storedTheme()
    effective.value = effectiveTheme()
})

const tabs = [
    { id: 'datos', label: 'Datos' },
    { id: 'geometria', label: 'Geometría' },
    { id: 'fotos', label: 'Fotografías' },
]

const acordeon = [
    { id: 'estados', label: '¿Cómo se manejan las fechas de finalización?' },
    { id: 'cache', label: '¿Cuánto tarda una obra en aparecer en el mapa?' },
]

const categorias = [
    { nombre: 'Vialidad', token: 'var(--rml-action)' },
    { nombre: 'Hidráulica', token: 'var(--rml-info)' },
    { nombre: 'Espacio público', token: 'var(--rml-accent)' },
]
</script>

<template>
    <div class="rml-container" style="padding-block: var(--rml-space-6)">
        <RmlBreadcrumb
            :items="[
                { label: 'Inicio', href: '/' },
                { label: 'Interno' },
                { label: 'Referencia del RDS' },
            ]"
        />

        <h1 style="margin-block: var(--rml-space-5) var(--rml-space-3)">
            Referencia del Ramallo Design System
        </h1>

        <p class="text-secondary" style="margin-bottom: var(--rml-space-6)">
            Todos los componentes en un lugar, para revisarlos en los dos temas.
            El contraste se mide con <code>npm run rds:contraste</code>; esta página
            es para lo que hay que mirar.
        </p>

        <RmlCard title="Tema" style="margin-bottom: var(--rml-space-6)">
            <div class="flex items-center gap-3 flex-wrap">
                <RmlButton
                    v-for="option in THEMES"
                    :key="option"
                    :variant="theme === option ? 'primary' : 'secondary'"
                    @click="choose(option)"
                >
                    {{ option === 'system' ? 'Del dispositivo' : option === 'light' ? 'Claro' : 'Oscuro' }}
                </RmlButton>

                <span class="text-muted" data-testid="theme-state">
                    Elegido: <strong>{{ theme }}</strong> · efectivo:
                    <strong data-testid="effective-theme">{{ effective }}</strong>
                </span>
            </div>
            <p class="text-muted" style="margin-top: var(--rml-space-3)">
                «Del dispositivo» quita el atributo <code>data-theme</code>: ahí manda
                <code>prefers-color-scheme</code> y los controles nativos siguen a
                <code>color-scheme: light dark</code>.
            </p>
        </RmlCard>

        <RmlCard title="Botones" style="margin-bottom: var(--rml-space-6)">
            <div class="flex items-center gap-3 flex-wrap">
                <RmlButton variant="primary">Guardar obra</RmlButton>
                <RmlButton variant="secondary">Cancelar</RmlButton>
                <RmlButton variant="accent">Publicar</RmlButton>
                <RmlButton variant="ghost">Ver historial</RmlButton>
                <RmlButton variant="primary" disabled>Sin permisos</RmlButton>
                <RmlButton variant="primary" loading>Procesando…</RmlButton>
            </div>
            <p class="text-muted" style="margin-top: var(--rml-space-3)">
                El primario usa <code>--rml-action</code>, que la extensión de
                accesibilidad bajó a <code>green-700</code>: con el
                <code>green-600</code> original, el texto blanco daba 3,18:1 y no
                llegaba a AA. El naranja lleva texto oscuro por el mismo motivo.
            </p>
        </RmlCard>

        <RmlCard title="Insignias y estados" style="margin-bottom: var(--rml-space-6)">
            <div class="flex items-center gap-3 flex-wrap">
                <RmlBadge tone="neutral">Planificada</RmlBadge>
                <RmlBadge tone="info">En ejecución</RmlBadge>
                <RmlBadge tone="success">Finalizada</RmlBadge>
                <RmlBadge tone="warning">Suspendida</RmlBadge>
                <RmlBadge tone="error">Cancelada</RmlBadge>
            </div>
        </RmlCard>

        <RmlCard title="Alertas" style="margin-bottom: var(--rml-space-6)">
            <div class="flex flex-wrap gap-3" style="flex-direction: column">
                <RmlAlert tone="success" title="Obra publicada">
                    Ya está disponible en la Web pública y en las pantallas.
                </RmlAlert>
                <RmlAlert tone="warning" title="Longitud aproximada">
                    El cálculo cayó al método de respaldo. El valor es aproximado.
                </RmlAlert>
                <RmlAlert tone="error" title="La geometría se cruza consigo misma">
                    Corregí el trazado antes de guardar.
                </RmlAlert>
                <RmlAlert tone="info">
                    El trazado asistido no está disponible. Podés dibujar la línea a mano.
                </RmlAlert>
            </div>
        </RmlCard>

        <RmlCard title="Campos" style="margin-bottom: var(--rml-space-6)">
            <div class="rml-field">
                <label class="rml-label" for="ref-nombre">Nombre de la obra</label>
                <input id="ref-nombre" class="rml-input" type="text" placeholder="Repavimentación de Avenida San Martín">
                <span class="rml-hint">Como se va a ver en el mapa público.</span>
            </div>

            <div class="rml-field" style="margin-top: var(--rml-space-4)">
                <label class="rml-label" for="ref-estado">Estado</label>
                <select id="ref-estado" class="rml-select">
                    <option>Planificada</option>
                    <option>En ejecución</option>
                    <option>Finalizada</option>
                </select>
            </div>

            <div class="rml-field" style="margin-top: var(--rml-space-4)">
                <label class="rml-label" for="ref-fecha">Finalización prevista</label>
                <input id="ref-fecha" class="rml-input" type="date">
                <span class="rml-hint">
                    Control nativo: sigue a <code>color-scheme</code>, así que cambia con el tema.
                </span>
            </div>

            <div class="rml-field" style="margin-top: var(--rml-space-4)">
                <label class="rml-label" for="ref-obs">Observaciones</label>
                <textarea id="ref-obs" class="rml-textarea" rows="3"></textarea>
            </div>

            <label class="rml-switch" style="margin-top: var(--rml-space-4)">
                <input v-model="switchOn" type="checkbox">
                <span class="rml-switch-track"><span class="rml-switch-thumb"></span></span>
                <span>Visible en la Web pública</span>
            </label>
        </RmlCard>

        <RmlCard title="Tabla" style="margin-bottom: var(--rml-space-6)">
            <table class="rml-table">
                <thead>
                    <tr>
                        <th class="rml-th-sortable" aria-sort="ascending">Código</th>
                        <th class="rml-th-sortable">Obra</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>OBR-2026-0001</code></td>
                        <td>Repavimentación de Avenida San Martín</td>
                        <td><RmlBadge tone="info">En ejecución</RmlBadge></td>
                    </tr>
                    <tr>
                        <td><code>OBR-2026-0002</code></td>
                        <td>Ampliación de red cloacal — Barrio Norte</td>
                        <td><RmlBadge tone="success">Finalizada</RmlBadge></td>
                    </tr>
                </tbody>
            </table>

            <nav class="rml-pagination" aria-label="Paginación" style="margin-top: var(--rml-space-4)">
                <a class="rml-page-link" href="#" aria-disabled="true">Anterior</a>
                <a class="rml-page-link" href="#" aria-current="page">1</a>
                <a class="rml-page-link" href="#">2</a>
                <a class="rml-page-link" href="#">Siguiente</a>
            </nav>
        </RmlCard>

        <RmlCard title="Pestañas y acordeón" style="margin-bottom: var(--rml-space-6)">
            <RmlTabs :tabs="tabs">
                <template #datos>Datos generales de la obra.</template>
                <template #geometria>Punto, línea o polígono según la subcategoría.</template>
                <template #fotos>Hasta diez fotografías, opcionales.</template>
            </RmlTabs>

            <div style="margin-top: var(--rml-space-5)">
                <RmlAccordion :items="acordeon" single>
                    <template #estados>
                        Hay dos columnas: la prevista nunca se sobrescribe y la real sólo
                        existe si el estado es finalizador.
                    </template>
                    <template #cache>
                        Hasta 30 segundos en las pantallas y hasta 60 en la Web, con
                        invalidación sincrónica al confirmar.
                    </template>
                </RmlAccordion>
            </div>
        </RmlCard>

        <RmlCard title="Estado vacío, esqueletos y leyenda" style="margin-bottom: var(--rml-space-6)">
            <div class="rml-empty">
                <strong class="rml-empty-title">No hay obras que coincidan</strong>
                <span>Probá quitar algún filtro.</span>
            </div>

            <div class="flex gap-3" style="flex-direction: column; margin-top: var(--rml-space-5)">
                <div class="rml-skeleton" style="height: 1rem; width: 70%"></div>
                <div class="rml-skeleton" style="height: 1rem; width: 45%"></div>
            </div>

            <div class="rml-legend" style="margin-top: var(--rml-space-5)">
                <div v-for="categoria in categorias" :key="categoria.nombre" class="rml-legend-item">
                    <span class="rml-legend-swatch" :style="{ background: categoria.token }"></span>
                    <span>{{ categoria.nombre }}</span>
                </div>
            </div>
        </RmlCard>

        <RmlCard title="Superficie de marca">
            <div class="rml-surface-brand" style="padding: var(--rml-space-5); border-radius: var(--rml-radius-md)">
                <strong>El verde de marca se conserva exacto.</strong>
                <p style="margin-top: var(--rml-space-2)">
                    En lugar de oscurecer la banda para que el texto blanco cumpliera AA,
                    el texto encima es oscuro: 8,52:1 sobre el mismo
                    <code>green-500</code>.
                </p>
            </div>
        </RmlCard>
    </div>
</template>
