<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\SimulationScenario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function account(SimulationScenario $s, string $code, string $side = 'debit', ?int $wallet = null, bool $allowNegative = false): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(['simulation_scenario_id' => $s->id, 'code' => $code], ['name' => $code, 'normal_side' => $side, 'wallet_id' => $wallet, 'allow_negative' => $allowNegative]);
    }

    public function initialize(SimulationScenario $s): void
    {
        $required = ['cash' => 'debit', 'reserve' => 'debit', 'capital' => 'credit', 'seed-equity' => 'credit', 'roi-expense' => 'debit', 'roi-payable' => 'credit'];
        $existing = $s->accounts()->whereIn('code', array_keys($required))->pluck('code')->all();
        foreach ($required as $code => $side) {
            if (! in_array($code, $existing, true)) {
                $this->account($s, $code, $side);
            }
        }
        if ($s->isLocal()) {
            // Balancing account for digital wallet credits; it is not a funding limit.
            $this->account($s, 'wallet-offset', 'credit', null, true);
        }
    }

    public function balance(SimulationScenario $s, string $code): int
    {
        return (int) $s->accounts()->where('code', $code)->value('balance');
    }

    /** Lines: [account code, debit|credit, integer rupiah]. Scenario lock serializes postings. */
    public function post(SimulationScenario $scenario, string $key, string $kind, string $description, array $lines, ?int $userId = null, ?string $date = null, ?int $reversal = null): LedgerTransaction
    {
        return DB::transaction(function () use ($scenario, $key, $kind, $description, $lines, $userId, $date, $reversal) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $date ??= app(WorkspaceClock::class)->date($s)->toDateString();
            $payload = hash('sha256', json_encode([$s->id, $kind, $description, $lines, $userId, $date, $reversal], JSON_THROW_ON_ERROR));
            if ($old = LedgerTransaction::where('idempotency_key', $key)->first()) {
                if (! hash_equals($old->payload_hash, $payload)) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci transaksi digunakan untuk permintaan berbeda.']);
                }

                return $old;
            }
            $debits = 0;
            $credits = 0;
            foreach ($lines as [$code,$side,$amount]) {
                if (! in_array($side, ['debit', 'credit'], true) || ! is_int($amount) || $amount <= 0 || $amount > 100000000000000000) {
                    throw new \InvalidArgumentException('Entri ledger tidak valid.');
                }
                if ($side === 'debit') {
                    $debits += $amount;
                } else {
                    $credits += $amount;
                }
            }
            if ($debits === 0 || $debits !== $credits) {
                throw new \LogicException('Debit dan kredit tidak seimbang.');
            }
            $accounts = $s->accounts()->whereIn('code', array_column($lines, 0))->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            $deltas = [];
            foreach ($lines as [$code,$side,$amount]) {
                $a = $accounts->get($code) ?? throw new \LogicException('Akun ledger belum diinisialisasi: '.$code);
                $deltas[$code] = ($deltas[$code] ?? 0) + ($side === $a->normal_side ? $amount : -$amount);
            }
            foreach ($deltas as $code => $delta) {
                if (! $accounts[$code]->allow_negative && $accounts[$code]->balance + $delta < 0) {
                    throw ValidationException::withMessages(['amount' => 'Saldo simulasi tidak mencukupi pada akun '.$code.'.']);
                }
            }
            $previous = $s->transactions()->orderByDesc('id')->value('integrity_hash');
            $actor = auth()->id();
            $hash = hash('sha256', ($previous ?? '').$payload.$key.($actor ?? 'system'));
            $tx = LedgerTransaction::create(['simulation_scenario_id' => $s->id, 'user_id' => $userId, 'actor_id' => $actor, 'idempotency_key' => $key, 'kind' => $kind, 'description' => $description,
                'effective_date' => $date, 'payload_hash' => $payload, 'previous_hash' => $previous, 'integrity_hash' => $hash, 'reversal_of' => $reversal]);
            foreach ($lines as [$code,$side,$amount]) {
                $tx->entries()->create(['ledger_account_id' => $accounts[$code]->id, 'side' => $side, 'amount' => $amount]);
            }
            foreach ($deltas as $code => $delta) {
                $accounts[$code]->balance += $delta;
                $accounts[$code]->save();
            }
            app(AuditService::class)->record('ledger.'.$kind, $tx, null, ['transaction' => $tx->id, 'amount' => $debits], $s->id);

            return $tx;
        }, 5);
    }

    public function reverse(LedgerTransaction $tx, string $reason): LedgerTransaction
    {
        if ($tx->reversal_of || ! in_array($tx->kind, ['adjustment', 'seed'], true)) {
            throw ValidationException::withMessages(['transaction' => 'Koreksi hanya untuk jurnal seed/penyesuaian. Transaksi operasional dikoreksi melalui modul asal.']);
        }
        $lines = $tx->entries()->with('account')->get()->map(fn ($e) => [$e->account->code, $e->side === 'debit' ? 'credit' : 'debit', (int) $e->amount])->all();

        return $this->post(SimulationScenario::findOrFail($tx->simulation_scenario_id), 'reversal:'.$tx->id, 'reversal', $reason, $lines, $tx->user_id, null, $tx->id);
    }
}
