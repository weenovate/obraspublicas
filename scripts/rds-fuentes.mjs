#!/usr/bin/env node
/**
 * Verificador de tipografías del RDS.
 *
 * Un `@font-face` que apunta a un 404 no rompe nada visiblemente: el navegador
 * degrada en silencio a la fuente del sistema y nadie lo nota hasta que alguien
 * mira una captura de producción y algo se ve raro. Este script convierte eso en
 * un fallo de build.
 *
 * Verifica, después de `npm run build`:
 *
 *   1. Que cada `@font-face` de `fonts.css` referencie un archivo que existe.
 *   2. Que cada uno de esos archivos esté en el manifiesto de Vite.
 *   3. Que el archivo emitido en `public/build` exista y no esté vacío.
 *   4. Que los subsets `latin` precargados por el layout estén en el manifiesto:
 *      `Vite::asset()` lanza excepción si falta uno, y eso tira la página entera.
 *
 * Uso: node scripts/rds-fuentes.mjs
 */

import { existsSync, readFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const FONTS_CSS = 'resources/rds/css/tokens/fonts.css';
const MANIFEST = 'public/build/manifest.json';

// Los que el layout precarga con `Vite::asset()`. Si falta uno, la página no
// renderiza: es un error 500, no una fuente que se ve distinta.
const PRELOADED = [
  'resources/rds/fonts/inter-400-latin.woff2',
  'resources/rds/fonts/inter-600-latin.woff2',
  'resources/rds/fonts/poppins-600-latin.woff2',
];

const problems = [];

const cssPath = resolve(root, FONTS_CSS);
if (! existsSync(cssPath)) {
  console.error(`No existe ${FONTS_CSS}. ¿Se copió el paquete del RDS a resources/rds?`);
  process.exit(1);
}

const css = readFileSync(cssPath, 'utf8');
const faceCount = (css.match(/@font-face/g) ?? []).length;
const referenced = [...css.matchAll(/url\('\.\.\/\.\.\/fonts\/([^']+)'\)/g)].map((m) => m[1]);
const unique = [...new Set(referenced)];

if (referenced.length !== faceCount) {
  problems.push(
    `Hay ${faceCount} bloques @font-face pero ${referenced.length} referencias a archivos: ` +
      'alguna declaración quedó sin `src` o con una ruta con otra forma.'
  );
}

// 1. Los archivos referenciados existen en el paquete.
for (const file of unique) {
  const onDisk = resolve(root, 'resources/rds/fonts', file);
  if (! existsSync(onDisk)) {
    problems.push(`\`${file}\` está declarado en fonts.css pero no existe en resources/rds/fonts.`);
  } else if (statSync(onDisk).size === 0) {
    problems.push(`\`${file}\` existe pero está vacío.`);
  }
}

// 2 y 3. Están en el manifiesto y el archivo emitido existe.
const manifestPath = resolve(root, MANIFEST);
if (! existsSync(manifestPath)) {
  problems.push(`No existe ${MANIFEST}. Corré \`npm run build\` antes de este verificador.`);
} else {
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

  for (const file of unique) {
    const key = `resources/rds/fonts/${file}`;
    const entry = manifest[key];

    if (! entry) {
      problems.push(`\`${key}\` no quedó en el manifiesto de Vite: la URL de esa fuente estaría rota.`);

      continue;
    }

    // `file` del manifiesto es relativo al directorio de build, no a public/.
    const emitted = resolve(root, 'public/build', entry.file);
    if (! existsSync(emitted)) {
      problems.push(`El manifiesto apunta a \`${entry.file}\`, que no existe en public/.`);
    } else if (statSync(emitted).size === 0) {
      problems.push(`El archivo emitido \`${entry.file}\` está vacío.`);
    }
  }

  // 4. Los precargados por el layout.
  for (const key of PRELOADED) {
    if (! manifest[key]) {
      problems.push(
        `\`${key}\` lo precarga app.blade.php con Vite::asset() y no está en el manifiesto: ` +
          'la página devolvería 500.'
      );
    }
  }
}

if (problems.length > 0) {
  console.error(`\n✗ Tipografías: ${problems.length} problema(s).\n`);
  for (const p of problems) console.error(`  · ${p}`);
  console.error('');
  process.exit(1);
}

console.log(
  `✓ Tipografías: ${faceCount} @font-face, ${unique.length} archivos, todos emitidos y en el manifiesto.`
);
console.log(`  Precargados por el layout: ${PRELOADED.length}, presentes.`);

// Vite deduplica por contenido, y en este paquete eso es esperable: los cuatro
// pesos de Inter son el mismo binario porque Inter es una fuente VARIABLE (tiene
// tablas `fvar`/`gvar`/`STAT`), así que un solo archivo sirve 400, 500, 600 y 700
// y el navegador lo descarga una sola vez. Poppins sí trae un archivo por peso.
// Se informa el recuento para que una futura deduplicación inesperada se note.
const emittedUnique = new Set(
  Object.entries(JSON.parse(readFileSync(manifestPath, 'utf8')))
    .filter(([key]) => key.startsWith('resources/rds/fonts/'))
    .map(([, entry]) => entry.file)
);
console.log(
  `  Binarios emitidos: ${emittedUnique.size} para ${unique.length} declaraciones ` +
    '(Inter es variable: un archivo por subset sirve los cuatro pesos).'
);
