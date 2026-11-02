<?php

namespace App\Http\Controllers;

use App\Models\MockPaymentInvoice;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DepositMethodController extends Controller
{
    public function edit()
    {
        return view('settings.deposit-methods', ['methods' => SystemSetting::qrisMethods()]);
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (SystemSetting::QRIS_METHODS as $key => $label) {
            $rules[$key.'_enabled'] = 'nullable|boolean';
            $rules[$key.'_holder'] = 'nullable|string|max:150';
            $rules[$key.'_image'] = 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048';
        }
        $data = $request->validate($rules);
        $before = SystemSetting::qrisMethods();
        foreach ($before as $key => $method) {
            if ($request->boolean($key.'_enabled') &&
                (empty($data[$key.'_holder']) || (! $request->hasFile($key.'_image') && (! $method['image'] || ! Storage::disk('local')->exists($method['image']))))) {
                throw ValidationException::withMessages([$key.'_image' => 'Isi nama penerima dan unggah gambar QRIS sebelum mengaktifkan '.$method['label'].'.']);
            }
        }
        $stored = [];
        try {
            foreach ($before as $key => $method) {
                if ($request->hasFile($key.'_image')) {
                    $path = $request->file($key.'_image')->store('deposit-qris', 'local');
                    if (! $path) {
                        throw ValidationException::withMessages([$key.'_image' => 'Gambar QRIS gagal disimpan. Silakan coba lagi.']);
                    }
                    $stored[$key] = $path;
                }
            }
            DB::transaction(function () use ($request, $data, $stored, $before) {
                foreach ($before as $key => $method) {
                    foreach (['enabled' => $request->boolean($key.'_enabled') ? '1' : '0', 'holder' => $data[$key.'_holder'] ?? '', 'image' => $stored[$key] ?? $method['image']] as $field => $value) {
                        SystemSetting::updateOrCreate(['key' => 'deposit_'.$key.'_'.$field], ['value' => $value]);
                    }
                }
                app(AuditService::class)->record('deposit-methods.updated', null, $before, SystemSetting::qrisMethods());
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        // Previous images remain available to invoices that reference them.
        return back()->with('status', 'Metode deposit QRIS disimpan.');
    }

    public function preview(string $method)
    {
        $destination = SystemSetting::qrisMethods()[$method] ?? null;

        return $this->image($destination['image'] ?? null);
    }

    public function invoiceImage(MockPaymentInvoice $invoice)
    {
        Gate::authorize('view', $invoice);
        abort_unless(isset(SystemSetting::QRIS_METHODS[$invoice->method]), 404);

        return $this->image($invoice->payment_destination['image'] ?? null);
    }

    private function image(?string $path)
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
