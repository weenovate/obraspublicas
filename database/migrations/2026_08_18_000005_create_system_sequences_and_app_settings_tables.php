<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secuencia de códigos y configuración funcional (spec 9.1, RF-OBR-001…004,
 * RF-CFG-001…005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_sequences', function (Blueprint $table) {
            // La clave es el nombre de la secuencia, no un autoincremental: hay
            // una sola fila por secuencia y se la bloquea por nombre.
            $table->string('name', 64)->primary();

            // Nunca se reinicia al cambiar de año (RF-OBR-002) y nunca decrementa.
            $table->unsignedBigInteger('current_value')->default(0);

            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            // Configuración TIPADA: no hay claves libres (RF-CFG-001). El
            // catálogo de claves válidas vive en el código, y esta tabla sólo
            // guarda el valor de las que existen.
            $table->string('key', 64)->primary();

            $table->enum('data_type', ['STRING', 'INTEGER', 'BOOLEAN', 'ENUM', 'JSON']);

            // Un valor por columna tipada sería más estricto, pero la
            // configuración se lee entera al arrancar y se valida contra su
            // definición antes de castear: el costo de una columna de texto es
            // nulo y el catálogo de claves ya impide un valor arbitrario.
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('system_sequences');
    }
};
