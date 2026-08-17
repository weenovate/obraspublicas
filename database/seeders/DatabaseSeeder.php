<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Los cinco estados base son parte del sistema, no datos de ejemplo:
        // sin ellos no se puede dar de alta ninguna obra.
        $this->call(CatalogoBaseSeeder::class);
    }
}
