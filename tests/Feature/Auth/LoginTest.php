<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('login:ana@ramallo.gob.ar|127.0.0.1');
    RateLimiter::clear('login:ana@ramallo.gob.ar|127.0.0.1:hora');
});

function usuarioActivo(array $overrides = []): User
{
    return User::forceCreate(array_merge([
        'name' => 'Ana Gómez',
        'email' => 'ana@ramallo.gob.ar',
        'password' => Hash::make('una-contrasena-de-doce-o-mas'),
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'must_change_password' => false,
        'theme_preference' => 'system',
    ], $overrides));
}

it('usa Argon2id para las contraseñas, no bcrypt', function () {
    // RNF-SEC-002. Se verifica el prefijo del hash, no la configuración: lo que
    // importa es con qué quedó guardada la contraseña.
    expect(usuarioActivo()->password)->toStartWith('$argon2id$');
});

it('deja entrar con credenciales correctas y regenera la sesión', function () {
    $user = usuarioActivo();

    $response = $this->post('/login', [
        'email' => 'ana@ramallo.gob.ar',
        'password' => 'una-contrasena-de-doce-o-mas',
    ]);

    $response->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('audita el login exitoso por el camino transaccional, no como intento fallido', function () {
    usuarioActivo();

    $this->post('/login', [
        'email' => 'ana@ramallo.gob.ar',
        'password' => 'una-contrasena-de-doce-o-mas',
    ]);

    $evento = AuditEvent::query()->where('action', 'auth.login')->sole();

    // Ésta es la corrección de la fe de erratas: un login exitoso es un cambio
    // de estado, así que se registra con `registrar()` y NUNCA con
    // `registrarIntentoFallido()`.
    expect($evento->is_failed_attempt)->toBeFalse()
        ->and($evento->actor_email)->toBe('ana@ramallo.gob.ar')
        ->and($evento->actor_role)->toBe(User::ROLE_ADMIN)
        // El identificador de sesión que quedó en el evento es el de DESPUÉS de
        // regenerar: si se hubiera registrado antes, no coincidiría con la sesión
        // real del usuario.
        ->and($evento->metadata_json['session_id'])->toBe(session()->getId());
});

it('no revela si el correo existe', function () {
    usuarioActivo();

    // Mismo mensaje palabra por palabra: cualquier diferencia es un oráculo de
    // enumeración de usuarios.
    $mensaje = 'Las credenciales no son correctas.';

    $this->post('/login', ['email' => 'nadie@ramallo.gob.ar', 'password' => 'x'])
        ->assertInvalid(['email' => $mensaje]);

    $this->post('/login', ['email' => 'ana@ramallo.gob.ar', 'password' => 'incorrecta'])
        ->assertInvalid(['email' => $mensaje]);
});

it('audita cada intento fallido, y registra el motivo sin contárselo al cliente', function () {
    usuarioActivo();

    $this->post('/login', ['email' => 'ana@ramallo.gob.ar', 'password' => 'incorrecta']);
    $this->post('/login', ['email' => 'nadie@ramallo.gob.ar', 'password' => 'incorrecta']);

    $eventos = AuditEvent::query()->where('action', 'auth.login.failed')->get();

    expect($eventos)->toHaveCount(2)
        ->and($eventos->every(fn ($e) => $e->is_failed_attempt === true))->toBeTrue()
        ->and($eventos->pluck('metadata_json.motivo')->all())
        ->toBe(['contrasena_incorrecta', 'usuario_inexistente']);
});

it('no deja entrar a un usuario desactivado y lo audita como tal', function () {
    usuarioActivo(['is_active' => false]);

    $this->post('/login', [
        'email' => 'ana@ramallo.gob.ar',
        'password' => 'una-contrasena-de-doce-o-mas',
    ]);

    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'auth.login.failed')->sole()->metadata_json['motivo'])
        ->toBe('usuario_inactivo');
});

it('responde 429 tras agotar el límite por minuto', function () {
    usuarioActivo();
    $limite = (int) config('auth.login_throttle.per_minute');

    for ($i = 0; $i < $limite; $i++) {
        $this->post('/login', ['email' => 'ana@ramallo.gob.ar', 'password' => 'incorrecta']);
    }

    // Con el límite agotado, hasta la contraseña CORRECTA se rechaza: si no,
    // el límite no serviría de nada.
    $this->post('/login', [
        'email' => 'ana@ramallo.gob.ar',
        'password' => 'una-contrasena-de-doce-o-mas',
    ])->assertStatus(429);

    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'auth.login.rate_limited')->count())->toBe(1);
});

it('limpia el contador al ingresar bien', function () {
    usuarioActivo();

    $this->post('/login', ['email' => 'ana@ramallo.gob.ar', 'password' => 'incorrecta']);
    $this->post('/login', [
        'email' => 'ana@ramallo.gob.ar',
        'password' => 'una-contrasena-de-doce-o-mas',
    ]);

    expect(RateLimiter::attempts('login:ana@ramallo.gob.ar|127.0.0.1'))->toBe(0);
});

it('audita el cierre de sesión por el camino transaccional', function () {
    $user = usuarioActivo();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'auth.logout')->sole()->is_failed_attempt)
        ->toBeFalse();
});

it('aplica las cabeceras de seguridad en toda respuesta web', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        // ORS y Nominatim se consultan desde el backend: el navegador no necesita
        // alcanzarlos, y así la clave de ORS no sale del servidor.
        ->toContain("connect-src 'self'");
});
