<?php

declare(strict_types=1);

/*
| Mensajes de autenticación.
|
| El ingreso de esta aplicación no usa `auth.failed`: `LoginController` responde
| siempre con el mismo texto —«Las credenciales no son correctas»— tanto si el
| correo no existe como si la contraseña está mal, porque distinguirlos permite
| averiguar qué correos tienen cuenta. Estos mensajes quedan traducidos igual,
| para que ningún camino del framework devuelva una clave técnica en pantalla.
*/

return [
    'failed' => 'Las credenciales no son correctas.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos. Probá de nuevo en :seconds segundos.',
];
