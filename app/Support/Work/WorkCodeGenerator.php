<?php

declare(strict_types=1);

namespace App\Support\Work;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera el código de obra `OBR-YYYY-XXXX` (RF-OBR-001…004).
 *
 * Tres propiedades que tienen que valer a la vez:
 *
 *   ATÓMICO — dos altas simultáneas reciben secuencias distintas (CA-002). Se
 *   consigue con `SELECT … FOR UPDATE` sobre la fila de la secuencia: la segunda
 *   transacción espera a que la primera confirme. Un `MAX(sequence_number) + 1`
 *   no sirve: las dos leerían el mismo máximo.
 *
 *   NO REUTILIZABLE — la parte numérica no se reinicia al cambiar de año y nunca
 *   decrementa (RF-OBR-002). Los códigos de obras borradas, incluso
 *   definitivamente, no se reasignan.
 *
 *   DENTRO DE LA TRANSACCIÓN DEL ALTA — el bloqueo tiene que liberarse cuando el
 *   alta confirma o se revierte. Por eso este servicio NO abre transacción
 *   propia: exige estar dentro de una, y lo verifica en lugar de suponerlo.
 */
final class WorkCodeGenerator
{
    public const SEQUENCE_NAME = 'work_code';

    /** Mínimo de dígitos; crece solo cuando la secuencia lo supera (RF-OBR-002). */
    private const MIN_DIGITS = 4;

    /**
     * @return array{code: string, sequence_number: int, code_year: int}
     */
    public function next(?int $year = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'WorkCodeGenerator::next() tiene que llamarse dentro de una transacción: '
                .'si no, el bloqueo de la secuencia se libera antes de que el alta confirme '
                .'y dos altas simultáneas pueden recibir el mismo número.',
            );
        }

        // El año lo da el servidor, no el cliente (RF-OBR-001), y en hora local:
        // una obra creada el 31 de diciembre a las 22 en Buenos Aires pertenece a
        // ese año, no al siguiente.
        $year ??= (int) now()->format('Y');

        // La fila puede no existir la primera vez. `insertOrIgnore` evita una
        // condición de carrera entre dos primeras altas simultáneas.
        DB::table('system_sequences')->insertOrIgnore([
            'name' => self::SEQUENCE_NAME,
            'current_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{current_value: int}|null $row */
        $row = DB::table('system_sequences')
            ->where('name', self::SEQUENCE_NAME)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException('No se pudo bloquear la secuencia de códigos de obra.');
        }

        $next = ((int) $row->current_value) + 1;

        DB::table('system_sequences')
            ->where('name', self::SEQUENCE_NAME)
            ->update(['current_value' => $next, 'updated_at' => now()]);

        return [
            'code' => sprintf('OBR-%d-%s', $year, str_pad((string) $next, self::MIN_DIGITS, '0', STR_PAD_LEFT)),
            'sequence_number' => $next,
            'code_year' => $year,
        ];
    }
}
