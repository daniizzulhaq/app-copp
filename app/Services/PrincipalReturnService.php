<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\SimulationScenario;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PrincipalReturnService
{
    public function pay(SimulationScenario $scenario, string $through): int
    {
        return DB::transaction(function () use ($scenario, $through) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $ledger = app(LedgerService::class);
            $ledger->initialize($s);
            $total = 0;
            $investments = $s->investments()->whereIn('status', ['active', 'defaulted', 'completed'])
                ->where(fn ($q) => $q->whereColumn('principal_returned_amount', '<', 'principal')->orWhere('status', '!=', 'completed'))
                ->whereDate('ends_at', '<=', $through)->orderBy('ends_at')->orderBy('id')->lockForUpdate()->get();

            foreach ($investments as $investment) {
                $remaining = $investment->principal - $investment->principal_returned_amount;
                $amount = $s->isLocal() ? $remaining : min($remaining, $ledger->balance($s, 'cash'));

                if ($amount > 0) {
                    app(WalletService::class)->get($investment->user, $s);
                    $ledger->post($s, 'principal:'.$investment->id.':'.$investment->principal_returned_amount, 'principal_returned',
                        'Pengembalian modal '.$investment->package_name,
                        [['capital', 'debit', $amount], [$s->isLocal() ? 'wallet-offset' : 'cash', 'credit', $amount], ['reserve', 'debit', $amount], ['wallet:'.$investment->user_id.':available', 'credit', $amount]],
                        $investment->user_id, $through);
                    $investment->principal_returned_amount += $amount;
                    if ($investment->principal_returned_amount === $investment->principal) {
                        $investment->principal_returned_at = now();
                    }
                    $total += $amount;

                    if (! $investment->user->is_cohort) {
                        $investment->user->notify(new SimulationNotification('Modal investasi dikembalikan',
                            Money::rupiah($amount).' ditambahkan ke saldo Anda.', '/investments/'.$investment->id));
                    }
                }

                $roiPaid = (int) $investment->obligations()->sum('paid_amount');
                $roiSettled = $s->isLocal()
                    ? ! $investment->obligations()->whereRaw('amount > paid_amount + expired_amount')->exists()
                    : $roiPaid >= $investment->promised();
                $investment->status = $investment->principal_returned_amount === $investment->principal
                    ? ($roiSettled ? 'completed' : ($s->isLocal() ? 'active' : 'defaulted'))
                    : 'defaulted';
                if ($investment->isDirty()) {
                    $investment->save();
                }
            }

            return $total;
        }, 5);
    }

    public function outstanding(SimulationScenario $s, string $through, ?int $userId = null): int
    {
        return (int) $s->investments()->whereIn('status', ['active', 'defaulted', 'completed'])
            ->whereDate('ends_at', '<=', $through)->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->selectRaw('COALESCE(SUM(principal - principal_returned_amount),0) AS total')->value('total');
    }

    public function paidOn(SimulationScenario $s, string $date): int
    {
        return (int) LedgerEntry::join('ledger_transactions AS t', 't.id', '=', 'ledger_entries.ledger_transaction_id')
            ->join('ledger_accounts AS a', 'a.id', '=', 'ledger_entries.ledger_account_id')
            ->where('t.simulation_scenario_id', $s->id)->whereDate('t.effective_date', $date)
            ->where('t.kind', 'principal_returned')->where('a.code', 'capital')->where('side', 'debit')->sum('amount');
    }
}
