<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| Inertia
|---------------------------------------------------------------------------
|
| Este archivo existe por UN cambio de Inertia 3: la ruta por omisión de las
| páginas pasó de `resources/js/Pages` a `resources/js/pages`, en minúscula.
|
| El proyecto usa `Pages` con mayúscula desde F0. En macOS —donde se desarrolla—
| el sistema de archivos no distingue mayúsculas y la diferencia no se nota; en
| Linux y en la CI sí, y ahí el buscador de componentes no encuentra nada. El
| síntoma es engañoso: la aplicación funciona igual, porque el cliente resuelve
| las páginas por su cuenta con `import.meta.glob`, y lo único que falla son las
| aserciones de test que verifican que el componente existe.
|
| Se declara la ruta real en lugar de renombrar el directorio: un renombre que
| sólo cambia mayúsculas es exactamente el que git y macOS manejan mal.
|
| Sólo se sobrescribe el bloque `pages`. Laravel fusiona la configuración de
| paquetes por clave de primer nivel, así que hay que repetirlo COMPLETO —si se
| declarara sólo `paths`, se perderían `extensions` y `ensure_pages_exist`—.
| El resto (`ssr`, `testing`) sigue viniendo del paquete.
|
*/

return [

    'pages' => [

        // Verificación en tiempo de render. Queda apagada como en el paquete: la
        // que interesa es la de los tests, que corre sin costo en producción.
        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
