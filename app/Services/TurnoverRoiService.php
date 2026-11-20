<?php

namespace App\Services;

use App\Models\DailyProductTurnover;
use App\Models\Investment;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TurnoverRoiService
{
    public function accrue(SimulationScenario $scenario, CarbonInterface $through): int
    {
        return DB::transaction(function () use ($scenario, $through) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (! $s->usesTurnoverRoi() || $s->status === 'archived') {
                return 0;
            }
            $created = 0;
            $limit = $through->lessThan(today()) ? $through->toDateString() : today()->toDateString();
            $days = DailyProductTurnover::where('simulation_scenario_id', $s->id)
                ->where('period_date', '<=', $limit)->orderBy('period_date')->get();
            foreach ($days as $day) {
                $date = $day->period_date->toDateString();
                $pool = DB::table('daily_roi_pools')->where('simulation_scenario_id', $s->id)->where('period_date', $date)->first();
                if (! $pool) {
                    $id = DB::table('daily_roi_pools')->insertGetId([
                        'simulation_scenario_id' => $s->id, 'period_date' => $date,
                        'total_shares' => 0, 'pool_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    // The day's participants are products active at 00:00 WIB.
                    // New purchases join tomorrow, so today's claims cannot dilute earlier claims.
                    $investments = $s->investments()->where('roi_method', 'turnover')
                        ->whereIn('status', ['active', 'completed', 'defaulted'])->whereDate('starts_at', '<', $date)
                        ->withSum(['obligations' => fn ($q) => $q->whereDate('due_date', '<', $date)], 'amount')->get();
                    $shares = 0;
                    foreach ($investments as $i) {
                        $remaining = max(0, $i->promised() - (int) $i->obligations_sum_amount);
                        if ($remaining === 0) {
                            continue;
                        }
                        DB::table('daily_roi_shares')->insert([
                            'daily_roi_pool_id' => $id, 'investment_id' => $i->id,
                            'shares' => $i->roi_shares, 'remaining_cap' => $remaining,
                        ]);
                        $shares += $i->roi_shares;
                    }
                    DB::table('daily_roi_pools')->where('id', $id)->update(['total_shares' => $shares]);
                    $pool = DB::table('daily_roi_pools')->where('id', $id)->first();
                }
                if ($pool->pool_amount === $day->roi_amount || $pool->total_shares === 0) {
                    continue;
                }
                $parts = DB::table('daily_roi_shares')->where('daily_roi_pool_id', $pool->id)->get();
                $ledger = app(LedgerService::class);
                $ledger->initialize($s);
                foreach ($parts as $part) {
                    $amount = min($part->remaining_cap, intdiv($day->roi_amount * $part->shares, $pool->total_shares));
                    if ($amount === 0) {
                        continue;
                    }
                    $i = Investment::findOrFail($part->investment_id);
                    $o = RoiObligation::firstOrNew(['investment_id' => $i->id, 'due_date' => $date], ['amount' => 0]);
                    $before = (int) $o->amount;
                    if ($amount < $before) {
                        throw new \LogicException('Alokasi ROI yang telah diakui tidak boleh berkurang.');
                    }
                    if ($amount === $before) {
                        continue;
                    }
                    $new = ! $o->exists;
                    $o->fill(['amount' => $amount, 'status' => 'due'])->save();
                    $ledger->post($s, 'turnover-roi:'.$o->id.':'.$amount, 'roi_accrued', 'Alokasi pool ROI omzet produk',
                        [['roi-expense', 'debit', $amount - $before], ['roi-payable', 'credit', $amount - $before]], $i->user_id, $date);
                    $created += (int) $new;
                    if ($amount === $part->remaining_cap && $i->ends_at->toDateString() > $date) {
                        $i->ends_at = $date;
                        if (! $i->returns_principal) {
                            $i->status = 'completed';
                        }
                        $i->save();
                    }
                }
                DB::table('daily_roi_pools')->where('id', $pool->id)->update(['pool_amount' => $day->roi_amount, 'updated_at' => now()]);
            }

            return $created;
        }, 5);
    }
}
