<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Driver de hashing
    |---------------------------------------------------------------------------
    |
    | Argon2id, no bcrypt (RNF-SEC-002). Verificado en el entorno: PHP 8.4.19
    | expone `PASSWORD_ARGON2ID`, así que no hace falta una extensión aparte.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |---------------------------------------------------------------------------
    | Parámetros de Argon2id
    |---------------------------------------------------------------------------
    |
    | 64 MiB de memoria, 4 pasadas y 2 hilos. Son valores conservadores para un
    | VPS compartido de cPanel: suben el costo de un ataque por fuerza bruta sin
    | dejar el login lento ni comerse la memoria del proceso PHP-FPM cuando
    | varios usuarios ingresan a la vez.
    |
    | Si el hosting resulta más holgado, subir `memory` es lo que más rinde. El
    | cambio es retrocompatible: Laravel rehashea al verificar.
    |
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 2),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

];
