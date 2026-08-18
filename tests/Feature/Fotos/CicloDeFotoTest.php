<?php

declare(strict_types=1);

use App\Jobs\ProcessWorkPhoto;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPhoto;
use App\Support\Photos\PhotoProcessor;
use App\Support\Photos\PhotoRuleViolation;
use App\Support\Photos\PhotoUploader;
use App\Support\Settings\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| F2 · El ciclo completo de una fotografía (ADR-019).
|
| Lo que se verifica acá no es que se pueda subir un archivo —eso lo hace
| Laravel—, sino las tres propiedades que ADR-019 fijó y que son las que se
| rompen en silencio:
|
|   1. La obra NO espera a la foto. Se publica igual, y la foto se suma cuando
|      llega a READY.
|   2. Una foto que falla no invalida nada ya guardado, y dice por qué falló.
|   3. El job es idempotente: correrlo dos veces deja el mismo resultado.
*/

beforeEach(function () {
    Storage::fake('local');
});

/** Un JPEG de verdad, no un archivo con nombre .jpg: GD tiene que poder leerlo. */
function jpegDePrueba(int $ancho = 2400, int $alto = 1800): UploadedFile
{
    return UploadedFile::fake()->image('obra.jpg', $ancho, $alto);
}

function obraConFoto(?UploadedFile $archivo = null): array
{
    $work = Work::factory()->create();
    $actor = User::factory()->create();

    $foto = app(PhotoUploader::class)->subir($work, $archivo ?? jpegDePrueba(), $actor);

    return [$work, $foto, $actor];
}

/*
|---------------------------------------------------------------------------
| La subida
|---------------------------------------------------------------------------
*/

it('guarda la foto en PENDING y encola su procesamiento', function () {
    Queue::fake();

    [$work, $foto] = obraConFoto();

    expect($foto->status)->toBe(WorkPhoto::STATUS_PENDING)
        ->and($foto->path_large)->toBeNull()
        ->and($foto->path_thumb)->toBeNull()
        ->and($foto->work_id)->toBe($work->getKey());

    // El archivo original está guardado ANTES de que el job corra: si no, el
    // job fallaría por una carrera propia y no por un problema real.
    Storage::disk('local')->assertExists($foto->path_original);

    Queue::assertPushed(ProcessWorkPhoto::class);
});

it('guarda las fotos fuera del alcance público', function () {
    [, $foto] = obraConFoto();

    // `storage/app/private/...`, nunca bajo `public/`. Las fotos se sirven por
    // controlador con URL firmada (RNF-SEC-005), y por eso el despliegue NO
    // corre `storage:link`.
    expect($foto->path_original)->toStartWith('fotos/')
        ->and($foto->path_original)->not->toContain('public');
});

it('audita la subida', function () {
    [, $foto] = obraConFoto();

    $evento = AuditEvent::query()->where('action', 'work.photo.uploaded')->sole();

    expect($evento->entity_id)->toBe($foto->getKey())
        ->and($evento->after_json['status'])->toBe(WorkPhoto::STATUS_PENDING);
});

it('rechaza lo que GD no puede procesar, antes de encolar nada', function () {
    Queue::fake();

    $work = Work::factory()->create();

    expect(fn () => app(PhotoUploader::class)->subir(
        $work,
        UploadedFile::fake()->create('planilla.pdf', 100, 'application/pdf'),
    ))->toThrow(PhotoRuleViolation::class, 'JPG, PNG o WEBP');

    expect(WorkPhoto::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('respeta el máximo de fotos por obra', function () {
    Queue::fake();

    $work = Work::factory()->create();
    app(AppSettings::class)->set(AppSettings::MAX_PHOTOS_PER_WORK, 2);

    app(PhotoUploader::class)->subir($work, jpegDePrueba());
    app(PhotoUploader::class)->subir($work, jpegDePrueba());

    expect(fn () => app(PhotoUploader::class)->subir($work, jpegDePrueba()))
        ->toThrow(PhotoRuleViolation::class, 'máximo');
});

it('respeta el tamaño máximo configurado', function () {
    Queue::fake();

    app(AppSettings::class)->set(AppSettings::MAX_PHOTO_MB, 1);

    $work = Work::factory()->create();
    $grande = UploadedFile::fake()->create('enorme.jpg', 2048, 'image/jpeg');

    expect(fn () => app(PhotoUploader::class)->subir($work, $grande))
        ->toThrow(PhotoRuleViolation::class, '1 MB');
});

/*
|---------------------------------------------------------------------------
| El procesamiento
|---------------------------------------------------------------------------
*/

it('genera los derivados y deja la foto publicable', function () {
    [, $foto] = obraConFoto();

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    expect($foto->status)->toBe(WorkPhoto::STATUS_READY)
        ->and($foto->esPublicable())->toBeTrue()
        ->and($foto->width)->toBe(2400)
        ->and($foto->height)->toBe(1800)
        ->and($foto->processed_at)->not->toBeNull();

    Storage::disk('local')->assertExists($foto->path_large);
    Storage::disk('local')->assertExists($foto->path_thumb);
});

it('achica los derivados sin deformar la foto', function () {
    [, $foto] = obraConFoto(jpegDePrueba(2400, 1200));

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    $medir = fn (string $ruta): array => getimagesizefromstring(
        (string) Storage::disk('local')->get($ruta),
    );

    [$anchoGrande, $altoGrande] = $medir($foto->path_large);
    [$anchoChico] = $medir($foto->path_thumb);

    expect($anchoGrande)->toBe(1600)
        // La proporción original es 2:1 y tiene que conservarse.
        ->and($altoGrande)->toBe(800)
        ->and($anchoChico)->toBe(400);
});

it('no agranda una foto que ya es más chica que el derivado', function () {
    // `scaleDown` y no `resize`: estirar una foto de 300 px a 1600 no agrega
    // información, sólo peso y una imagen borrosa.
    [, $foto] = obraConFoto(jpegDePrueba(300, 200));

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    [$ancho] = getimagesizefromstring((string) Storage::disk('local')->get($foto->path_large));

    expect($ancho)->toBe(300);
});

it('marca FAILED con un motivo legible cuando el archivo no es una imagen', function () {
    [, $foto] = obraConFoto();

    // Se corrompe el original después de subirlo: es el caso realista de un
    // archivo truncado por una conexión que se cortó.
    Storage::disk('local')->put($foto->path_original, 'esto no es una imagen');

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    expect($foto->status)->toBe(WorkPhoto::STATUS_FAILED)
        ->and($foto->esPublicable())->toBeFalse()
        ->and($foto->failure_reason)->toContain('No se pudo procesar')
        // El motivo es de negocio: no filtra rutas del servidor ni trazas.
        ->and($foto->failure_reason)->not->toContain('/home')
        ->and($foto->failure_reason)->not->toContain('Exception');
});

it('marca FAILED si el original desapareció del almacenamiento', function () {
    [, $foto] = obraConFoto();

    Storage::disk('local')->delete($foto->path_original);

    app(PhotoProcessor::class)->procesar($foto);

    expect($foto->refresh()->status)->toBe(WorkPhoto::STATUS_FAILED)
        ->and($foto->failure_reason)->toContain('no está en el almacenamiento');
});

/*
|---------------------------------------------------------------------------
| Idempotencia: la propiedad que hace seguro el reintento
|---------------------------------------------------------------------------
*/

it('procesar dos veces deja el mismo resultado, sin duplicar filas ni archivos', function () {
    [$work, $foto] = obraConFoto();

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    $rutas = [$foto->path_large, $foto->path_thumb];
    $archivosAntes = count(Storage::disk('local')->allFiles());

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();

    expect($foto->status)->toBe(WorkPhoto::STATUS_READY)
        // Las MISMAS rutas: por eso reprocesar sobrescribe en lugar de acumular.
        ->and([$foto->path_large, $foto->path_thumb])->toBe($rutas)
        ->and(Storage::disk('local')->allFiles())->toHaveCount($archivosAntes)
        ->and($work->photos()->count())->toBe(1);
});

it('el job no vuelve a trabajar sobre una foto que ya está lista', function () {
    [, $foto] = obraConFoto();

    app(PhotoProcessor::class)->procesar($foto);
    $foto->refresh();
    $procesadaEn = $foto->processed_at;
    $intentos = $foto->attempts;

    // Un reintento duplicado de la cola: el proceso murió después de trabajar
    // pero antes de confirmar, y el job vuelve a llegar.
    app(ProcessWorkPhoto::class, ['workPhotoId' => $foto->getKey()])
        ->handle(app(PhotoProcessor::class));

    $foto->refresh();

    expect($foto->attempts)->toBe($intentos)
        ->and($foto->processed_at->equalTo($procesadaEn))->toBeTrue();
});

it('el job deja de insistir después del máximo de intentos', function () {
    $foto = WorkPhoto::factory()->agotada()->create();

    app(ProcessWorkPhoto::class, ['workPhotoId' => $foto->getKey()])
        ->handle(app(PhotoProcessor::class));

    expect($foto->refresh()->attempts)->toBe(WorkPhoto::MAX_ATTEMPTS)
        ->and($foto->sePuedeReintentar())->toBeFalse();
});

it('el job no explota si la foto se borró entre el encolado y la ejecución', function () {
    [, $foto] = obraConFoto();
    $id = $foto->getKey();
    $foto->forceDelete();

    app(ProcessWorkPhoto::class, ['workPhotoId' => $id])->handle(app(PhotoProcessor::class));
})->throwsNoExceptions();

/*
|---------------------------------------------------------------------------
| Lo que ADR-019 protege: la obra no depende de sus fotos
|---------------------------------------------------------------------------
*/

it('la obra sigue publicada aunque todas sus fotos fallen', function () {
    [$work, $foto] = obraConFoto();

    Storage::disk('local')->put($foto->path_original, 'roto');
    app(PhotoProcessor::class)->procesar($foto);

    // La obra no cambió de estado ni desapareció: una falla de procesamiento no
    // invalida datos ya guardados, que es exactamente lo que pide el spec.
    expect(Work::query()->find($work->getKey()))->not->toBeNull()
        ->and($work->refresh()->photos()->count())->toBe(1)
        ->and($work->publishedPhotos()->count())->toBe(0);
});

it('sólo publica las fotos que llegaron a READY', function () {
    $work = Work::factory()->create();

    WorkPhoto::factory()->lista()->for($work)->create();
    WorkPhoto::factory()->for($work)->create();          // PENDING
    WorkPhoto::factory()->fallida()->for($work)->create();

    expect($work->photos()->count())->toBe(3)
        ->and($work->publishedPhotos()->count())->toBe(1);
});
