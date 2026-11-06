<?php

namespace App\Services;

use App\Models\MemberBonus;
use App\Models\MockPaymentInvoice;
use App\Models\Referral;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class MemberBonusService
{
    public function registration(User $referrer): void
    {
        $workspace = SimulationScenario::find(SystemSetting::whereKey('local_workspace')->value('value'));
        if ($workspace && $workspace->isLocal() && $workspace->status !== 'archived') {
            $this->missions($referrer, $workspace);
        }
    }

    public function topup(MockPaymentInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $s = SimulationScenario::whereKey($invoice->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            if (! $s->isLocal() || $invoice->status !== 'paid_local') {
                return;
            }
            $current = $invoice->user_id;
            $seen = [$current];
            foreach (config('copp.referral_rates_bps') as $level => $rate) {
                $referrerId = Referral::where('referred_id', $current)->value('referrer_id');
                if (! $referrerId || in_array($referrerId, $seen, true)) {
                    break;
                }
                $seen[] = $referrerId;
                $referrer = User::find($referrerId);
                if ($referrer && $referrer->active && ! $referrer->is_cohort) {
                    $this->award($referrer, $s, 'topup:'.$invoice->id.':level:'.$level,
                        'referral_bonus', 'Bonus referral level '.$level, Money::rate($invoice->amount, $rate));
                    if ($level === 1) {
                        $this->missions($referrer, $s);
                    }
                }
                $current = $referrerId;
            }
        }, 5);
    }

    public function progress(User $user, SimulationScenario $s): array
    {
        $ids = Referral::where('referrer_id', $user->id)->select('referred_id');
        $topups = MockPaymentInvoice::where('simulation_scenario_id', $s->id)->whereIn('user_id', $ids)
            ->where('workflow', 'manual_local')->where('status', 'paid_local');
        return [
            'registrations' => Referral::where('referrer_id', $user->id)->count(),
            'topups' => (clone $topups)->whereDate('paid_at', today())->distinct()->count('user_id'),
            'turnover' => (int) $topups->sum('amount'),
        ];
    }

    public function missions(User $user, SimulationScenario $scenario): void
    {
        DB::transaction(function () use ($user, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (! $s->isLocal() || $s->status === 'archived' || ! $user->active || $user->is_cohort) {
                return;
            }
            // Lifetime rewards belong to the account, including across workspaces.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $progress = $this->progress($user, $s);
            foreach (config('copp.missions') as $code => $mission) {
                if ($progress[$mission['metric']] >= $mission['target']) {
                    $key = 'mission:'.$user->id.':'.$code;
                    // A dated bonus paid under the old daily rules also fulfills this target.
                    if (! $mission['daily'] && MemberBonus::where('bonus_key', 'like', $key.':%')->exists()) {
                        continue;
                    }
                    $period = $mission['daily'] ? today()->toDateString() : 'once';
                    $this->award($user, $s, $key.':'.$period,
                        'mission_bonus', $mission['label'], $mission['reward']);
                }
            }
        }, 5);
    }

    private function award(User $user, SimulationScenario $s, string $key, string $kind, string $description, int $amount): void
    {
        if ($amount <= 0 || MemberBonus::where('bonus_key', $key)->exists()) {
            return;
        }
        app(WalletService::class)->get($user, $s);
        // Reserve tracks credited wallet funds, as it does for verified deposits.
        $tx = app(LedgerService::class)->post($s, 'bonus:'.$key, $kind, $description,
            [['reserve', 'debit', $amount], ['wallet:'.$user->id.':available', 'credit', $amount]], $user->id);
        MemberBonus::create(['user_id' => $user->id, 'simulation_scenario_id' => $s->id,
            'bonus_key' => $key, 'kind' => $kind, 'description' => $description, 'amount' => $amount, 'ledger_transaction_id' => $tx->id]);
    }
}
