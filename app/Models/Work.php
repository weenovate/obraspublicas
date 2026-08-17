<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Obra pública. En F1-A existe como esquema y modelo; su CRUD llega en F1-B.
 *
 * @property int $id
 * @property string $code
 * @property int $sequence_number
 * @property Carbon $start_date
 * @property Carbon $estimated_end_date
 * @property Carbon|null $actual_end_date
 * @property Carbon $effective_end_date
 */
class Work extends Model
{
    /** @use HasFactory<WorkFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * `code` y `sequence_number` NO son asignables: los genera el dominio y son
     * inmutables. Tampoco lo son las columnas geométricas, que pasan por
     * `GeometryService` con su invariante `ST_Contains`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'start_date',
        'estimated_end_date',
        'actual_end_date',
        'street',
        'street_number',
        'locality',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'estimated_end_date' => 'date',
            'actual_end_date' => 'date',
            'effective_end_date' => 'date',
            'published_at' => 'datetime',
            'length_m' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Guarda de aplicación para la inmutabilidad del código (RF-OBR-003). El
        // índice único impide duplicados; esto impide el cambio, que es otra cosa
        // y no la cubre ningún índice.
        static::updating(function (self $work): void {
            foreach (['code', 'sequence_number', 'code_year'] as $columna) {
                if ($work->isDirty($columna)) {
                    throw new RuntimeException(
                        "El código de obra es inmutable: no se puede cambiar `{$columna}` "
                        ."de «{$work->getOriginal($columna)}» a «{$work->$columna}».",
                    );
                }
            }
        });
    }

    /** @return BelongsTo<WorkSubcategory, $this> */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(WorkSubcategory::class, 'work_subcategory_id');
    }

    /** @return BelongsTo<WorkStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkStatus::class, 'work_status_id');
    }

    /**
     * La fecha efectiva que corresponde al estado actual (ADR-008).
     *
     * `CASE WHEN status.is_final THEN actual ELSE estimated END`, nunca
     * `COALESCE`: como `actual_end_date` se conserva al salir de un estado
     * finalizador, un `COALESCE` devolvería esa fecha real histórica aunque la
     * obra ya no esté terminada.
     */
    public function resolveEffectiveEndDate(?WorkStatus $status = null): Carbon
    {
        $status ??= $this->status;

        return $status !== null && $status->is_final && $this->actual_end_date !== null
            ? $this->actual_end_date
            : $this->estimated_end_date;
    }
}
