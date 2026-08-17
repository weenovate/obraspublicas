#!/usr/bin/env node
/**
 * Lint de la frontera de ejes en el cliente (ADR-003).
 *
 * El proyecto es `[longitud, latitud]` de punta a punta. Leaflet usa `[lat, lng]`,
 * el orden contrario. En PHP esa frontera es `GeoJsonPhpGeoAdapter`, y un test de
 * arquitectura verifica que `Location\Coordinate` no se instancie en otro lado.
 * Este script cumple el mismo papel del lado del navegador.
 *
 * La razón de tener un lint y no una convención escrita: la inversión de ejes es
 * SILENCIOSA. No hay excepción, ni tipo, ni validación de esquema que la
 * atrape —una latitud de −60 es perfectamente válida, cae en el pasaje de Drake—.
 * Un mapa con los ejes cambiados dibuja Ramallo en el océano Índico sin quejarse,
 * y lo que se ve es «el marcador aparece en otro lado», que es lo que uno mira
 * último. La única defensa barata es que el orden se decida en un solo archivo.
 *
 * Uso: node scripts/ejes-lint.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, resolve, extname } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const SCAN = ['resources/js'];
const EXTENSIONS = new Set(['.js', '.vue']);

// El único módulo autorizado a conocer el orden de Leaflet.
const FRONTERA = 'resources/js/mapa/ejes.js';

// Los otros archivos de `mapa/` construyen el mapa y sus capas: usan la frontera
// para convertir, pero necesitan pasarle a Leaflet lo que ya viene convertido.
// Se les permite llamar a la API, no invertir a mano.
const MODULOS_DE_MAPA = 'resources/js/mapa/';

const REGLAS = [
    {
        id: 'latlng-crudo',
        test: /\bL\.latLng\s*\(|\bL\.latLngBounds\s*\(/,
        message: '`L.latLng`/`L.latLngBounds` fuera de `resources/js/mapa/` — usá `aLeaflet()`',
        permitidoEnModulosDeMapa: true,
    },
    {
        id: 'getlatlng',
        test: /\.getLatLng\s*\(|\.setLatLng\s*\(|\.getBounds\s*\(\)\.getCenter/,
        message: 'lectura/escritura directa de `LatLng` — pasá por `desdeLeaflet()`',
        permitidoEnModulosDeMapa: true,
    },
    {
        id: 'lat-lng-literal',
        // `{ lat: …, lng: … }` armado a mano: el orden queda fijado ahí.
        test: /\{\s*lat\s*:[^}]*\blng\s*:/,
        message: 'objeto `{lat, lng}` literal — la conversión va en `mapa/ejes.js`',
        permitidoEnModulosDeMapa: false,
    },
];

function archivos (directorio) {
    const encontrados = [];

    for (const entrada of readdirSync(directorio)) {
        const ruta = join(directorio, entrada);

        if (statSync(ruta).isDirectory()) {
            encontrados.push(...archivos(ruta));
        } else if (EXTENSIONS.has(extname(ruta))) {
            encontrados.push(ruta);
        }
    }

    return encontrados;
}

const infracciones = [];
let revisados = 0;

for (const base of SCAN) {
    for (const ruta of archivos(join(root, base))) {
        const relativa = relative(root, ruta).split('\\').join('/');

        if (relativa === FRONTERA) continue;

        revisados++;

        const enModulosDeMapa = relativa.startsWith(MODULOS_DE_MAPA);
        const lineas = readFileSync(ruta, 'utf8').split('\n');

        lineas.forEach((linea, indice) => {
            // Un comentario que menciona la regla no es una infracción de la regla.
            const codigo = linea.replace(/\/\/.*$|\/\*.*?\*\//g, '');

            for (const regla of REGLAS) {
                if (regla.permitidoEnModulosDeMapa && enModulosDeMapa) continue;
                if (! regla.test.test(codigo)) continue;

                infracciones.push(`${relativa}:${indice + 1}  ${regla.message}`);
            }
        });
    }
}

if (infracciones.length > 0) {
    console.error('✗ La frontera de ejes se cruzó fuera de `resources/js/mapa/ejes.js`:\n');
    infracciones.forEach((i) => console.error(`  ${i}`));
    console.error(
        '\n  El orden de los ejes se decide en UN solo lugar (ADR-003). Invertirlo por'
        + '\n  segunda vez no da ningún error: da un mapa que dibuja Ramallo en otro'
        + '\n  continente y nadie se entera hasta mirar.'
    );
    process.exit(1);
}

console.log(`✓ Frontera de ejes: ${revisados} archivos sin conversiones fuera de \`mapa/ejes.js\`.`);
