<?php

namespace App\Jobs;

use App\Models\SimulationScenario;
use App\Services\LocalOperationsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLocalRoi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $workspaceId, public string $through) {}

    public function handle(LocalOperationsService $operations): void
    {
        $operations->process(SimulationScenario::findOrFail($this->workspaceId), $this->through);
    }
}
