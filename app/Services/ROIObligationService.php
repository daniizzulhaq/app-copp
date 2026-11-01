<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ROIObligationService
{
    public function accrue(SimulationScenario $s, CarbonInterface $through): int
    {
        return DB::transaction(function () use ($s, $through) {
            SimulationScenario::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $created = 0;
            Investment::where('simulation_scenario_id', $s->id)->whereIn('status', ['active', 'defaulted', 'completed'])->whereDate('starts_at', '<', $through)->each(function ($i) use ($s, $through, &$created) {
                $elapsed = min($i->duration_days, (int) $i->starts_at->diffInDays($through));
                $frequency = $s->isLocal() ? 1 : $i->payment_frequency_days;
                $lastDue = $i->obligations()->max('due_date');
                $lastDay = $lastDue ? (int) $i->starts_at->diffInDays(Carbon::parse($lastDue)) : 0;
                $accrued = (int) $i->obligations()->sum('amount');
                if ($lastDay >= $i->duration_days) {
                    return;
                }
                for ($day = $lastDay + $frequency; $day <= $elapsed + $frequency - 1; $day += $frequency) {
                    $end = min($day, $i->duration_days);
                    if ($end > $elapsed) {
                        break;
                    }
                    $previous = $lastDay;
                    $amount = Money::rate($i->principal, $i->daily_rate_bps, $end) - Money::rate($i->principal, $i->daily_rate_bps, $previous);
                    if ($i->daily_roi_cap !== null) {
                        $amount = min($amount, $i->daily_roi_cap * ($end - $previous));
                    }
                    $amount = min($amount, max(0, $i->promised() - $accrued));
                    if ($amount > 0) {
                        $o = RoiObligation::firstOrCreate(['investment_id' => $i->id, 'due_date' => $i->starts_at->copy()->addDays($end)->toDateString()], ['amount' => $amount]);
                        if ($o->wasRecentlyCreated) {
                            $accrued += $amount;
                            $created++;
                            app(LedgerService::class)->post($s, 'obligation:'.$o->id, 'roi_accrued', 'Pengakuan kewajiban ROI jatuh tempo',
                                [['roi-expense', 'debit', $amount], ['roi-payable', 'credit', $amount]], $i->user_id, $o->due_date->toDateString());
                            if (! $i->user->is_cohort && (! $s->isLocal() || $o->due_date->isSameDay($through))) {
                                $i->user->notify(new SimulationNotification($s->isLocal() ? 'ROI harian siap diklaim' : 'ROI simulasi jatuh tempo',
                                    Money::rupiah($amount).($s->isLocal() ? ' tersedia untuk diklaim hari ini. Jika tidak diklaim sampai akhir hari, ROI hangus.' : ' menjadi kewajiban. Pembayaran bergantung pada kas.'), '/investments/'.$i->id));
                            }
                        }
                    }
                    $lastDay = $end;
                    if ($end === $i->duration_days) {
                        break;
                    }
                }
            });

            return $created;
        }, 5);
    }
}
