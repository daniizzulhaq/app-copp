<?php

namespace App\Services;

use App\Models\SimulatedPayout;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalSimulationService
{
    public function request(User $user, SimulationScenario $s, int $amount, string $method, ?string $note, string $key): WithdrawalRequest
    {
        return DB::transaction(function () use ($user, $s, $amount, $method, $note, $key) {
            $s = SimulationScenario::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($old = WithdrawalRequest::where('request_key', $key)->first()) {
                if ($old->user_id !== $user->id || $old->amount !== $amount || $old->method !== $method || $old->simulation_scenario_id !== $s->id) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci penarikan konflik.']);
                }

                return $old;
            }
            if ($s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Skenario diarsipkan. Pilih skenario aktif untuk transaksi baru.']);
            }
            if ($amount < 50000 || $amount > 1000000000) {
                throw ValidationException::withMessages(['amount' => 'WD minimal Rp50.000 dan maksimal Rp1.000.000.000.']);
            }
            if ($s->isLocal() && $method !== 'bank_transfer') {
                throw ValidationException::withMessages(['method' => 'Withdrawal menggunakan transfer ke rekening bank terdaftar.']);
            }
            $bankAccount = [];
            if ($method === 'bank_transfer') {
                $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $owner->hasBankAccount()) {
                    throw ValidationException::withMessages(['bank_account' => 'Tambahkan rekening bank terlebih dahulu sebelum mengajukan withdrawal.']);
                }
                $bankAccount = $owner->only('bank_name', 'bank_account_number', 'bank_account_holder');
            }
            app(WalletService::class)->get($user, $s);
            $w = WithdrawalRequest::create(['user_id' => $user->id, 'simulation_scenario_id' => $s->id, 'amount' => $amount, 'admin_fee' => Money::rate($amount, 1000), 'method' => $method, 'note' => $note, 'request_key' => $key] + $bankAccount);
            app(LedgerService::class)->post($s, 'withdrawal:'.$w->id.':reserve', 'withdrawal_pending', 'Penahanan saldo withdrawal',
                [['wallet:'.$user->id.':available', 'debit', $amount], ['wallet:'.$user->id.':pending', 'credit', $amount]], $user->id);
            if (! $user->is_cohort) {
                $user->notify(new SimulationNotification('Withdrawal diajukan', 'Saldo ditahan sementara. Admin akan meninjau dan mentransfer ke rekening tujuan.', '/withdrawals'));
            }

            return $w;
        }, 5);
    }

    public function review(WithdrawalRequest $withdrawal, bool $approve, ?User $actor = null, ?string $reviewNote = null): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $approve, $actor, $reviewNote) {
            $s = SimulationScenario::whereKey($withdrawal->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            $w = WithdrawalRequest::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $target = $approve ? 'approved' : 'rejected';
            if ($w->status !== 'pending') {
                if ($w->status === $target) {
                    return $w;
                }
                throw ValidationException::withMessages(['withdrawal' => 'Penarikan sudah ditinjau.']);
            }
            if ($approve && ($s->isLocal() || $w->method === 'bank_transfer') && ! $w->hasBankAccount()) {
                throw ValidationException::withMessages(['withdrawal' => 'Rekening tujuan belum tersedia pada pengajuan ini. Penarikan tidak dapat diselesaikan.']);
            }
            $ledger = app(LedgerService::class);
            $lines = [['wallet:'.$w->user_id.':pending', 'debit', $w->amount], [$approve ? 'reserve' : 'wallet:'.$w->user_id.':available', 'credit', $w->amount]];
            if ($approve && $w->admin_fee > 0) {
                $ledger->account($s, 'withdrawal-fees', 'credit');
                $ledger->initialize($s);
                $lines[] = [$s->isLocal() ? 'wallet-offset' : 'cash', 'debit', $w->admin_fee];
                $lines[] = ['withdrawal-fees', 'credit', $w->admin_fee];
            }
            $tx = $ledger->post($s, 'withdrawal:'.$w->id.':review', 'withdrawal_'.$target, 'Withdrawal '.$target, $lines, $w->user_id);
            $w->update(['status' => $target, 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $reviewNote]);
            if ($approve) {
                SimulatedPayout::create(['withdrawal_request_id' => $w->id, 'ledger_transaction_id' => $tx->id, 'amount' => $w->netAmount(), 'reference' => ($s->isLocal() ? 'LOCAL-WD-' : 'DUMMY-').$w->id]);
            }
            if (! $w->user->is_cohort) {
                $message = $approve
                    ? Money::rupiah($w->netAmount()).' telah ditandai selesai oleh admin. Admin: '.Money::rupiah($w->admin_fee).'.'.($w->hasBankAccount() ? ' Tujuan: '.$w->bank_name.' ••••'.substr($w->bank_account_number, -4).'.' : '')
                    : Money::rupiah($w->amount).' dikembalikan ke saldo tersedia.';
                $w->user->notify(new SimulationNotification('Withdrawal '.($approve ? 'selesai' : 'ditolak'), $message, '/withdrawals'));
            }
            app(AuditService::class)->record('withdrawal.'.$target, $w, ['status' => 'pending'], ['status' => $target], $s->id);

            return $w;
        }, 5);
    }
}
