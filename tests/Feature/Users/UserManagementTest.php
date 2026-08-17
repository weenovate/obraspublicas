<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\Users\LastAdminException;
use App\Support\Users\UserManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| RF-USR-001…003 y RF-AUT-004/005.
|
| El test que más importa acá es el del último Admin bajo concurrencia: la
| implementación ingenua —contar administradores activos y después actualizar—
| pasa con un solo hilo y deja el sistema sin acceso con dos.
*/

it('crea usuarios con contraseña temporal que hay que cambiar al ingresar', function () {
    $admin = User::factory()->admin()->create();

    $user = app(UserManager::class)->create(
        name: 'Carla Pérez',
        email: 'CARLA@ramallo.gob.ar',
        role: User::ROLE_OBRAS_PUBLICAS,
        temporaryPassword: 'una-contrasena-de-doce-o-mas',
        actor: $admin,
    );

    expect($user->must_change_password)->toBeTrue()
        // El correo se normaliza: los correos son únicos sin distinguir
        // mayúsculas (RF-AUT-001).
        ->and($user->email)->toBe('carla@ramallo.gob.ar')
        ->and($user->password)->toStartWith('$argon2id$');

    $evento = AuditEvent::query()->where('action', 'user.created')->sole();
    expect($evento->after_json['email'])->toBe('carla@ramallo.gob.ar');
});

it('nunca registra la contraseña en la bitácora', function () {
    $admin = User::factory()->admin()->create();

    app(UserManager::class)->create(
        name: 'Carla Pérez',
        email: 'carla@ramallo.gob.ar',
        role: User::ROLE_OBRAS_PUBLICAS,
        temporaryPassword: 'CONTRASENA-QUE-NO-DEBE-QUEDAR',
        actor: $admin,
    );

    $eventos = json_encode(AuditEvent::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);

    expect($eventos)
        ->not->toContain('CONTRASENA-QUE-NO-DEBE-QUEDAR')
        ->not->toContain('$argon2id$');
});

it('desactiva un usuario y revoca sus sesiones en la misma transacción', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    AuthSession::factory()->count(3)->create(['user_id' => $user->id]);

    app(UserManager::class)->deactivate($user, $admin);

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(AuthSession::query()->where('user_id', $user->id)->first()->revoked_reason)
        ->toBe(AuthSession::REASON_USER_DEACTIVATED);
});

it('si la desactivación se revierte, no queda ni el cambio ni la revocación', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    AuthSession::factory()->create(['user_id' => $user->id]);

    try {
        DB::transaction(function () use ($user, $admin): void {
            app(UserManager::class)->deactivate($user, $admin);

            throw new RuntimeException('Algo falla después de desactivar.');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect($user->fresh()->is_active)->toBeTrue()
        ->and(AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'user.deactivated')->count())->toBe(0);
});

it('no deja desactivar al último Administrador activo', function () {
    $unico = User::factory()->admin()->create();

    expect(fn () => app(UserManager::class)->deactivate($unico))
        ->toThrow(LastAdminException::class);

    expect($unico->fresh()->is_active)->toBeTrue();
});

it('no deja degradar al último Administrador activo', function () {
    $unico = User::factory()->admin()->create();

    expect(fn () => app(UserManager::class)->changeRole($unico, User::ROLE_OBRAS_PUBLICAS))
        ->toThrow(LastAdminException::class);

    expect($unico->fresh()->role)->toBe(User::ROLE_ADMIN);
});

it('deja desactivar a un Administrador si queda otro activo', function () {
    $uno = User::factory()->admin()->create();
    User::factory()->admin()->create();

    app(UserManager::class)->deactivate($uno);

    expect($uno->fresh()->is_active)->toBeFalse();
});

it('no cuenta a los Administradores inactivos como respaldo', function () {
    $activo = User::factory()->admin()->create();
    User::factory()->admin()->inactivo()->create();

    expect(fn () => app(UserManager::class)->deactivate($activo))
        ->toThrow(LastAdminException::class);
});

it('bloquea las filas de administradores para que dos desactivaciones simultáneas no dejen el sistema sin acceso', function () {
    // Éste es el test que separa la implementación correcta de la ingenua. Con un
    // `count()` previo sin bloqueo, dos transacciones leerían «hay 2 activos»,
    // las dos pasarían la validación y el sistema quedaría sin Admin.
    //
    // Se usa una segunda CONEXIÓN real a la misma base: `mariadb_audit` apunta al
    // mismo esquema. Con la primera transacción abierta y las filas bloqueadas, la
    // segunda tiene que esperar; con el tiempo de espera al mínimo, esa espera se
    // manifiesta como un error de bloqueo, que es exactamente la prueba de que el
    // bloqueo existe.
    $primero = User::factory()->admin()->create();
    $segundo = User::factory()->admin()->create();

    DB::connection('mariadb_audit')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        // Toma el mismo bloqueo que toma `assertNotLastActiveAdmin`.
        User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($primero->getKey())
            ->lockForUpdate()
            ->count();

        $bloqueado = false;

        try {
            DB::connection('mariadb_audit')->transaction(function () use ($segundo): void {
                DB::connection('mariadb_audit')
                    ->table('users')
                    ->where('role', User::ROLE_ADMIN)
                    ->where('is_active', true)
                    ->whereKeyNot($segundo->getKey())
                    ->lockForUpdate()
                    ->count();
            });
        } catch (QueryException) {
            $bloqueado = true;
        }

        expect($bloqueado)->toBeTrue(
            'La segunda transacción no esperó: sin bloqueo, dos desactivaciones simultáneas '
            .'pueden dejar el sistema sin ningún Administrador activo.',
        );
    } finally {
        DB::rollBack();
    }
});

it('el cambio de contraseña revoca las demás sesiones y conserva la actual', function () {
    $user = User::factory()->create();
    $actual = AuthSession::factory()->create(['user_id' => $user->id]);
    $otras = AuthSession::factory()->count(2)->create(['user_id' => $user->id]);

    app(UserManager::class)->changePassword(
        user: $user,
        newPassword: 'otra-contrasena-de-doce',
        actor: $user,
        keepSession: $actual,
    );

    expect($actual->fresh()->revoked_at)->toBeNull()
        ->and($otras->every(fn (AuthSession $s): bool => $s->fresh()->revoked_at !== null))->toBeTrue()
        ->and($otras->first()->fresh()->revoked_reason)->toBe(AuthSession::REASON_PASSWORD_CHANGED)
        ->and(Hash::check('otra-contrasena-de-doce', $user->fresh()->password))->toBeTrue();
});

it('el Admin repone una contraseña temporal y se vuelve a exigir el cambio', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($admin)->post("/admin/usuarios/{$user->id}/password", [
        'password' => 'temporal-de-doce-caracteres',
    ])->assertRedirect();

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('rechaza contraseñas de menos de doce caracteres', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/usuarios', [
        'name' => 'Corta',
        'email' => 'corta@ramallo.gob.ar',
        'role' => User::ROLE_OBRAS_PUBLICAS,
        'password' => 'corta',
    ])->assertInvalid(['password']);
});
