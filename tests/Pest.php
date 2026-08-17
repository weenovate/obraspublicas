<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|---------------------------------------------------------------------------
| Configuración de la suite
|---------------------------------------------------------------------------
|
| Toda la suite corre contra MariaDB 10.11.18 (ver phpunit.xml). No se usa
| SQLite: buena parte de lo que hay que verificar —DDL geométrico, índices
| SPATIAL, disparadores de inmutabilidad, comportamiento de ST_*— es específica
| del motor, y un test verde sobre otro motor no dice nada sobre producción.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Arch');

/*
| Los tests unitarios corren sin base de datos y en general sin aplicación. La
| excepción son los de `Unit/Work`, que necesitan el contenedor para sustituir la
| fachada `DB`: verifican una guarda que `RefreshDatabase` haría inobservable en
| `Feature`, porque allá siempre hay una transacción abierta.
*/
pest()->extend(TestCase::class)->in('Unit/Work');
