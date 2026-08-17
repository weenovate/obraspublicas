<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\WorkCategory;

/**
 * Registro cerrado de iconos (RF-CAT-002).
 *
 * No se admite SVG ni HTML del usuario: un icono subido es una vía de entrada
 * para scripts en cada pantalla donde se dibuje la categoría.
 *
 * EL REGISTRO ES SÓLO-AGREGAR. Quitar un identificador dejaría a las categorías
 * que lo referencian con un marcador roto o vacío en el mapa público, y eso se
 * descubre mirando el mapa, no desplegando. Para retirar un icono de la oferta
 * sin romper nada existe `SELECCIONABLES`: sale del selector, sigue en el
 * registro.
 */
final class IconRegistry
{
    /**
     * Todos los iconos que existieron alguna vez. Sólo se agregan entradas.
     *
     * @var array<string, string> identificador => etiqueta
     */
    public const ICONS = [
        'road' => 'Camino',
        'bridge' => 'Puente',
        'water-drop' => 'Agua',
        'pipe' => 'Cañería',
        'lightbulb' => 'Alumbrado',
        'tree' => 'Espacio verde',
        'building' => 'Edificio',
        'school' => 'Escuela',
        'hospital' => 'Salud',
        'sports' => 'Deportes',
        'traffic-light' => 'Tránsito',
        'trash' => 'Higiene urbana',
        'wave' => 'Hidráulica',
        'plug' => 'Energía',
        'bench' => 'Mobiliario urbano',
        'sign' => 'Señalización',
    ];

    /**
     * Los que se ofrecen hoy en el selector. Retirar uno de acá es seguro;
     * borrarlo de `ICONS` no lo es.
     *
     * @var list<string>
     */
    public const SELECCIONABLES = [
        'road', 'bridge', 'water-drop', 'pipe', 'lightbulb', 'tree', 'building',
        'school', 'hospital', 'sports', 'traffic-light', 'trash', 'wave', 'plug',
        'bench', 'sign',
    ];

    public static function exists(string $icon): bool
    {
        return array_key_exists($icon, self::ICONS);
    }

    public static function isSelectable(string $icon): bool
    {
        return in_array($icon, self::SELECCIONABLES, true);
    }

    /** @return array<string, string> */
    public static function selectable(): array
    {
        return array_intersect_key(self::ICONS, array_flip(self::SELECCIONABLES));
    }

    /**
     * Identificadores referenciados por categorías que ya no existen en el
     * registro.
     *
     * Se ejecuta al arrancar: el problema tiene que aparecer al desplegar y no en
     * el mapa público.
     *
     * @return list<string>
     */
    public static function missingReferences(): array
    {
        $usados = WorkCategory::query()
            ->distinct()
            ->pluck('icon')
            ->all();

        return array_values(array_filter(
            $usados,
            static fn (string $icon): bool => ! self::exists($icon),
        ));
    }
}
