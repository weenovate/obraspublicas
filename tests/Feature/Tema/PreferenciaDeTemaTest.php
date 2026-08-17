<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings\AppSettings;

/*
| CA-025 · Tema por usuario, del lado del servidor.
|
|   Dado: un usuario que eligió tema oscuro
|   Cuando: ingresa desde otro navegador
|   Entonces: ve el tema oscuro, sin haberlo elegido de nuevo.
|
| La mitad visible de este criterio se verifica en Playwright con dos contextos
| de navegador. Lo que se fija acá es la otra mitad, que es la que lo hace
| posible: la preferencia vive en la BASE, no en `localStorage`, y el servidor la
| estampa en el HTML antes de la primera pintura.
|
| Se prueba con dos sesiones HTTP distintas —dos `actingAs` separados, sin estado
| compartido entre ellos— porque un test que reusara la misma sesión pasaría
| igual con la preferencia guardada en el cliente, que es justo lo que no se
| quiere.
*/

it('estampa el tema elegido en el HTML, antes de que corra JavaScript', function () {
    $user = User::factory()->create(['theme_preference' => 'dark']);

    $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('data-theme="dark"');
});

it('aplica la preferencia guardada en una sesión de navegador distinta', function () {
    $user = User::factory()->create(['theme_preference' => 'dark']);

    // Primera visita: nada guardado del lado del cliente todavía.
    $this->actingAs($user)->get('/admin')->assertOk();

    // Segunda «visita» desde cero: sesión nueva, sin cookies ni almacenamiento
    // heredado. Si el tema viviera en el navegador, acá volvería a claro.
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $html = $this->actingAs($user->fresh())->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('data-theme="dark"');
});

it('usa el predeterminado configurable cuando el usuario no eligió', function () {
    // RF-CFG-005: la preferencia vacía no significa «seguir al dispositivo» en el
    // backoffice, significa «usar el tema predeterminado configurado».
    AppSettings::set(AppSettings::DEFAULT_THEME, 'dark');

    $user = User::factory()->create(['theme_preference' => null]);

    expect(HandleInertiaRequests::themeFor($user))->toBe('dark');

    $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('data-theme="dark"');
});

it('la elección del usuario le gana al predeterminado', function () {
    AppSettings::set(AppSettings::DEFAULT_THEME, 'dark');

    $user = User::factory()->create(['theme_preference' => 'light']);

    expect(HandleInertiaRequests::themeFor($user))->toBe('light');
});

it('aplica el predeterminado también cuando no hay nadie con sesión', function () {
    // La pantalla de ingreso no tiene usuario, y sin embargo tiene que verse en
    // el tema que la Municipalidad configuró: es lo primero que ve cualquiera.
    AppSettings::set(AppSettings::DEFAULT_THEME, 'dark');

    expect(HandleInertiaRequests::themeFor(null))->toBe('dark')
        ->and($this->get('/login')->getContent())->toContain('data-theme="dark"');
});

it('no estampa el tema donde manda el dispositivo', function () {
    // La página de referencia del RDS existe para revisar los TRES estados del
    // tema, y el tercero —«sin elección», que sigue al sistema operativo— sólo se
    // puede ver con el atributo ausente. La Web pública de F4 entra por la misma
    // puerta (RF-THE-001): se agrega su nombre de ruta a la lista y ya.
    $html = $this->get('/referencia-rds')->assertOk()->getContent();

    expect($html)->not->toContain('data-theme=')
        // Vacío no es lo mismo que ausente: con `data-theme=""` el selector del
        // tema oscuro por preferencia del dispositivo dejaría de aplicar.
        ->and($html)->not->toContain('data-theme=""');
});

it('guarda la preferencia desde el perfil y la audita', function () {
    $user = User::factory()->create(['theme_preference' => null]);

    $this->actingAs($user)
        ->put('/perfil', ['name' => $user->name, 'theme_preference' => 'dark'])
        ->assertRedirect();

    expect($user->fresh()->theme_preference)->toBe('dark');

    $evento = AuditEvent::query()->where('action', 'user.profile_updated')->sole();

    expect($evento->before_json['theme_preference'])->toBeNull()
        ->and($evento->after_json['theme_preference'])->toBe('dark');
});

it('permite volver a «sin preferencia» y entonces vuelve a mandar el predeterminado', function () {
    AppSettings::set(AppSettings::DEFAULT_THEME, 'light');
    $user = User::factory()->create(['theme_preference' => 'dark']);

    $this->actingAs($user)
        ->put('/perfil', ['name' => $user->name, 'theme_preference' => null])
        ->assertRedirect();

    $user = $user->fresh();

    expect($user->theme_preference)->toBeNull()
        ->and(HandleInertiaRequests::themeFor($user))->toBe('light');
});

it('rechaza una preferencia que no es ninguno de los dos temas', function () {
    // `system` era válido antes de alinear con el spec: RF-CFG-004 define LIGHT o
    // DARK, y el respaldo es el predeterminado configurable, no el dispositivo.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/perfil', ['name' => $user->name, 'theme_preference' => 'system'])
        ->assertSessionHasErrors('theme_preference');

    expect($user->fresh()->theme_preference)->toBeNull();
});

it('comparte la preferencia y el tema efectivo por separado', function () {
    // Son dos cosas distintas y la interfaz necesita las dos: la preferencia para
    // marcar la opción elegida en el selector, el efectivo para pintar.
    AppSettings::set(AppSettings::DEFAULT_THEME, 'dark');
    $user = User::factory()->create(['theme_preference' => null]);

    $compartido = app(HandleInertiaRequests::class)->share(
        tap(request(), fn ($r) => $r->setUserResolver(fn () => $user)),
    );

    expect($compartido['theme']['preferencia'])->toBeNull()
        ->and($compartido['theme']['efectivo'])->toBe('dark')
        ->and($compartido['theme']['predeterminado'])->toBe('dark');
});
