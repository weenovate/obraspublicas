<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * El hash se calcula una sola vez por proceso: Argon2id es caro a propósito,
     * y pagarlo en cada usuario de cada test multiplica la duración de la suite
     * sin verificar nada nuevo.
     */
    protected static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$passwordHash ??= Hash::make('una-contrasena-de-doce-o-mas'),
            'role' => User::ROLE_OBRAS_PUBLICAS,
            'is_active' => true,
            'must_change_password' => false,
            // `null` = no eligió tema, manda el predeterminado (RF-CFG-005).
            'theme_preference' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => User::ROLE_ADMIN]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function conPasswordTemporal(): static
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }
}
