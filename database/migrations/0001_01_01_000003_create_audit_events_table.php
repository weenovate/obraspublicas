<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría (spec 9.13, RF-AUD-001/002).
 *
 * Dos propiedades que tienen que valer a la vez, y que una versión anterior del
 * plan resolvía mal:
 *
 *   ATOMICIDAD — el evento se escribe en la MISMA conexión y la MISMA
 *   transacción que el cambio de negocio. Escribirlo por una segunda conexión
 *   deja dos modos de falla silenciosos: si el negocio se revierte, la bitácora
 *   afirma un cambio que nunca ocurrió; si la auditoría falla, el negocio puede
 *   confirmar igual y el cambio queda sin registrar.
 *
 *   INMUTABILIDAD — se consigue sin romper la atomicidad, porque para insertar
 *   en la misma transacción sólo se necesita el privilegio INSERT. Tres capas:
 *   privilegios de tabla (runbook), disparadores (acá) y guardas de modelo.
 *
 * Los disparadores requieren el privilegio TRIGGER al desplegar. Si el hosting
 * no lo concede, la migración lo informa y queda como riesgo residual
 * documentado, no como supuesto silencioso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Cuándo ocurrió, en UTC: es un timestamp técnico, no una fecha
            // operativa. Las fechas de negocio van en hora de Buenos Aires.
            $table->timestamp('occurred_at')->useCurrent();

            // El actor. `user_id` puede quedar en null si el usuario se elimina
            // alguna vez, pero el correo queda desnormalizado: una bitácora que
            // pierde de quién fue la acción no sirve de nada.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 32)->nullable();

            // Qué pasó. Verbo del dominio, no nombre de método.
            $table->string('action', 64)->index();

            // Sobre qué. Polimórfico y laxo a propósito: los eventos de
            // seguridad no tienen entidad asociada.
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // Contexto de la petición. `request_id` permite cruzar el evento con
            // el log estructurado.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable()->index();

            // Antes y después, ya redactados: la lista de redacción se verifica
            // por test y nunca incluye secretos ni hashes (RF-CFG-003).
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('metadata_json')->nullable();

            // Si el evento fue un intento fallido o denegado, no hay transacción
            // de negocio asociada: es el único camino no transaccional.
            $table->boolean('is_failed_attempt')->default(false);

            $table->index(['entity_type', 'entity_id']);
            $table->index(['occurred_at', 'id']);
        });

        // Capa 2 de la inmutabilidad. `SIGNAL` aborta la sentencia con un error
        // que la aplicación no puede ignorar.
        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');

        Schema::dropIfExists('audit_events');
    }

    private function createImmutabilityTriggers(): void
    {
        try {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'La bitacora de auditoria es inmutable: no se admite UPDATE.';
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'La bitacora de auditoria es inmutable: no se admite DELETE.';
                END
            SQL);
        } catch (Throwable $e) {
            // Que falte el privilegio TRIGGER no puede impedir el despliegue,
            // pero tampoco puede pasar inadvertido: quedan las otras dos capas y
            // esto se registra como riesgo residual.
            $message = 'No se pudieron crear los disparadores de inmutabilidad de audit_events. '
                .'Verificá el privilegio TRIGGER del usuario de base (ver docs/DEPLOY-CPANEL.md). '
                .'Motivo: '.$e->getMessage();

            if (app()->runningInConsole()) {
                fwrite(STDERR, "\n[ADVERTENCIA] {$message}\n\n");
            }

            logger()->warning($message);
        }
    }
};
