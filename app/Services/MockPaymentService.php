<?php

namespace App\Services;

use App\Models\MockPaymentInvoice;
use App\Models\PaymentWebhookEvent;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Notifications\SimulationNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MockPaymentService
{
    public function create(User $user, SimulationScenario $s, int $amount, string $method, string $key): MockPaymentInvoice
    {
        return DB::transaction(function () use ($user, $s, $amount, $method, $key) {
            $s = SimulationScenario::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($s->isLocal()) {
                throw ValidationException::withMessages(['method' => 'Workspace lokal memakai pengajuan dan verifikasi admin.']);
            }
            if ($i = MockPaymentInvoice::where('request_key', $key)->first()) {
                if ($i->user_id !== $user->id || $i->amount !== $amount || $i->method !== $method || $i->simulation_scenario_id !== $s->id) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci invoice sudah digunakan.']);
                }

                return $i;
            }
            if ($s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Skenario diarsipkan. Pilih skenario aktif untuk transaksi baru.']);
            }
            $i = MockPaymentInvoice::create(['number' => (string) Str::uuid(), 'user_id' => $user->id, 'simulation_scenario_id' => $s->id, 'amount' => $amount, 'method' => $method, 'request_key' => $key, 'expires_at' => now()->addMinutes(30)]);
            app(AuditService::class)->record('invoice.created', $i, null, ['amount' => $amount, 'method' => $method], $s->id);

            return $i;
        }, 5);
    }

    public function process(string $number, string $eventId, string $result, string $payloadHash): MockPaymentInvoice
    {
        return DB::transaction(function () use ($number, $eventId, $result, $payloadHash) {
            $probe = MockPaymentInvoice::where('number', $number)->firstOrFail();
            $s = SimulationScenario::whereKey($probe->simulation_scenario_id)->lockForUpdate()->firstOrFail();
            $i = MockPaymentInvoice::whereKey($probe->id)->lockForUpdate()->firstOrFail();
            if ($s->isLocal() || $i->workflow === 'manual_local') {
                throw ValidationException::withMessages(['invoice' => 'Deposit lokal hanya boleh disetujui admin melalui proses verifikasi.']);
            }
            if ($event = PaymentWebhookEvent::where('event_id', $eventId)->first()) {
                if ($event->mock_payment_invoice_id !== $i->id || ! hash_equals($event->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['event_id' => 'Event webhook konflik.']);
                }

                return $i;
            }
            if (! in_array($result, ['paid_simulated', 'failed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Status pembayaran tidak valid.']);
            }
            if ($i->status === 'pending') {
                $i->status = $i->expires_at->isPast() ? 'expired' : $result;
                if ($i->status === 'paid_simulated') {
                    app(WalletService::class)->creditDeposit($i->user, $s, $i->amount, 'invoice:'.$i->id);
                    $i->paid_at = now();
                    $i->user->notify(new SimulationNotification('Deposit sandbox berhasil', Money::rupiah($i->amount).' ditambahkan ke wallet simulasi.', '/payments/'.$i->id));
                }
                $i->save();
                app(AuditService::class)->record('invoice.'.$i->status, $i, null, ['status' => $i->status], $s->id);
            }
            PaymentWebhookEvent::create(['event_id' => $eventId, 'mock_payment_invoice_id' => $i->id, 'payload_hash' => $payloadHash, 'result' => $i->status]);

            return $i;
        }, 5);
    }

    public function expire(): int
    {
        $count = 0;
        MockPaymentInvoice::where('workflow', 'sandbox')->where('status', 'pending')->where('expires_at', '<=', now())->each(function ($i) use (&$count) {
            $this->process($i->number, 'expiration:'.$i->id, 'cancelled', hash('sha256', 'expiration:'.$i->id));
            $count++;
        });

        return $count;
    }
}
