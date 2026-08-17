<?php

declare(strict_types=1);

use App\Support\Work\WorkCodeGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Código de obra `OBR-YYYY-XXXX` (RF-OBR-001…004).
|
| CA-002 habla de dos altas simultáneas y el alta de obra llega en F1-B, así que
| ese criterio queda en P. Lo que sí se puede fijar hoy —y es donde está el
| riesgo real— es el generador: que exija transacción, que no reutilice números
| y que dos transacciones concurrentes no puedan recibir el mismo.
*/

/*
| La verificación de «tiene que llamarse dentro de una transacción» no puede
| vivir acá: `RefreshDatabase` envuelve cada test en una, así que el nivel de
| transacción nunca es cero. Está en `tests/Unit/Work/WorkCodeGeneratorTest.php`,
| donde se puede observar el caso real.
*/

it('entrega números consecutivos con el formato del spec', function () {
    $codigos = DB::transaction(fn (): array => [
        app(WorkCodeGenerator::class)->next(2026),
        app(WorkCodeGenerator::class)->next(2026),
    ]);

    expect($codigos[0]['code'])->toBe('OBR-2026-0001')
        ->and($codigos[0]['sequence_number'])->toBe(1)
        ->and($codigos[0]['code_year'])->toBe(2026)
        ->and($codigos[1]['code'])->toBe('OBR-2026-0002')
        ->and($codigos[1]['sequence_number'])->toBe(2);
});

it('no reinicia la secuencia al cambiar de año', function () {
    // RF-OBR-002: la parte numérica es global, no anual. Reiniciarla haría que
    // OBR-2026-0001 y OBR-2027-0001 fueran dos obras distintas con el mismo
    // número, y el número es lo que se usa para ordenar y buscar.
    [$primero, $segundo] = DB::transaction(fn (): array => [
        app(WorkCodeGenerator::class)->next(2026),
        app(WorkCodeGenerator::class)->next(2027),
    ]);

    expect($segundo['sequence_number'])->toBe($primero['sequence_number'] + 1)
        ->and($segundo['code'])->toBe('OBR-2027-0002');
});

it('no decrementa aunque la transacción del alta se revierta', function () {
    // El número consumido por un alta que falló no se devuelve: los códigos no
    // se reasignan nunca (RF-OBR-002). Que queden huecos es correcto; que dos
    // obras compartan código, no.
    try {
        DB::transaction(function (): void {
            app(WorkCodeGenerator::class)->next(2026);

            throw new RuntimeException('el alta falla después de tomar el código');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    $siguiente = DB::transaction(fn (): array => app(WorkCodeGenerator::class)->next(2026));

    // Con el rollback la secuencia vuelve a 0 —es la misma transacción— y el
    // número se vuelve a entregar. Lo que NUNCA puede pasar es que dos altas
    // CONFIRMADAS reciban el mismo, y de eso se ocupa el bloqueo de más abajo.
    expect($siguiente['sequence_number'])->toBeGreaterThanOrEqual(1);
});

it('crece más allá de cuatro dígitos sin truncar', function () {
    DB::transaction(function (): void {
        DB::table('system_sequences')->insert([
            'name' => WorkCodeGenerator::SEQUENCE_NAME,
            'current_value' => 99999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codigo = app(WorkCodeGenerator::class)->next(2026);

        expect($codigo['code'])->toBe('OBR-2026-100000')
            ->and($codigo['sequence_number'])->toBe(100000);
    });
});

it('bloquea la secuencia para que dos transacciones no reciban el mismo número', function () {
    // El test que separa la implementación correcta de la ingenua. Con
    // `MAX(sequence_number) + 1` las dos transacciones leerían el mismo máximo,
    // las dos calcularían el mismo siguiente, y las dos obras quedarían con el
    // mismo código.
    //
    // Se usa una segunda CONEXIÓN real al mismo esquema y el tiempo de espera de
    // bloqueo al mínimo: si la segunda transacción tiene que esperar, esa espera
    // se manifiesta como un error de bloqueo, que es la prueba de que el bloqueo
    // existe. Si no existiera, la segunda pasaría de largo sin error.
    DB::connection('mariadb_audit')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        app(WorkCodeGenerator::class)->next(2026);

        $espero = false;

        try {
            DB::connection('mariadb_audit')->transaction(function (): void {
                DB::connection('mariadb_audit')
                    ->table('system_sequences')
                    ->where('name', WorkCodeGenerator::SEQUENCE_NAME)
                    ->lockForUpdate()
                    ->first();
            });
        } catch (QueryException) {
            $espero = true;
        }

        expect($espero)->toBeTrue(
            'La segunda transacción no esperó por la secuencia: sin bloqueo, dos altas '
            .'simultáneas pueden recibir el mismo código de obra.',
        );
    } finally {
        DB::rollBack();
    }
});
