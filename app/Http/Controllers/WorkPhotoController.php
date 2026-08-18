<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessWorkPhoto;
use App\Models\Work;
use App\Models\WorkPhoto;
use App\Support\Audit\AuditRecorder;
use App\Support\Photos\PhotoRuleViolation;
use App\Support\Photos\PhotoUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotografías de una obra: subir, servir, reintentar y dar de baja.
 *
 * LAS FOTOS NO SE SIRVEN COMO ARCHIVOS ESTÁTICOS. Viven fuera del document root
 * y salen por acá, con firma en la URL (RNF-SEC-005). Es la razón por la que el
 * despliegue NO corre `storage:link`: un symlink en `public/` publicaría el
 * directorio entero y haría enumerable todo lo subido, incluidas las fotos de
 * obras que todavía no se publicaron.
 *
 * La firma protege dos cosas distintas: que la URL no se pueda adivinar cambiando
 * un número, y que no siga sirviendo para siempre si alguien la reenvía.
 */
final class WorkPhotoController
{
    public function __construct(
        private readonly PhotoUploader $uploader,
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request, Work $work): RedirectResponse
    {
        $request->validate(
            ['foto' => ['required', 'file', 'max:20480']],
            [],
            ['foto' => 'fotografía'],
        );

        try {
            $this->uploader->subir($work, $request->file('foto'), $request->user());
        } catch (PhotoRuleViolation $e) {
            throw ValidationException::withMessages(['foto' => $e->getMessage()]);
        }

        return back()->with('success', 'La foto se subió y se está procesando.');
    }

    /**
     * Sirve un derivado. La ruta va firmada; sin firma válida, 403.
     *
     * `$tamano` es `large` o `thumb`, y nunca una ruta: la columna manda, así que
     * no hay forma de pedir un archivo arbitrario cambiando el parámetro.
     */
    public function show(WorkPhoto $photo, string $tamano): SymfonyResponse
    {
        // Una foto que no llegó a READY no tiene derivados que mostrar, y
        // tampoco debería verse: no se publica (ADR-019).
        if (! $photo->esPublicable()) {
            abort(404);
        }

        $ruta = match ($tamano) {
            'large' => $photo->path_large,
            'thumb' => $photo->path_thumb,
            default => abort(404),
        };

        $disco = Storage::disk($photo->disk);

        if ($ruta === null || ! $disco->exists($ruta)) {
            abort(404);
        }

        return new StreamedResponse(
            function () use ($disco, $ruta): void {
                $stream = $disco->readStream($ruta);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => 'image/jpeg',
                // El derivado no cambia nunca: si se reprocesa, cambia el
                // contenido en la misma ruta, y por eso el caché es privado y
                // acotado en lugar de eterno.
                'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => 'inline',
            ],
        );
    }

    /** Vuelve a intentar el procesamiento de una foto que falló. */
    public function retry(Request $request, WorkPhoto $photo): RedirectResponse
    {
        if ($photo->status !== WorkPhoto::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'foto' => 'Sólo se reintentan las fotos que fallaron.',
            ]);
        }

        DB::transaction(function () use ($photo, $request): void {
            // El contador de intentos NO se reinicia: un reintento manual suma,
            // no borra la historia. Lo que se levanta es el techo, y eso es
            // deliberado —quien reintenta está tomando la decisión de insistir—.
            $photo->status = WorkPhoto::STATUS_PENDING;
            $photo->failure_reason = null;
            $photo->attempts = 0;
            $photo->save();

            $this->audit->registrar(
                action: 'work.photo.retried',
                entityType: $photo->getTable(),
                entityId: $photo->getKey(),
                actor: $request->user(),
            );
        });

        ProcessWorkPhoto::dispatch($photo->getKey())->afterCommit();

        return back()->with('success', 'Se está reintentando el procesamiento.');
    }

    /** Baja lógica, igual que la de las obras: quién y cuándo. */
    public function destroy(Request $request, WorkPhoto $photo): RedirectResponse
    {
        DB::transaction(function () use ($photo, $request): void {
            $photo->deleted_by = $request->user()?->getKey();
            $photo->save();
            $photo->delete();

            $this->audit->registrar(
                action: 'work.photo.trashed',
                entityType: $photo->getTable(),
                entityId: $photo->getKey(),
                before: ['status' => $photo->status, 'original_filename' => $photo->original_filename],
                actor: $request->user(),
            );
        });

        return back()->with('success', 'La foto se quitó de la obra.');
    }
}
