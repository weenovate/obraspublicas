<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use App\Support\Work\ConcurrentEditException;
use App\Support\Work\WorkGeometry;
use App\Support\Work\WorkRuleViolation;
use App\Support\Work\WorkWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| F1-B · Alta, edición y baja lógica de una obra.
|
| Lo que se verifica acá es lo que no se puede verificar de otra forma: que el
| código salga de la secuencia atómica DENTRO de la transacción del alta, que la
| fecha efectiva quede materializada según `is_final` y no según una clave, que
| dos ediciones simultáneas no se pisen, y que la geometría cumpla el invariante
| contra el motor y no contra una expectativa.
*/

function estadoEnCurso(): WorkStatus
{
    // Reutiliza el existente: la clave del estado es única y varios tests dan de
    // alta más de una obra en curso. Va por la factoría y no por `firstOrCreate`
    // porque `key` e `is_final` no son asignables en masa —a propósito, son parte
    // de las reglas de inmutabilidad de F1-A— y sólo la factoría escribe sin guarda.
    return WorkStatus::query()->firstWhere('key', WorkStatus::KEY_IN_PROGRESS)
        ?? WorkStatus::factory()->create([
            'key' => WorkStatus::KEY_IN_PROGRESS,
            'label' => 'En ejecución',
            'is_final' => false,
        ]);
}

function estadoFinalizador(string $etiqueta = 'Finalizada'): WorkStatus
{
    return WorkStatus::factory()->create([
        'key' => 'FINALIZADA_'.strtoupper(substr(md5($etiqueta), 0, 6)),
        'label' => $etiqueta,
        'is_final' => true,
    ]);
}

function subcategoriaDePunto(): WorkSubcategory
{
    return WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT]);
}

function geometriaDePunto(WorkSubcategory $subcategoria): WorkGeometry
{
    return WorkGeometry::desdeGeoJson(
        ['type' => 'Point', 'coordinates' => config('obras.mapa.centro')],
        $subcategoria,
    );
}

/** @param array<string, mixed> $atributos */
function altaDeObra(array $atributos = [], ?WorkStatus $estado = null, ?WorkSubcategory $subcategoria = null): Work
{
    $subcategoria ??= subcategoriaDePunto();
    $estado ??= estadoEnCurso();

    return app(WorkWriter::class)->crear(
        atributos: array_merge([
            'name' => 'Repavimentación de calle San Martín',
            'start_date' => '2026-01-10',
            'estimated_end_date' => '2026-06-30',
        ], $atributos),
        geometria: geometriaDePunto($subcategoria),
        subcategoria: $subcategoria,
        estado: $estado,
        actor: User::factory()->create(),
    );
}

/*
|---------------------------------------------------------------------------
| Alta
|---------------------------------------------------------------------------
*/

it('crea la obra con su código, su geometría y su fecha efectiva', function () {
    $obra = altaDeObra();

    expect($obra->code)->toMatch('/^OBR-\d{4}-\d{4,}$/')
        ->and($obra->sequence_number)->toBe(1)
        ->and($obra->lock_version)->toBe(0);

    $fila = DB::selectOne(
        'SELECT ST_SRID(geometry) AS srid, GeometryType(geometry) AS tipo,
                ST_Contains(geometry, representative_point) AS dentro, effective_end_date
         FROM works WHERE id = ?',
        [$obra->getKey()],
    );

    expect((int) $fila->srid)->toBe(4326)
        ->and(strtoupper((string) $fila->tipo))->toBe('POINT')
        ->and((bool) $fila->dentro)->toBeTrue()
        // Sin estado finalizador, la efectiva es la prevista (ADR-008).
        ->and($fila->effective_end_date)->toBe('2026-06-30');
});

it('da códigos distintos a dos altas seguidas y no reutiliza el de una obra borrada', function () {
    // La otra mitad de CA-002: la secuencia ya estaba probada con dos
    // transacciones concurrentes en F1-A, pero su enunciado habla de dos ALTAS.
    $primera = altaDeObra();
    $segunda = altaDeObra();

    expect($primera->code)->not->toBe($segunda->code)
        ->and($segunda->sequence_number)->toBe($primera->sequence_number + 1);

    // Borrarla definitivamente no devuelve el número a la bolsa (RF-OBR-002).
    $segunda->forceDelete();
    $tercera = altaDeObra();

    expect($tercera->sequence_number)->toBe($segunda->sequence_number + 1);
});

it('no deja ni obra ni código si el alta se revierte', function () {
    $subcategoria = subcategoriaDePunto();
    $estado = estadoEnCurso();

    // Un estado finalizador sin fecha real: la regla salta DESPUÉS de que el
    // generador ya reservó el número si el orden fuera el equivocado.
    try {
        app(WorkWriter::class)->crear(
            atributos: ['name' => 'Obra que no llega a existir', 'start_date' => '2026-01-10', 'estimated_end_date' => '2026-06-30'],
            geometria: geometriaDePunto($subcategoria),
            subcategoria: $subcategoria,
            estado: estadoFinalizador(),
            actor: User::factory()->create(),
        );
    } catch (WorkRuleViolation) {
        // Esperado.
    }

    expect(Work::withTrashed()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'work.created')->count())->toBe(0);

    // Y el alta siguiente arranca en 1: la secuencia se revirtió con todo lo demás.
    expect(altaDeObra(estado: $estado)->sequence_number)->toBe(1);
});

it('audita el alta en la misma transacción, con el código adentro', function () {
    $obra = altaDeObra();

    $evento = AuditEvent::query()->where('action', 'work.created')->sole();

    expect($evento->entity_type)->toBe('works')
        ->and($evento->entity_id)->toBe($obra->getKey())
        ->and($evento->before_json)->toBeNull()
        ->and($evento->after_json['code'])->toBe($obra->code);
});

it('guarda la longitud y el método sólo cuando es una línea', function () {
    $subcategoria = WorkSubcategory::factory()->create([
        'geometry_mode' => WorkSubcategory::MODE_LINE_MANUAL_NETWORK,
    ]);

    $obra = app(WorkWriter::class)->crear(
        atributos: ['name' => 'Red de agua', 'start_date' => '2026-01-10', 'estimated_end_date' => '2026-06-30'],
        geometria: WorkGeometry::desdeGeoJson([
            'type' => 'LineString',
            'coordinates' => [[-60.0575, -33.5872], [-60.0575, -33.5772]],
        ], $subcategoria),
        subcategoria: $subcategoria,
        estado: estadoEnCurso(),
        actor: User::factory()->create(),
    );

    expect((float) $obra->length_m)->toBeGreaterThan(1_100.0)
        ->and($obra->length_calc_method)->toBe('VINCENTY')
        // Y el punto representativo de la línea es uno de sus vértices.
        ->and(altaDeObra()->length_m)->toBeNull();
});

/*
|---------------------------------------------------------------------------
| Las tres fechas (ADR-008)
|---------------------------------------------------------------------------
*/

it('exige fecha real cuando el estado es finalizador, sea cual sea su clave', function () {
    // La regla mira `is_final`, no la clave `COMPLETED`: un estado propio del
    // municipio finaliza igual (D3).
    expect(fn () => altaDeObra(estado: estadoFinalizador('Finalizada con observaciones')))
        ->toThrow(WorkRuleViolation::class, 'estado finalizador');
});

it('usa la fecha real como efectiva sólo mientras el estado finaliza', function () {
    $obra = altaDeObra(
        ['actual_end_date' => '2026-05-20'],
        estado: estadoFinalizador(),
    );

    expect($obra->effective_end_date->toDateString())->toBe('2026-05-20');

    // La obra vuelve a ejecución: la fecha real SE CONSERVA como dato histórico,
    // pero deja de gobernar la efectiva. Con `COALESCE` esto daría 2026-05-20 y
    // la obra figuraría terminada en cualquier filtro por rango.
    $obra = app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        geometria: null,
        subcategoria: $obra->subcategory,
        estado: estadoEnCurso(),
        versionEsperada: $obra->lock_version,
        actor: User::factory()->create(),
    );

    expect($obra->actual_end_date?->toDateString())->toBe('2026-05-20')
        ->and($obra->effective_end_date->toDateString())->toBe('2026-06-30');
});

it('rechaza una finalización prevista anterior al inicio', function () {
    expect(fn () => altaDeObra(['estimated_end_date' => '2025-12-31']))
        ->toThrow(WorkRuleViolation::class, 'anterior al inicio');
});

it('acepta una finalización prevista futura, porque es un pronóstico', function () {
    $obra = altaDeObra(['estimated_end_date' => Carbon::today()->addYear()->toDateString()]);

    expect($obra->estimated_end_date->isFuture())->toBeTrue();
});

it('rechaza una finalización real futura', function () {
    expect(fn () => altaDeObra(
        ['actual_end_date' => Carbon::today()->addWeek()->toDateString()],
        estado: estadoFinalizador(),
    ))->toThrow(WorkRuleViolation::class, 'no puede ser futura');
});

it('rechaza una finalización real anterior al inicio', function () {
    expect(fn () => altaDeObra(
        ['actual_end_date' => '2025-11-01'],
        estado: estadoFinalizador(),
    ))->toThrow(WorkRuleViolation::class, 'anterior al inicio');
});

it('recalcula la efectiva cuando cambia el estado sin que cambien las fechas', function () {
    // El caso que desincroniza una columna materializada si sólo se recalcula al
    // tocar las fechas.
    $obra = altaDeObra(['actual_end_date' => '2026-04-15']);

    expect($obra->effective_end_date->toDateString())->toBe('2026-06-30');

    $obra = app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        geometria: null,
        subcategoria: $obra->subcategory,
        estado: estadoFinalizador(),
        versionEsperada: $obra->lock_version,
    );

    expect($obra->effective_end_date->toDateString())->toBe('2026-04-15');

    // Y en la base, que es donde la lee el filtro por rango.
    expect(DB::table('works')->where('id', $obra->getKey())->value('effective_end_date'))
        ->toBe('2026-04-15');
});

/*
|---------------------------------------------------------------------------
| Concurrencia optimista
|---------------------------------------------------------------------------
*/

it('rechaza la segunda de dos ediciones simultáneas en lugar de pisarla', function () {
    $obra = altaDeObra();
    $version = $obra->lock_version;

    // Las dos personas abrieron el formulario con la misma versión.
    $primera = Work::query()->findOrFail($obra->getKey());
    $segunda = Work::query()->findOrFail($obra->getKey());

    app(WorkWriter::class)->actualizar(
        work: $primera,
        atributos: ['name' => 'Nombre de la primera'],
        geometria: null,
        subcategoria: $primera->subcategory,
        estado: $primera->status,
        versionEsperada: $version,
    );

    expect(fn () => app(WorkWriter::class)->actualizar(
        work: $segunda,
        atributos: ['name' => 'Nombre de la segunda'],
        geometria: null,
        subcategoria: $segunda->subcategory,
        estado: $segunda->status,
        versionEsperada: $version,
    ))->toThrow(ConcurrentEditException::class);

    // El nombre de la primera sobrevive: la segunda no pisó nada.
    expect(Work::query()->findOrFail($obra->getKey())->name)->toBe('Nombre de la primera');
});

it('incrementa la versión en cada guardado', function () {
    $obra = altaDeObra();

    foreach ([1, 2, 3] as $esperada) {
        $obra = app(WorkWriter::class)->actualizar(
            work: $obra,
            atributos: ['name' => "Revisión {$esperada}"],
            geometria: null,
            subcategoria: $obra->subcategory,
            estado: $obra->status,
            versionEsperada: $obra->lock_version,
        );

        expect($obra->lock_version)->toBe($esperada);
    }
});

it('no deja auditoría cuando la edición se rechaza por conflicto', function () {
    $obra = altaDeObra();
    $version = $obra->lock_version;

    app(WorkWriter::class)->actualizar(
        work: Work::query()->findOrFail($obra->getKey()),
        atributos: ['name' => 'Primera'],
        geometria: null,
        subcategoria: $obra->subcategory,
        estado: $obra->status,
        versionEsperada: $version,
    );

    try {
        app(WorkWriter::class)->actualizar(
            work: Work::query()->findOrFail($obra->getKey()),
            atributos: ['name' => 'Segunda'],
            geometria: null,
            subcategoria: $obra->subcategory,
            estado: $obra->status,
            versionEsperada: $version,
        );
    } catch (ConcurrentEditException) {
        // Esperado.
    }

    expect(AuditEvent::query()->where('action', 'work.updated')->count())->toBe(1);
});

/*
|---------------------------------------------------------------------------
| Edición de la geometría
|---------------------------------------------------------------------------
*/

it('reemplaza la geometría y vuelve a verificar el invariante', function () {
    $subcategoria = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POLYGON]);

    $obra = app(WorkWriter::class)->crear(
        atributos: ['name' => 'Plaza', 'start_date' => '2026-01-10', 'estimated_end_date' => '2026-06-30'],
        geometria: WorkGeometry::desdeGeoJson([
            'type' => 'Polygon',
            'coordinates' => [[[-60.06, -33.59], [-60.05, -33.59], [-60.05, -33.58], [-60.06, -33.59]]],
        ], $subcategoria),
        subcategoria: $subcategoria,
        estado: estadoEnCurso(),
    );

    $obra = app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        // Una forma cóncava: el centroide caería afuera y `ST_PointOnSurface` no.
        geometria: WorkGeometry::desdeGeoJson([
            'type' => 'Polygon',
            'coordinates' => [[
                [-60.08, -33.50], [-60.04, -33.50], [-60.04, -33.46], [-60.07, -33.46],
                [-60.07, -33.48], [-60.06, -33.48], [-60.06, -33.46], [-60.08, -33.46], [-60.08, -33.50],
            ]],
        ], $subcategoria),
        subcategoria: $subcategoria,
        estado: $obra->status,
        versionEsperada: $obra->lock_version,
    );

    $fila = DB::selectOne(
        'SELECT ST_Contains(geometry, representative_point) AS dentro, ST_Area(geometry) AS area
         FROM works WHERE id = ?',
        [$obra->getKey()],
    );

    expect((bool) $fila->dentro)->toBeTrue()
        ->and((float) $fila->area)->toBeGreaterThan(0.0);
});

it('borra la longitud si la obra deja de ser una línea', function () {
    $linea = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_LINE_MANUAL_NETWORK]);

    $obra = app(WorkWriter::class)->crear(
        atributos: ['name' => 'Cordón cuneta', 'start_date' => '2026-01-10', 'estimated_end_date' => '2026-06-30'],
        geometria: WorkGeometry::desdeGeoJson([
            'type' => 'LineString',
            'coordinates' => [[-60.0575, -33.5872], [-60.0575, -33.5772]],
        ], $linea),
        subcategoria: $linea,
        estado: estadoEnCurso(),
    );

    expect($obra->length_m)->not->toBeNull();

    $punto = subcategoriaDePunto();

    $obra = app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        geometria: geometriaDePunto($punto),
        subcategoria: $punto,
        estado: $obra->status,
        versionEsperada: $obra->lock_version,
    );

    expect($obra->length_m)->toBeNull()
        ->and($obra->length_calc_method)->toBeNull();
});

it('no deja cambiar a una subcategoría de otra forma sin redibujar', function () {
    $obra = altaDeObra();
    $poligonal = WorkSubcategory::factory()->create([
        'geometry_mode' => WorkSubcategory::MODE_POLYGON,
        'name' => 'Espacios verdes',
    ]);

    expect(fn () => app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        geometria: null,
        subcategoria: $poligonal,
        estado: $obra->status,
        versionEsperada: $obra->lock_version,
    ))->toThrow(WorkRuleViolation::class, 'volver a dibujar');
});

it('deja cambiar entre los dos modos de línea sin redibujar', function () {
    $trazada = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_LINE_ROUTED_ROAD]);
    $manual = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_LINE_MANUAL_NETWORK]);

    $obra = app(WorkWriter::class)->crear(
        atributos: ['name' => 'Bacheo', 'start_date' => '2026-01-10', 'estimated_end_date' => '2026-06-30'],
        geometria: WorkGeometry::desdeGeoJson([
            'type' => 'LineString',
            'coordinates' => [[-60.0575, -33.5872], [-60.0575, -33.5772]],
        ], $trazada),
        subcategoria: $trazada,
        estado: estadoEnCurso(),
    );

    // Los dos modos persisten exactamente el mismo `LINESTRING`: la geometría
    // guardada sigue siendo válida, así que no hace falta redibujar.
    $obra = app(WorkWriter::class)->actualizar(
        work: $obra,
        atributos: [],
        geometria: null,
        subcategoria: $manual,
        estado: $obra->status,
        versionEsperada: $obra->lock_version,
    );

    expect($obra->work_subcategory_id)->toBe($manual->getKey());
});

/*
|---------------------------------------------------------------------------
| Papelera lógica
|---------------------------------------------------------------------------
*/

it('manda la obra a la papelera dejando quién y cuándo', function () {
    $obra = altaDeObra();
    $actor = User::factory()->create();

    app(WorkWriter::class)->enviarAPapelera($obra, $obra->lock_version, $actor);

    expect(Work::query()->count())->toBe(0)
        ->and(Work::withTrashed()->count())->toBe(1);

    $fila = DB::table('works')->where('id', $obra->getKey())->first();

    expect($fila->deleted_at)->not->toBeNull()
        // Quién la borró es parte del dato, no sólo cuándo (RF-DEL-001).
        ->and((int) $fila->deleted_by)->toBe($actor->getKey());

    // Y el evento MUESTRA la baja: un antes sin fecha y un después con ella, más
    // quién la hizo. Una auditoría que registra una baja sin mostrarla no sirve
    // para lo que existe la auditoría.
    $evento = AuditEvent::query()->where('action', 'work.trashed')->sole();

    expect($evento->before_json['deleted_at'])->toBeNull()
        ->and($evento->after_json['deleted_at'])->not->toBeNull()
        ->and($evento->after_json['deleted_by'])->toBe($actor->getKey());
});

it('no deja editar una obra que está en la papelera', function () {
    $obra = altaDeObra();

    app(WorkWriter::class)->enviarAPapelera($obra, $obra->lock_version);

    expect(fn () => app(WorkWriter::class)->actualizar(
        work: $obra->fresh(['subcategory', 'status']),
        atributos: ['name' => 'Editada desde la papelera'],
        geometria: null,
        subcategoria: $obra->subcategory,
        estado: $obra->status,
        versionEsperada: $obra->fresh()->lock_version,
    ))->toThrow(WorkRuleViolation::class, 'papelera');
});

it('una obra en papelera sigue contando como uso del catálogo', function () {
    $obra = altaDeObra();
    $subcategoria = $obra->subcategory;

    app(WorkWriter::class)->enviarAPapelera($obra, $obra->lock_version);

    // Es lo que sostiene las reglas de inmutabilidad de F1-A: restaurar una obra
    // tiene que seguir dando un registro válido.
    expect($subcategoria->refresh()->isInUse())->toBeTrue()
        ->and($obra->status->refresh()->isInUse())->toBeTrue();
});

it('mantiene inmutable el código de una obra ya creada', function () {
    $obra = altaDeObra();
    $obra->code = 'OBR-2026-9999';

    expect(fn () => $obra->save())->toThrow(RuntimeException::class, 'inmutable');
});
