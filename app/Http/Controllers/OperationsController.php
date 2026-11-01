<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessLocalRoi;
use App\Models\MockPaymentInvoice;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\WithdrawalRequest;
use App\Services\LocalOperationsService;
use App\Services\RiskAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperationsController extends Controller
{
    private function workspace(): SimulationScenario
    {
        return SimulationScenario::whereKey(SystemSetting::whereKey('local_workspace')->value('value'))->where('mode', 'local')->firstOrFail();
    }

    public function index(Request $request)
    {
        Gate::authorize('view-reports');
        $s = $this->workspace();
        $request->session()->put(['scenario_id' => $s->id, 'local_workspace_context' => true]);
        app(\App\Services\DailyRoiClaimService::class)->prepare($s);
        $risk = app(RiskAnalysisService::class)->analyze($s);
        $pendingDeposits = MockPaymentInvoice::where('simulation_scenario_id', $s->id)->where('workflow', 'manual_local')->where('status', 'awaiting_review')->with('user')->latest('submitted_at')->paginate(10);
        $pendingWithdrawals = WithdrawalRequest::where('simulation_scenario_id', $s->id)->where('status', 'pending')->count();
        $runs = $s->runs()->latest('day')->limit(7)->get();

        return view('operations.index', compact('s', 'risk', 'pendingDeposits', 'pendingWithdrawals', 'runs'));
    }

    public function process(Request $request, LocalOperationsService $service)
    {
        Gate::authorize('manage');
        $data = $request->validate(['execution' => 'required|in:sync,queue']);
        $s = $this->workspace();
        if ($data['execution'] === 'queue') {
            ProcessLocalRoi::dispatch($s->id, today()->toDateString());

            return back()->with('status', 'Persiapan klaim ROI, penghangusan klaim lewat hari, dan pengembalian modal masuk antrean.');
        }
        $run = $service->process($s);

        return back()->with('status', 'Hak klaim ROI dan pengembalian modal diproses sampai '.$run->period_date->format('d M Y').'. Kewajiban dan pembayaran tercatat pada ledger.');
    }
}
