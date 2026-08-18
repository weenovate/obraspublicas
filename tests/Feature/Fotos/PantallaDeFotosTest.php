<?php

declare(strict_types=1);

use App\Jobs\ProcessWorkPhoto;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPhoto;
use App\Support\Photos\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
| F2 · Las fotos por HTTP.
|
| El foco está en lo que sólo se ve pasando por la capa web: que el archivo NO
| sea alcanzable sin firma, que una foto que no llegó a READY no se sirva, y que
| el parámetro de tamaño no se pueda usar para pedir un archivo cualquiera.
*/

beforeEach(function () {
    Storage::fake('local');
});

function usuarioCargador(): User
{
    return User::factory()->create(['role' => 'OBRAS_PUBLICAS', 'must_change_password' => false]);
}

function fotoListaDe(Work $work): WorkPhoto
{
    $foto = WorkPhoto::factory()->for($work)->create();

    Storage::disk('local')->put(
        $foto->path_original,
        (string) UploadedFile::fake()->image('x.jpg', 900, 600)->getContent(),
    );

    app(PhotoProcessor::class)->procesar($foto);

    return $foto->refresh();
}

/*
|---------------------------------------------------------------------------
| Subir
|---------------------------------------------------------------------------
*/

it('sube una foto desde la pantalla de la obra', function () {
    Queue::fake();

    $work = Work::factory()->create();

    $this->actingAs(usuarioCargador())
        ->post("/obras/{$work->getKey()}/fotos", ['foto' => UploadedFile::fake()->image('obra.jpg', 1200, 900)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($work->photos()->count())->toBe(1)
        ->and($work->photos()->sole()->status)->toBe(WorkPhoto::STATUS_PENDING);

    Queue::assertPushed(ProcessWorkPhoto::class);
});

it('traduce la regla de negocio a un error de formulario, no a un 500', function () {
    Queue::fake();

    $work = Work::factory()->create();

    $this->actingAs(usuarioCargador())
        ->post("/obras/{$work->getKey()}/fotos", [
            'foto' => UploadedFile::fake()->create('planilla.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasErrors('foto');

    expect(WorkPhoto::query()->count())->toBe(0);
});

it('no deja subir fotos a quien no inició sesión', function () {
    $work = Work::factory()->create();

    $this->post("/obras/{$work->getKey()}/fotos", ['foto' => UploadedFile::fake()->image('x.jpg')])
        ->assertRedirect('/login');
});

/*
|---------------------------------------------------------------------------
| Servir el archivo: lo que protege RNF-SEC-005
|---------------------------------------------------------------------------
*/

it('sirve el derivado sólo con la URL firmada', function () {
    $foto = fotoListaDe(Work::factory()->create());

    $firmada = URL::signedRoute('fotos.ver', ['photo' => $foto->getKey(), 'tamano' => 'thumb']);

    $this->get($firmada)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('rechaza la misma URL sin firma, aunque haya sesión iniciada', function () {
    $foto = fotoListaDe(Work::factory()->create());

    // Lo que autoriza es la firma, no la cookie: así la misma ruta sirve a la
    // web pública de F4 sin abrir el directorio de archivos.
    $this->actingAs(usuarioCargador())
        ->get("/fotos/{$foto->getKey()}/thumb")
        ->assertForbidden();
});

it('rechaza una firma manipulada', function () {
    $foto = fotoListaDe(Work::factory()->create());
    $otra = fotoListaDe(Work::factory()->create());

    $firmada = URL::signedRoute('fotos.ver', ['photo' => $foto->getKey(), 'tamano' => 'thumb']);

    // Cambiar el identificador conservando la firma: es el ataque que la firma
    // existe para impedir.
    $manipulada = str_replace(
        "/fotos/{$foto->getKey()}/",
        "/fotos/{$otra->getKey()}/",
        $firmada,
    );

    $this->get($manipulada)->assertForbidden();
});

it('no sirve una foto que todavía no está lista', function () {
    // PENDING y FAILED no se publican nunca (ADR-019). Que la URL esté bien
    // firmada no cambia eso.
    $pendiente = WorkPhoto::factory()->create();

    $this->get(URL::signedRoute('fotos.ver', ['photo' => $pendiente->getKey(), 'tamano' => 'thumb']))
        ->assertNotFound();
});

it('no acepta un tamaño que no sea uno de los dos derivados', function () {
    $foto = fotoListaDe(Work::factory()->create());

    // La restricción está en la ruta, así que ni siquiera llega al controlador:
    // el parámetro no puede usarse para pedir un archivo arbitrario.
    $this->get(URL::signedRoute('fotos.ver', ['photo' => $foto->getKey(), 'tamano' => 'original']))
        ->assertNotFound();
});

/*
|---------------------------------------------------------------------------
| Reintentar y dar de baja
|---------------------------------------------------------------------------
*/

it('reintenta una foto fallida y la vuelve a encolar', function () {
    Queue::fake();

    $foto = WorkPhoto::factory()->fallida()->create();

    $this->actingAs(usuarioCargador())
        ->post("/fotos/{$foto->getKey()}/reintentar")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $foto->refresh();

    expect($foto->status)->toBe(WorkPhoto::STATUS_PENDING)
        ->and($foto->failure_reason)->toBeNull();

    Queue::assertPushed(ProcessWorkPhoto::class);
    expect(AuditEvent::query()->where('action', 'work.photo.retried')->count())->toBe(1);
});

it('no reintenta una foto que no falló', function () {
    Queue::fake();

    $foto = WorkPhoto::factory()->lista()->create();

    $this->actingAs(usuarioCargador())
        ->post("/fotos/{$foto->getKey()}/reintentar")
        ->assertSessionHasErrors('foto');

    Queue::assertNothingPushed();
});

it('da de baja la foto dejando quién la quitó', function () {
    $foto = WorkPhoto::factory()->lista()->create();
    $actor = usuarioCargador();

    $this->actingAs($actor)->delete("/fotos/{$foto->getKey()}")->assertRedirect();

    expect(WorkPhoto::query()->count())->toBe(0)
        ->and(WorkPhoto::withTrashed()->count())->toBe(1);

    $fila = WorkPhoto::withTrashed()->sole();

    expect($fila->deleted_at)->not->toBeNull()
        ->and((int) $fila->deleted_by)->toBe($actor->getKey());

    expect(AuditEvent::query()->where('action', 'work.photo.trashed')->count())->toBe(1);
});

it('la foto dada de baja deja de contar y deja de publicarse', function () {
    $work = Work::factory()->create();
    $foto = WorkPhoto::factory()->lista()->for($work)->create();

    $this->actingAs(usuarioCargador())->delete("/fotos/{$foto->getKey()}");

    expect($work->photos()->count())->toBe(0)
        ->and($work->publishedPhotos()->count())->toBe(0);
});
