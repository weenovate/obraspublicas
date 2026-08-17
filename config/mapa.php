<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Teselas
    |---------------------------------------------------------------------------
    |
    | Categoría (c) de la política de secretos: el token PUEDE ser público, pero
    | sólo si cumple las seis condiciones de docs/ARQUITECTURA.md. El servidor
    | público de OpenStreetMap no es una opción para producción (spec 11.2, T1).
    |
    | El rango de zoom está acotado a propósito: el costo de teselas lo dominan
    | las pantallas LIVE, que piden mapa de forma continua durante toda la
    | jornada de exhibición.
    |
    */

    'tiles' => [
        'provider' => env('TILES_PROVIDER'),
        'url_template' => env('TILES_URL_TEMPLATE'),
        'attribution' => env('TILES_ATTRIBUTION'),
        'public_token' => env('TILES_PUBLIC_TOKEN'),
        'min_zoom' => (int) env('TILES_MIN_ZOOM', 11),
        'max_zoom' => (int) env('TILES_MAX_ZOOM', 18),
    ],

    /*
    |---------------------------------------------------------------------------
    | Presupuesto de propagación (RF-BO-010)
    |---------------------------------------------------------------------------
    |
    | El caché es la red de seguridad, no el mecanismo de propagación: la
    | invalidación es sincrónica al commit y el peor caso queda acotado por el
    | intervalo de sondeo del cliente. TTL 30 s + sondeo 30 s da 30 s de
    | obsolescencia efectiva tras una escritura, no 60.
    |
    */

    'cache_ttl_seconds' => (int) env('PUBLIC_CACHE_TTL_SECONDS', 30),
    'public_poll_seconds' => (int) env('PUBLIC_POLL_INTERVAL_SECONDS', 30),
    'live_poll_seconds' => (int) env('LIVE_POLL_INTERVAL_SECONDS', 15),

    /*
    |---------------------------------------------------------------------------
    | Recorrido automático de LIVE (RF-LIV-009)
    |---------------------------------------------------------------------------
    */

    'live_tour_seconds' => (int) env('LIVE_TOUR_INTERVAL_SECONDS', 12),
    'live_tour_min_seconds' => 5,
    'live_tour_max_seconds' => 120,
    'live_pause_seconds' => 60,

];
