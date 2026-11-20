<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\MemberBonus;
use App\Models\MockPaymentInvoice;
use App\Models\Referral;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GotongRoyongBonusService
{
    public function canClaimRegistration(User $user, SimulationScenario $s): bool
    {
        return $s->usesTurnoverRoi() && $s->status !== 'archived' && $user->active && ! $user->isStaff()
            && ! $user->is_cohort && $user->created_at->greaterThanOrEqualTo($s->created_at)
            && ! MemberBonus::where('bonus_key', 'signup:'.$user->id.':once')->exists();
    }

    public function claimRegistration(User $user, SimulationScenario $scenario): void
    {
        DB::transaction(function () use ($user, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (MemberBonus::where('bonus_key', 'signup:'.$user->id.':once')->exists()) {
                return;
            }
            if (! $this->canClaimRegistration($user, $s)) {
                throw ValidationException::withMessages(['bonus' => 'Bonus daftar tersedia sekali untuk akun baru pada plan aktif.']);
            }
            $this->award($user, $s, 'signup:'.$user->id.':once', 'mission_bonus', 'Klaim bonus daftar', config('copp.registration_bonus'));
        }, 5);
    }

    public function invitation(User $referrer, User $referred, SimulationScenario $scenario): void
    {
        DB::transaction(function () use ($referrer, $referred, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (! $s->usesTurnoverRoi() || $s->status === 'archived' || $referred->created_at->lessThan($s->created_at)
                || ! Referral::where('referrer_id', $referrer->id)->where('referred_id', $referred->id)->exists()) {
                return;
            }
            $this->award($referrer, $s, 'invite:'.$referred->id.':once', 'mission_bonus', 'Bonus undang teman mendaftar', config('copp.invitation_bonus'));
        }, 5);
    }

    public function activation(Investment $investment): void
    {
        DB::transaction(function () use ($investment) {
            $s = SimulationScenario::whereKey($investment->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            if (! $s->usesTurnoverRoi() || $s->status === 'archived' || $investment->roi_method !== 'turnover') {
                return;
            }
            $current = $investment->user_id;
            $seen = [$current];
            // Direct referral is separate; the next five uplines receive level 1–5.
            for ($depth = 0; $depth <= config('copp.activation_levels'); $depth++) {
                $parentId = Referral::where('referred_id', $current)->value('referrer_id');
                if (! $parentId || in_array($parentId, $seen, true)) {
                    break;
                }
                $seen[] = $parentId;
                if ($parent = User::find($parentId)) {
                    $this->award($parent, $s, 'activation:'.$investment->id.':depth:'.$depth, 'referral_bonus',
                        $depth === 0 ? 'Bonus referral aktivasi produk' : 'Bonus aktivasi level '.$depth,
                        $depth === 0 ? config('copp.activation_referral_bonus') : config('copp.activation_level_bonus'));
                }
                $current = $parentId;
            }
            $this->cashback($investment->user, $s);
        }, 5);
    }

    public function cashback(User $user, SimulationScenario $scenario): void
    {
        DB::transaction(function () use ($user, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if (! $s->usesTurnoverRoi() || $s->status === 'archived') {
                return;
            }
            $deposits = (int) MockPaymentInvoice::where('user_id', $user->id)->where('simulation_scenario_id', $s->id)
                ->where('workflow', 'manual_local')->where('status', 'paid_local')->sum('amount');
            if ($deposits >= 100000 && $user->investments()->where('simulation_scenario_id', $s->id)
                ->where('roi_method', 'turnover')->whereIn('status', ['active', 'completed'])->exists()) {
                $this->award($user, $s, 'cashback:'.$user->id.':once', 'mission_bonus', 'Cashback deposit dan aktivasi produk', config('copp.activation_cashback'));
            }
        }, 5);
    }

    private function award(User $user, SimulationScenario $s, string $key, string $kind, string $description, int $amount): void
    {
        if (! $user->active || $user->is_cohort || MemberBonus::where('bonus_key', $key)->exists()) {
            return;
        }
        app(WalletService::class)->get($user, $s);
        $tx = app(LedgerService::class)->post($s, 'bonus:'.$key, $kind, $description,
            [['reserve', 'debit', $amount], ['wallet:'.$user->id.':available', 'credit', $amount]], $user->id);
        MemberBonus::create(['user_id' => $user->id, 'simulation_scenario_id' => $s->id, 'bonus_key' => $key,
            'kind' => $kind, 'description' => $description, 'amount' => $amount, 'ledger_transaction_id' => $tx->id]);
    }
}
