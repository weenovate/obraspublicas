<?php

declare(strict_types=1);

namespace App\Support\Photos;

/**
 * Los tamaños que se generan de cada foto, y por qué son estos.
 *
 * `LARGE` es lo que se ve al abrir una foto en la ficha de la obra. 1600 px de
 * lado mayor cubre una pantalla grande sin que el archivo pese como el original
 * de una cámara de teléfono, que hoy ronda los 4000 px.
 *
 * `THUMB` es la grilla de la galería y el listado. 400 px alcanza para un
 * recuadro nítido incluso en pantallas de alta densidad, donde el navegador pide
 * el doble de píxeles que los CSS que ocupa.
 *
 * NO se genera un tamaño intermedio: cada derivado es tiempo de procesamiento y
 * espacio en disco por cada foto de cada obra, y con dos se cubren los dos usos
 * que existen hoy. Cuando F4 defina el mapa público se revisa con datos.
 */
final class PhotoDerivatives
{
    public const LARGE_MAX_SIDE = 1600;

    public const THUMB_MAX_SIDE = 400;

    /**
     * Sufijo del archivo → lado máximo. El sufijo es parte de la ruta guardada
     * en `work_photos`, así que agregar un tamaño acá no basta: hay que darle su
     * columna, o el derivado quedaría en disco sin que nadie sepa encontrarlo.
     *
     * @var array<string, int>
     */
    public const TAMANOS = [
        'large' => self::LARGE_MAX_SIDE,
        'thumb' => self::THUMB_MAX_SIDE,
    ];

    /**
     * Calidad de recompresión JPEG/WEBP.
     *
     * 82 es el punto donde la diferencia deja de verse a simple vista y el
     * archivo pesa la mitad. Subirlo engorda sin que nadie lo note.
     */
    public const QUALITY = 82;
}
