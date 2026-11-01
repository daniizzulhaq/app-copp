<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\RoiObligation;
use App\Models\SimulationRun;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SimulationEngineService
{
    private function cohort(SimulationScenario $s, int $count, int $day): int
    {
        if ($count <= 0) {
            return 0;
        }
        $amount = $count * $s->average_deposit;
        $date = $s->start_date->copy()->addDays($day);
        $u = User::create(['name' => 'Kohor S'.$s->id.' D'.$day.' ('.$count.' peserta)', 'email' => 'cohort-'.$s->id.'-'.$day.'@nexavest.invalid', 'password' => Str::random(64)]);
        $u->forceFill(['is_cohort' => true, 'active' => false, 'email_verified_at' => now()])->save();
        $u->assignRole('member');
        app(WalletService::class)->creditDeposit($u, $s, $amount, 'cohort:'.$s->id.':'.$day.':deposit', $date->toDateString());
        $i = Investment::create(['number' => 'COHORT-'.$s->id.'-'.$day, 'user_id' => $u->id, 'simulation_scenario_id' => $s->id,
            'package_name' => 'Kohor eksperimen', 'principal' => $amount, 'daily_rate_bps' => $s->daily_rate_bps, 'duration_days' => $s->duration_days,
            'payment_frequency_days' => $s->payment_frequency_days, 'participant_count' => $count, 'starts_at' => $date, 'ends_at' => $date->copy()->addDays($s->duration_days), 'request_key' => 'cohort:'.$s->id.':'.$day]);
        app(LedgerService::class)->post($s, 'cohort:'.$s->id.':'.$day.':investment', 'investment', 'Aktivasi kohor '.$count.' peserta',
            [['wallet:'.$u->id.':available', 'debit', $amount], ['capital', 'credit', $amount], ['cash', 'debit', $amount], ['reserve', 'credit', $amount]], $u->id, $date->toDateString());
        $s->participant_count += $count;

        return $amount;
    }

    public function initialize(SimulationScenario $scenario): void
    {
        DB::transaction(function () use ($scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if ($s->isLocal()) {
                throw ValidationException::withMessages(['scenario' => 'Workspace lokal diproses oleh scheduler operasional, tanpa pembuatan peserta otomatis.']);
            }
            if ($s->status !== 'draft') {
                return;
            }
            $l = app(LedgerService::class);
            $l->initialize($s);
            if ($s->initial_cash > 0) {
                $l->post($s, 'scenario:'.$s->id.':seed', 'seed', 'Kas awal eksperimen', [['cash', 'debit', (int) $s->initial_cash], ['seed-equity', 'credit', (int) $s->initial_cash]], null, $s->start_date->toDateString());
            }
            $this->cohort($s, $s->initial_members, 0);
            $s->status = 'running';
            $s->save();
            app(AuditService::class)->record('scenario.initialized', $s, null, $s->toArray(), $s->id);
        }, 5);
    }

    public function step(SimulationScenario $scenario, int $targetDay): SimulationRun
    {
        return DB::transaction(function () use ($scenario, $targetDay) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if ($run = $s->runs()->where('day', $targetDay)->first()) {
                return $run;
            }
            if ($s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Skenario telah diarsipkan.']);
            }
            if ($targetDay !== $s->current_day + 1 || $targetDay > 365) {
                throw ValidationException::withMessages(['day' => 'Hari harus berurutan dan maksimum 365.']);
            }
            $this->initialize($s);
            $s->refresh();
            $previous = $s->runs()->orderByDesc('day')->first();
            $new = $targetDay === 1 ? $s->daily_new_members : (int) ($previous?->new_members ?? 0);
            if ($targetDay > 1) {
                $new = intdiv($new * (10000 + $s->growth_bps) + ($s->growth_bps > 0 ? 9999 : 0), 10000);
            }
            if ($targetDay > $s->decline_after_day) {
                $new = Money::rate($new, 10000 - $s->decline_bps);
            }
            $new = min(10000, max(0, $new));
            $s->current_day = $targetDay;
            $s->save();
            $in = $this->cohort($s, $new, $targetDay);
            $s->save();
            $date = $s->start_date->copy()->addDays($targetDay)->toDateString();
            app(ROIObligationService::class)->accrue($s, Carbon::parse($date));
            $paid = app(SimulatedDistributionService::class)->pay($s, $date);
            $withdrawn = 0;
            // Cohorts aggregate fictional participants; withdrawals are fully posted through the same workflow.
            $cohorts = $s->investments()->with('user')->whereHas('user', fn ($q) => $q->where('is_cohort', true))->get();
            foreach ($cohorts as $i) {
                $balance = app(WalletService::class)->get($i->user, $s)->balance();
                $amount = Money::rate($balance, $s->withdrawal_bps);
                if ($amount > 0) {
                    $w = app(WithdrawalSimulationService::class)->request($i->user, $s, $amount, 'bank_dummy', 'Withdrawal kohor otomatis', 'cohort-withdraw:'.$s->id.':'.$targetDay.':'.$i->id);
                    app(WithdrawalSimulationService::class)->review($w, true);
                    $withdrawn += $amount;
                }
            }
            $o = RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id));
            $due = (int) (clone $o)->whereDate('due_date', $date)->sum('amount');
            $outstanding = (int) $o->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS outstanding')->value('outstanding');
            $principalOutstanding = app(PrincipalReturnService::class)->outstanding($s, $date);
            $outstanding += $principalOutstanding;
            $cash = app(LedgerService::class)->balance($s, 'cash');
            $status = $outstanding > 0 ? 'defaulted' : 'running';
            $totalDue = $outstanding + $paid;
            $run = $s->runs()->create(['day' => $targetDay, 'period_date' => $date, 'new_members' => $new, 'total_members' => $s->participant_count,
                'deposits' => $in, 'roi_due' => $due, 'roi_paid' => $paid, 'withdrawals' => $withdrawn, 'cash' => $cash, 'outstanding' => $outstanding,
                'funding_gap' => max(0, $outstanding - $cash), 'principal_returned' => app(PrincipalReturnService::class)->paidOn($s, $date), 'principal_outstanding' => $principalOutstanding,
                'coverage_bps' => $totalDue > 0 ? min(10000, intdiv($paid * 10000, $totalDue)) : 10000,
                'dependency_bps' => $paid > 0 ? min(10000, intdiv(min($in, $paid) * 10000, $paid)) : 0, 'status' => $status]);
            $s->update(['status' => $status]);
            if ($status === 'defaulted' && $previous?->status !== 'defaulted') {
                User::role('super-admin')->each(fn ($u) => $u->notify(new SimulationNotification('Kekurangan kas simulasi', $s->name.' memiliki kewajiban tertunggak.', '/scenarios/'.$s->id)));
            }
            app(AuditService::class)->record('scenario.day_completed', $run, null, $run->toArray(), $s->id);

            return $run;
        }, 5);
    }
}
