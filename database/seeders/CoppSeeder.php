<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CoppSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductPlanSeeder::class);
        $this->call(AccountSeeder::class);
    }
}
