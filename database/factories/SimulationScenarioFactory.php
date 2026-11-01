<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SimulationScenarioFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Eksperimen '.fake()->word(), 'start_date' => '2026-01-01', 'initial_members' => 0, 'daily_new_members' => 0, 'average_deposit' => 1000000, 'daily_rate_bps' => 200, 'duration_days' => 60, 'payment_frequency_days' => 1, 'withdrawal_bps' => 0, 'initial_cash' => 0, 'status' => 'draft'];
    }
}
