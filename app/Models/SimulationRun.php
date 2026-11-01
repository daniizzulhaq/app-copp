<?php

namespace App\Models;

class SimulationRun extends Record
{
    protected function casts(): array
    {
        return ['period_date' => 'date'];
    }

    public function scenario()
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }
}
