#!/usr/bin/env node
/**
 * Verificador de contraste del RDS — RNF-ACC-001, RF-CAT-003, CA-025.
 *
 * El contraste no se estima: se mide. El verde de marca `#75C932` y el naranja
 * `#F7911F` no se comportan igual sobre superficies oscuras, así que cada par
 * texto/superficie de los DOS temas se calcula y el build falla por debajo de AA.
 *
 * Verifica además una cosa que se rompe sola con el tiempo: que el bloque de la
 * elección explícita (`:root[data-theme='dark']`) y el de la preferencia del
 * dispositivo (`@media (prefers-color-scheme: dark)`) declaren exactamente los
 * mismos tokens. Si uno se actualiza y el otro no, el tema oscuro se comporta
 * distinto según cómo se llegó a él, y es el tipo de bug que nadie encuentra
 * mirando una pantalla.
 *
 * Uso: node scripts/rds-contraste.mjs [--verbose]
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const verbose = process.argv.includes('--verbose');

// Paleta clara del paquete original, y nuestras dos extensiones.
const LIGHT_TOKENS = 'resources/rds/css/tokens/colors.css';
const DARK_TOKENS = 'resources/css/rds-dark.css';
const A11Y_TOKENS = 'resources/css/rds-a11y.css';

// Umbrales WCAG 2.2 AA.
const AA_TEXT = 4.5; // 1.4.3 texto normal
const AA_UI = 3.0; // 1.4.11 componentes de interfaz y bordes

// ---------------------------------------------------------------------------
// Parseo de CSS: suficiente para tokens, sin traer una dependencia entera.
// ---------------------------------------------------------------------------

function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Extrae los bloques `@media ... { ... }` y devuelve [cssSinMedia, [{query, body}]]. */
function extractMediaBlocks(css) {
  const blocks = [];
  let out = '';
  let i = 0;

  while (i < css.length) {
    const at = css.indexOf('@media', i);
    if (at === -1) {
      out += css.slice(i);
      break;
    }

    out += css.slice(i, at);
    const braceStart = css.indexOf('{', at);
    if (braceStart === -1) break;

    let depth = 0;
    let j = braceStart;
    for (; j < css.length; j++) {
      if (css[j] === '{') depth++;
      else if (css[j] === '}') {
        depth--;
        if (depth === 0) break;
      }
    }

    blocks.push({
      query: css.slice(at + 6, braceStart).trim(),
      body: css.slice(braceStart + 1, j),
    });
    i = j + 1;
  }

  return [out, blocks];
}

/** Devuelve las declaraciones `--token: valor` de los bloques cuyo selector matchea. */
function declarationsFor(css, selectorPredicate) {
  const declarations = {};
  const blockRe = /([^{}]+)\{([^{}]*)\}/g;
  let match;

  while ((match = blockRe.exec(css)) !== null) {
    const selector = match[1].trim();
    if (!selectorPredicate(selector)) continue;

    for (const raw of match[2].split(';')) {
      const idx = raw.indexOf(':');
      if (idx === -1) continue;
      const name = raw.slice(0, idx).trim();
      if (!name.startsWith('--')) continue;
      declarations[name] = raw.slice(idx + 1).trim();
    }
  }

  return declarations;
}

/** Resuelve cadenas `var(--x)` hasta llegar a un color literal. */
function resolveVar(name, vars, seen = new Set()) {
  if (!(name in vars)) return null;
  if (seen.has(name)) throw new Error(`Ciclo de variables en ${name}`);
  seen.add(name);

  const value = vars[name];
  const varMatch = /^var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)$/.exec(value);
  if (varMatch) return resolveVar(varMatch[1], vars, seen);

  return value;
}

// ---------------------------------------------------------------------------
// Color y contraste
// ---------------------------------------------------------------------------

function parseColor(value) {
  if (!value) return null;
  const v = value.trim();

  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
  if (hex) {
    const h = hex[1];
    const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
    return [
      parseInt(full.slice(0, 2), 16),
      parseInt(full.slice(2, 4), 16),
      parseInt(full.slice(4, 6), 16),
    ];
  }

  const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i.exec(v);
  if (rgb) return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])];

  return null;
}

function relativeLuminance([r, g, b]) {
  const channel = (c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrastRatio(a, b) {
  const la = relativeLuminance(a);
  const lb = relativeLuminance(b);
  const [hi, lo] = la > lb ? [la, lb] : [lb, la];
  return (hi + 0.05) / (lo + 0.05);
}

// ---------------------------------------------------------------------------
// Pares a verificar
// ---------------------------------------------------------------------------

const TEXT_SURFACES = ['--rml-surface-page', '--rml-surface-card', '--rml-surface-sunken'];

const pairs = [];

for (const surface of TEXT_SURFACES) {
  for (const text of [
    '--rml-text-strong',
    '--rml-text-body',
    '--rml-text-secondary',
    '--rml-text-muted',
    '--rml-text-link',
    '--rml-text-link-hover',
  ]) {
    pairs.push({ fg: text, bg: surface, min: AA_TEXT, kind: 'texto' });
  }

  // 1.4.11 pide 3:1 para lo que *identifica* un control o su estado. Acá entran
  // el borde del botón secundario (`components.css`: `1.5px solid var(--rml-action)`),
  // el borde de los campos (`1.5px solid var(--rml-border)`) y el anillo de foco.
  // No entra el relleno de un botón: lo que importa ahí es el texto encima, y eso
  // se verifica aparte.
  for (const ui of ['--rml-action', '--rml-border', '--rml-focus-ring-color']) {
    pairs.push({ fg: ui, bg: surface, min: AA_UI, kind: 'interfaz' });
  }

  // Informativos: el texto deshabilitado está exento de 1.4.3, y estos tres se
  // usan como relleno o como separación decorativa, no como límite de control.
  for (const info of [
    '--rml-text-disabled',
    '--rml-accent',
    '--rml-border-strong',
    '--rml-border-subtle',
  ]) {
    pairs.push({ fg: info, bg: surface, min: 0, kind: 'informativo' });
  }
}

// Texto sobre rellenos de acción.
for (const bg of ['--rml-action', '--rml-action-hover', '--rml-action-active']) {
  pairs.push({ fg: '--rml-text-on-brand', bg, min: AA_TEXT, kind: 'texto' });
}

// Texto sobre rellenos de acento: el paquete usaba blanco y no llegaba a AA, así
// que la extensión agrega `--rml-text-on-accent`.
for (const bg of ['--rml-accent', '--rml-accent-hover', '--rml-accent-active']) {
  pairs.push({ fg: '--rml-text-on-accent', bg, min: AA_TEXT, kind: 'texto' });
}

// Superficie de marca decorativa: se conserva el verde exacto y se oscurece el
// texto, en lugar de oscurecer la banda.
pairs.push({ fg: '--rml-text-on-surface-brand', bg: '--rml-surface-brand', min: AA_TEXT, kind: 'texto' });

// Feedback: cada `fg` sobre su propio `bg`.
for (const state of ['success', 'warning', 'error', 'info']) {
  pairs.push({
    fg: `--rml-${state}-fg`,
    bg: `--rml-${state}-bg`,
    min: AA_TEXT,
    kind: 'texto',
  });
}

// ---------------------------------------------------------------------------
// Ejecución
// ---------------------------------------------------------------------------

const lightCss = stripComments(readFileSync(resolve(root, LIGHT_TOKENS), 'utf8'));
const darkCss = stripComments(readFileSync(resolve(root, DARK_TOKENS), 'utf8'));
const a11yCss = stripComments(readFileSync(resolve(root, A11Y_TOKENS), 'utf8'));

const [a11yWithoutMedia, a11yMediaBlocks] = extractMediaBlocks(a11yCss);

// El tema claro es la paleta del paquete más los overrides de accesibilidad.
const lightVars = {
  ...declarationsFor(lightCss, (s) => s === ':root'),
  ...declarationsFor(a11yWithoutMedia, (s) => s === ':root'),
};
if (Object.keys(lightVars).length === 0) {
  console.error(`No se encontraron tokens en ${LIGHT_TOKENS}. ¿Cambió la estructura del paquete?`);
  process.exit(1);
}

// El tema oscuro se declara en dos archivos, así que se acumulan los dos.
const [darkWithoutMedia, darkMediaBlocks] = extractMediaBlocks(darkCss);
const isDarkSelector = (s) => s.includes("[data-theme='dark']");

const darkExplicit = {
  ...declarationsFor(darkWithoutMedia, isDarkSelector),
  ...declarationsFor(a11yWithoutMedia, isDarkSelector),
};

const collectDarkMedia = (blocks) => {
  const block = blocks.find((b) => b.query.includes('prefers-color-scheme: dark'));

  return block ? declarationsFor(block.body, (s) => s.includes(':root')) : {};
};

const darkMedia = {
  ...collectDarkMedia(darkMediaBlocks),
  ...collectDarkMedia(a11yMediaBlocks),
};

const problems = [];
const rows = [];

// --- Paridad entre el bloque explícito y el del dispositivo ---------------
const explicitKeys = Object.keys(darkExplicit).sort();
const mediaKeys = Object.keys(darkMedia).sort();
const onlyExplicit = explicitKeys.filter((k) => !(k in darkMedia));
const onlyMedia = mediaKeys.filter((k) => !(k in darkExplicit));
// Se comparan los valores normalizando espacios: una diferencia de sangría o de
// salto de línea no es una diferencia de color, y reportarla sería ruido.
const normalizeValue = (v) => v.replace(/\s+/g, ' ').trim();
const differing = explicitKeys.filter(
  (k) => k in darkMedia && normalizeValue(darkExplicit[k]) !== normalizeValue(darkMedia[k])
);

if (explicitKeys.length === 0) {
  problems.push(`No se encontró el bloque :root[data-theme='dark'] en ${DARK_TOKENS}.`);
}
if (mediaKeys.length === 0) {
  problems.push(`No se encontró el bloque @media (prefers-color-scheme: dark) en ${DARK_TOKENS}.`);
}
for (const k of onlyExplicit) {
  problems.push(`\`${k}\` se declara sólo en la elección explícita: en un dispositivo en oscuro sin elección, ese token queda claro.`);
}
for (const k of onlyMedia) {
  problems.push(`\`${k}\` se declara SÓLO dentro del media query: viola la regla de que ningún color viva únicamente ahí.`);
}
for (const k of differing) {
  problems.push(`\`${k}\` difiere entre los dos bloques del tema oscuro (${darkExplicit[k]} vs ${darkMedia[k]}): el tema se vería distinto según cómo se llegó a él.`);
}

// --- Contraste en los dos temas -------------------------------------------
const themes = [
  { name: 'claro', vars: lightVars },
  { name: 'oscuro', vars: { ...lightVars, ...darkExplicit } },
];

for (const theme of themes) {
  for (const pair of pairs) {
    const fgRaw = resolveVar(pair.fg, theme.vars);
    const bgRaw = resolveVar(pair.bg, theme.vars);
    const fg = parseColor(fgRaw);
    const bg = parseColor(bgRaw);

    if (!fg || !bg) {
      problems.push(`No se pudo resolver ${pair.fg} sobre ${pair.bg} en tema ${theme.name} (fg=${fgRaw}, bg=${bgRaw}).`);
      continue;
    }

    const ratio = contrastRatio(fg, bg);
    const ok = pair.min === 0 || ratio >= pair.min;

    rows.push({
      theme: theme.name,
      fg: pair.fg,
      bg: pair.bg,
      ratio,
      min: pair.min,
      kind: pair.kind,
      ok,
    });

    if (!ok) {
      problems.push(
        `Tema ${theme.name}: ${pair.fg} (${fgRaw}) sobre ${pair.bg} (${bgRaw}) da ${ratio.toFixed(2)}:1, ` +
          `por debajo del mínimo ${pair.min}:1 para ${pair.kind}.`
      );
    }
  }
}

// --- Informe ---------------------------------------------------------------

if (verbose) {
  for (const theme of ['claro', 'oscuro']) {
    console.log(`\nTema ${theme}`);
    console.log('-'.repeat(78));
    for (const r of rows.filter((x) => x.theme === theme)) {
      const flag = r.min === 0 ? 'info' : r.ok ? ' ok ' : 'FAIL';
      console.log(
        `[${flag}] ${r.ratio.toFixed(2).padStart(6)}:1  min ${String(r.min).padStart(3)}  ${r.fg} sobre ${r.bg}`
      );
    }
  }
}

const checked = rows.filter((r) => r.min > 0).length;

if (problems.length > 0) {
  console.error(`\n✗ Contraste RDS: ${problems.length} problema(s).\n`);
  for (const p of problems) console.error(`  · ${p}`);
  console.error(`\nPares verificados: ${checked} (${rows.length} incluyendo informativos).`);
  console.error('Corregí los aliases semánticos en resources/css/rds-dark.css, no las escalas de marca.\n');
  process.exit(1);
}

const worst = rows
  .filter((r) => r.min > 0)
  .reduce((acc, r) => (r.ratio < acc.ratio ? r : acc), { ratio: Infinity });

console.log(`✓ Contraste RDS: ${checked} pares en AA, en los dos temas.`);
console.log(
  `  Par más ajustado: ${worst.fg} sobre ${worst.bg} (tema ${worst.theme}) = ${worst.ratio.toFixed(2)}:1, mínimo ${worst.min}:1.`
);
console.log('  Los dos bloques del tema oscuro declaran los mismos tokens.');
