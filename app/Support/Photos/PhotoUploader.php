<?php

declare(strict_types=1);

namespace App\Support\Photos;

use App\Jobs\ProcessWorkPhoto;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPhoto;
use App\Support\Audit\AuditRecorder;
use App\Support\Settings\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recibe el archivo, lo guarda y encola su procesamiento (ADR-019).
 *
 * LA OBRA NO ESPERA A LA FOTO. Acá se persiste la fila en `PENDING` y se
 * devuelve; los derivados los hace un job. Es lo que permite que RF-BO-007
 * —publicación inmediata— conviva con el ciclo de procesamiento: la obra ya está
 * publicada y cada foto se suma cuando llega a `READY`.
 *
 * EL ARCHIVO ORIGINAL SE GUARDA ANTES DE ENCOLAR, y en la misma transacción que
 * la fila. Si se encolara primero, el job podría arrancar antes de que el
 * archivo exista y fallaría por una carrera propia, no por un problema real.
 *
 * DÓNDE VIVEN. `storage/app/private/fotos/{obra}/…`, fuera del document root.
 * Las fotos son privadas y se sirven por controlador con URL firmada: nunca se
 * corre `storage:link`, que abriría por HTTP lo que RNF-SEC-005 quiere cerrado.
 */
final class PhotoUploader
{
    /**
     * Formatos que se aceptan.
     *
     * La lista es blanca y corta a propósito. Un PDF o un HEIC no los procesa
     * GD, y aceptarlos para después fallar en el job sería avisar tarde.
     *
     * @var list<string>
     */
    public const MIMES_ADMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws PhotoRuleViolation
     */
    public function subir(Work $work, UploadedFile $archivo, ?User $actor = null): WorkPhoto
    {
        $this->validar($work, $archivo);

        return DB::transaction(function () use ($work, $archivo, $actor): WorkPhoto {
            // Nombre opaco: el que trae el archivo puede repetirse entre fotos y
            // puede traer caracteres que compliquen la ruta.
            $nombre = Str::uuid()->toString().'.'.$this->extension($archivo);
            $ruta = "fotos/{$work->getKey()}/{$nombre}";

            $archivo->storeAs("fotos/{$work->getKey()}", $nombre, ['disk' => 'local']);

            $foto = new WorkPhoto;
            $foto->forceFill([
                'work_id' => $work->getKey(),
                'status' => WorkPhoto::STATUS_PENDING,
                'original_filename' => Str::limit((string) $archivo->getClientOriginalName(), 250, ''),
                'disk' => 'local',
                'path_original' => $ruta,
                'mime_type' => (string) $archivo->getMimeType(),
                'size_bytes' => $archivo->getSize(),
                'checksum_sha256' => hash_file('sha256', $archivo->getRealPath()) ?: null,
                'sort_order' => $this->proximoOrden($work),
                'uploaded_by' => $actor?->getKey(),
            ]);
            $foto->save();

            $this->audit->registrar(
                action: 'work.photo.uploaded',
                entityType: $foto->getTable(),
                entityId: $foto->getKey(),
                after: [
                    'work_id' => $work->getKey(),
                    'original_filename' => $foto->original_filename,
                    'size_bytes' => $foto->size_bytes,
                    'status' => $foto->status,
                ],
                actor: $actor,
            );

            // Después de confirmar, no antes: si la transacción se revierte, el
            // job no debe existir. `afterCommit` lo garantiza sin que haya que
            // acordarse en cada punto de llamada.
            ProcessWorkPhoto::dispatch($foto->getKey())->afterCommit();

            return $foto;
        });
    }

    /**
     * @throws PhotoRuleViolation
     */
    private function validar(Work $work, UploadedFile $archivo): void
    {
        if (! $archivo->isValid()) {
            throw new PhotoRuleViolation('El archivo no llegó completo. Probá subirlo de nuevo.');
        }

        $mime = (string) $archivo->getMimeType();

        if (! in_array($mime, self::MIMES_ADMITIDOS, true)) {
            throw new PhotoRuleViolation(
                'Sólo se aceptan imágenes JPG, PNG o WEBP. El archivo que elegiste es de otro tipo.',
            );
        }

        $maximoMb = (int) $this->settings->get(AppSettings::MAX_PHOTO_MB);

        if ($archivo->getSize() > $maximoMb * 1024 * 1024) {
            throw new PhotoRuleViolation("Cada foto puede pesar hasta {$maximoMb} MB.");
        }

        $maximoFotos = (int) $this->settings->get(AppSettings::MAX_PHOTOS_PER_WORK);

        // Las que están en papelera no cuentan: liberar un lugar es justamente
        // para lo que sirve borrarlas.
        if ($work->photos()->count() >= $maximoFotos) {
            throw new PhotoRuleViolation(
                "La obra ya tiene {$maximoFotos} fotos, que es el máximo. Borrá alguna para agregar otra.",
            );
        }
    }

    private function extension(UploadedFile $archivo): string
    {
        return match ((string) $archivo->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function proximoOrden(Work $work): int
    {
        return (int) $work->photos()->max('sort_order') + 1;
    }
}
