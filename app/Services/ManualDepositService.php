<?php

namespace App\Services;

use App\Models\MockPaymentInvoice;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualDepositService
{
    public function create(User $user, SimulationScenario $workspace, int $amount, string $key): MockPaymentInvoice
    {
        return DB::transaction(function () use ($user, $workspace, $amount, $key) {
            $s = SimulationScenario::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->assertLocal($s);
            if ($old = MockPaymentInvoice::where('request_key', $key)->first()) {
                if ($old->user_id !== $user->id || $old->simulation_scenario_id !== $s->id || $old->amount !== $amount || $old->workflow !== 'manual_local') {
                    throw ValidationException::withMessages(['request_key' => 'Kunci pengajuan digunakan untuk transaksi berbeda.']);
                }

                return $old;
            }
            if ($s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Workspace telah diarsipkan.']);
            }
            if ($amount < 10000 || $amount > 1000000000) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus antara Rp10.000 dan Rp1.000.000.000.']);
            }
            $invoice = MockPaymentInvoice::create([
                'number' => (string) Str::uuid(), 'user_id' => $user->id, 'simulation_scenario_id' => $s->id,
                'amount' => $amount, 'method' => 'manual_local', 'workflow' => 'manual_local', 'status' => 'pending',
                'request_key' => $key, 'expires_at' => now()->addDay(),
            ]);
            app(AuditService::class)->record('deposit.created', $invoice, null, ['amount' => $amount, 'workflow' => 'manual_local'], $s->id);

            return $invoice;
        }, 5);
    }

    public function submit(MockPaymentInvoice $invoice, User $actor, string $reference, ?string $note, UploadedFile $proof): MockPaymentInvoice
    {
        Gate::forUser($actor)->authorize('submit', $invoice);
        $hash = hash_file('sha256', $proof->getRealPath());
        $path = $proof->store('deposit-proofs/'.$invoice->id, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['transfer_proof' => 'Bukti transfer gagal disimpan. Silakan coba lagi.']);
        }

        try {
            $submitted = DB::transaction(function () use ($invoice, $reference, $note, $hash, $path) {
                $s = SimulationScenario::whereKey($invoice->simulation_scenario_id)->lockForUpdate()->firstOrFail();
                $this->assertLocal($s);
                $i = MockPaymentInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $this->assertManual($i);
                if ($i->status === 'awaiting_review' && $i->submission_reference === $reference && $i->submission_note === $note && $i->transfer_proof_hash === $hash) {
                    return $i;
                }
                if ($i->status !== 'pending') {
                    throw ValidationException::withMessages(['invoice' => 'Pengajuan sudah dikirim atau ditinjau.']);
                }
                if ($s->status === 'archived' || $i->expires_at->isPast()) {
                    throw ValidationException::withMessages(['invoice' => 'Pengajuan kedaluwarsa atau workspace diarsipkan.']);
                }
                $i->update(['status' => 'awaiting_review', 'submission_reference' => $reference, 'submission_note' => $note, 'submitted_at' => now(), 'transfer_proof_path' => $path, 'transfer_proof_hash' => $hash]);
                app(AuditService::class)->record('deposit.submitted', $i, ['status' => 'pending'], ['status' => 'awaiting_review', 'reference' => $reference], $s->id);
                $i->user->notify(new SimulationNotification('Deposit menunggu verifikasi', 'Pengajuan '.Money::rupiah($i->amount).' telah dikirim ke admin. Saldo belum bertambah.', '/payments/'.$i->id));
                User::role('super-admin')->where('active', true)->each(fn ($admin) => $admin->notify(new SimulationNotification('Pengajuan deposit baru', $i->user->name.' mengajukan '.Money::rupiah($i->amount).'.', '/payments/'.$i->id)));

                return $i;
            }, 5);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($submitted->transfer_proof_path !== $path) {
            Storage::disk('local')->delete($path);
        }

        return $submitted;
    }

    public function review(MockPaymentInvoice $invoice, User $reviewer, bool $approve, ?string $note): MockPaymentInvoice
    {
        Gate::forUser($reviewer)->authorize('manage');

        return DB::transaction(function () use ($invoice, $reviewer, $approve, $note) {
            $s = SimulationScenario::whereKey($invoice->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            $this->assertLocal($s);
            $i = MockPaymentInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertManual($i);
            $target = $approve ? 'paid_local' : 'rejected';
            if ($i->status === $target) {
                return $i;
            }
            if ($i->status !== 'awaiting_review') {
                throw ValidationException::withMessages(['invoice' => 'Hanya pengajuan yang menunggu verifikasi dapat ditinjau.']);
            }
            if ($approve && (! $i->transfer_proof_path || ! Storage::disk('local')->exists($i->transfer_proof_path))) {
                throw ValidationException::withMessages(['invoice' => 'Bukti transfer belum tersedia. Deposit tidak dapat disetujui.']);
            }
            if ($approve && $s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Workspace diarsipkan; pengajuan hanya dapat ditolak.']);
            }
            if ($reviewer->id === $i->user_id) {
                throw ValidationException::withMessages(['invoice' => 'Pengajuan sendiri tidak dapat diverifikasi oleh akun yang sama.']);
            }
            $before = $i->only('status', 'amount');
            if ($approve) {
                app(WalletService::class)->get($i->user, $s);
                $tx = app(LedgerService::class)->post($s, 'manual-deposit:'.$i->id, 'deposit', 'Deposit lokal terverifikasi',
                    [['reserve', 'debit', $i->amount], ['wallet:'.$i->user_id.':available', 'credit', $i->amount]], $i->user_id);
                $i->ledger_transaction_id = $tx->id;
                $i->paid_at = now();
            }
            $i->fill(['status' => $target, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            if ($approve) {
                app(MemberBonusService::class)->topup($i);
            }
            app(AuditService::class)->record('deposit.'.$target, $i, $before, ['status' => $target, 'reviewed_by' => $reviewer->id, 'review_note' => $note, 'ledger_transaction_id' => $i->ledger_transaction_id], $s->id);
            $i->user->notify(new SimulationNotification($approve ? 'Deposit disetujui' : 'Deposit ditolak', Money::rupiah($i->amount).($approve ? ' telah ditambahkan ke wallet setelah verifikasi bukti transfer.' : ' tidak ditambahkan ke wallet. Catatan: '.($note ?: 'Tidak ada catatan.')), '/payments/'.$i->id));

            return $i;
        }, 5);
    }

    public function cancel(MockPaymentInvoice $invoice, User $actor): MockPaymentInvoice
    {
        Gate::forUser($actor)->authorize('submit', $invoice);

        return DB::transaction(function () use ($invoice) {
            $s = SimulationScenario::whereKey($invoice->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            $this->assertLocal($s);
            $i = MockPaymentInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertManual($i);
            if ($i->status === 'cancelled') {
                return $i;
            }
            if (! in_array($i->status, ['pending', 'awaiting_review'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Pengajuan yang telah ditinjau tidak dapat dibatalkan.']);
            }
            $before = $i->status;
            $i->update(['status' => 'cancelled']);
            app(AuditService::class)->record('deposit.cancelled', $i, ['status' => $before], ['status' => 'cancelled'], $s->id);

            return $i;
        }, 5);
    }

    public function expire(): int
    {
        $count = 0;
        MockPaymentInvoice::where('workflow', 'manual_local')->where('status', 'pending')->where('expires_at', '<=', now())->each(function ($invoice) use (&$count) {
            DB::transaction(function () use ($invoice, &$count) {
                SimulationScenario::whereKey($invoice->simulation_scenario_id)->lockForUpdate()->firstOrFail();
                $i = MockPaymentInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($i->status !== 'pending' || $i->expires_at->isFuture()) {
                    return;
                }
                $i->update(['status' => 'expired']);
                app(AuditService::class)->record('deposit.expired', $i, ['status' => 'pending'], ['status' => 'expired'], $i->simulation_scenario_id);
                $count++;
            }, 5);
        });

        return $count;
    }

    private function assertLocal(SimulationScenario $workspace): void
    {
        if ($workspace->mode !== 'local') {
            throw ValidationException::withMessages(['scenario' => 'Alur manual ini khusus workspace operasional lokal.']);
        }
    }

    private function assertManual(MockPaymentInvoice $invoice): void
    {
        if ($invoice->workflow !== 'manual_local') {
            throw ValidationException::withMessages(['invoice' => 'Invoice ini bukan pengajuan deposit lokal.']);
        }
    }
}
