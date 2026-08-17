<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Work;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use Illuminate\Support\Facades\DB;

/*
| F1-B · Las pantallas de obras.
|
| Acá se verifica lo que sólo se ve pasando por HTTP: que los dos roles puedan
| cargar obras —es lo que dice la matriz de permisos del spec, y una obra que
| sólo pudiera cargar el Admin haría inútil al otro rol—, que las excepciones de
| dominio lleguen como errores de validación y no como un 500, y que la geometría
| dé la vuelta completa —editor, base, editor— sin invertirse.
*/

function usuarioDeObras(): User
{
    return User::factory()->create(['role' => 'OBRAS_PUBLICAS', 'must_change_password' => false]);
}

function administrador(): User
{
    return User::factory()->create(['role' => 'ADMIN', 'must_change_password' => false]);
}

function subcategoriaPunto(): WorkSubcategory
{
    return WorkSubcategory::factory()->create([
        'geometry_mode' => WorkSubcategory::MODE_POINT,
        'is_active' => true,
    ]);
}

function estadoActivo(bool $finaliza = false): WorkStatus
{
    // Reutiliza el existente: la clave es única y varias pruebas piden el mismo
    // estado más de una vez. Va por la factoría porque `key` e `is_final` no son
    // asignables en masa, a propósito.
    $clave = $finaliza ? WorkStatus::KEY_COMPLETED : WorkStatus::KEY_IN_PROGRESS;

    return WorkStatus::query()->firstWhere('key', $clave)
        ?? WorkStatus::factory()->create([
            'key' => $clave,
            'label' => $finaliza ? 'Finalizada' : 'En ejecución',
            'is_final' => $finaliza,
            'is_active' => true,
        ]);
}

/** @return array<string, mixed> */
function formularioDeObra(array $extra = []): array
{
    return array_merge([
        'name' => 'Repavimentación de Belgrano',
        'work_subcategory_id' => subcategoriaPunto()->getKey(),
        'work_status_id' => estadoActivo()->getKey(),
        'start_date' => '2026-02-01',
        'estimated_end_date' => '2026-08-01',
        'geometria' => ['type' => 'Point', 'coordinates' => config('obras.mapa.centro')],
    ], $extra);
}

it('deja cargar una obra a los dos roles', function (callable $comoQuien) {
    // La matriz de permisos del spec 2.2: crear y editar obras no es exclusivo
    // del Administrador. Si lo fuera, el rol Obras Públicas no serviría para nada.
    $respuesta = $this->actingAs($comoQuien())->post('/obras', formularioDeObra());

    $respuesta->assertRedirect();

    expect(Work::query()->count())->toBe(1);
})->with([
    'obras públicas' => [fn () => usuarioDeObras(...)()],
    'administrador' => [fn () => administrador(...)()],
]);

it('muestra el listado con la obra recién creada', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());

    $this->actingAs(usuarioDeObras())->get('/obras')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('Obras/Index')
            ->has('obras.data', 1)
            ->where('obras.data.0.name', 'Repavimentación de Belgrano'));
});

it('filtra el listado por texto, estado y subcategoría', function () {
    $usuario = usuarioDeObras();
    $enCurso = estadoActivo();
    $subcategoria = subcategoriaPunto();
    $otra = subcategoriaPunto();

    $this->actingAs($usuario)->post('/obras', formularioDeObra([
        'name' => 'Cloacas del barrio norte',
        'work_status_id' => $enCurso->getKey(),
        'work_subcategory_id' => $subcategoria->getKey(),
    ]));
    $this->actingAs($usuario)->post('/obras', formularioDeObra([
        'name' => 'Plaza central',
        'work_status_id' => $enCurso->getKey(),
        'work_subcategory_id' => $otra->getKey(),
    ]));

    $this->actingAs($usuario)->get('/obras?buscar=Cloacas')
        ->assertInertia(fn ($p) => $p->has('obras.data', 1)->where('obras.data.0.name', 'Cloacas del barrio norte'));

    $this->actingAs($usuario)->get('/obras?subcategoria='.$otra->getKey())
        ->assertInertia(fn ($p) => $p->has('obras.data', 1)->where('obras.data.0.name', 'Plaza central'));

    $this->actingAs($usuario)->get('/obras?estado='.$enCurso->getKey())
        ->assertInertia(fn ($p) => $p->has('obras.data', 2));
});

it('busca también por código', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());
    $codigo = Work::query()->sole()->code;

    $this->actingAs(usuarioDeObras())->get('/obras?buscar='.$codigo)
        ->assertInertia(fn ($p) => $p->has('obras.data', 1));
});

/*
|---------------------------------------------------------------------------
| La geometría, ida y vuelta
|---------------------------------------------------------------------------
*/

it('devuelve al editor la misma geometría que se dibujó, sin invertir los ejes', function () {
    $subcategoria = WorkSubcategory::factory()->create([
        'geometry_mode' => WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
        'is_active' => true,
    ]);

    $recorrido = [[-60.0575, -33.5872], [-60.0525, -33.5822], [-60.0475, -33.5772]];

    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra([
        'work_subcategory_id' => $subcategoria->getKey(),
        'geometria' => ['type' => 'LineString', 'coordinates' => $recorrido],
    ]));

    $obra = Work::query()->sole();

    // La vuelta completa: lo que el editor mandó es lo que el editor recibe. Con
    // los ejes invertidos en cualquiera de los dos tramos, esto falla; con los
    // dos invertidos, también, porque los valores son asimétricos a propósito.
    $this->actingAs(usuarioDeObras())->get("/obras/{$obra->getKey()}/editar")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Obras/Formulario')
            ->where('obra.geometria.type', 'LineString')
            ->where('obra.geometria.coordinates', $recorrido));
});

it('sirve el contorno del partido con su validador', function () {
    $respuesta = $this->actingAs(usuarioDeObras())->get('/mapa/partido.geojson');

    $respuesta->assertOk()->assertHeader('Content-Type', 'application/geo+json');

    $geojson = json_decode($respuesta->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($geojson['features'][0]['properties']['nam'])->toBe('Ramallo');

    // Y no se vuelve a mandar si el navegador ya lo tiene: son 58 kB fijos.
    $this->actingAs(usuarioDeObras())
        ->withHeaders(['If-None-Match' => $respuesta->headers->get('ETag')])
        ->get('/mapa/partido.geojson')
        ->assertStatus(304);
});

it('no sirve el contorno a quien no inició sesión', function () {
    $this->get('/mapa/partido.geojson')->assertRedirect('/login');
});

/*
|---------------------------------------------------------------------------
| Las reglas, traducidas a errores de formulario
|---------------------------------------------------------------------------
*/

it('devuelve un error de validación, y no un 500, cuando la forma no coincide', function () {
    $this->actingAs(usuarioDeObras())
        ->post('/obras', formularioDeObra([
            'geometria' => ['type' => 'Polygon', 'coordinates' => [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.06, -33.59]]]],
        ]))
        ->assertSessionHasErrors('geometria');

    expect(Work::withTrashed()->count())->toBe(0);
});

it('devuelve un error de validación cuando falta la fecha real de un estado finalizador', function () {
    $this->actingAs(usuarioDeObras())
        ->post('/obras', formularioDeObra(['work_status_id' => estadoActivo(finaliza: true)->getKey()]))
        ->assertSessionHasErrors('fechas');
});

it('exige geometría en el alta', function () {
    $datos = formularioDeObra();
    unset($datos['geometria']);

    $this->actingAs(usuarioDeObras())->post('/obras', $datos)->assertSessionHasErrors('geometria');
});

it('deja editar los datos sin volver a mandar la geometría', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());
    $obra = Work::query()->sole();

    $this->actingAs(usuarioDeObras())->put("/obras/{$obra->getKey()}", [
        'name' => 'Nombre corregido',
        'work_subcategory_id' => $obra->work_subcategory_id,
        'work_status_id' => $obra->work_status_id,
        'start_date' => '2026-02-01',
        'estimated_end_date' => '2026-09-01',
        'lock_version' => $obra->lock_version,
    ])->assertRedirect();

    $obra->refresh();

    expect($obra->name)->toBe('Nombre corregido')
        ->and($obra->lock_version)->toBe(1);

    // Y la geometría sigue ahí: no mandarla significa «no la toqué», no «borrala».
    $dentro = DB::selectOne(
        'SELECT ST_Contains(geometry, representative_point) AS r FROM works WHERE id = ?',
        [$obra->getKey()],
    )->r;

    expect((bool) $dentro)->toBeTrue();
});

it('avisa del conflicto en lugar de pisar la edición de otra persona', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());
    $obra = Work::query()->sole();
    $version = $obra->lock_version;

    $editar = fn (string $nombre) => $this->actingAs(usuarioDeObras())->put("/obras/{$obra->getKey()}", [
        'name' => $nombre,
        'work_subcategory_id' => $obra->work_subcategory_id,
        'work_status_id' => $obra->work_status_id,
        'start_date' => '2026-02-01',
        'estimated_end_date' => '2026-08-01',
        'lock_version' => $version,
    ]);

    $editar('Nombre de la primera')->assertSessionHasNoErrors();

    // La segunda llega con la versión vieja: recibe el aviso y NO pisa.
    $editar('Nombre de la segunda')->assertSessionHasErrors('lock_version');

    expect($obra->refresh()->name)->toBe('Nombre de la primera');
});

/*
|---------------------------------------------------------------------------
| Papelera
|---------------------------------------------------------------------------
*/

it('manda la obra a la papelera y deja de listarla', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());
    $obra = Work::query()->sole();

    $this->actingAs(usuarioDeObras())
        ->delete("/obras/{$obra->getKey()}", ['lock_version' => $obra->lock_version])
        ->assertRedirect('/obras');

    $this->actingAs(usuarioDeObras())->get('/obras')
        ->assertInertia(fn ($p) => $p->has('obras.data', 0));

    expect(Work::withTrashed()->count())->toBe(1);
});

it('no deja abrir una obra que está en la papelera', function () {
    $this->actingAs(usuarioDeObras())->post('/obras', formularioDeObra());
    $obra = Work::query()->sole();

    $this->actingAs(usuarioDeObras())->delete("/obras/{$obra->getKey()}", ['lock_version' => 0]);

    // El binding de ruta no resuelve las borradas: 404, que es lo correcto.
    // Restaurarlas es de F6 y va con la política de papelera.
    $this->actingAs(usuarioDeObras())->get("/obras/{$obra->getKey()}/editar")->assertNotFound();
});

it('no deja entrar a nadie sin sesión', function () {
    $this->get('/obras')->assertRedirect('/login');
    $this->post('/obras', formularioDeObra())->assertRedirect('/login');
});
