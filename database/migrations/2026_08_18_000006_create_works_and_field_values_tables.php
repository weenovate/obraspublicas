<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro central de obra y valores de campos técnicos (spec 9.2, 9.3).
 *
 * ESTA MIGRACIÓN ENTRA EN F1-A A PROPÓSITO, aunque el CRUD de obras sea de F1-B.
 * No es adelantarse: las reglas de inmutabilidad de los catálogos dependen de
 * saber si algo *está en uso* —«una subcategoría usada no puede cambiar de
 * categoría»— y sin esta tabla esa regla no se puede enforcar ni testear. G3
 * bloquea las coordenadas verificadas, no el esquema.
 *
 * Dos decisiones que vienen del plan y conviene no perder de vista:
 *
 *   FECHAS (ADR-008). Tres columnas, no una. `estimated_end_date` nunca se
 *   sobrescribe; `actual_end_date` sólo tiene sentido con un estado finalizador
 *   pero se conserva como valor histórico si la obra vuelve atrás; y
 *   `effective_end_date` es derivada y MATERIALIZADA para que el filtro por rango
 *   sea un predicado plano e indexable.
 *
 *   GEOMETRÍA (ADR-011). Sin atributo SRID de columna: la sintaxis de MySQL 8 no
 *   parsea en MariaDB, y el `REF_SYSTEM_ID` propio se acepta pero NO rechaza un
 *   SRID distinto (medido en P2). El 4326 lo impone la aplicación y se verifica
 *   con `ST_SRID` antes de persistir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();

            // Código y secuencia: únicos e INMUTABLES (RF-OBR-003). El modelo
            // tiene guardas además de estos índices.
            $table->unsignedBigInteger('sequence_number')->unique();
            $table->string('code', 32)->unique();
            $table->unsignedSmallInteger('code_year');

            $table->string('name');
            $table->text('description')->nullable();

            // La categoría NO se duplica acá: se obtiene por la subcategoría, y
            // por eso reubicar una subcategoría usada está prohibido (spec 9.2).
            $table->foreignId('work_subcategory_id')->constrained('work_subcategories')->restrictOnDelete();
            $table->foreignId('work_status_id')->constrained('work_statuses')->restrictOnDelete();

            $table->date('start_date');

            // Pronóstico. Puede ser futura, y NUNCA se sobrescribe al finalizar.
            $table->date('estimated_end_date');

            // Fecha real. Obligatoria cuando el estado tiene `is_final = true`;
            // con `is_final = false` puede conservarse como valor histórico y no
            // participa de la fecha efectiva.
            $table->date('actual_end_date')->nullable();

            // Derivada y materializada: `CASE WHEN status.is_final THEN actual
            // ELSE estimated END`, recalculada en cada guardado. Está materializada
            // porque evaluar ese CASE sobre un join en cada filtro impide usar un
            // índice y choca con RNF-PER-001 con 10.000 obras.
            $table->date('effective_end_date');

            // Dirección. `district` y `province` van bloqueados en la interfaz:
            // la versión 1 es de municipio único.
            $table->string('street')->nullable();
            $table->string('street_number', 32)->nullable();
            $table->string('locality', 100)->nullable();
            $table->string('district')->default('Ramallo');
            $table->string('province')->default('Buenos Aires');

            $table->timestamp('published_at')->nullable();

            // Concurrencia optimista: dos ediciones simultáneas no se pisan en
            // silencio, la segunda recibe un conflicto.
            $table->unsignedInteger('lock_version')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Papelera. `deleted_by` acompaña a `deleted_at`: quién la dio de baja
            // es parte de la información, no sólo cuándo (RF-DEL-001).
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // Los filtros reales del listado y del mapa.
            $table->index(['deleted_at', 'work_status_id']);
            $table->index(['deleted_at', 'work_subcategory_id']);
            // Rango de fechas, indexable justamente por estar materializada.
            $table->index(['start_date', 'effective_end_date']);
        });

        // Las columnas geométricas van por SQL crudo y no por el Blueprint porque
        // tienen que ser NOT NULL sin default, y una tabla ya creada no admite
        // agregar una geometría NOT NULL con `$table->geometry()` si hubiera
        // filas. Acá la tabla está vacía, pero se deja explícito el DDL que
        // realmente se emite: es el mismo que P3 verificó.
        DB::statement('ALTER TABLE works ADD COLUMN geometry GEOMETRY NOT NULL');
        DB::statement('ALTER TABLE works ADD COLUMN representative_point POINT NOT NULL');

        // Longitud sólo para líneas, con el método de cálculo persistido: nunca
        // se guarda un resultado no convergido como si fuera exacto (ADR-012).
        Schema::table('works', function (Blueprint $table) {
            $table->decimal('length_m', 14, 2)->nullable();
            $table->enum('length_calc_method', ['VINCENTY', 'HAVERSINE_FALLBACK'])->nullable();
        });

        // Índice SPATIAL en LAS DOS columnas: se interrogan según el modo de
        // consulta, y un índice creado pero ignorado no cumple RNF-PER-001
        // (ADR-007, medido en P9).
        DB::statement('CREATE SPATIAL INDEX works_geometry_spatial ON works (geometry)');
        DB::statement('CREATE SPATIAL INDEX works_representative_point_spatial ON works (representative_point)');

        Schema::create('work_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('work_field_definition_id')
                ->constrained('work_field_definitions')
                ->restrictOnDelete();

            // Columnas tipadas, no un `value` genérico: una validación de
            // aplicación garantiza que EXACTAMENTE UNA coincida con el
            // `data_type` de la definición (spec 9.3).
            $table->text('value_text')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 20, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->foreignId('option_id')->nullable()
                ->constrained('work_field_options')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['work_id', 'work_field_definition_id'], 'wfv_obra_definicion_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_field_values');
        Schema::dropIfExists('works');
    }
};
