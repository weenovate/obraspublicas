<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotografías de una obra (spec 9.4, RF-FOT-001…, ADR-019).
 *
 * La decimotercera y última tabla del modelo. Cuatro decisiones que conviene no
 * perder de vista, porque las tres primeras salen de ADR-019:
 *
 *   EL ESTADO ES DEL ARCHIVO, NO DE LA OBRA. La obra se publica de inmediato y
 *   cada foto aparece cuando llega a `READY`. Una foto en `PENDING` o `FAILED`
 *   nunca se publica, y su falla no invalida datos ya guardados.
 *
 *   LOS DERIVADOS SON COLUMNAS, NO CONVENCIÓN DE NOMBRES. Deducir la ruta de la
 *   miniatura a partir de la del original ata el código a un esquema de nombres
 *   que después no se puede cambiar sin migrar archivos. Guardadas, el día que se
 *   agregue un tamaño nuevo o se pase a almacenamiento de objetos, sólo cambian
 *   filas.
 *
 *   EL DISCO SE GUARDA POR FILA. Hoy todas las fotos viven en el disco local; si
 *   mañana se migra a S3, las viejas siguen resolviéndose donde están mientras
 *   las nuevas van al destino nuevo. Sin esta columna, la migración sería un
 *   corte con todo movido de una vez.
 *
 *   EL PROCESAMIENTO DEJA RASTRO. `attempts`, `failure_reason` y `processed_at`
 *   existen para que un fallo se pueda diagnosticar sin entrar al servidor: la
 *   galería muestra por qué falló y ofrece reintentar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_photos', function (Blueprint $table) {
            $table->id();

            // Al eliminar definitivamente una obra (F6) se van sus fotos: no
            // tienen sentido sueltas y nadie las reclamaría después.
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();

            // PENDING → READY | FAILED. Nunca al revés salvo por reintento
            // explícito, que vuelve a PENDING.
            $table->enum('status', ['PENDING', 'READY', 'FAILED'])->default('PENDING');

            // Lo que subió la persona, para poder decirle cuál falló.
            $table->string('original_filename');

            // Dónde vive. Ver la nota de arriba sobre migrar a objetos.
            $table->string('disk', 32)->default('local');

            // El archivo tal cual llegó y sus derivados. `path_original` es lo
            // único obligatorio: los otros dos se llenan al procesar.
            $table->string('path_original');
            $table->string('path_large')->nullable();
            $table->string('path_thumb')->nullable();

            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');

            // Del original, una vez leído. Nulos mientras esté PENDING.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Integridad, no deduplicación: sirve para detectar un archivo
            // corrupto o truncado. NO es único a propósito —subir dos veces la
            // misma foto a la misma obra es raro, pero no es un error del
            // sistema y no le toca al esquema impedirlo—.
            $table->char('checksum_sha256', 64)->nullable();

            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Diagnóstico del procesamiento.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Igual que en `works`: quién la dio de baja es parte del dato.
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // La consulta real de la galería y la de la web pública: las fotos
            // de una obra, listas, en orden.
            $table->index(['work_id', 'deleted_at', 'status', 'sort_order'], 'wp_obra_estado_orden_idx');

            // La que barre lo que quedó a medias para reintentarlo.
            $table->index(['status', 'attempts'], 'wp_estado_intentos_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_photos');
    }
};
