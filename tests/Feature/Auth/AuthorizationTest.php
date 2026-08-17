<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use App\Policies\AdminPolicy;

/*
| CA-014 · Autorización
|
|   Dado: un usuario Obras Públicas
|   Cuando: intenta acceder por URL/API a usuarios o configuración
|   Entonces: recibe 403 y el intento queda registrado sin exponer datos.
|
| Las tres partes del «entonces» importan por igual, y la tercera es la que más
| fácil se pasa por alto: una bitácora que copia lo que el usuario no tenía
| permiso de ver convierte el registro de seguridad en la filtración que quería
| evitar.
*/

/** @return array<string, string> ruta => nombre legible */
function rutasAdministrativas(): array
{
    return [
        '/admin/usuarios' => 'usuarios',
        '/admin/categorias' => 'categorías',
        '/admin/subcategorias' => 'subcategorías',
        '/admin/estados' => 'estados',
        '/admin/campos' => 'campos técnicos',
        '/admin/configuracion' => 'configuración',
    ];
}

it('devuelve 403 a un usuario Obras Públicas en cada ruta administrativa', function (string $ruta) {
    $user = User::factory()->create(['role' => User::ROLE_OBRAS_PUBLICAS]);

    $this->actingAs($user)->get($ruta)->assertForbidden();
})->with(array_keys(rutasAdministrativas()));

it('deja pasar al Administrador por las mismas rutas', function (string $ruta) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get($ruta)->assertOk();
})->with(array_keys(rutasAdministrativas()));

it('registra cada intento denegado', function () {
    $user = User::factory()->create(['role' => User::ROLE_OBRAS_PUBLICAS]);

    $this->actingAs($user)->get('/admin/usuarios')->assertForbidden();

    $evento = AuditEvent::query()->where('action', 'authz.denied')->sole();

    expect($evento->is_failed_attempt)->toBeTrue()
        ->and($evento->actor_email)->toBe($user->email)
        ->and($evento->actor_role)->toBe(User::ROLE_OBRAS_PUBLICAS)
        ->and($evento->metadata_json['ruta'])->toBe('/admin/usuarios')
        ->and($evento->metadata_json['metodo'])->toBe('GET');
});

it('no expone datos en el evento de denegación', function () {
    $user = User::factory()->create(['role' => User::ROLE_OBRAS_PUBLICAS]);

    // Se intenta crear un usuario con datos reconocibles: si alguno apareciera en
    // la bitácora, el registro de seguridad estaría filtrando lo que impidió ver.
    $this->actingAs($user)->post('/admin/usuarios', [
        'name' => 'NOMBRE-QUE-NO-DEBE-QUEDAR',
        'email' => 'correo-que-no-debe-quedar@ramallo.gob.ar',
        'role' => User::ROLE_ADMIN,
        'password' => 'una-contrasena-de-doce-o-mas',
    ])->assertForbidden();

    $evento = AuditEvent::query()->where('action', 'authz.denied')->sole();
    $serializado = json_encode($evento->toArray(), JSON_UNESCAPED_UNICODE);

    expect($serializado)
        ->not->toContain('NOMBRE-QUE-NO-DEBE-QUEDAR')
        ->not->toContain('correo-que-no-debe-quedar')
        ->not->toContain('una-contrasena-de-doce-o-mas')
        // Tampoco el cuerpo ni la cadena de consulta: ahí es donde viajarían.
        ->and($evento->before_json)->toBeNull()
        ->and($evento->after_json)->toBeNull();
});

it('la respuesta denegada no filtra el contenido protegido', function () {
    User::factory()->admin()->create(['name' => 'Administradora Secreta']);
    $user = User::factory()->create(['role' => User::ROLE_OBRAS_PUBLICAS]);

    $respuesta = $this->actingAs($user)->get('/admin/usuarios');

    $respuesta->assertForbidden();
    expect($respuesta->getContent())->not->toContain('Administradora Secreta');
});

it('un usuario desactivado no conserva permisos administrativos', function () {
    // La sesión podría seguir abierta; la política es la segunda línea después
    // del middleware que la corta.
    $admin = User::factory()->admin()->inactivo()->create();

    expect(AdminPolicy::permite($admin))->toBeFalse();
});
