#!/usr/bin/env node
/**
 * Lint de disciplina de tokens del RDS.
 *
 * Regla del Definition of Done: ningún color, sombra ni familia tipográfica
 * literal en el código de la aplicación. Todo sale de tokens `--rml-*`. Sin un
 * lint que lo verifique, la regla se cumple las primeras semanas y después se
 * erosiona con un `#fff` apurado que nadie vuelve a mirar.
 *
 * Qué se revisa: nuestras extensiones CSS y el código de la aplicación
 * (Vue, Blade, JS). El paquete original en `resources/rds/` queda fuera: es
 * proveedor, no se edita, y ahí los literales son precisamente los tokens.
 *
 * Excepción única: una línea que DECLARA un token (`--rml-algo: valor`) puede
 * llevar un literal. Es el lugar donde los colores tienen que vivir.
 *
 * Uso: node scripts/rds-lint.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, resolve, extname } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const SCAN = ['resources/css', 'resources/js', 'resources/views'];
const EXTENSIONS = new Set(['.css', '.vue', '.js', '.ts', '.php']);

// El paquete del RDS es proveedor: no se edita y sus literales son los tokens.
const EXCLUDED = ['resources/rds'];

const NAMED_COLORS = [
  'white', 'black', 'red', 'green', 'blue', 'yellow', 'orange', 'purple',
  'gray', 'grey', 'silver', 'navy', 'teal', 'olive', 'maroon', 'lime', 'aqua',
  'fuchsia',
];

const rules = [
  {
    id: 'color-hex',
    // Un hexadecimal suelto. `#` seguido de 3, 4, 6 u 8 dígitos hex.
    test: /#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i,
    message: 'color hexadecimal literal — usá un token `--rml-*`',
  },
  {
    id: 'color-function',
    test: /\b(?:rgba?|hsla?|hwb|lab|lch|oklch|oklab)\s*\(/i,
    message: 'color literal por función — usá un token `--rml-*`',
  },
  {
    id: 'color-named',
    test: new RegExp(
      `(?:^|[\\s:,])(?:color|background|background-color|border-color|fill|stroke|outline-color)\\s*:\\s*(?:${NAMED_COLORS.join('|')})\\b`,
      'i'
    ),
    message: 'color con nombre — usá un token `--rml-*`',
  },
  {
    id: 'font-family',
    test: /font-family\s*:(?!\s*var\()/i,
    message: 'familia tipográfica literal — usá `--rml-font-body` o `--rml-font-display`',
  },
  {
    id: 'spacing-px',
    // Espaciado en píxeles. Se permiten hasta 2px: son grosores de borde y de
    // anillo de foco, no espaciado, y el sistema no tiene tokens para eso.
    test: /\b(?:padding|margin|gap|row-gap|column-gap)(?:-(?:top|right|bottom|left|inline|block))?\s*:\s*[^;]*?\b(?![012](?:\.\d+)?px)\d+(?:\.\d+)?px/i,
    message: 'espaciado en píxeles — usá los tokens `--rml-space-*` o unidades relativas',
  },
];

/** Una línea que declara un token puede llevar literales: es su lugar. */
function isTokenDeclaration(line) {
  return /^\s*--rml-[\w-]+\s*:/.test(line);
}

/** Quita comentarios de línea y de bloque de una sola línea, para no marcar prosa. */
function stripInlineComments(line) {
  return line.replace(/\/\*.*?\*\//g, '').replace(/\/\/.*$/, '');
}

function collectFiles(dir, acc = []) {
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return acc;
  }

  for (const entry of entries) {
    const full = join(dir, entry);
    const rel = relative(root, full);

    if (EXCLUDED.some((ex) => rel === ex || rel.startsWith(`${ex}/`))) continue;

    if (statSync(full).isDirectory()) {
      collectFiles(full, acc);
    } else if (EXTENSIONS.has(extname(full))) {
      acc.push(full);
    }
  }

  return acc;
}

const files = SCAN.flatMap((dir) => collectFiles(resolve(root, dir)));
const findings = [];
let inBlockComment = false;

for (const file of files) {
  const rel = relative(root, file);
  const lines = readFileSync(file, 'utf8').split('\n');
  inBlockComment = false;
  // Una declaración de token puede ocupar varias líneas (el anillo de foco son
  // dos sombras). Mientras no aparezca el `;` que la cierra, seguimos dentro de
  // la declaración y sus literales están en su lugar.
  let inTokenDeclaration = false;

  lines.forEach((rawLine, index) => {
    // Seguimiento simple de comentarios de bloque multilínea: los archivos de
    // este proyecto tienen encabezados largos y no hay que lintear prosa.
    let line = rawLine;

    if (inBlockComment) {
      const end = line.indexOf('*/');
      if (end === -1) return;
      line = line.slice(end + 2);
      inBlockComment = false;
    }

    const open = line.lastIndexOf('/*');
    const close = line.lastIndexOf('*/');
    if (open !== -1 && close < open) {
      inBlockComment = true;
      line = line.slice(0, open);
    }

    line = stripInlineComments(line);

    if (line.trim() === '') return;

    if (inTokenDeclaration) {
      if (line.includes(';')) inTokenDeclaration = false;

      return;
    }

    if (isTokenDeclaration(line)) {
      if (! line.includes(';')) inTokenDeclaration = true;

      return;
    }

    for (const rule of rules) {
      if (rule.test.test(line)) {
        findings.push({
          file: rel,
          line: index + 1,
          rule: rule.id,
          message: rule.message,
          snippet: line.trim().slice(0, 100),
        });
      }
    }
  });
}

if (findings.length > 0) {
  console.error(`\n✗ Lint del RDS: ${findings.length} literal(es) fuera de tokens.\n`);
  for (const f of findings) {
    console.error(`  ${f.file}:${f.line}  [${f.rule}] ${f.message}`);
    console.error(`      ${f.snippet}`);
  }
  console.error(`\nArchivos revisados: ${files.length}.`);
  console.error('Los literales van en la declaración de un token `--rml-*`, no en el uso.\n');
  process.exit(1);
}

console.log(`✓ Lint del RDS: ${files.length} archivos sin colores, sombras ni tipografías literales.`);
