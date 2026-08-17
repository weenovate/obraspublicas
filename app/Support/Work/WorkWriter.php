<?php

declare(strict_types=1);

namespace App\Support\Work;

use App\Models\User;
use App\Models\Work;
use App\Models\WorkStatus;
use App\Models\WorkSubcategory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alta, edición y baja lógica de una obra, con todo lo que tiene que ser atómico
 * adentro de la misma transacción (RF-OBR-001…009, RF-DEL-001, RF-AUD-001).
 *
 * Lo que esta clase sostiene, y por qué cada cosa está donde está:
 *
 *   EL CÓDIGO SE GENERA ADENTRO. `WorkCodeGenerator` bloquea la fila de la
 *   secuencia y exige estar en transacción; si el alta después falla, el bloqueo
 *   se libera con el rollback y el número no se pierde a medias.
 *
 *   LA GEOMETRÍA SE ESCRIBE Y DESPUÉS SE INTERROGA. `WorkGeometry` elige un
 *   punto representativo con fundamento, pero fundamento no es verificación: acá
 *   se le pregunta a la base, ya escrito el registro, si `ST_Contains(geometry,
 *   representative_point)` es cierto, si el SRID es 4326 y si el tipo es el que
 *   corresponde al modo de la subcategoría. Si algo de eso no da, se revierte.
 *   Es el invariante no negociable de ADR-009, comprobado donde vive el dato.
 *
 *   LA FECHA EFECTIVA SE RECALCULA SIEMPRE. Es una columna derivada y
 *   materializada (ADR-008): si se escribiera sólo cuando cambian las fechas,
 *   bastaría un cambio de estado para desincronizarla.
 *
 *   LA VERSIÓN SE COMPARA EN EL `WHERE`, NO ANTES. Leer `lock_version`, comparar
 *   en PHP y después actualizar deja una ventana entre la lectura y la escritura
 *   por la que pasan exactamente las dos ediciones simultáneas que se quiere
 *   evitar. La comparación va en la misma sentencia que el `UPDATE`, y lo que
 *   decide es la cantidad de filas afectadas.
 *
 * El WKT viaja siempre por binding, nunca interpolado (RNF-SEC-003).
 */
final class WorkWriter
{
    /**
     * Lo que se registra en la bitácora al crear, editar o dar de baja.
     *
     * `deleted_at` y `deleted_by` están en la lista aunque sólo cambien en la
     * baja: sin ellos el evento `work.trashed` mostraría un antes y un después
     * idénticos salvo la versión, y una auditoría de una baja que no muestra la
     * baja no sirve para lo que existe la auditoría.
     */
    private const AUDITADOS = [
        'code', 'name', 'description', 'work_subcategory_id', 'work_status_id',
        'start_date', 'estimated_end_date', 'actual_end_date', 'effective_end_date',
        'street', 'street_number', 'locality', 'length_m', 'length_calc_method',
        'lock_version', 'deleted_at', 'deleted_by',
    ];

    public function __construct(
        private readonly WorkCodeGenerator $codigos,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos
     *
     * @throws WorkRuleViolation
     */
    public function crear(
        array $atributos,
        WorkGeometry $geometria,
        WorkSubcategory $subcategoria,
        WorkStatus $estado,
        ?User $actor = null,
    ): Work {
        return DB::transaction(function () use ($atributos, $geometria, $subcategoria, $estado, $actor): Work {
            $fechas = $this->fechasValidadas($atributos, $estado);
            $codigo = $this->codigos->next();

            $work = new Work;
            $work->fill($atributos);
            $work->forceFill([
                'code' => $codigo['code'],
                'sequence_number' => $codigo['sequence_number'],
                'code_year' => $codigo['code_year'],
                'work_subcategory_id' => $subcategoria->getKey(),
                'work_status_id' => $estado->getKey(),
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
                'lock_version' => 0,
            ]);

            $this->aplicarFechas($work, $fechas, $estado);
            $this->aplicarLongitud($work, $geometria);

            // Las columnas geométricas son `NOT NULL` sin default, así que la
            // fila no puede insertarse sin ellas y Eloquent no sabe construirlas.
            // Se insertan en la misma sentencia, por binding.
            $this->insertarConGeometria($work, $geometria);

            $this->verificarInvariante($work, $subcategoria);

            $this->audit->registrar(
                action: 'work.created',
                entityType: $work->getTable(),
                entityId: $work->getKey(),
                before: null,
                after: $this->snapshot($work->refresh()),
                actor: $actor,
            );

            return $work;
        });
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  WorkGeometry|null  $geometria  `null` deja la geometría como está
     *
     * @throws WorkRuleViolation|ConcurrentEditException
     */
    public function actualizar(
        Work $work,
        array $atributos,
        ?WorkGeometry $geometria,
        WorkSubcategory $subcategoria,
        WorkStatus $estado,
        int $versionEsperada,
        ?User $actor = null,
    ): Work {
        return DB::transaction(function () use (
            $work, $atributos, $geometria, $subcategoria, $estado, $versionEsperada, $actor
        ): Work {
            $antes = $this->snapshot($work);
            $fechas = $this->fechasValidadas($atributos, $estado, $work);

            // Cambiar de subcategoría cambiando de modo geométrico sin redibujar
            // dejaría un `POLYGON` guardado en algo que se dibuja como línea. La
            // geometría vieja no se puede convertir sola (RF-CAT-004).
            if ($geometria === null && $subcategoria->getKey() !== $work->work_subcategory_id) {
                $anterior = $work->subcategory;

                if ($anterior !== null && $anterior->expectedGeometryType() !== $subcategoria->expectedGeometryType()) {
                    throw new WorkRuleViolation(
                        "«{$subcategoria->name}» usa otra forma geométrica que la subcategoría "
                        .'actual de la obra. Hay que volver a dibujar la geometría para cambiarla.',
                    );
                }
            }

            $work->fill($atributos);
            $work->forceFill([
                'work_subcategory_id' => $subcategoria->getKey(),
                'work_status_id' => $estado->getKey(),
                'updated_by' => $actor?->getKey(),
            ]);

            $this->aplicarFechas($work, $fechas, $estado);

            if ($geometria !== null) {
                $this->aplicarLongitud($work, $geometria);
            }

            $this->guardarConVersion($work, $versionEsperada, $geometria);

            if ($geometria !== null) {
                $this->verificarInvariante($work, $subcategoria);
            }

            $this->audit->registrar(
                action: 'work.updated',
                entityType: $work->getTable(),
                entityId: $work->getKey(),
                before: $antes,
                after: $this->snapshot($work->refresh()),
                actor: $actor,
            );

            return $work;
        });
    }

    /**
     * Papelera lógica (RF-DEL-001): la obra deja de verse, no deja de existir.
     *
     * @throws ConcurrentEditException
     */
    public function enviarAPapelera(Work $work, int $versionEsperada, ?User $actor = null): void
    {
        DB::transaction(function () use ($work, $versionEsperada, $actor): void {
            $antes = $this->snapshot($work);

            // La baja también respeta la versión: si alguien acaba de editar la
            // obra, quien la manda a papelera está decidiendo sobre otra cosa de
            // la que tiene en pantalla.
            $afectadas = DB::table('works')
                ->where('id', $work->getKey())
                ->where('lock_version', $versionEsperada)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    // Quién la dio de baja es parte de la información, no sólo
                    // cuándo: sin esto la papelera no se puede auditar.
                    'deleted_by' => $actor?->getKey(),
                    'lock_version' => $versionEsperada + 1,
                    'updated_at' => now(),
                ]);

            if ($afectadas === 0) {
                $this->rechazarPorVersion($work, $versionEsperada);
            }

            $work->refresh();

            $this->audit->registrar(
                action: 'work.trashed',
                entityType: $work->getTable(),
                entityId: $work->getKey(),
                before: $antes,
                after: $this->snapshot($work),
                actor: $actor,
            );
        });
    }

    /**
     * Las tres reglas de fecha de ADR-008, resueltas juntas porque se condicionan.
     *
     * @param  array<string, mixed>  $atributos
     * @return array{start: Carbon, estimated: Carbon, actual: Carbon|null}
     *
     * @throws WorkRuleViolation
     */
    private function fechasValidadas(array $atributos, WorkStatus $estado, ?Work $work = null): array
    {
        $leer = function (string $clave) use ($atributos, $work): ?Carbon {
            if (array_key_exists($clave, $atributos)) {
                return $atributos[$clave] === null ? null : Carbon::parse((string) $atributos[$clave])->startOfDay();
            }

            $actual = $work?->getAttribute($clave);

            return $actual instanceof Carbon ? $actual->copy()->startOfDay() : null;
        };

        $inicio = $leer('start_date');
        $prevista = $leer('estimated_end_date');
        $real = $leer('actual_end_date');

        if ($inicio === null || $prevista === null) {
            throw new WorkRuleViolation('La obra necesita fecha de inicio y fecha de finalización prevista.');
        }

        if ($prevista->lt($inicio)) {
            throw new WorkRuleViolation(
                'La finalización prevista no puede ser anterior al inicio. Puede ser futura: es un pronóstico.',
            );
        }

        // La bandera del catálogo gobierna, nunca la clave `COMPLETED`: un estado
        // propio como «Finalizada con observaciones» también finaliza (D3).
        if ($estado->is_final && $real === null) {
            throw new WorkRuleViolation(
                "«{$estado->label}» es un estado finalizador, así que la obra necesita su fecha real "
                .'de finalización. La prevista no se sobrescribe: son dos datos distintos.',
            );
        }

        if ($real !== null) {
            if ($real->lt($inicio)) {
                throw new WorkRuleViolation('La finalización real no puede ser anterior al inicio.');
            }

            if ($real->gt(Carbon::today())) {
                throw new WorkRuleViolation(
                    'La finalización real no puede ser futura: es la fecha en que la obra terminó, '
                    .'no una estimación.',
                );
            }
        }

        return ['start' => $inicio, 'estimated' => $prevista, 'actual' => $real];
    }

    /** @param array{start: Carbon, estimated: Carbon, actual: Carbon|null} $fechas */
    private function aplicarFechas(Work $work, array $fechas, WorkStatus $estado): void
    {
        $work->start_date = $fechas['start'];
        $work->estimated_end_date = $fechas['estimated'];
        // La fecha real se CONSERVA al salir de un estado finalizador: es un dato
        // histórico. Lo que cambia es que deje de participar de la efectiva.
        $work->actual_end_date = $fechas['actual'];

        // Derivada y materializada, recalculada en cada guardado. Se resuelve con
        // el estado que se está por guardar, no con el que tiene el modelo
        // cargado: en una edición que cambia el estado, ese sería el viejo.
        $work->effective_end_date = $work->resolveEffectiveEndDate($estado);
    }

    private function aplicarLongitud(Work $work, WorkGeometry $geometria): void
    {
        // Punto y polígono la dejan en NULL, y eso incluye BORRARLA si la obra
        // era una línea y se redibujó de otra forma.
        $work->length_m = $geometria->lengthMeters;
        $work->length_calc_method = $geometria->lengthCalcMethod;
    }

    /** Inserta la fila con las dos columnas geométricas en la misma sentencia. */
    private function insertarConGeometria(Work $work, WorkGeometry $geometria): void
    {
        $atributos = $work->getAttributes();
        $ahora = now();
        $atributos['created_at'] = $ahora;
        $atributos['updated_at'] = $ahora;

        $geo = $this->expresionesGeometricas($geometria);

        $columnas = array_keys($atributos);

        $sql = sprintf(
            'INSERT INTO works (%s, geometry, representative_point) VALUES (%s, %s, %s)',
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columnas)),
            implode(', ', array_fill(0, count($columnas), '?')),
            $geo['geometry'],
            $geo['punto'],
        );

        $valores = array_map(
            static fn (mixed $valor): mixed => $valor instanceof Carbon ? $valor->toDateTimeString() : $valor,
            array_values($atributos),
        );

        DB::insert($sql, array_merge($valores, $geo['bindings']));

        $work->setAttribute($work->getKeyName(), (int) DB::getPdo()->lastInsertId());
        $work->exists = true;
        $work->wasRecentlyCreated = true;
        $work->syncOriginal();
    }

    /**
     * `UPDATE` guardado por versión: la comparación va en el `WHERE`.
     *
     * @throws ConcurrentEditException
     */
    private function guardarConVersion(Work $work, int $versionEsperada, ?WorkGeometry $geometria): void
    {
        $valores = $work->getDirty();
        unset($valores['id'], $valores['lock_version']);

        $valores['lock_version'] = $versionEsperada + 1;
        $valores['updated_at'] = now();

        $asignaciones = [];
        $bindings = [];

        foreach ($valores as $columna => $valor) {
            $asignaciones[] = "`{$columna}` = ?";
            $bindings[] = $valor instanceof Carbon ? $valor->toDateTimeString() : $valor;
        }

        if ($geometria !== null) {
            $geo = $this->expresionesGeometricas($geometria);
            $asignaciones[] = "`geometry` = {$geo['geometry']}";
            $asignaciones[] = "`representative_point` = {$geo['punto']}";
            $bindings = array_merge($bindings, $geo['bindings']);
        }

        $bindings[] = $work->getKey();
        $bindings[] = $versionEsperada;

        $afectadas = DB::update(
            sprintf(
                'UPDATE works SET %s WHERE id = ? AND lock_version = ? AND deleted_at IS NULL',
                implode(', ', $asignaciones),
            ),
            $bindings,
        );

        if ($afectadas === 0) {
            $this->rechazarPorVersion($work, $versionEsperada);
        }

        $work->syncOriginal();
    }

    /**
     * Nunca vuelve: o lanza el conflicto o lanza la regla que corresponda.
     *
     * @throws ConcurrentEditException|WorkRuleViolation
     */
    private function rechazarPorVersion(Work $work, int $versionEsperada): never
    {
        /** @var object{lock_version: int, deleted_at: string|null}|null $fila */
        $fila = DB::table('works')
            ->select('lock_version', 'deleted_at')
            ->where('id', $work->getKey())
            ->first();

        if ($fila === null) {
            throw new WorkRuleViolation('La obra ya no existe.');
        }

        if ($fila->deleted_at !== null) {
            throw new WorkRuleViolation('La obra está en la papelera: hay que restaurarla antes de modificarla.');
        }

        throw new ConcurrentEditException($versionEsperada, (int) $fila->lock_version);
    }

    /**
     * El invariante, preguntado a la base con la fila ya escrita (ADR-009).
     *
     * @throws WorkRuleViolation
     */
    private function verificarInvariante(Work $work, WorkSubcategory $subcategoria): void
    {
        /** @var object{srid: int, tipo: string, dentro: int|null} $fila */
        $fila = DB::selectOne(
            'SELECT ST_SRID(geometry) AS srid,
                    GeometryType(geometry) AS tipo,
                    ST_Contains(geometry, representative_point) AS dentro
             FROM works WHERE id = ?',
            [$work->getKey()],
        );

        if ((int) $fila->srid !== 4326) {
            throw new WorkRuleViolation(
                "La geometría quedó guardada con SRID {$fila->srid} en lugar de 4326.",
            );
        }

        $esperado = $subcategoria->expectedGeometryType();

        if (strtoupper((string) $fila->tipo) !== $esperado) {
            throw new WorkRuleViolation(
                "La geometría guardada es {$fila->tipo} y la subcategoría exige {$esperado}.",
            );
        }

        if (! (bool) $fila->dentro) {
            // Con una geometría válida esto no debería ocurrir nunca; que ocurra
            // significa que la elección del punto dejó de valer para alguna forma
            // nueva, y entonces lo correcto es no guardar.
            throw new WorkRuleViolation(
                'El punto representativo no quedó contenido en la geometría. '
                .'No se guarda una obra que el mapa no podría ubicar.',
            );
        }
    }

    /**
     * Las dos expresiones geométricas, cada una con su marcador, y los valores
     * que van en ellas en el orden en que aparecen.
     *
     * @return array{geometry: string, punto: string, bindings: list<string>}
     */
    private function expresionesGeometricas(WorkGeometry $geometria): array
    {
        // El WKT va dos veces porque el polígono lo necesita también adentro de
        // `ST_PointOnSurface`. Con marcadores posicionales se pasa dos veces; con
        // nombrados PDO no admite repetir el mismo, que es el error que ya
        // apareció una vez en los tests del recorte del IGN.
        if ($geometria->puntoLoCalculaLaBase()) {
            return [
                'geometry' => 'ST_GeomFromText(?, 4326)',
                'punto' => 'ST_PointOnSurface(ST_GeomFromText(?, 4326))',
                'bindings' => [$geometria->wkt, $geometria->wkt],
            ];
        }

        return [
            'geometry' => 'ST_GeomFromText(?, 4326)',
            'punto' => 'ST_GeomFromText(?, 4326)',
            'bindings' => [$geometria->wkt, (string) $geometria->representativePointWkt],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Work $work): array
    {
        $valores = [];

        foreach (self::AUDITADOS as $atributo) {
            $valor = $work->getAttribute($atributo);
            $valores[$atributo] = $valor instanceof Carbon ? $valor->toDateString() : $valor;
        }

        return $valores;
    }
}
