<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/*
| La corrección central de la sección 4 del plan. La versión anterior escribía la
| auditoría por una segunda conexión, y eso deja dos modos de falla silenciosos:
|
|   · si el negocio se revierte, el evento QUEDA y la bitácora afirma un cambio
|     que nunca ocurrió;
|   · si la auditoría falla, el negocio puede confirmar igual y el cambio queda
|     sin registrar.
|
| Estos tests fijan el comportamiento correcto en las dos direcciones.
*/

function unUsuario(): User
{
    return User::forceCreate([
        'name' => 'Bruno Díaz',
        'email' => 'bruno@ramallo.gob.ar',
        'password' => Hash::make('una-contrasena-de-doce-o-mas'),
        'role' => User::ROLE_OBRAS_PUBLICAS,
        'is_active' => true,
        'must_change_password' => false,
        'theme_preference' => null,
    ]);
}

it('no deja evento si la transacción de negocio se revierte', function () {
    $user = unUsuario();
    $recorder = new AuditRecorder;

    try {
        DB::transaction(function () use ($user, $recorder): void {
            $user->forceFill(['name' => 'Nombre cambiado'])->save();

            $recorder->registrar(
                action: 'user.updated',
                entityType: 'user',
                entityId: $user->getKey(),
                before: ['name' => 'Bruno Díaz'],
                after: ['name' => 'Nombre cambiado'],
                actor: $user,
            );

            throw new RuntimeException('Algo falló después de auditar.');
        });
    } catch (RuntimeException) {
        // Esperado: lo que importa es qué quedó en la base.
    }

    // Ni el cambio ni el evento: o quedan los dos, o no queda ninguno.
    expect($user->fresh()->name)->toBe('Bruno Díaz')
        ->and(AuditEvent::query()->where('action', 'user.updated')->count())->toBe(0);
});

it('deja el evento y el cambio juntos cuando la transacción confirma', function () {
    $user = unUsuario();
    $recorder = new AuditRecorder;

    DB::transaction(function () use ($user, $recorder): void {
        $user->forceFill(['name' => 'Nombre nuevo'])->save();

        $recorder->registrar(
            action: 'user.updated',
            entityType: 'user',
            entityId: $user->getKey(),
            before: ['name' => 'Bruno Díaz'],
            after: ['name' => 'Nombre nuevo'],
            actor: $user,
        );
    });

    expect($user->fresh()->name)->toBe('Nombre nuevo')
        ->and(AuditEvent::query()->where('action', 'user.updated')->count())->toBe(1);
});

it('conserva el evento de un intento fallido aunque el llamador revierta', function () {
    // Éste es el caso que motiva la conexión independiente: una denegación de
    // autorización (CA-014) puede saltar dentro de una transacción que después se
    // revierte, y el rechazo no puede desaparecer de la bitácora justo cuando más
    // interesa haberlo registrado.
    config(['audit.independent_connection' => 'mariadb_audit']);
    $recorder = new AuditRecorder;

    try {
        DB::transaction(function () use ($recorder): void {
            $recorder->registrarIntentoFallido('authz.denied', ['ruta' => '/admin/usuarios']);

            throw new RuntimeException('La operación se revierte después de la denegación.');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    // Se consulta por la conexión independiente: la de por omisión está dentro de
    // la transacción de RefreshDatabase y no ve lo que se confirmó por fuera.
    $escritos = DB::connection('mariadb_audit')
        ->table('audit_events')
        ->where('action', 'authz.denied')
        ->count();

    expect($escritos)->toBe(1);

    // Limpieza explícita: esta fila se confirmó por fuera de la transacción del
    // test, así que RefreshDatabase no la va a deshacer. TRUNCATE no dispara los
    // triggers de inmutabilidad, que es justamente lo que lo hace posible acá.
    DB::connection('mariadb_audit')->statement('TRUNCATE TABLE audit_events');
});

it('avisa si se usa el camino de intentos fallidos dentro de una transacción sin conexión independiente', function () {
    // Sin la conexión configurada la garantía no existe, y eso no puede quedar
    // implícito: queda un aviso en el log.
    config(['audit.independent_connection' => null]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $mensaje) => str_contains($mensaje, 'AUDIT_INDEPENDENT_CONNECTION'));

    DB::transaction(function (): void {
        (new AuditRecorder)->registrarIntentoFallido('authz.denied');
    });
});

it('rechaza modificar un evento ya registrado', function () {
    $evento = (new AuditRecorder)->registrarIntentoFallido('auth.login.failed');

    // Capa 3: la guarda del modelo, que da un error legible antes de que el
    // disparador de MariaDB aborte la sentencia.
    expect(fn () => $evento->update(['action' => 'otra.cosa']))
        ->toThrow(RuntimeException::class, 'inmutable');
});

it('rechaza eliminar un evento ya registrado', function () {
    $evento = (new AuditRecorder)->registrarIntentoFallido('auth.login.failed');

    expect(fn () => $evento->delete())->toThrow(RuntimeException::class, 'inmutable');
});

it('el motor rechaza UPDATE y DELETE incluso salteando el modelo', function () {
    // Capa 2: los disparadores. Si alguien escribe SQL a mano, el motor aborta.
    (new AuditRecorder)->registrarIntentoFallido('auth.login.failed');

    expect(fn () => DB::table('audit_events')->update(['action' => 'falsificado']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->delete())
        ->toThrow(QueryException::class);

    expect(AuditEvent::query()->count())->toBe(1);
});

it('redacta secretos en cualquier nivel de anidamiento', function () {
    $evento = (new AuditRecorder)->registrarIntentoFallido('config.updated', [
        'ors_api_key' => 'clave-real-que-no-debe-quedar',
        'anidado' => [
            'password' => 'tampoco-esta',
            'nombre' => 'esto-si-queda',
            'mas_profundo' => ['remember_token' => 'ni-esto'],
        ],
        'campo_normal' => 'valor-visible',
    ]);

    $metadata = $evento->fresh()->metadata_json;

    expect($metadata['ors_api_key'])->toBe(AuditRecorder::REDACTED_PLACEHOLDER)
        ->and($metadata['anidado']['password'])->toBe(AuditRecorder::REDACTED_PLACEHOLDER)
        ->and($metadata['anidado']['mas_profundo']['remember_token'])->toBe(AuditRecorder::REDACTED_PLACEHOLDER)
        ->and($metadata['anidado']['nombre'])->toBe('esto-si-queda')
        ->and($metadata['campo_normal'])->toBe('valor-visible');
});

it('redacta por coincidencia parcial, no por igualdad exacta', function () {
    // Es mejor redactar de más que filtrar un secreto porque alguien nombró un
    // campo `api_key_nueva` en lugar de `api_key`.
    $evento = (new AuditRecorder)->registrarIntentoFallido('config.updated', [
        'api_key_nueva' => 'secreto',
        'token_de_teselas' => 'secreto',
        'contrasena_temporal' => 'secreto',
    ]);

    expect(array_values($evento->fresh()->metadata_json))
        ->each->toBe(AuditRecorder::REDACTED_PLACEHOLDER);
});

it('guarda el contexto de la petición para poder investigar', function () {
    $evento = (new AuditRecorder)->registrarIntentoFallido('auth.login.failed');

    expect($evento->request_id)->not->toBeNull()
        ->and($evento->occurred_at)->not->toBeNull()
        ->and($evento->is_failed_attempt)->toBeTrue();
});
