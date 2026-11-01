<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;

class LedgerIntegrityService
{
    public function check(SimulationScenario $s): array
    {
        $errors = [];
        $previous = null;
        foreach ($s->transactions()->with('entries.account')->orderBy('id')->cursor() as $tx) {
            $lines = $tx->entries->sortBy('id')->map(fn ($e) => [$e->account->code, $e->side, (int) $e->amount])->values()->all();
            $payload = hash('sha256', json_encode([$s->id, $tx->kind, $tx->description, $lines, $tx->user_id, $tx->effective_date->toDateString(), $tx->reversal_of], JSON_THROW_ON_ERROR));
            $hash = hash('sha256', ($previous ?? '').$payload.$tx->idempotency_key.($tx->actor_id ?? 'system'));
            if (! hash_equals($tx->payload_hash, $payload) || ! hash_equals($tx->integrity_hash, $hash) || $tx->previous_hash !== $previous) {
                $errors[] = 'Rantai hash tidak valid: transaksi '.$tx->id;
            }
            if ($tx->entries->where('side', 'debit')->sum('amount') !== $tx->entries->where('side', 'credit')->sum('amount')) {
                $errors[] = 'Jurnal tidak seimbang: '.$tx->id;
            }
            $previous = $tx->integrity_hash;
        }
        foreach ($s->accounts()->get() as $a) {
            $sum = (int) LedgerEntry::where('ledger_account_id', $a->id)->selectRaw('COALESCE(SUM(CASE WHEN side=? THEN amount ELSE -CAST(amount AS SIGNED) END),0) AS total', [$a->normal_side])->value('total');
            if ($a->balance !== $sum) {
                $errors[] = 'Saldo berbeda dari ledger: '.$a->code;
            }
            if (! $a->allow_negative && $a->balance < 0) {
                $errors[] = 'Saldo negatif: '.$a->code;
            }
        }
        $reserve = app(LedgerService::class)->balance($s, 'reserve');
        $wallets = (int) $s->accounts()->whereNotNull('wallet_id')->sum('balance');
        if ($reserve !== $wallets) {
            $errors[] = 'Cadangan wallet tidak sama dengan total available + pending.';
        }
        $outstanding = (int) RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS total')->value('total');
        if (app(LedgerService::class)->balance($s, 'roi-payable') !== $outstanding) {
            $errors[] = 'Kewajiban ROI pada ledger tidak cocok dengan subledger kewajiban.';
        }
        $principal = (int) $s->investments()->selectRaw('COALESCE(SUM(principal - principal_returned_amount),0) AS total')->value('total');
        if (app(LedgerService::class)->balance($s, 'capital') !== $principal) {
            $errors[] = 'Saldo modal pada ledger tidak cocok dengan sisa modal investasi.';
        }

        return $errors;
    }
}
