<?php

namespace App\Services;

use App\Models\RoiObligation;
use App\Models\SimulationScenario;

class RiskAnalysisService
{
    public function analyze(SimulationScenario $s): array
    {
        $cash = app(LedgerService::class)->balance($s, 'cash');
        $out = (int) RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS total')->value('total');
        $principalOutstanding = app(PrincipalReturnService::class)->outstanding($s, app(WorkspaceClock::class)->date($s)->toDateString());
        $out += $principalOutstanding;
        $runs = $s->runs()->orderByDesc('day')->limit(7)->get();
        $avg = (int) round($runs->avg('roi_due') ?? 0);
        $last = $runs->first();
        if ($s->isLocal()) {
            $wallets = $s->accounts()->whereNotNull('wallet_id');
            return ['cash' => 0, 'gap' => 0, 'runway' => null, 'cash_ratio' => null, 'dependency' => 0, 'first_default' => null,
                'outstanding' => $out, 'principal_outstanding' => $principalOutstanding,
                'coverage' => ($last?->coverage_bps ?? 10000) / 100, 'status' => 'Saldo web aktif',
                'wallet_available' => (int) (clone $wallets)->where('code', 'like', '%:available')->sum('balance'),
                'wallet_pending' => (int) (clone $wallets)->where('code', 'like', '%:pending')->sum('balance')];
        }

        return ['cash' => $cash, 'outstanding' => $out, 'principal_outstanding' => $principalOutstanding, 'gap' => max(0, $out - $cash), 'runway' => $avg > 0 ? round($cash / $avg, 1) : null,
            'cash_ratio' => $out > 0 ? round($cash / $out * 100, 2) : null, 'coverage' => ($last?->coverage_bps ?? 10000) / 100,
            'dependency' => ($last?->dependency_bps ?? 0) / 100, 'first_default' => $s->runs()->where('status', 'defaulted')->orderBy('day')->value('day'),
            'status' => $out > 0 ? 'Krisis likuiditas' : ($avg > 0 && $cash < $avg * 7 ? 'Perlu perhatian' : 'Kas sementara mencukupi')];
    }
}
