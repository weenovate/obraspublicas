<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección: la preferencia de tema del usuario se alinea con el spec.
 *
 * F0 la implementó como `enum('light','dark','system')` con `system` por omisión,
 * donde `system` significaba «seguir al dispositivo». El spec dice otra cosa:
 *
 *   RF-CFG-004 — `theme_preference` tiene valores LIGHT o DARK.
 *   RF-CFG-005 — si la preferencia falta, se usa «el tema predeterminado
 *                configurado», que es un valor de `app_settings`, NO el del
 *                dispositivo. Los usuarios existentes reciben LIGHT en la
 *                migración inicial.
 *
 * No son lo mismo: seguir al dispositivo y usar un predeterminado configurable son
 * comportamientos distintos, y el segundo es el que la Municipalidad puede
 * gobernar desde la interfaz.
 *
 * La columna pasa a ser NULLABLE sin default: «vacía» es el estado «no eligió», y
 * es lo que activa el respaldo de `app_settings.default_theme`.
 *
 * El seguimiento del dispositivo NO desaparece: sigue vigente en la Web pública,
 * que no tiene sesión y por lo tanto no tiene preferencia guardada (RF-THE-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los que hoy tienen `system` no eligieron nada en realidad: el valor era
        // el default de F0. Se los deja en `light`, como pide RF-CFG-005 para la
        // migración inicial, en lugar de dejarlos en NULL: así el comportamiento
        // es idéntico para todos hasta que alguien elija.
        DB::table('users')->where('theme_preference', 'system')->update([
            'theme_preference' => 'light',
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('theme_preference', ['light', 'dark'])
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('theme_preference', ['light', 'dark', 'system'])
                ->default('system')
                ->change();
        });

        DB::table('users')->whereNull('theme_preference')->update([
            'theme_preference' => 'system',
        ]);
    }
};
