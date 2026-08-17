<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Capacidades administrativas (matriz de permisos, spec 2.2).
 *
 * Con dos roles y sin jerarquía intermedia, la matriz se reduce a una pregunta:
 * ¿esta capacidad es administrativa? Un sistema de permisos granular sería más
 * flexible y también más fácil de configurar mal, y el spec no lo pide.
 *
 * Lo que puede hacer Obras Públicas —consultar el mapa, acceder a LIVE, crear y
 * editar obras y fotos, enviarlas a papelera— no necesita política: le alcanza
 * con estar autenticado y activo.
 *
 * Lo que es exclusivo del Admin: usuarios, catálogos, configuración, auditoría,
 * restaurar y eliminar definitivamente.
 */
final class AdminPolicy
{
    /** Capacidades del spec 2.2 reservadas al Administrador. */
    public const GESTIONAR_USUARIOS = 'gestionar-usuarios';

    public const GESTIONAR_CATALOGOS = 'gestionar-catalogos';

    public const GESTIONAR_CONFIGURACION = 'gestionar-configuracion';

    public const VER_AUDITORIA = 'ver-auditoria';

    public const GESTIONAR_PAPELERA = 'gestionar-papelera';

    /** @var list<string> */
    public const CAPACIDADES = [
        self::GESTIONAR_USUARIOS,
        self::GESTIONAR_CATALOGOS,
        self::GESTIONAR_CONFIGURACION,
        self::VER_AUDITORIA,
        self::GESTIONAR_PAPELERA,
    ];

    public static function permite(User $user): bool
    {
        // Un usuario desactivado no conserva permisos aunque su sesión siga
        // abierta: el middleware la corta, y esto es la segunda línea.
        return $user->is_active && $user->isAdmin();
    }
}
