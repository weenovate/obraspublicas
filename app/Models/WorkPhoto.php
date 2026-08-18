<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Una fotografía de obra y el estado de su procesamiento (ADR-019).
 *
 * La regla que gobierna todo lo demás: **una foto sólo se publica en `READY`**.
 * La obra, en cambio, se publica de inmediato. Por eso el estado vive acá y no
 * en la obra, y por eso una falla de procesamiento no invalida nada ya guardado.
 *
 * @property int $id
 * @property int $work_id
 * @property string $status
 * @property string $disk
 * @property string $path_original
 * @property string|null $path_large
 * @property string|null $path_thumb
 * @property int $attempts
 * @property string|null $failure_reason
 * @property Carbon|null $processed_at
 */
class WorkPhoto extends Model
{
    /** @use HasFactory<WorkPhotoFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Recién subida: el archivo está, los derivados todavía no. */
    public const STATUS_PENDING = 'PENDING';

    /** Procesada y publicable. */
    public const STATUS_READY = 'READY';

    /** El procesamiento falló. Se puede reintentar; no se publica. */
    public const STATUS_FAILED = 'FAILED';

    /**
     * Cuántas veces se reintenta antes de dejarla en `FAILED` definitivo.
     *
     * Tres es suficiente para cubrir lo transitorio —un pico de memoria, un
     * disco momentáneamente lleno— sin insistir eternamente sobre lo que no va a
     * andar, como un archivo que dice ser JPEG y no lo es.
     */
    public const MAX_ATTEMPTS = 3;

    /** Lo único que edita una persona. El resto lo escribe el dominio. */
    protected $fillable = ['caption', 'sort_order'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'attempts' => 'integer',
            'sort_order' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Work, $this> */
    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * ¿Se puede mostrar donde la ve el público?
     *
     * Es la pregunta que hacen la web y LIVE, y la respuesta es una sola:
     * únicamente `READY`. Ni `PENDING` ni `FAILED` (ADR-019).
     */
    public function esPublicable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** ¿Vale la pena volver a intentarlo, o ya se insistió bastante? */
    public function sePuedeReintentar(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempts < self::MAX_ATTEMPTS;
    }

    /** Las que se publican, en el orden en que se muestran. */
    public function scopePublicables(mixed $query): mixed
    {
        return $query->where('status', self::STATUS_READY)->orderBy('sort_order')->orderBy('id');
    }
}
