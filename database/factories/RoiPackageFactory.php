<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoiPackageFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->word().' Plan', 'minimum_amount' => 100000, 'daily_rate_bps' => 100, 'duration_days' => 30, 'active' => true];
    }
}
