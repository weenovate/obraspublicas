<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuthSession> */
class AuthSessionFactory extends Factory
{
    protected $model = AuthSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_id' => Str::random(40),
            'device_label' => 'Chrome en Windows',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'is_persistent' => false,
            'last_seen_at' => now(),
        ];
    }

    /** Sesión de una pantalla LIVE: no vence por inactividad (RF-AUT-007). */
    public function persistente(): static
    {
        return $this->state(fn (): array => [
            'is_persistent' => true,
            'device_label' => 'Pantalla del hall',
        ]);
    }
}
