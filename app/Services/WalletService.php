<?php

namespace App\Services;

use App\Models\SimulationScenario;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(private LedgerService $ledger) {}

    public function get(User $user, SimulationScenario $s): Wallet
    {
        return DB::transaction(function () use ($user, $s) {
            SimulationScenario::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $this->ledger->initialize($s);
            $w = Wallet::firstOrCreate(['user_id' => $user->id, 'simulation_scenario_id' => $s->id]);
            foreach (['available', 'pending'] as $bucket) {
                $this->ledger->account($s, 'wallet:'.$user->id.':'.$bucket, 'credit', $w->id);
            }

            return $w;
        }, 5);
    }

    public function creditDeposit(User $user, SimulationScenario $s, int $amount, string $key, ?string $date = null): void
    {
        $this->get($user, $s);
        $this->ledger->post($s, $key, 'deposit', 'Deposit sandbox', [['reserve', 'debit', $amount], ['wallet:'.$user->id.':available', 'credit', $amount]], $user->id, $date);
    }
}
