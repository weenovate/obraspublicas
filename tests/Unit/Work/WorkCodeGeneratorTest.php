<?php

declare(strict_types=1);

use App\Support\Work\WorkCodeGenerator;
use Illuminate\Support\Facades\DB;

/*
| La guarda de transacción del generador de códigos (RF-OBR-001…004).
|
| Vive fuera de `Feature` por una razón concreta: allá `RefreshDatabase` envuelve
| cada test en una transacción, así que el nivel nunca es cero y el caso que hay
| que observar —que se lo llame SIN transacción— no puede producirse.
|
| Acá se simula la respuesta de la base en lugar de tocarla. Es legítimo porque
| lo que se verifica no es el comportamiento del motor, sino que el servicio se
| niegue a trabajar cuando la condición que lo hace seguro no se cumple.
*/

it('se niega a generar un código fuera de una transacción', function () {
    // Fuera de una transacción el bloqueo de la secuencia se libera apenas
    // termina la consulta, mucho antes de que el alta confirme: dos altas
    // simultáneas podrían recibir el mismo número.
    DB::shouldReceive('transactionLevel')->once()->andReturn(0);

    expect(fn () => (new WorkCodeGenerator)->next())
        ->toThrow(RuntimeException::class, 'dentro de una transacción');
});
