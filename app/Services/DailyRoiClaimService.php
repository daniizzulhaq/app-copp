<?php

namespace App\Services;

use App\Models\RoiDistribution;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DailyRoiClaimService
{
    public function forMember(SimulationScenario $scenario, User $member): Collection
    {
        $this->prepare($scenario);

        return RoiObligation::with('investment')->whereHas('investment', fn ($q) => $q
            ->where('simulation_scenario_id', $scenario->id)->where('user_id', $member->id))
            ->whereDate('due_date', today())->orderBy('id')->get();
    }

    public function prepare(SimulationScenario $scenario): void
    {
        if (! $scenario->isLocal() || $scenario->status === 'archived') {
            return;
        }
        DB::transaction(function () use ($scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            app(LedgerService::class)->initialize($s);
            app(ROIObligationService::class)->accrue($s, today());
            $this->expire($s);
            // Settle matured principal on access as well as from the optional scheduler.
            app(PrincipalReturnService::class)->pay($s, today()->toDateString());
        }, 5);
    }

    public function expire(SimulationScenario $scenario): int
    {
        if (! $scenario->isLocal()) {
            return 0;
        }

        return DB::transaction(function () use ($scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $ledger = app(LedgerService::class);
            $ledger->account($s, 'roi-forfeited', 'credit');
            $items = RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))
                ->whereDate('due_date', '<', today())->whereRaw('amount > paid_amount + expired_amount')
                ->orderBy('id')->lockForUpdate()->get();
            $total = 0;
            foreach ($items as $obligation) {
                $amount = $obligation->remaining();
                $ledger->post($s, 'roi-expired:'.$obligation->id, 'roi_expired', 'ROI harian tidak diklaim dan hangus',
                    [['roi-payable', 'debit', $amount], ['roi-forfeited', 'credit', $amount]], $obligation->investment->user_id, today()->toDateString());
                $obligation->update(['expired_amount' => $obligation->expired_amount + $amount, 'expired_at' => now(), 'status' => 'expired']);
                $total += $amount;
            }

            return $total;
        }, 5);
    }

    public function claim(User $member, RoiObligation $obligation): RoiObligation
    {
        // The owner is always the authenticated member, never a request field.
        Gate::forUser($member)->authorize('transact');
        abort_unless($obligation->investment->user_id === $member->id, 403);
        $scenario = $obligation->investment->scenario;
        // Expiry is committed even if the requested claim is rejected below.
        $this->prepare($scenario);

        return DB::transaction(function () use ($member, $obligation, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $o = RoiObligation::whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            if (! $s->isLocal() || $s->status === 'archived') {
                throw ValidationException::withMessages(['claim' => 'Klaim hanya tersedia pada workspace operasional aktif.']);
            }
            if (! $o->due_date->isSameDay(today()) || $o->expired_amount > 0) {
                throw ValidationException::withMessages(['claim' => 'ROI hanya dapat diklaim pada tanggal jatuh tempo. Klaim hari sebelumnya hangus.']);
            }
            if ($o->remaining() === 0) {
                return $o;
            }
            $amount = $o->remaining();
            $ledger = app(LedgerService::class);
            app(WalletService::class)->get($member, $s);
            $tx = $ledger->post($s, 'roi-claim:'.$o->id, 'roi_paid', 'Klaim ROI harian oleh member',
                [['roi-payable', 'debit', $amount], ['wallet-offset', 'credit', $amount], ['reserve', 'debit', $amount], ['wallet:'.$member->id.':available', 'credit', $amount]],
                $member->id, today()->toDateString());
            RoiDistribution::create(['roi_obligation_id' => $o->id, 'ledger_transaction_id' => $tx->id, 'amount' => $amount]);
            $o->update(['paid_amount' => $o->paid_amount + $amount, 'claimed_at' => now(), 'status' => 'paid']);
            app(AuditService::class)->record('roi.claimed', $o, null, ['amount' => $amount, 'member_id' => $member->id], $s->id);

            return $o;
        }, 5);
    }
}
