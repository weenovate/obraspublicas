<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\AdminPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registro de las capacidades de la matriz de permisos (spec 2.2).
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (AdminPolicy::CAPACIDADES as $capacidad) {
            Gate::define($capacidad, static fn (User $user): bool => AdminPolicy::permite($user));
        }
    }
}
