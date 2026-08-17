<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| Opciones propias de la aplicación
|---------------------------------------------------------------------------
|
| Acá van las que se resuelven en el ENTORNO, no en la interfaz. La
| configuración funcional —intervalos, límites, tema predeterminado— vive en
| `app_settings` y la edita el Administrador (RF-CFG-001).
|
| La distinción es la de RF-CFG-003: lo que es un secreto o depende del
| despliegue no se edita desde una pantalla.
|
*/

return [

    /*
    | Contraseña del primer Administrador, sólo para despliegues desatendidos.
    |
    | El uso normal es el prompt oculto de `obras:crear-admin`. Nunca se pasa
    | como argumento de línea de comandos: quedaría en el historial del shell y
    | en la lista de procesos, donde la ve cualquiera con acceso a la máquina.
    */
    'admin_initial_password' => env('ADMIN_INITIAL_PASSWORD'),

    /*
    | Encuadre del mapa, DERIVADO del recorte oficial del IGN (compuerta G3).
    |
    | Ninguno de estos números se eligió a ojo: todos salen del polígono del
    | partido y están documentados en `database/geo/MANIFIESTO.md`, con el hash
    | del archivo del que provienen. Si el recorte cambia, cambian acá y el test
    | de `tests/Feature/Geo/RecorteIgnTest.php` falla hasta que coincidan.
    |
    | Van en configuración de entorno y no en `app_settings` porque no son una
    | preferencia que el Administrador ajuste: son una propiedad del territorio.
    */
    'mapa' => [

        // El archivo del que se deriva todo lo demás, con su hash. El test
        // compara: si alguien reemplaza el recorte sin actualizar el manifiesto,
        // la suite se pone en rojo en lugar de mover el mapa en silencio.
        'dataset' => 'database/geo/ramallo-partido-20260817.geojson',
        'dataset_sha256' => 'c4bb3568b5035d70596c317465389daf4e5c1041aab45cc744d3555dedcb36b4',

        // Centroide del partido, comprobado DENTRO del polígono. En `[lon, lat]`,
        // la convención canónica del proyecto (ADR-003): a Leaflet se le pasa
        // invertido y eso ocurre en el adaptador, nunca a mano.
        'centro' => [-60.057506, -33.587186],

        // Respaldo para cuando no hay bounds a mano —una pantalla LIVE que
        // arranca, un enlace compartido sin recorte—. La carga normal hace
        // `fitBounds` sobre el bbox.
        'zoom' => 11,

        // `[lon_min, lat_min, lon_max, lat_max]`.
        'bbox' => [-60.313175, -33.827512, -59.808429, -33.350769],

        // Sesgo para la geocodificación (F3). Nominatim ordena los cuatro
        // valores distinto del bbox: izquierda, arriba, derecha, abajo.
        'viewbox_nominatim' => '-60.313175,-33.350769,-59.808429,-33.827512',
    ],

];
