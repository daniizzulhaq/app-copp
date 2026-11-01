<?php

namespace App\Services;

use App\Models\RoiDistribution;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SimulatedDistributionService
{
    public function pay(SimulationScenario $scenario, string $through): int
    {
        return DB::transaction(function () use ($scenario, $through) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            if ($s->isLocal()) {
                app(DailyRoiClaimService::class)->expire($s);
                app(PrincipalReturnService::class)->pay($s, today()->toDateString());

                return 0;
            }
            $total = 0;
            $ledger = app(LedgerService::class);
            $items = RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))->where('due_date', '<=', $through)
                ->whereColumn('paid_amount', '<', 'amount')->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $o) {
                $available = $ledger->balance($s, 'cash');
                $amount = min($available, $o->amount - $o->paid_amount);
                $i = $o->investment;
                if ($amount > 0) {
                    app(WalletService::class)->get($i->user, $s);
                    $tx = $ledger->post($s, 'roi:'.$o->id.':'.$o->paid_amount, 'roi_paid', 'Pembayaran ROI simulasi',
                        [['roi-payable', 'debit', $amount], ['cash', 'credit', $amount], ['reserve', 'debit', $amount], ['wallet:'.$i->user_id.':available', 'credit', $amount]], $i->user_id, $through);
                    RoiDistribution::create(['roi_obligation_id' => $o->id, 'ledger_transaction_id' => $tx->id, 'amount' => $amount]);
                    $o->paid_amount += $amount;
                    $total += $amount;
                    if (! $i->user->is_cohort) {
                        $i->user->notify(new SimulationNotification('ROI simulasi dibayar', Money::rupiah($amount).' ditambahkan ke wallet.', '/investments/'.$i->id));
                    }
                }
                $old = $o->status;
                $o->status = $o->paid_amount >= $o->amount ? 'paid' : 'overdue';
                $o->save();
                if ($o->status === 'overdue') {
                    $i->update(['status' => 'defaulted']);
                    if ($old !== 'overdue') {
                        if (! $i->user->is_cohort) {
                            $i->user->notify(new SimulationNotification('Kewajiban ROI tertunggak', 'Kas bebas skenario tidak mencukupi. Tidak ada saldo ROI palsu.', '/investments/'.$i->id));
                        }
                    }
                }
            }
            app(PrincipalReturnService::class)->pay($s, $through);

            return $total;
        }, 5);
    }
}
