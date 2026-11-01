<?php

namespace App\Services;

use App\Models\SimulationScenario;
use Carbon\Carbon;

class WorkspaceClock
{
    public function date(SimulationScenario $workspace): Carbon
    {
        return $workspace->mode === 'local'
            ? today()
            : $workspace->start_date->copy()->addDays($workspace->current_day);
    }
}
