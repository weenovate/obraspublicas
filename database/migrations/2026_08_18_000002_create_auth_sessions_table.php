<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones revocables (spec 9.1, RF-AUT-006/007, RF-USR-003).
 *
 * Es una tabla de dominio, no la de sesiones del framework. `sessions` guarda la
 * carga útil de la sesión HTTP; ésta guarda lo que la Municipalidad necesita
 * poder ver y revocar: qué dispositivo está conectado, desde cuándo, y por qué
 * dejó de estarlo.
 *
 * La distinción que gobierna el diseño es `is_persistent`:
 *
 *   - Sesión normal de backoffice: vence a las 8 h de inactividad (RF-AUT-006).
 *   - Sesión persistente de LIVE: NO vence por inactividad, sobrevive al reinicio
 *     del navegador y sólo se revoca al cerrar sesión, desactivar al usuario,
 *     cambiar su contraseña o revocarla desde administración (RF-AUT-007).
 *
 * Una pantalla de exhibición que se desconecta sola a las ocho horas es una
 * pantalla que alguien tiene que ir a reiniciar cada mañana; por eso la
 * persistencia es una decisión explícita del usuario y no un efecto secundario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Identificador de la sesión HTTP asociada. Permite cortar de verdad
            // la sesión en curso, no sólo marcar la fila como revocada.
            $table->string('session_id')->nullable()->index();

            // Para que el Admin distinga «la tele del hall» de «mi notebook»
            // cuando decide cuál revocar (RF-USR-003).
            $table->string('device_label')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->boolean('is_persistent')->default(false);

            $table->timestamp('last_seen_at')->nullable();

            // Revocada, y por qué. El motivo importa: no es lo mismo un cierre de
            // sesión voluntario que una revocación por desactivación del usuario,
            // y la bitácora tiene que poder distinguirlos.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // El filtro habitual es «sesiones vivas de este usuario».
            $table->index(['user_id', 'revoked_at']);
            // Y el de la revisión de LIVE: «persistentes vivas».
            $table->index(['is_persistent', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
