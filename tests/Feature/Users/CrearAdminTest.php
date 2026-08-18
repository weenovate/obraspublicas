<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Users\UserManager;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| El comando que crea el primer Administrador (RF-AUT-002).
|
| Existe fuera de la interfaz porque cuando se corre no hay todavía nadie que
| pueda entrar a crearlo. La contraseña NUNCA se pasa como argumento: quedaría en
| el historial del shell y en la lista de procesos.
*/

beforeEach(function () {
    // Se fija por configuración y no por variable de entorno del proceso: es el
    // camino de los despliegues desatendidos, y además evita que el comando caiga
    // al prompt oculto, que en un test bloquea esperando entrada para siempre.
    config(['obras.admin_initial_password' => 'una-contrasena-de-doce-o-mas']);
});

it('crea el primer Administrador con la contraseña de despliegue desatendido', function () {
    $this->artisan('obras:crear-admin', [
        '--email' => 'admina@ramallo.gob.ar',
        '--name' => 'Administradora',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'admina@ramallo.gob.ar')->sole();

    expect($user->role)->toBe(User::ROLE_ADMIN)
        ->and($user->is_active)->toBeTrue();
});

it('rechaza un correo repetido con un mensaje legible', function () {
    User::factory()->create(['email' => 'ocupado@ramallo.gob.ar']);

    $this->artisan('obras:crear-admin', [
        '--email' => 'ocupado@ramallo.gob.ar',
        '--name' => 'Otra',
        '--no-interaction' => true,
    ])->expectsOutputToContain('ya está en uso')->assertFailed();
});

it('deja que el índice único cierre la carrera, y no la validación', function () {
    // La validación `unique` comprueba y después inserta, y entre las dos cosas
    // hay una ventana: dos ejecuciones simultáneas la pasan las dos. Lo que
    // realmente impide el duplicado es el índice de la base, y esto lo
    // comprueba: con la fila ya puesta, el alta lanza la excepción de
    // integridad. El comando la traduce al mismo mensaje que da la validación.
    //
    // Se verifica contra la base y no con un doble: el punto es que el índice
    // existe y actúa, no que una clase falsa lance lo que se le pidió lanzar.
    User::factory()->create(['email' => 'carrera@ramallo.gob.ar']);

    expect(fn () => app(UserManager::class)->create(
        name: 'La que perdió',
        email: 'carrera@ramallo.gob.ar',
        role: User::ROLE_ADMIN,
        temporaryPassword: 'una-contrasena-de-doce-o-mas',
    ))->toThrow(UniqueConstraintViolationException::class);
});
