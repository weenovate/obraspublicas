<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Escribe un catálogo y su auditoría en la misma transacción.
 *
 * Los cinco catálogos comparten exactamente el mismo patrón: validar la regla de
 * inmutabilidad, aplicar el cambio, y registrar antes y después (RF-CFG-002,
 * RF-AUD-001). Repetirlo cinco veces invita a que en el sexto alguien se olvide
 * de la transacción, que es justo donde la atomicidad se rompe en silencio.
 *
 * Los valores del `before` se toman ANTES de aplicar el cambio y con
 * `getOriginal()`, no releyendo el modelo: releer devolvería el estado nuevo.
 */
final class CatalogWriter
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  callable(): void  $cambio
     * @param  list<string>  $atributos  Los que interesa registrar
     */
    public function apply(
        Model $model,
        string $action,
        callable $cambio,
        array $atributos,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($model, $action, $cambio, $atributos, $actor): void {
            $antes = $model->exists ? $this->snapshot($model, $atributos) : null;

            $cambio();

            $this->audit->registrar(
                action: $action,
                entityType: $model->getTable(),
                entityId: $model->getKey(),
                before: $antes,
                after: $this->snapshot($model->refresh(), $atributos),
                actor: $actor,
            );
        });
    }

    /**
     * @param  list<string>  $atributos
     * @return array<string, mixed>
     */
    private function snapshot(Model $model, array $atributos): array
    {
        $valores = [];

        foreach ($atributos as $atributo) {
            $valores[$atributo] = $model->getAttribute($atributo);
        }

        return $valores;
    }
}
