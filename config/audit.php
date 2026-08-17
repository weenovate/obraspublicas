<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Conexión independiente para intentos fallidos
    |---------------------------------------------------------------------------
    |
    | La auditoría de un cambio de negocio va SIEMPRE en la conexión y la
    | transacción del cambio: eso es lo que da la atomicidad, y es la corrección
    | central de la sección 4 del plan.
    |
    | Los intentos FALLIDOS o DENEGADOS son el caso opuesto. No tienen cambio de
    | negocio que confirmar, y sí pueden ocurrir dentro de una transacción ajena:
    | una denegación de autorización (CA-014) puede saltar en medio de una
    | actualización que después se revierte. Si el evento viajara en esa
    | transacción, el rechazo desaparecería de la bitácora justo cuando más
    | interesa haberlo registrado.
    |
    | Con esta conexión configurada, esos eventos se escriben por fuera y
    | sobreviven al rollback. Si queda vacía, se usa la conexión por omisión: el
    | comportamiento sigue siendo correcto en los tres puntos de llamada reales
    | —login fallido, denegación y límite de tasa—, donde no hay transacción
    | abierta, pero se pierde la garantía en el caso anidado.
    |
    | En producción y staging se configura. Ver docs/DEPLOY-CPANEL.md.
    |
    */

    'independent_connection' => env('AUDIT_INDEPENDENT_CONNECTION'),

];
