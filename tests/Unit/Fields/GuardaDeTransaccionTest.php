<?php

declare(strict_types=1);

use App\Models\Work;
use App\Support\Fields\FieldRuleViolation;
use App\Support\Fields\WorkFieldValueWriter;
use Illuminate\Support\Facades\DB;

/*
| Vive en `Unit` y no en `Feature` por la misma razón que la guarda equivalente
| de `WorkCodeGenerator`: allá corre `RefreshDatabase`, que mantiene una
| transacción abierta durante todo el test, y entonces la condición que se quiere
| verificar —que NO haya transacción— es inobservable.
*/

it('se niega a escribir valores fuera de una transacción', function () {
    DB::shouldReceive('transactionLevel')->andReturn(0);

    expect(fn () => app(WorkFieldValueWriter::class)->guardar(new Work, []))
        ->toThrow(FieldRuleViolation::class, 'dentro de la transacción de la obra');
});
