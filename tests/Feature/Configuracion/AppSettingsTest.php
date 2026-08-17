<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings\AppSettings;

/*
| Configuración tipada (RF-CFG-001/002/003/005).
|
| Lo que se fija acá no es «que se pueda guardar una opción», sino las tres
| propiedades que la hacen segura: no hay claves libres, el rango se valida del
| lado del servidor, y ningún secreto es editable desde la interfaz.
*/

it('devuelve el valor por omisión cuando nunca se guardó nada', function () {
    expect(AppSettings::get(AppSettings::DEFAULT_THEME))->toBe('light')
        ->and(AppSettings::get(AppSettings::SESSION_IDLE_MINUTES))->toBe(480); // 8 h, RF-AUT-006
});

it('devuelve enteros como enteros, no como texto', function () {
    AppSettings::set(AppSettings::LIVE_TOUR_SECONDS, 20);

    // La fila guarda texto —es una tabla clave/valor—, así que si el casteo de
    // vuelta se pierde, una comparación numérica en LIVE empezaría a comparar
    // cadenas y «100» sería menor que «20».
    expect(AppSettings::get(AppSettings::LIVE_TOUR_SECONDS))->toBe(20)
        ->and(AppSettings::all()[AppSettings::LIVE_TOUR_SECONDS])->toBe(20);
});

it('rechaza una clave que no está declarada', function () {
    expect(fn () => AppSettings::set('clave_inventada', 'lo que sea'))
        ->toThrow(InvalidArgumentException::class);

    expect(AppSetting::query()->count())->toBe(0);
});

it('rechaza un valor fuera de rango', function () {
    // El máximo de 30 s no es decorativo: es lo que sostiene el presupuesto de
    // propagación de 30 s de RF-BO-010 para LIVE.
    expect(fn () => AppSettings::set(AppSettings::LIVE_POLL_SECONDS, 45))
        ->toThrow(InvalidArgumentException::class);

    expect(AppSettings::get(AppSettings::LIVE_POLL_SECONDS))->toBe(15);
});

it('rechaza un valor que no es del tipo declarado', function () {
    expect(fn () => AppSettings::set(AppSettings::LIVE_POLL_SECONDS, 'quince'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => AppSettings::set(AppSettings::DEFAULT_THEME, 'system'))
        ->toThrow(InvalidArgumentException::class);
});

it('no declara ninguna opción que parezca un secreto', function () {
    // RF-CFG-003: claves de API, contraseñas y credenciales se inyectan por
    // entorno. Este test es una red: si mañana alguien agrega un campo para la
    // clave de un proveedor de mapas, falla acá antes de llegar a producción.
    $sospechosas = ['password', 'contrasena', 'secret', 'token', 'api_key', 'apikey', 'credential', 'clave'];

    foreach (array_keys(AppSettings::definitions()) as $clave) {
        foreach ($sospechosas as $palabra) {
            expect(str_contains($clave, $palabra))->toBeFalse(
                "La opción «{$clave}» parece un secreto y los secretos no se editan desde la interfaz.",
            );
        }
    }
});

it('devuelve el valor anterior al guardar, que es lo que la auditoría necesita', function () {
    AppSettings::set(AppSettings::MAX_PHOTO_MB, 8);

    expect(AppSettings::set(AppSettings::MAX_PHOTO_MB, 5))->toBe(8);
});

it('guarda la configuración y la audita con antes y después', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/configuracion', [
        'valores' => [
            AppSettings::DEFAULT_THEME => 'dark',
            AppSettings::LIVE_TOUR_SECONDS => 20,
        ],
    ])->assertRedirect();

    expect(AppSettings::get(AppSettings::DEFAULT_THEME))->toBe('dark')
        ->and(AppSettings::get(AppSettings::LIVE_TOUR_SECONDS))->toBe(20);

    // Un solo evento para todo el formulario: el usuario vivió una sola acción
    // (RF-CFG-002), y partirla en ocho eventos haría ilegible la bitácora.
    $evento = AuditEvent::query()->where('action', 'settings.updated')->sole();

    expect($evento->before_json[AppSettings::DEFAULT_THEME])->toBe('light')
        ->and($evento->after_json[AppSettings::DEFAULT_THEME])->toBe('dark')
        ->and($evento->before_json[AppSettings::LIVE_TOUR_SECONDS])->toBe(12)
        ->and($evento->after_json[AppSettings::LIVE_TOUR_SECONDS])->toBe(20)
        ->and($evento->actor_email)->toBe($admin->email);
});

it('no guarda nada si alguna opción del formulario es inválida', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/configuracion', [
        'valores' => [
            AppSettings::DEFAULT_THEME => 'dark',
            AppSettings::LIVE_POLL_SECONDS => 999,
        ],
    ])->assertSessionHasErrors('valores.'.AppSettings::LIVE_POLL_SECONDS);

    // La transacción se revierte entera: guardar la mitad de un formulario
    // dejaría al usuario creyendo que no cambió nada cuando sí cambió algo.
    expect(AppSettings::get(AppSettings::DEFAULT_THEME))->toBe('light')
        ->and(AppSettings::get(AppSettings::LIVE_POLL_SECONDS))->toBe(15)
        ->and(AuditEvent::query()->where('action', 'settings.updated')->count())->toBe(0);
});

it('rechaza el formulario entero si trae una clave desconocida', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/configuracion', [
        'valores' => [
            AppSettings::DEFAULT_THEME => 'dark',
            'clave_inventada' => 'x',
        ],
    ])->assertSessionHasErrors('valores');

    expect(AppSettings::get(AppSettings::DEFAULT_THEME))->toBe('light');
});
