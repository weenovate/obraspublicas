<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios y sesiones (spec 9.1).
 *
 * Es una de las dos migraciones definitivas de F0. La política de esquema es
 * expansiva: se agrega, no se rompe, para que un rollback de release siga
 * funcionando contra el esquema nuevo.
 *
 * No hay tabla de tokens de recuperación por email: la recuperación automática
 * por email está fuera de alcance (spec 16). El Admin repone la contraseña con
 * una temporal y `must_change_password` fuerza el cambio en el primer ingreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            // Hash Argon2id (RNF-SEC-002). El largo alcanza para el formato
            // `$argon2id$v=19$m=...` con sal y etiqueta.
            $table->string('password', 255);

            // Dos roles internos, sin jerarquía intermedia (spec 4).
            $table->enum('role', ['ADMIN', 'OBRAS_PUBLICAS']);

            // Un usuario desactivado no puede iniciar sesión y sus sesiones se
            // revocan en cascada. No se borra: sus eventos de auditoría tienen
            // que seguir apuntando a alguien.
            $table->boolean('is_active')->default(true);

            // Contraseña temporal puesta por el Admin: el primer ingreso obliga
            // a cambiarla antes de hacer cualquier otra cosa.
            $table->boolean('must_change_password')->default(false);

            // Preferencia de tema del usuario (RF-CFG-004). `system` es un
            // estado real, no la ausencia de elección: el backend la estampa en
            // `<html>` antes de la primera pintura.
            $table->enum('theme_preference', ['light', 'dark', 'system'])->default('system');

            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            // Los filtros del listado de usuarios son por rol y por actividad.
            $table->index(['role', 'is_active']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
