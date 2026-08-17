<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\AppSetting;
use InvalidArgumentException;

/**
 * Configuración funcional tipada (RF-CFG-001…003).
 *
 * Tres reglas que vienen del spec y que este servicio hace cumplir:
 *
 *   1. NO HAY CLAVES LIBRES. El catálogo de abajo es la lista completa; guardar
 *      algo que no esté declarado es un error, no una fila nueva.
 *   2. NINGÚN SECRETO. Claves de API, contraseñas y credenciales no se editan
 *      desde la interfaz ni se guardan en auditoría: se inyectan por variables de
 *      entorno (RF-CFG-003). Por eso acá no hay ni una sola.
 *   3. TODO CAMBIO SE AUDITA con valor anterior y posterior (RF-CFG-002). De eso
 *      se ocupa el servicio de aplicación que llama a `set()`, dentro de la misma
 *      transacción.
 */
final class AppSettings
{
    public const DEFAULT_THEME = 'default_theme';

    public const LIVE_TOUR_SECONDS = 'live_tour_seconds';

    public const LIVE_PAUSE_SECONDS = 'live_pause_seconds';

    public const LIVE_POLL_SECONDS = 'live_poll_seconds';

    public const PUBLIC_POLL_SECONDS = 'public_poll_seconds';

    public const MAX_PHOTOS_PER_WORK = 'max_photos_per_work';

    public const MAX_PHOTO_MB = 'max_photo_mb';

    public const SESSION_IDLE_MINUTES = 'session_idle_minutes';

    /**
     * El catálogo completo. Agregar una opción es agregar una entrada acá.
     *
     * @return array<string, SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::DEFAULT_THEME => new SettingDefinition(
                key: self::DEFAULT_THEME,
                dataType: 'ENUM',
                default: 'light',
                label: 'Tema predeterminado',
                // RF-CFG-005: es el respaldo cuando el usuario no eligió tema, y
                // el que se usa en la pantalla de ingreso.
                help: 'Se aplica al ingresar y a quienes todavía no eligieron un tema.',
                allowed: ['light', 'dark'],
            ),
            self::LIVE_TOUR_SECONDS => new SettingDefinition(
                key: self::LIVE_TOUR_SECONDS,
                dataType: 'INTEGER',
                default: 12,
                label: 'Intervalo del recorrido de LIVE',
                help: 'Segundos que cada obra permanece en pantalla.',
                min: 5,
                max: 120, // RF-LIV-009
            ),
            self::LIVE_PAUSE_SECONDS => new SettingDefinition(
                key: self::LIVE_PAUSE_SECONDS,
                dataType: 'INTEGER',
                default: 60,
                label: 'Pausa tras interacción manual',
                help: 'Segundos que el recorrido queda pausado si alguien toca la pantalla.',
                min: 10,
                max: 600,
            ),
            self::LIVE_POLL_SECONDS => new SettingDefinition(
                key: self::LIVE_POLL_SECONDS,
                dataType: 'INTEGER',
                default: 15,
                label: 'Sondeo de LIVE',
                // El presupuesto de propagación de RF-BO-010 es de 30 s para
                // LIVE, y el peor caso es el intervalo de sondeo: por eso el
                // máximo es 30 y no un número mayor.
                help: 'Cada cuántos segundos la pantalla busca cambios. El máximo mantiene el presupuesto de 30 s.',
                min: 5,
                max: 30,
            ),
            self::PUBLIC_POLL_SECONDS => new SettingDefinition(
                key: self::PUBLIC_POLL_SECONDS,
                dataType: 'INTEGER',
                default: 30,
                label: 'Sondeo de la Web pública',
                help: 'El máximo mantiene el presupuesto de 60 s de RF-BO-010.',
                min: 10,
                max: 60,
            ),
            self::MAX_PHOTOS_PER_WORK => new SettingDefinition(
                key: self::MAX_PHOTOS_PER_WORK,
                dataType: 'INTEGER',
                default: 10,
                label: 'Fotos por obra',
                help: 'Hasta el máximo técnico definido en la especificación.',
                min: 1,
                max: 10,
            ),
            self::MAX_PHOTO_MB => new SettingDefinition(
                key: self::MAX_PHOTO_MB,
                dataType: 'INTEGER',
                default: 10,
                label: 'Tamaño máximo por foto (MB)',
                min: 1,
                max: 10,
            ),
            self::SESSION_IDLE_MINUTES => new SettingDefinition(
                key: self::SESSION_IDLE_MINUTES,
                dataType: 'INTEGER',
                default: 480, // 8 h, RF-AUT-006
                label: 'Inactividad que cierra la sesión (minutos)',
                help: 'No afecta a las pantallas LIVE con sesión persistente.',
                min: 30,
                max: 1440,
            ),
        ];
    }

    public static function definition(string $key): SettingDefinition
    {
        $definitions = self::definitions();

        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException(
                "«{$key}» no es una opción de configuración conocida. "
                .'La configuración es tipada: no admite claves libres.',
            );
        }

        return $definitions[$key];
    }

    /** Lee una opción, con su valor por omisión si nunca se guardó. */
    public static function get(string $key): mixed
    {
        $definition = self::definition($key);
        $row = AppSetting::query()->find($key);

        if ($row === null || $row->value === null) {
            return $definition->default;
        }

        return self::cast($row->value, $definition->dataType);
    }

    /**
     * Todas las opciones con su valor efectivo.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = AppSetting::query()->pluck('value', 'key');

        $valores = [];
        foreach (self::definitions() as $key => $definition) {
            $raw = $stored[$key] ?? null;
            $valores[$key] = $raw === null
                ? $definition->default
                : self::cast($raw, $definition->dataType);
        }

        return $valores;
    }

    /**
     * Guarda una opción validando tipo y rango. Devuelve el valor anterior, que
     * es lo que la auditoría necesita para registrar el antes y el después.
     */
    public static function set(string $key, mixed $value): mixed
    {
        $definition = self::definition($key);
        $anterior = self::get($key);

        self::assertValid($definition, $value);

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['data_type' => $definition->dataType, 'value' => self::serialize($value, $definition->dataType)],
        );

        return $anterior;
    }

    public static function assertValid(SettingDefinition $definition, mixed $value): void
    {
        switch ($definition->dataType) {
            case 'INTEGER':
                if (! is_numeric($value) || (int) $value !== $value) {
                    throw new InvalidArgumentException("«{$definition->label}» tiene que ser un número entero.");
                }
                $entero = (int) $value;
                if ($definition->min !== null && $entero < $definition->min) {
                    throw new InvalidArgumentException(
                        "«{$definition->label}» no puede ser menor que {$definition->min}.",
                    );
                }
                if ($definition->max !== null && $entero > $definition->max) {
                    throw new InvalidArgumentException(
                        "«{$definition->label}» no puede ser mayor que {$definition->max}.",
                    );
                }
                break;

            case 'ENUM':
                if (! in_array($value, $definition->allowed ?? [], true)) {
                    $admitidos = implode(', ', $definition->allowed ?? []);
                    throw new InvalidArgumentException(
                        "«{$definition->label}» sólo admite: {$admitidos}.",
                    );
                }
                break;

            case 'BOOLEAN':
                if (! is_bool($value)) {
                    throw new InvalidArgumentException("«{$definition->label}» tiene que ser sí o no.");
                }
                break;
        }
    }

    private static function cast(string $raw, string $dataType): mixed
    {
        return match ($dataType) {
            'INTEGER' => (int) $raw,
            'BOOLEAN' => $raw === '1',
            'JSON' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
            default => $raw,
        };
    }

    private static function serialize(mixed $value, string $dataType): string
    {
        return match ($dataType) {
            'BOOLEAN' => $value ? '1' : '0',
            'JSON' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
