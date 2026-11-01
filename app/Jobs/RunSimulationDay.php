<?php

namespace App\Jobs;

use App\Models\SimulationScenario;
use App\Services\SimulationEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSimulationDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $scenarioId, public int $targetDay) {}

    public function handle(SimulationEngineService $engine): void
    {
        $engine->step(SimulationScenario::findOrFail($this->scenarioId), $this->targetDay);
    }
}
