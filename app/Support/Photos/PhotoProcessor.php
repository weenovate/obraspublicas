<?php

declare(strict_types=1);

namespace App\Support\Photos;

use App\Models\WorkPhoto;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Genera los derivados de una foto y la deja en `READY`, o la marca `FAILED`.
 *
 * ES IDEMPOTENTE POR ID (ADR-019). Reprocesar la misma foto sobrescribe sus
 * derivados en las mismas rutas y no crea filas ni archivos nuevos. Eso es lo
 * que permite reintentar sin miedo: un reintento que duplicara archivos dejaría
 * basura en disco en cada falla transitoria.
 *
 * LOS METADATOS SE DESCARTAN AL RECOMPRIMIR, y eso es una decisión, no un efecto
 * colateral. Las fotos de obra se sacan con teléfonos que escriben la ubicación
 * GPS del operario en el EXIF; publicarlas con ese dato adentro filtraría dónde
 * estuvo una persona, no dónde está la obra. La orientación EXIF sí se aplica
 * ANTES de descartar, porque si no las fotos verticales salen acostadas.
 *
 * GD, NO IMAGICK: el entorno de producción no tiene imagick y la CI tampoco, así
 * que el driver está fijado y no se autodetecta —una autodetección haría que la
 * imagen salga distinta según dónde corra—.
 */
final class PhotoProcessor
{
    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Procesa la foto y devuelve si quedó publicable.
     *
     * No lanza: traduce cualquier falla a `FAILED` con su motivo. Quien lo llama
     * es un job en segundo plano, y ahí una excepción no la ve nadie —el motivo
     * guardado, en cambio, aparece en la galería—.
     */
    public function procesar(WorkPhoto $foto): bool
    {
        $disco = Storage::disk($foto->disk);

        try {
            if (! $disco->exists($foto->path_original)) {
                return $this->fallar($foto, 'El archivo original no está en el almacenamiento.');
            }

            $imagen = $this->manager->decodeBinary((string) $disco->get($foto->path_original));

            // Primero la orientación, después todo lo demás: girar más tarde
            // significaría generar los derivados acostados.
            $imagen->orient();

            $foto->width = $imagen->width();
            $foto->height = $imagen->height();

            $base = $this->rutaBase($foto);

            // `strip: true` es lo que borra el EXIF, y con él la ubicación GPS
            // del teléfono que sacó la foto. Va explícito y no por omisión: es
            // una decisión de privacidad, no un detalle de compresión.
            $codificador = new JpegEncoder(quality: PhotoDerivatives::QUALITY, strip: true);

            foreach (PhotoDerivatives::TAMANOS as $sufijo => $lado) {
                $derivado = (clone $imagen)->scaleDown($lado, $lado);
                $disco->put("{$base}-{$sufijo}.jpg", (string) $derivado->encode($codificador));
            }

            $foto->path_large = "{$base}-large.jpg";
            $foto->path_thumb = "{$base}-thumb.jpg";
            $foto->status = WorkPhoto::STATUS_READY;
            $foto->failure_reason = null;
            $foto->processed_at = now();
            $foto->save();

            return true;
        } catch (Throwable $e) {
            // El mensaje del motor puede traer rutas del servidor; se guarda un
            // texto de negocio y el detalle técnico va al log.
            logger()->warning('Falló el procesamiento de una foto de obra.', [
                'work_photo_id' => $foto->getKey(),
                'excepcion' => $e->getMessage(),
            ]);

            return $this->fallar($foto, 'No se pudo procesar la imagen. Puede estar dañada o no ser una foto válida.');
        }
    }

    /**
     * La ruta de los derivados, DERIVADA de la del original.
     *
     * Que se calcule no contradice que se guarde en la base: se calcula una vez,
     * al generarlos, y desde entonces manda la columna. Reprocesar cae en las
     * mismas rutas, y de ahí la idempotencia.
     */
    private function rutaBase(WorkPhoto $foto): string
    {
        $sinExtension = preg_replace('/\.[^.]+$/', '', $foto->path_original);

        return (string) $sinExtension;
    }

    private function fallar(WorkPhoto $foto, string $motivo): bool
    {
        $foto->status = WorkPhoto::STATUS_FAILED;
        $foto->failure_reason = $motivo;
        $foto->processed_at = now();
        $foto->save();

        return false;
    }
}
