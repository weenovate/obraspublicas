<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;

/*
| Los mensajes de validación llegan traducidos.
|
| La aplicación corre con `APP_LOCALE=es` y Laravel sólo trae el juego de
| mensajes en inglés: sin `lang/es/` cada formulario responde con la CLAVE del
| mensaje —«validation.required»— en lugar del texto. Es el tipo de defecto que
| no rompe ningún test de comportamiento y sin embargo hace inusable la pantalla.
*/

/**
 * Los errores de la sesión, ya serializados.
 *
 * La sesión guarda la bolsa de errores como arreglo, no como objeto, así que
 * leerla directo devuelve estructuras distintas según por dónde se pasó. Esto
 * normaliza una sola vez.
 */
function bolsaDeErrores(TestResponse $respuesta): MessageBag
{
    $errores = $respuesta->getSession()->get('errors');

    if ($errores instanceof ViewErrorBag) {
        return $errores->getBag('default');
    }

    return new MessageBag($errores['default']['messages'] ?? []);
}

it('devuelve mensajes legibles, no claves de traducción', function () {
    $admin = User::factory()->admin()->create();

    $respuesta = $this->actingAs($admin)->post('/admin/usuarios', [
        'name' => '',
        'email' => 'no-es-un-correo',
        'role' => 'INVENTADO',
        'password' => 'corta',
    ]);

    $respuesta->assertSessionHasErrors(['name', 'email', 'role', 'password']);
    $errores = bolsaDeErrores($respuesta)->all();

    expect($errores)->not->toBeEmpty();

    foreach ($errores as $mensaje) {
        expect($mensaje)->not->toStartWith('validation.')
            ->and($mensaje)->not->toContain('validation.');
    }
});

it('usa los nombres de campo que declara cada formulario', function () {
    // Los controladores pasan su propio `:attribute` porque «contraseña
    // temporal» y «contraseña» son cosas distintas para quien está mirando la
    // pantalla, aunque el campo se llame igual en las dos.
    $admin = User::factory()->admin()->create();

    $errores = bolsaDeErrores($this->actingAs($admin)->post('/admin/usuarios', [
        'name' => '', 'email' => '', 'role' => '', 'password' => '',
    ]));

    expect($errores->first('name'))->toContain('nombre')
        ->and($errores->first('email'))->toContain('correo electrónico')
        ->and($errores->first('password'))->toContain('contraseña temporal');
});

it('traduce también el mínimo de doce caracteres de la contraseña', function () {
    $user = User::factory()->create();

    $errores = bolsaDeErrores($this->actingAs($user)->put('/perfil/password', [
        'current_password' => 'una-contrasena-de-doce-o-mas',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ]));

    // RF-AUT-003: el número tiene que aparecer, porque un mensaje que sólo dice
    // «es muy corta» obliga a adivinar cuánto falta.
    expect($errores->first('password'))->toContain('12');
});
