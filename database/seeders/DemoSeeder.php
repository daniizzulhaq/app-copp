<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// Retained as a compatible entry point; legacy demo data is no longer generated.
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CoppSeeder::class]);
    }
}
