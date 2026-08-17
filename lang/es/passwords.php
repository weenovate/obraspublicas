<?php

declare(strict_types=1);

/*
| La versión 1 no tiene recuperación de contraseña por correo (RF-AUT-004): la
| repone un Administrador. Estos textos existen para que, si alguna vez se
| habilita, no aparezcan claves técnicas en pantalla.
*/

return [
    'reset' => 'Tu contraseña quedó restablecida.',
    'sent' => 'Te enviamos el enlace para restablecer la contraseña.',
    'throttled' => 'Esperá un momento antes de volver a intentar.',
    'token' => 'El enlace para restablecer la contraseña no es válido.',
    'user' => 'No encontramos ningún usuario con ese correo electrónico.',
];
