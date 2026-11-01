<?php

namespace App\Http\Controllers;

use App\Models\MockPaymentInvoice;
use App\Services\ManualDepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ManualDepositController extends Controller
{
    public function submit(Request $request, MockPaymentInvoice $invoice, ManualDepositService $service)
    {
        Gate::authorize('submit', $invoice);
        $data = $request->validate([
            'reference' => 'required|string|min:3|max:100',
            'note' => 'nullable|string|max:500',
            'transfer_proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:2048',
        ]);
        $service->submit($invoice, $request->user(), $data['reference'], $data['note'] ?? null, $data['transfer_proof'] ?? null);

        return back()->with('status', 'Konfirmasi transfer dikirim ke admin. Saldo bertambah setelah verifikasi disetujui.');
    }

    public function proof(MockPaymentInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        abort_unless($invoice->transfer_proof_path && Storage::disk('local')->exists($invoice->transfer_proof_path), 404);

        return Storage::disk('local')->response($invoice->transfer_proof_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, MockPaymentInvoice $invoice, ManualDepositService $service)
    {
        Gate::authorize('manage');
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'review_note' => 'nullable|string|max:500|required_if:decision,rejected']);
        $service->review($invoice, $request->user(), $data['decision'] === 'approved', $data['review_note'] ?? null);

        return back()->with('status', $data['decision'] === 'approved' ? 'Deposit disetujui. Wallet dan ledger lokal telah diperbarui.' : 'Deposit ditolak. Saldo tidak berubah.');
    }

    public function cancel(Request $request, MockPaymentInvoice $invoice, ManualDepositService $service)
    {
        $service->cancel($invoice, $request->user());

        return back()->with('status', 'Pengajuan deposit dibatalkan. Saldo tidak berubah.');
    }
}
