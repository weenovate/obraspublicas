<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Work;
use App\Models\WorkFieldDefinition;
use App\Models\WorkFieldOption;
use App\Models\WorkFieldValue;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use App\Support\Fields\FieldRuleViolation;
use App\Support\Fields\WorkFieldSet;
use App\Support\Fields\WorkFieldValueWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| F2 · Campos técnicos dinámicos (RF-DIN-001…005, spec 9.3).
|
| Tres reglas sostienen todo lo demás y son las que se verifican acá:
|
|   1. Una obra ve la UNIÓN de los campos de su categoría y su subcategoría, sin
|      códigos duplicados, y ante un empate gana el alcance más específico.
|   2. Cada valor va en la columna que corresponde a su tipo, y exactamente en
|      una.
|   3. Volver obligatorio un campo NO invalida las obras que ya existían.
*/

function definirCampo(string $scopeType, int $scopeId, string $code, array $extra = []): WorkFieldDefinition
{
    $definicion = new WorkFieldDefinition;

    $definicion->forceFill(array_merge([
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'code' => $code,
        'label' => ucfirst($code),
        'data_type' => WorkFieldDefinition::TYPE_TEXT,
        'is_required' => false,
        'sort_order' => 0,
        'is_active' => true,
    ], $extra));
    $definicion->save();

    return $definicion;
}

/** @param array<array-key, mixed> $valores */
function guardarCampos(Work $work, array $valores, bool $exigir = true): void
{
    DB::transaction(fn () => app(WorkFieldValueWriter::class)->guardar($work, $valores, $exigir));
}

/*
|---------------------------------------------------------------------------
| Qué campos aplican (RF-DIN-001)
|---------------------------------------------------------------------------
*/

it('presenta la unión de los campos de la categoría y de la subcategoría', function () {
    $sub = WorkSubcategory::factory()->create();

    definirCampo(WorkFieldDefinition::SCOPE_CATEGORY, $sub->work_category_id, 'superficie');
    definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'espesor');

    $codigos = app(WorkFieldSet::class)->paraSubcategoria($sub)->pluck('code')->all();

    expect($codigos)->toBe(['superficie', 'espesor']);
});

it('ante el mismo código, gana la definición de la subcategoría', function () {
    $sub = WorkSubcategory::factory()->create();

    $general = definirCampo(WorkFieldDefinition::SCOPE_CATEGORY, $sub->work_category_id, 'superficie', [
        'label' => 'Superficie de la categoría',
    ]);
    $especifica = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'superficie', [
        'label' => 'Superficie de la subcategoría',
    ]);

    $resueltos = app(WorkFieldSet::class)->paraSubcategoria($sub);

    // Un solo campo, y es el específico: quien lo definió en la subcategoría lo
    // hizo sabiendo que la categoría ya tenía uno, y la intención es afinarlo.
    expect($resueltos)->toHaveCount(1)
        ->and($resueltos->first()->id)->toBe($especifica->id)
        ->and($resueltos->first()->id)->not->toBe($general->id);
});

it('no ofrece los campos desactivados', function () {
    $sub = WorkSubcategory::factory()->create();

    definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'vigente');
    definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'viejo', ['is_active' => false]);

    expect(app(WorkFieldSet::class)->paraSubcategoria($sub)->pluck('code')->all())->toBe(['vigente']);
});

/*
|---------------------------------------------------------------------------
| Cada tipo en su columna (spec 9.3)
|---------------------------------------------------------------------------
*/

it('guarda cada tipo en su columna y deja las demás vacías', function (string $tipo, mixed $entrada, string $columna, mixed $esperado) {
    $work = Work::factory()->create();
    $definicion = definirCampo(
        WorkFieldDefinition::SCOPE_SUBCATEGORY,
        $work->work_subcategory_id,
        'campo',
        ['data_type' => $tipo],
    );

    guardarCampos($work, [$definicion->id => $entrada]);

    $fila = WorkFieldValue::query()->sole();

    expect($fila->getAttribute($columna))->not->toBeNull();

    if ($esperado !== null) {
        $valor = $fila->getAttribute($columna);
        expect($valor instanceof Carbon ? $valor->toDateString() : $valor)->toEqual($esperado);
    }

    // Exactamente UNA columna tipada tiene valor: es la regla de la spec 9.3.
    $conValor = collect(array_unique(array_values(WorkFieldDefinition::VALUE_COLUMNS)))
        ->filter(fn (string $c): bool => $fila->getAttribute($c) !== null);

    expect($conValor)->toHaveCount(1);
})->with([
    [WorkFieldDefinition::TYPE_TEXT, 'Hormigón', 'value_text', 'Hormigón'],
    [WorkFieldDefinition::TYPE_LONG_TEXT, 'Una descripción larga', 'value_text', 'Una descripción larga'],
    [WorkFieldDefinition::TYPE_INTEGER, '42', 'value_integer', 42],
    [WorkFieldDefinition::TYPE_DECIMAL, '12.5', 'value_decimal', '12.500000'],
    [WorkFieldDefinition::TYPE_BOOLEAN, true, 'value_boolean', true],
    [WorkFieldDefinition::TYPE_DATE, '2026-03-15', 'value_date', '2026-03-15'],
]);

it('rechaza un valor que no corresponde al tipo declarado', function (string $tipo, mixed $entrada, string $mensaje) {
    $work = Work::factory()->create();
    $definicion = definirCampo(
        WorkFieldDefinition::SCOPE_SUBCATEGORY,
        $work->work_subcategory_id,
        'campo',
        ['data_type' => $tipo],
    );

    expect(fn () => guardarCampos($work, [$definicion->id => $entrada]))
        ->toThrow(FieldRuleViolation::class, $mensaje);
})->with([
    [WorkFieldDefinition::TYPE_INTEGER, 'doce', 'número entero'],
    // Un decimal no es un entero: aceptarlo redondearía en silencio.
    [WorkFieldDefinition::TYPE_INTEGER, '12.5', 'número entero'],
    [WorkFieldDefinition::TYPE_DECIMAL, 'mucho', 'tiene que ser un número'],
    [WorkFieldDefinition::TYPE_DATE, 'el martes', 'fecha válida'],
]);

it('aplica el rango sólo a los campos numéricos', function () {
    $work = Work::factory()->create();

    $numerico = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'ancho', [
        'data_type' => WorkFieldDefinition::TYPE_DECIMAL,
        'unit' => 'm',
        'min_value' => 1,
        'max_value' => 10,
    ]);

    expect(fn () => guardarCampos($work, [$numerico->id => '0.5']))
        ->toThrow(FieldRuleViolation::class, 'no puede ser menor que');

    expect(fn () => guardarCampos($work, [$numerico->id => '11']))
        ->toThrow(FieldRuleViolation::class, 'no puede ser mayor que');

    guardarCampos($work, [$numerico->id => '5.5']);

    expect(WorkFieldValue::query()->sole()->value_decimal)->toEqual('5.500000');
});

it('el mensaje de rango incluye la unidad declarada', function () {
    $work = Work::factory()->create();
    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'ancho', [
        'data_type' => WorkFieldDefinition::TYPE_INTEGER,
        'unit' => 'm',
        'max_value' => 10,
    ]);

    expect(fn () => guardarCampos($work, [$campo->id => '99']))
        ->toThrow(FieldRuleViolation::class, '10 m');
});

/*
|---------------------------------------------------------------------------
| Opciones de selección
|---------------------------------------------------------------------------
*/

it('acepta sólo una opción propia del campo y activa', function () {
    $work = Work::factory()->create();

    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'material', [
        'data_type' => WorkFieldDefinition::TYPE_SELECT,
    ]);
    $otro = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'terminacion', [
        'data_type' => WorkFieldDefinition::TYPE_SELECT,
    ]);

    $propia = WorkFieldOption::query()->forceCreate([
        'work_field_definition_id' => $campo->id, 'value' => 'hormigon', 'label' => 'Hormigón', 'is_active' => true,
    ]);
    $ajena = WorkFieldOption::query()->forceCreate([
        'work_field_definition_id' => $otro->id, 'value' => 'lisa', 'label' => 'Lisa', 'is_active' => true,
    ]);
    $desactivada = WorkFieldOption::query()->forceCreate([
        'work_field_definition_id' => $campo->id, 'value' => 'adoquin', 'label' => 'Adoquín', 'is_active' => false,
    ]);

    guardarCampos($work, [$campo->id => $propia->id]);
    expect(WorkFieldValue::query()->sole()->option_id)->toBe($propia->id);

    // La opción de OTRO campo se rechaza aunque exista: el desplegable no la
    // ofrece, pero una petición manipulada sí la puede pedir.
    expect(fn () => guardarCampos($work, [$campo->id => $ajena->id]))
        ->toThrow(FieldRuleViolation::class, 'no es válida');

    expect(fn () => guardarCampos($work, [$campo->id => $desactivada->id]))
        ->toThrow(FieldRuleViolation::class, 'no es válida');
});

/*
|---------------------------------------------------------------------------
| Obligatoriedad no retroactiva
|---------------------------------------------------------------------------
*/

it('exige lo obligatorio en la edición que se está guardando', function () {
    $work = Work::factory()->create();
    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'ancho', [
        'is_required' => true,
    ]);

    expect(fn () => guardarCampos($work, []))
        ->toThrow(FieldRuleViolation::class, 'es obligatorio');

    // Y con el valor puesto, pasa.
    guardarCampos($work, [$campo->id => 'algo']);

    expect(WorkFieldValue::query()->sole()->value_text)->toBe('algo');
});

it('volver obligatorio un campo NO invalida las obras que ya existían', function () {
    $work = Work::factory()->create();
    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'ancho');

    // La obra se cargó cuando el campo era opcional.
    guardarCampos($work, []);

    $campo->forceFill(['is_required' => true])->save();

    // Un guardado que no lo exige —el de una carga previa— pasa igual. Si se
    // rechazara, la obra quedaría imposible de corregir sin llenar un campo que
    // no existía cuando se cargó.
    guardarCampos($work, [], exigir: false);

    // Pero SÍ queda marcada como incompleta: la regla es permisiva, no ciega.
    $faltantes = app(WorkFieldSet::class)->faltantesObligatorios($work);

    expect($faltantes)->toHaveCount(1)
        ->and($faltantes->first()->id)->toBe($campo->id);
});

/*
|---------------------------------------------------------------------------
| Valores fuera de alcance (ADR-027)
|---------------------------------------------------------------------------
*/

it('conserva los valores que quedan fuera de alcance y los devuelve al volver', function () {
    $work = Work::factory()->create();
    $original = $work->subcategory;

    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $original->getKey(), 'espesor');
    guardarCampos($work, [$campo->id => '15 cm']);

    // La obra se muda a otra subcategoría, donde ese campo no existe.
    $otra = WorkSubcategory::factory()->create();
    $work->forceFill(['work_subcategory_id' => $otra->getKey()])->save();
    $work->refresh();

    // El valor NO se ve...
    expect(app(WorkFieldSet::class)->paraObra($work))->toHaveCount(0);

    // ...pero sigue guardado. Elegir mal en un desplegable no destruye carga
    // manual (ADR-027).
    expect($work->fieldValues()->count())->toBe(1);

    // Y al volver, reaparece con su valor intacto.
    $work->forceFill(['work_subcategory_id' => $original->getKey()])->save();
    $work->refresh();

    expect(app(WorkFieldSet::class)->paraObra($work))->toHaveCount(1)
        ->and($work->fieldValues()->sole()->value_text)->toBe('15 cm');
});

it('vaciar un campo que sigue en alcance sí borra su valor', function () {
    // La contracara: si el campo se sigue viendo, dejarlo vacío es corregir una
    // carga equivocada, y conservar el valor viejo contradiría lo que se ve.
    $work = Work::factory()->create();
    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'espesor');

    guardarCampos($work, [$campo->id => '15']);
    expect($work->fieldValues()->count())->toBe(1);

    guardarCampos($work, [$campo->id => '']);
    expect($work->fieldValues()->count())->toBe(0);
});

it('guarda el falso de un booleano en lugar de tratarlo como vacío', function () {
    $work = Work::factory()->create();
    $campo = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $work->work_subcategory_id, 'iluminacion', [
        'data_type' => WorkFieldDefinition::TYPE_BOOLEAN,
    ]);

    guardarCampos($work, [$campo->id => false]);

    expect(WorkFieldValue::query()->sole()->value_boolean)->toBeFalse();
});

/*
|---------------------------------------------------------------------------
| Por HTTP: el camino real
|---------------------------------------------------------------------------
*/

it('guarda los valores al crear la obra, y los devuelve al editarla', function () {
    $usuario = User::factory()->create(['role' => 'OBRAS_PUBLICAS', 'must_change_password' => false]);
    $sub = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT, 'is_active' => true]);
    $estado = WorkStatus::factory()->create(['key' => 'IN_PROGRESS', 'is_final' => false, 'is_active' => true]);

    $ancho = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'ancho', [
        'data_type' => WorkFieldDefinition::TYPE_DECIMAL, 'unit' => 'm', 'min_value' => 1, 'max_value' => 20,
    ]);

    $this->actingAs($usuario)->post('/obras', [
        'name' => 'Repavimentación',
        'work_subcategory_id' => $sub->getKey(),
        'work_status_id' => $estado->getKey(),
        'start_date' => '2026-02-01',
        'estimated_end_date' => '2026-08-01',
        'geometria' => ['type' => 'Point', 'coordinates' => config('obras.mapa.centro')],
        'campos' => [$ancho->id => '7.5'],
    ])->assertRedirect();

    $obra = Work::query()->sole();

    expect($obra->fieldValues()->sole()->value_decimal)->toEqual('7.500000');

    // Y vuelve al formulario con su valor, listo para editar.
    $this->actingAs($usuario)->get("/obras/{$obra->getKey()}/editar")
        ->assertInertia(fn ($p) => $p
            ->has('campos', 1)
            ->where('campos.0.code', 'ancho')
            ->where('campos.0.unit', 'm')
            ->where('campos.0.valor', '7.500000'));
});

it('devuelve el error del campo como error de formulario, no como 500', function () {
    $usuario = User::factory()->create(['role' => 'OBRAS_PUBLICAS', 'must_change_password' => false]);
    $sub = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT, 'is_active' => true]);
    $estado = WorkStatus::factory()->create(['key' => 'IN_PROGRESS', 'is_final' => false, 'is_active' => true]);

    $ancho = definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'ancho', [
        'data_type' => WorkFieldDefinition::TYPE_INTEGER, 'max_value' => 10,
    ]);

    $this->actingAs($usuario)->post('/obras', [
        'name' => 'Con un ancho imposible',
        'work_subcategory_id' => $sub->getKey(),
        'work_status_id' => $estado->getKey(),
        'start_date' => '2026-02-01',
        'estimated_end_date' => '2026-08-01',
        'geometria' => ['type' => 'Point', 'coordinates' => config('obras.mapa.centro')],
        'campos' => [$ancho->id => '99'],
    ])->assertSessionHasErrors('campos');

    // Y el alta entera se revirtió: no queda una obra sin sus campos.
    expect(Work::withTrashed()->count())->toBe(0);
});

it('muestra los campos de la subcategoría preseleccionada en la primera carga', function () {
    // El formulario preselecciona la primera subcategoría de la lista. Si el
    // servidor no hiciera lo mismo, la primera carga diría «esta subcategoría no
    // tiene campos» aunque los tenga, y sólo aparecerían al cambiar el
    // desplegable y volver.
    $usuario = User::factory()->create(['role' => 'OBRAS_PUBLICAS', 'must_change_password' => false]);
    $sub = WorkSubcategory::factory()->create(['geometry_mode' => WorkSubcategory::MODE_POINT, 'is_active' => true]);

    definirCampo(WorkFieldDefinition::SCOPE_SUBCATEGORY, $sub->getKey(), 'ancho');

    $this->actingAs($usuario)->get('/obras/nueva')
        ->assertInertia(fn ($p) => $p->has('campos', 1)->where('campos.0.code', 'ancho'));
});
