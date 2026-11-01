<?php

namespace App\Services;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class ScenarioContext
{
    public function resolve(Request $request): SimulationScenario
    {
        $data = $request->validate(['scenario' => 'nullable|integer|exists:simulation_scenarios,id']);
        $localId = SystemSetting::whereKey('local_workspace')->value('value');
        if ($localId && ! $request->user()?->isStaff()) {
            $s = SimulationScenario::whereKey($localId)->where('mode', 'local')->firstOrFail();
            $request->session()->put('scenario_id', $s->id);

            return $this->prepare($s);
        }
        if ($localId && ! $request->session()->has('local_workspace_context')) {
            $request->session()->put(['scenario_id' => (int) $localId, 'local_workspace_context' => true]);
        }
        $id = $data['scenario'] ?? $request->session()->get('scenario_id') ?? SystemSetting::whereKey('default_scenario')->value('value');
        $s = SimulationScenario::find($id) ?? SimulationScenario::firstOrFail();
        $request->session()->put('scenario_id', $s->id);

        return $this->prepare($s);
    }

    private function prepare(SimulationScenario $scenario): SimulationScenario
    {
        app(DailyRoiClaimService::class)->prepare($scenario);

        return $scenario;
    }
}
