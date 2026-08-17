<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor de configuración funcional (RF-CFG-001).
 *
 * La clave es la primaria: hay una fila por opción y el catálogo de opciones
 * válidas vive en el código, no en la base.
 *
 * @property string $key
 * @property string $data_type
 * @property string|null $value
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'data_type', 'value'];
}
