<?php

namespace App\Services;

use App\Models\DailyProductTurnover;
use App\Models\Investment;
use App\Models\SimulationScenario;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProductTurnoverService
{
    public function record(Investment $investment): void
    {
        DB::transaction(function () use ($investment) {
            $workspace = SimulationScenario::whereKey($investment->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            if (! $workspace->isLocal()) {
                return;
            }
            // Recompute from persisted purchases so retries cannot count a purchase twice.
            $purchases = Investment::where('simulation_scenario_id', $workspace->id)
                ->whereDate('starts_at', $investment->starts_at->toDateString());
            $gross = (int) (clone $purchases)->sum('principal');
            $day = DailyProductTurnover::firstOrNew([
                'simulation_scenario_id' => $workspace->id,
                'period_date' => $investment->starts_at->toDateString(),
            ], ['roi_rate_bps' => 3000]);
            $before = $day->exists ? $day->only(['gross_amount', 'purchase_count', 'roi_rate_bps', 'roi_amount']) : null;
            $day->fill([
                'gross_amount' => $gross, 'purchase_count' => $purchases->count(),
                'roi_amount' => intdiv($gross * $day->roi_rate_bps, 10000),
            ]);
            if (! $day->exists || $day->isDirty()) {
                $day->save();
                app(AuditService::class)->record('product_turnover.recorded', $day, $before,
                    $day->only(['period_date', 'gross_amount', 'purchase_count', 'roi_rate_bps', 'roi_amount']), $workspace->id);
            }
        }, 5);
    }

    public function report(SimulationScenario $workspace, string $from, string $to): array
    {
        $period = $this->dailyQuery($workspace, $from, $to);
        $totals = DB::query()->fromSub(clone $period, 'summary')->selectRaw('
            COALESCE(SUM(gross_amount), 0) as gross,
            COALESCE(SUM(roi_amount), 0) as roi,
            COALESCE(SUM(funds_in), 0) as funds_in,
            COALESCE(SUM(referral_bonus), 0) as referral_bonus,
            COALESCE(SUM(mission_bonus), 0) as mission_bonus,
            COALESCE(SUM(bonus_amount), 0) as bonus
        ')->first();
        $today = today()->toDateString();

        return [
            'today' => $this->figures($this->dailyQuery($workspace, $today, $today)->first()),
            'gross' => (int) $totals->gross, 'roi' => (int) $totals->roi,
            'funds_in' => (int) $totals->funds_in, 'bonus' => (int) $totals->bonus,
            'referral_bonus' => (int) $totals->referral_bonus, 'mission_bonus' => (int) $totals->mission_bonus,
            'days' => $period->orderByDesc('days.period_date')->paginate(15, ['*'], 'turnover_page')
                ->withQueryString()->through(fn ($row) => $this->figures($row)),
            'from' => $from, 'to' => $to,
        ];
    }

    private function dailyQuery(SimulationScenario $workspace, string $from, string $to): Builder
    {
        $products = DB::table('daily_product_turnovers')->where('simulation_scenario_id', $workspace->id)
            ->whereBetween('period_date', [$from, $to]);
        // Select one debit per posting, so the two ledger sides are never counted twice.
        $money = DB::table('ledger_transactions as t')
            ->join('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('t.simulation_scenario_id', $workspace->id)
            ->whereBetween('t.effective_date', [$from, $to])
            ->whereIn('t.kind', ['deposit', 'referral_bonus', 'mission_bonus'])
            ->where('a.code', 'reserve')->where('e.side', 'debit')
            ->groupBy('t.effective_date');
        // Include deposits and bonuses on dates with no product purchases.
        $dates = (clone $products)->select('period_date')
            ->union((clone $money)->select('t.effective_date as period_date'));

        return DB::query()->fromSub($dates, 'days')
            ->leftJoinSub($products, 'p', 'p.period_date', '=', 'days.period_date')
            ->leftJoinSub($money->selectRaw("t.effective_date as period_date,
                SUM(CASE WHEN t.kind = 'deposit' THEN e.amount ELSE 0 END) as funds_in,
                SUM(CASE WHEN t.kind = 'referral_bonus' THEN e.amount ELSE 0 END) as referral_bonus,
                SUM(CASE WHEN t.kind = 'mission_bonus' THEN e.amount ELSE 0 END) as mission_bonus"),
                'm', 'm.period_date', '=', 'days.period_date')
            ->selectRaw('days.period_date,
                COALESCE(p.gross_amount, 0) as gross_amount,
                COALESCE(p.purchase_count, 0) as purchase_count,
                COALESCE(p.roi_rate_bps, 3000) as roi_rate_bps,
                COALESCE(p.roi_amount, 0) as roi_amount,
                COALESCE(m.funds_in, 0) as funds_in,
                COALESCE(m.referral_bonus, 0) as referral_bonus,
                COALESCE(m.mission_bonus, 0) as mission_bonus,
                COALESCE(m.referral_bonus, 0) + COALESCE(m.mission_bonus, 0) as bonus_amount');
    }

    private function figures(?object $row): object
    {
        $figures = ['period_date' => $row ? Carbon::parse($row->period_date) : today(),
            'roi_rate_bps' => (int) ($row?->roi_rate_bps ?? 3000)];
        foreach (['gross_amount', 'purchase_count', 'roi_amount', 'funds_in', 'referral_bonus', 'mission_bonus', 'bonus_amount'] as $field) {
            $figures[$field] = (int) ($row?->$field ?? 0);
        }

        return (object) $figures;
    }
}
