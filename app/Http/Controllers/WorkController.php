<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\WorkPhoto;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use App\Support\Settings\AppSettings;
use App\Support\Work\ConcurrentEditException;
use App\Support\Work\GeometryRuleViolation;
use App\Support\Work\WorkGeometry;
use App\Support\Work\WorkRuleViolation;
use App\Support\Work\WorkWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Obras (RF-OBR-001…009, RF-GEO-001…005, RF-DEL-001).
 *
 * No lleva `can:`: crear y editar obras es lo que hace el rol Obras Públicas, y
 * la matriz del spec (2.2) se lo permite igual que al Administrador. Lo que sí
 * es exclusivo del Admin —restaurar, eliminar definitivamente— es F6 y va con su
 * política.
 *
 * Las tres excepciones de dominio se traducen a errores de validación con
 * mensaje de negocio, nunca a un 500: son situaciones previstas y quien las
 * provoca es alguien llenando un formulario, no un sistema roto.
 */
final class WorkController
{
    public function __construct(private readonly WorkWriter $writer) {}

    public function index(Request $request): InertiaResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'integer', 'exists:work_statuses,id'],
            'subcategoria' => ['nullable', 'integer', 'exists:work_subcategories,id'],
        ]);

        $obras = Work::query()
            ->with(['subcategory:id,name,geometry_mode', 'status:id,label,color,is_final'])
            ->when($filtros['buscar'] ?? null, fn ($q, string $texto) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$texto}%")->orWhere('code', 'like', "%{$texto}%"),
            ))
            ->when($filtros['estado'] ?? null, fn ($q, int $id) => $q->where('work_status_id', $id))
            ->when($filtros['subcategoria'] ?? null, fn ($q, int $id) => $q->where('work_subcategory_id', $id))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Work $obra): array => [
                'id' => $obra->id,
                'code' => $obra->code,
                'name' => $obra->name,
                'subcategoria' => $obra->subcategory?->name,
                'estado' => $obra->status?->label,
                'estado_color' => $obra->status?->color,
                'start_date' => $obra->start_date->toDateString(),
                'effective_end_date' => $obra->effective_end_date->toDateString(),
                'lock_version' => $obra->lock_version,
            ]);

        return Inertia::render('Obras/Index', [
            'obras' => $obras,
            'filtros' => $filtros,
            'estados' => WorkStatus::query()->where('is_active', true)
                ->orderBy('sort_order')->get(['id', 'label']),
            'subcategorias' => WorkSubcategory::query()->where('is_active', true)
                ->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('Obras/Formulario', $this->datosDelFormulario());
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        [$subcategoria, $estado] = $this->catalogos($datos);

        $obra = $this->traduciendoExcepciones(fn (): Work => $this->writer->crear(
            atributos: $this->atributos($datos),
            geometria: $this->geometria($datos['geometria'], $subcategoria),
            subcategoria: $subcategoria,
            estado: $estado,
            actor: $request->user(),
        ));

        return redirect()->route('obras.edit', $obra)
            ->with('success', "Obra {$obra->code} creada.");
    }

    public function edit(Work $work): InertiaResponse
    {
        return Inertia::render('Obras/Formulario', array_merge($this->datosDelFormulario(), [
            'obra' => [
                'id' => $work->id,
                'code' => $work->code,
                'name' => $work->name,
                'description' => $work->description,
                'work_subcategory_id' => $work->work_subcategory_id,
                'work_status_id' => $work->work_status_id,
                'start_date' => $work->start_date->toDateString(),
                'estimated_end_date' => $work->estimated_end_date->toDateString(),
                'actual_end_date' => $work->actual_end_date?->toDateString(),
                'effective_end_date' => $work->effective_end_date->toDateString(),
                'street' => $work->street,
                'street_number' => $work->street_number,
                'locality' => $work->locality,
                'length_m' => $work->length_m,
                'length_calc_method' => $work->length_calc_method,
                // La versión viaja al formulario y vuelve con el envío: es lo que
                // permite detectar que alguien más guardó mientras tanto.
                'lock_version' => $work->lock_version,
                'geometria' => $this->geometriaComoGeoJson($work),
            ],
            'fotos' => $this->fotos($work),
            // El máximo lo fija el Administrador (RF-CFG-001), así que viaja
            // con la página en lugar de estar escrito en el componente.
            'maxFotos' => (int) app(AppSettings::class)->get(AppSettings::MAX_PHOTOS_PER_WORK),
        ]));
    }

    public function update(Request $request, Work $work): RedirectResponse
    {
        $datos = $this->validar($request, obligarGeometria: false);

        [$subcategoria, $estado] = $this->catalogos($datos);

        $this->traduciendoExcepciones(fn (): Work => $this->writer->actualizar(
            work: $work,
            atributos: $this->atributos($datos),
            geometria: isset($datos['geometria'])
                ? $this->geometria($datos['geometria'], $subcategoria)
                : null,
            subcategoria: $subcategoria,
            estado: $estado,
            versionEsperada: (int) $datos['lock_version'],
            actor: $request->user(),
        ));

        return back()->with('success', 'Obra actualizada.');
    }

    /** Papelera lógica: la obra deja de verse, no deja de existir (RF-DEL-001). */
    public function destroy(Request $request, Work $work): RedirectResponse
    {
        $datos = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        $this->traduciendoExcepciones(function () use ($work, $datos, $request): Work {
            $this->writer->enviarAPapelera($work, (int) $datos['lock_version'], $request->user());

            return $work;
        });

        return redirect()->route('obras.index')
            ->with('success', "La obra {$work->code} se envió a la papelera.");
    }

    /**
     * @param  callable(): Work  $operacion
     *
     * @throws ValidationException
     */
    private function traduciendoExcepciones(callable $operacion): Work
    {
        try {
            return $operacion();
        } catch (GeometryRuleViolation|WorkRuleViolation $e) {
            throw ValidationException::withMessages([
                $e instanceof GeometryRuleViolation ? 'geometria' : 'fechas' => $e->getMessage(),
            ]);
        } catch (ConcurrentEditException $e) {
            // 409 sería más preciso en HTTP, pero Inertia lo trataría como un
            // error de página y perdería lo que la persona escribió. Un error de
            // validación devuelve el formulario intacto con el aviso arriba, que
            // es exactamente lo que hace falta para no perder el trabajo.
            throw ValidationException::withMessages(['lock_version' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function datosDelFormulario(): array
    {
        return [
            'subcategorias' => WorkSubcategory::query()
                ->with('category:id,name')
                ->where('is_active', true)
                ->orderBy('work_category_id')->orderBy('sort_order')
                ->get()
                ->map(fn (WorkSubcategory $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'categoria' => $s->category?->name,
                    // El modo determina qué dibuja el editor: sin esto el
                    // formulario no sabe si pedir un punto o un área.
                    'geometry_mode' => $s->geometry_mode,
                ]),
            'estados' => WorkStatus::query()->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'label', 'is_final'])
                ->map(fn (WorkStatus $e): array => [
                    'id' => $e->id,
                    'label' => $e->label,
                    // Con `is_final` el formulario puede pedir la fecha real
                    // ANTES de enviar, en lugar de que la rechace el servidor.
                    'is_final' => $e->is_final,
                ]),
            'mapa' => [
                'centro' => config('obras.mapa.centro'),
                'zoom' => config('obras.mapa.zoom'),
                'bbox' => config('obras.mapa.bbox'),
                'tiles' => [
                    'url_template' => config('mapa.tiles.url_template'),
                    'attribution' => config('mapa.tiles.attribution'),
                    'min_zoom' => config('mapa.tiles.min_zoom'),
                    'max_zoom' => config('mapa.tiles.max_zoom'),
                ],
                'partido_url' => route('mapa.partido'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: WorkSubcategory, 1: WorkStatus}
     */
    private function catalogos(array $datos): array
    {
        return [
            WorkSubcategory::query()->findOrFail($datos['work_subcategory_id']),
            WorkStatus::query()->findOrFail($datos['work_status_id']),
        ];
    }

    /**
     * @param  array<string, mixed>  $geojson
     *
     * @throws ValidationException
     */
    private function geometria(array $geojson, WorkSubcategory $subcategoria): WorkGeometry
    {
        try {
            return WorkGeometry::desdeGeoJson($geojson, $subcategoria);
        } catch (GeometryRuleViolation $e) {
            throw ValidationException::withMessages(['geometria' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributos(array $datos): array
    {
        return array_intersect_key($datos, array_flip([
            'name', 'description', 'start_date', 'estimated_end_date', 'actual_end_date',
            'street', 'street_number', 'locality',
        ]));
    }

    /**
     * Las fotos de la obra, con las URL ya firmadas.
     *
     * Se firman ACÁ y no en el cliente porque la firma la emite el servidor: es
     * lo único que la hace confiable. El cliente recibe una URL usable y no
     * sabe —ni necesita saber— dónde vive el archivo.
     *
     * Las que no llegaron a `READY` viajan sin URL: no hay derivado que mostrar,
     * y la galería dibuja su estado en lugar de una imagen rota.
     *
     * @return list<array<string, mixed>>
     */
    private function fotos(Work $work): array
    {
        return $work->photos()->get()->map(fn (WorkPhoto $foto): array => [
            'id' => $foto->id,
            'status' => $foto->status,
            'original_filename' => $foto->original_filename,
            'caption' => $foto->caption,
            'sort_order' => $foto->sort_order,
            'failure_reason' => $foto->failure_reason,
            'se_puede_reintentar' => $foto->status === WorkPhoto::STATUS_FAILED,
            'url_thumb' => $foto->esPublicable()
                ? URL::signedRoute('fotos.ver', ['photo' => $foto->id, 'tamano' => 'thumb'])
                : null,
            'url_large' => $foto->esPublicable()
                ? URL::signedRoute('fotos.ver', ['photo' => $foto->id, 'tamano' => 'large'])
                : null,
        ])->all();
    }

    /**
     * La geometría guardada, en el mismo formato que emite el editor.
     *
     * `ST_AsGeoJSON` existe en MariaDB 10.11.18 y devuelve `[lon, lat]`, medido:
     * es exactamente la convención del proyecto, así que no hay que invertir
     * nada. Que no haya que invertir es una propiedad verificada, no supuesta —el
     * test de edición la comprueba contra el motor—.
     *
     * @return array<string, mixed>|null
     */
    private function geometriaComoGeoJson(Work $work): ?array
    {
        $fila = DB::selectOne('SELECT ST_AsGeoJSON(geometry) AS geojson FROM works WHERE id = ?', [$work->getKey()]);
        $json = $fila?->geojson;

        if (! is_string($json)) {
            return null;
        }

        /** @var array<string, mixed> $decodificado */
        $decodificado = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decodificado;
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $obligarGeometria = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'work_subcategory_id' => ['required', 'integer', 'exists:work_subcategories,id'],
            'work_status_id' => ['required', 'integer', 'exists:work_statuses,id'],
            'start_date' => ['required', 'date'],
            'estimated_end_date' => ['required', 'date'],
            // Las reglas de coherencia entre fechas NO están acá: viven en
            // `WorkWriter`, porque dependen de `is_final` del estado y tienen que
            // valer también para el alta por consola o por importación.
            'actual_end_date' => ['nullable', 'date'],
            'street' => ['nullable', 'string', 'max:255'],
            'street_number' => ['nullable', 'string', 'max:32'],
            'locality' => ['nullable', 'string', 'max:100'],
            'geometria' => [$obligarGeometria ? 'required' : 'nullable', 'array'],
            'geometria.type' => ['required_with:geometria', 'string'],
            'geometria.coordinates' => ['required_with:geometria', 'array'],
            'lock_version' => [$obligarGeometria ? 'nullable' : 'required', 'integer', 'min:0'],
        ], [], [
            'name' => 'nombre',
            'work_subcategory_id' => 'subcategoría',
            'work_status_id' => 'estado',
            'start_date' => 'fecha de inicio',
            'estimated_end_date' => 'finalización prevista',
            'actual_end_date' => 'finalización real',
            'geometria' => 'geometría',
        ]);
    }
}
