<?php

namespace App\Livewire;

use App\Models\SimulationScenario;
use App\Services\RiskAnalysisService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Optional enhancement, activated automatically after installing Livewire 4. */
class ScenarioMonitor extends Component
{
    #[Locked]
    public int $scenarioId;

    public function mount(int $scenarioId): void
    {
        Gate::authorize('view-reports');
        $this->scenarioId = $scenarioId;
    }

    public function render()
    {
        Gate::authorize('view-reports');
        $scenario = SimulationScenario::findOrFail($this->scenarioId);

        return view('livewire.scenario-monitor', ['scenario' => $scenario, 'risk' => app(RiskAnalysisService::class)->analyze($scenario)]);
    }
}
