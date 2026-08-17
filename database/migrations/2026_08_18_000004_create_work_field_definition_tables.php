<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos técnicos dinámicos (spec 9.3, RF-DIN-001…005).
 *
 * El Admin define campos por categoría o por subcategoría; si existen los dos, la
 * obra presenta la unión sin códigos duplicados (RF-DIN-001).
 *
 * `scope_type` + `scope_id` es polimórfico a mano y no con la convención de
 * Laravel: los dos únicos alcances posibles están cerrados por el spec, y una
 * relación polimórfica genérica invitaría a agregar un tercero sin pensarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_field_definitions', function (Blueprint $table) {
            $table->id();

            $table->enum('scope_type', ['CATEGORY', 'SUBCATEGORY']);
            $table->unsignedBigInteger('scope_id');

            // Inmutable siempre (RF-DIN-003). Es la clave con la que se guardan
            // los valores: renombrarlo huerfanaría los datos ya cargados.
            $table->string('code', 64);

            $table->string('label');
            $table->text('help_text')->nullable();

            // Inmutable si ya hay valores cargados (RF-DIN-004): no existe
            // conversión segura de, por ejemplo, texto libre a entero.
            $table->enum('data_type', [
                'TEXT',
                'LONG_TEXT',
                'INTEGER',
                'DECIMAL',
                'BOOLEAN',
                'DATE',
                'SELECT',
            ]);

            // Sólo para numéricos (RF-DIN-002).
            $table->string('unit', 32)->nullable();
            $table->decimal('min_value', 20, 6)->nullable();
            $table->decimal('max_value', 20, 6)->nullable();

            // Volver obligatorio un campo con obras previas NO invalida
            // retroactivamente: se exige en las ediciones siguientes.
            $table->boolean('is_required')->default(false);

            // Visibilidad independiente en cada superficie (RF-DIN-003). Por
            // omisión NO se publica: el valor inicial es el conservador, y
            // abrirlo es una decisión explícita.
            $table->boolean('public_visible')->default(false);
            $table->boolean('live_visible')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // El código es único dentro de su alcance, no globalmente: dos
            // categorías distintas pueden tener cada una su campo `superficie`.
            $table->unique(['scope_type', 'scope_id', 'code']);
            $table->index(['scope_type', 'scope_id', 'is_active', 'sort_order'], 'wfd_scope_activo_orden_idx');
        });

        Schema::create('work_field_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_field_definition_id')
                ->constrained('work_field_definitions')
                ->cascadeOnDelete();

            $table->string('value', 128);
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Se desactivan, no se borran, si alguna obra las usa (RF-CAT-005).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['work_field_definition_id', 'value'], 'wfo_definicion_valor_unq');
            $table->index(['work_field_definition_id', 'is_active', 'sort_order'], 'wfo_definicion_activo_orden_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_field_options');
        Schema::dropIfExists('work_field_definitions');
    }
};
