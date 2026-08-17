<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de auditoría con DOS caminos explícitos, y el nombre de cada método
 * dice cuál es cuál.
 *
 * `registrar()` es el camino por omisión: misma conexión y misma transacción que
 * el cambio de negocio. Cubre TODAS las operaciones exitosas, incluidas las de
 * seguridad. Un login exitoso, una revocación de sesión o un cambio de
 * contraseña son cambios de estado, y su auditoría tiene que ser atómica con
 * ellos: si la operación se revierte, el evento no puede sobrevivir afirmando
 * algo que no pasó.
 *
 * `registrarIntentoFallido()` existe sólo para intentos fallidos o denegados,
 * donde por definición no hay transacción de negocio que confirmar: login
 * fallido, denegación de autorización (CA-014) y rechazo por límite de tasa.
 * Con `audit.independent_connection` configurada escribe por una conexión
 * aparte, de modo que el evento sobreviva incluso si la transacción en la que
 * saltó se revierte —el caso realista es una denegación en medio de una
 * actualización—. Sin esa configuración escribe por la conexión de siempre, que
 * es correcto en los tres puntos de llamada reales porque ahí no hay transacción
 * abierta, y avisa por log si detecta una.
 *
 * Un test de arquitectura verifica que `registrarIntentoFallido` sólo se invoque
 * desde la lista blanca de fallos y denegaciones.
 */
final class AuditRecorder
{
    /**
     * Claves que nunca se escriben en la bitácora, en ninguna forma (RF-CFG-003).
     *
     * La comparación es por coincidencia parcial y sin distinguir mayúsculas: es
     * mejor redactar de más que filtrar un secreto porque alguien nombró un
     * campo `api_key_nuevo`.
     */
    public const REDACTED_KEYS = [
        'password',
        'contrasena',
        'contraseña',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'ors_api_key',
        'age_recipient',
        'private_key',
        'remember_token',
        'credential',
    ];

    public const REDACTED_PLACEHOLDER = '[redactado]';

    /**
     * Camino transaccional. Para toda operación EXITOSA.
     *
     * No abre transacción propia a propósito: se apoya en la del llamador, que es
     * exactamente lo que da la atomicidad. Si no hay transacción abierta, avisa
     * en desarrollo en lugar de crear la ilusión de atomicidad.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function registrar(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): AuditEvent {
        if (DB::transactionLevel() === 0 && ! app()->environment('production')) {
            logger()->warning(
                "AuditRecorder::registrar('{$action}') se llamó sin transacción abierta. "
                .'La atomicidad con el cambio de negocio no está garantizada. '
                .'Si esto es un intento fallido o denegado, usá registrarIntentoFallido().',
            );
        }

        return $this->write($action, $entityType, $entityId, $before, $after, $metadata, $actor, false);
    }

    /**
     * Camino NO transaccional. Exclusivamente para intentos fallidos o denegados.
     *
     * Se escribe en una transacción propia e independiente de la del llamador: un
     * intento rechazado no tiene cambio de negocio que confirmar, y el evento
     * tiene que sobrevivir al rechazo.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function registrarIntentoFallido(
        string $action,
        ?array $metadata = null,
        ?User $actor = null,
    ): AuditEvent {
        $connection = config('audit.independent_connection');

        // Sin conexión independiente configurada el evento viaja en la
        // transacción del llamador, y en los tres puntos de llamada reales no hay
        // ninguna abierta, así que se escribe y se confirma igual. El caso que
        // esto no cubre es el anidado: una denegación que salta dentro de una
        // transacción que después se revierte.
        if (! is_string($connection) || $connection === '') {
            if (DB::transactionLevel() > 0) {
                logger()->warning(
                    "AuditRecorder::registrarIntentoFallido('{$action}') se llamó dentro de una "
                    .'transacción y no hay conexión independiente configurada '
                    .'(`AUDIT_INDEPENDENT_CONNECTION`). Si esa transacción se revierte, el '
                    .'evento se pierde.',
                );
            }

            return $this->write($action, null, null, null, null, $metadata, $actor, true);
        }

        return $this->write($action, null, null, null, null, $metadata, $actor, true, $connection);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    private function write(
        string $action,
        ?string $entityType,
        int|string|null $entityId,
        ?array $before,
        ?array $after,
        ?array $metadata,
        ?User $actor,
        bool $isFailedAttempt,
        ?string $connection = null,
    ): AuditEvent {
        $user = $actor ?? Auth::user();
        $request = request();

        $attributes = [
            'occurred_at' => now(),
            'user_id' => $user?->getKey(),
            'actor_email' => $user?->email,
            'actor_role' => $user?->role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (int) $entityId,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'request_id' => $this->requestId(),
            'before_json' => $before === null ? null : self::redact($before),
            'after_json' => $after === null ? null : self::redact($after),
            'metadata_json' => $metadata === null ? null : self::redact($metadata),
            'is_failed_attempt' => $isFailedAttempt,
        ];

        if ($connection === null) {
            return AuditEvent::create($attributes);
        }

        // Conexión aparte: la escritura se confirma sola, sin depender de la
        // transacción del llamador ni poder arrastrarla.
        $event = (new AuditEvent)->setConnection($connection)->fill($attributes);
        $event->save();

        return $event;
    }

    /**
     * Identificador de la petición, para cruzar el evento con el log estructurado.
     */
    private function requestId(): string
    {
        $request = request();

        if (! $request->attributes->has('rml_request_id')) {
            $request->attributes->set('rml_request_id', (string) Str::uuid());
        }

        return (string) $request->attributes->get('rml_request_id');
    }

    /**
     * Redacta recursivamente las claves sensibles.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $redacted[$key] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            $redacted[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $redacted;
    }

    private static function isSensitive(string $key): bool
    {
        $needle = mb_strtolower($key);

        foreach (self::REDACTED_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
