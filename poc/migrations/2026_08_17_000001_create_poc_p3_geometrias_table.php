<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 — ¿El grammar de MariaDB de Laravel 13 emite DDL geométrico válido?
 *
 * Laravel 11+ trae un grammar dedicado para MariaDB que emite `geometry` plano
 * en lugar del `geometry srid 4326` de MySQL 8. Esta migración lo ejercita de
 * verdad, con `$table->geometry(...)` y `$table->spatialIndex(...)`, en vez de
 * suponer el resultado. `poc/sonda.php` inspecciona después el DDL emitido y el
 * índice creado en `information_schema`.
 *
 * Vive en `poc/migrations/`, no en `database/migrations/`, para no contaminar el
 * esquema de la aplicación. Se corre con:
 *
 *   php artisan migrate --path=poc/migrations
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poc_p3_geometrias', function (Blueprint $table) {
            $table->id();

            // Geometría de la obra: punto, línea o polígono según la subcategoría.
            $table->geometry('geometry');

            // Punto representativo: sólo para clustering (sección 8 del plan).
            $table->geometry('representative_point', subtype: 'point');

            // Ambas columnas se interrogan según el modo de consulta, así que
            // ambas necesitan índice espacial.
            $table->spatialIndex('geometry');
            $table->spatialIndex('representative_point');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poc_p3_geometrias');
    }
};
