<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WorkPhoto;
use App\Support\Photos\PhotoProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa una foto en segundo plano (ADR-019).
 *
 * RECIBE UN ID, NO EL MODELO. Serializar el modelo guardaría una copia del
 * estado al momento de encolar, y cuando el job corriera —minutos después, con
 * el cron— podría estar pisando algo más nuevo. Con el id, el job lee el estado
 * actual y decide con él.
 *
 * ES IDEMPOTENTE. Correrlo dos veces sobre la misma foto deja el mismo
 * resultado: los derivados se sobrescriben en las mismas rutas. Hace falta
 * porque la cola por cron puede reintentar un job cuyo proceso murió después de
 * hacer el trabajo pero antes de confirmarlo.
 *
 * NO REINTENTA SOLO MÁS ALLÁ DE LA CUENTA. `MAX_ATTEMPTS` acota lo transitorio;
 * pasado eso la foto queda en `FAILED` y el reintento es una decisión de una
 * persona, desde la galería. Insistir sin límite sobre un archivo que no es una
 * imagen quema la cola sin arreglar nada.
 */
final class ProcessWorkPhoto implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $workPhotoId) {}

    public function handle(PhotoProcessor $procesador): void
    {
        $foto = WorkPhoto::query()->find($this->workPhotoId);

        if ($foto === null) {
            // La borraron entre el encolado y ahora. No es un error.
            return;
        }

        if ($foto->status === WorkPhoto::STATUS_READY) {
            // Ya procesada: un reintento duplicado de la cola. Salir es lo
            // correcto, y es lo que hace que el job sea idempotente de verdad.
            return;
        }

        if ($foto->attempts >= WorkPhoto::MAX_ATTEMPTS) {
            return;
        }

        $foto->increment('attempts');
        $foto->refresh();

        $procesador->procesar($foto);
    }
}
