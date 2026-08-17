<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos de obras: categorías, subcategorías y estados (spec 9.1).
 *
 * Van juntos en una migración porque son un solo grafo de dependencias y
 * separarlos obligaría a tres archivos que nunca se aplican por separado.
 *
 * Lo que estas columnas tienen que sostener no es el CRUD, sino las reglas de
 * INMUTABILIDAD de `docs/MODELO-DATOS.md`: qué deja de poder cambiarse cuando el
 * catálogo ya está en uso. El esquema no las enforca —dependen de si hay obras
 * asociadas, incluidas las de papelera— así que viven en el dominio, en
 * `app/Support/Catalog/`, y están fijadas por tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();

            // Participa de URLs compartibles (RF-WEB-006), así que una vez que la
            // categoría tiene obras deja de poder cambiar: una URL que alguien
            // guardó no puede dejar de funcionar porque se corrigió un nombre.
            $table->string('slug')->unique();

            // Obligatorios por RF-CAT-001. El icono sale de un registro cerrado
            // (RF-CAT-002) y el color se valida por contraste contra los DOS temas
            // antes de guardar (RF-CAT-003).
            $table->string('icon', 64);
            $table->string('color', 7);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('work_subcategories', function (Blueprint $table) {
            $table->id();

            // Inmutable una vez usada (RF-CAT-004).
            $table->foreignId('work_category_id')->constrained('work_categories')->restrictOnDelete();

            $table->string('name');
            $table->string('slug');

            // Determina qué geometría admite la obra. Inmutable con obras
            // asociadas: cambiar POINT por POLYGON invalidaría de golpe la
            // geometría de cada obra existente y no hay conversión razonable.
            // Única excepción, por no tocar ninguna geometría almacenada:
            // LINE_ROUTED_ROAD ↔ LINE_MANUAL_NETWORK.
            $table->enum('geometry_mode', [
                'POINT',
                'LINE_ROUTED_ROAD',
                'LINE_MANUAL_NETWORK',
                'POLYGON',
            ]);

            // Sólo aplica al trazado asistido. Editable siempre: afecta
            // sugerencias futuras de ORS, no las líneas ya guardadas.
            $table->enum('routing_profile', ['driving-car', 'foot-walking', 'cycling-regular'])
                ->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['work_category_id', 'slug']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('work_statuses', function (Blueprint $table) {
            $table->id();

            // Clave interna estable (RF-OBR-008). Las reglas del sistema se
            // apoyan en `is_final`, nunca en comparar contra esta clave.
            $table->string('key', 32)->unique();
            $table->string('label');

            // D3. Gobierna la semántica de fechas: con `true`, `actual_end_date`
            // es obligatoria y pasa a ser la fecha efectiva.
            //
            // CANCELLED es `false`: una obra cancelada no se terminó. Eso no
            // restringe el flujo — RF-OBR-007 no define máquina de estados.
            $table->boolean('is_final')->default(false);

            // Los cinco base no se pueden eliminar ni renombrar su clave
            // (RF-OBR-008/009).
            $table->boolean('is_system')->default(false);

            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_statuses');
        Schema::dropIfExists('work_subcategories');
        Schema::dropIfExists('work_categories');
    }
};
