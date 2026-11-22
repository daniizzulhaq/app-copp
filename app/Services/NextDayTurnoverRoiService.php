<?php

namespace App\Services;

use App\Models\DailyProductTurnover;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class NextDayTurnoverRoiService
{
    public function accrue(SimulationScenario $scenario, CarbonInterface $through): int
    {
        return DB::transaction(function () use ($scenario, $through) {
            $workspace = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (! $workspace->usesNextDayTurnoverRoi() || $workspace->status === 'archived') {
                return 0;
            }
            $limit = $through->lessThan(today()) ? $through->toDateString() : today()->toDateString();
            $firstSource = ($workspace->roi_claims_start_date ?? $workspace->start_date)->copy()->subDay()->toDateString();
            $days = DailyProductTurnover::where('simulation_scenario_id', $workspace->id)
                ->where('period_date', '>=', $firstSource)->where('period_date', '<', $limit)->orderBy('period_date')->get();
            $created = 0;
            foreach ($days as $day) {
                $sourceDate = $day->period_date->toDateString();
                $claimDate = $day->period_date->copy()->addDay()->toDateString();
                if (DB::table('daily_roi_claim_pools')->where('simulation_scenario_id', $workspace->id)->where('period_date', $sourceDate)->exists()) {
                    continue;
                }
                // Old same-day payouts consume the same sales budget and must never be paid twice.
                $legacyPaid = $this->legacyPaid($workspace, $sourceDate);
                $available = max(0, $day->roi_amount - $legacyPaid);
                $investments = $workspace->investments()->where('roi_method', 'turnover')
                    ->where('status', 'active')->whereDate('starts_at', '<=', $sourceDate)
                    ->withSum('obligations', 'amount')->orderBy('id')->get()
                    ->filter(fn ($investment) => $investment->promised() > (int) $investment->obligations_sum_amount);
                // Every active product receives one equal portion, regardless of its old weight.
                $totalProducts = $investments->count();
                $profitPerProduct = $totalProducts > 0 ? intdiv($available, $totalProducts) : 0;
                $poolId = DB::table('daily_roi_claim_pools')->insertGetId([
                    'simulation_scenario_id' => $workspace->id, 'period_date' => $sourceDate, 'claim_date' => $claimDate,
                    'pool_amount' => $day->roi_amount, 'legacy_paid_amount' => $legacyPaid,
                    'distributable_amount' => $available, 'total_shares' => $totalProducts, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($investments as $investment) {
                    $remaining = $investment->promised() - (int) $investment->obligations_sum_amount;
                    $amount = min($remaining, $profitPerProduct);
                    $obligation = null;
                    if ($amount > 0) {
                        $obligation = RoiObligation::firstOrNew([
                            'investment_id' => $investment->id, 'due_date' => $claimDate,
                        ], ['amount' => 0]);
                        $new = ! $obligation->exists;
                        // A transition date can also contain a legacy claim from a different sales day.
                        $obligation->fill(['amount' => $obligation->amount + $amount, 'status' => 'due'])->save();
                        $ledger = app(LedgerService::class);
                        $ledger->initialize($workspace);
                        $ledger->post($workspace, 'next-day-roi:'.$poolId.':'.$investment->id, 'roi_accrued',
                            'ROI omzet '.$sourceDate.', klaim '.$claimDate,
                            [['roi-expense', 'debit', $amount], ['roi-payable', 'credit', $amount]],
                            $investment->user_id, $claimDate);
                        $created += (int) $new;
                        if ($amount === $remaining && $investment->ends_at->toDateString() > $claimDate) {
                            $investment->ends_at = $claimDate;
                            if (! $investment->returns_principal) {
                                $investment->status = 'completed';
                            }
                            $investment->save();
                        }
                    }
                    DB::table('daily_roi_claim_shares')->insert([
                        'daily_roi_claim_pool_id' => $poolId, 'investment_id' => $investment->id,
                        'roi_obligation_id' => $obligation?->id, 'shares' => 1,
                        'remaining_cap' => $remaining, 'amount' => $amount,
                    ]);
                }
            }

            return $created;
        }, 5);
    }

    private function legacyPaid(SimulationScenario $workspace, string $sourceDate): int
    {
        $pool = DB::table('daily_roi_pools')->where('simulation_scenario_id', $workspace->id)->where('period_date', $sourceDate)->first();
        if (! $pool || ! $pool->total_shares) {
            return 0;
        }
        $parts = DB::table('daily_roi_shares as s')
            ->leftJoin('roi_obligations as o', fn ($join) => $join->on('o.investment_id', '=', 's.investment_id')->where('o.due_date', '=', $sourceDate))
            ->where('s.daily_roi_pool_id', $pool->id)->select('s.*', 'o.paid_amount')->get();

        return (int) $parts->sum(fn ($part) => min((int) $part->paid_amount,
            min($part->remaining_cap, intdiv($pool->pool_amount * $part->shares, $pool->total_shares))));
    }
}
