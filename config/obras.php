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

];
