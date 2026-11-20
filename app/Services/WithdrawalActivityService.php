<?php

namespace App\Services;

use App\Models\SimulationScenario;
use App\Models\WithdrawalRequest;
use Carbon\CarbonPeriod;

class WithdrawalActivityService
{
    public function report(SimulationScenario $workspace, string $from, string $to): array
    {
        $query = WithdrawalRequest::where('simulation_scenario_id', $workspace->id)
            ->where('status', 'approved')->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereHas('user', fn ($q) => $q->where('is_cohort', false)
                ->whereHas('roles', fn ($r) => $r->where('name', 'member'))
                ->whereDoesntHave('roles', fn ($r) => $r->where('name', 'super-admin')));
        $rows = (clone $query)->selectRaw('DATE(reviewed_at) AS date, COUNT(DISTINCT user_id) AS members, SUM(amount - admin_fee) AS net_amount')
            ->groupByRaw('DATE(reviewed_at)')->get()->keyBy('date');
        $chart = ['labels' => [], 'members' => [], 'amounts' => []];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $row = $rows->get($date->toDateString());
            $chart['labels'][] = $date->format('d M');
            $chart['members'][] = (int) ($row?->members ?? 0);
            $chart['amounts'][] = (int) ($row?->net_amount ?? 0);
        }

        return ['chart' => $chart, 'member_count' => (clone $query)->distinct()->count('user_id'),
            'net_amount' => (int) $rows->sum('net_amount'), 'from' => $from, 'to' => $to];
    }
}
