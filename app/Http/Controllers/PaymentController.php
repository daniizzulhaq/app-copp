<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\MockPaymentInvoice;
use App\Models\SystemSetting;
use App\Services\ManualDepositService;
use App\Services\MockPaymentService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function index(Request $r, ScenarioContext $context)
    {
        $s = $context->resolve($r);
        $r->validate(['status' => 'nullable|in:pending,awaiting_review,paid_local,rejected,paid_simulated,failed,expired,cancelled', 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:asc,desc']);
        $invoices = MockPaymentInvoice::with('user')->where('simulation_scenario_id', $s->id)->when(! $r->user()->isStaff(), fn ($q) => $q->where('user_id', $r->user()->id))
            ->when($r->status, fn ($q) => $q->where('status', $r->status))->when($r->q, fn ($q) => $q->where('number', 'like', '%'.$r->q.'%'))->orderBy('id', $r->sort === 'asc' ? 'asc' : 'desc')->paginate(15)->withQueryString();

        $bankAccount = SystemSetting::depositAccount();

        return view('payments.index', compact('s', 'invoices', 'bankAccount'));
    }

    public function store(PaymentRequest $r, ScenarioContext $context, MockPaymentService $service)
    {
        $s = $context->resolve($r);
        if ($s->isLocal()) {
            $r->validate(['method' => 'required|in:manual_local']);
            $i = app(ManualDepositService::class)->create($r->user(), $s, (int) $r->amount, $r->request_key);

            return redirect('/payments/'.$i->id)->with('status', 'Deposit dibuat. Transfer sesuai total dengan kode unik, lalu konfirmasi ke admin. Bukti transfer opsional.');
        }
        $i = $service->create($r->user(), $s, (int) $r->amount, $r->method, $r->request_key);

        return redirect('/payments/'.$i->id)->with('status', 'Invoice sandbox dibuat.');
    }

    public function show(MockPaymentInvoice $invoice)
    {
        Gate::authorize('view', $invoice);

        if ($invoice->workflow === 'manual_local') {
            $invoice->load('user', 'scenario', 'reviewer');

            $bankAccount = SystemSetting::depositAccount();

            return view('payments.manual-show', compact('invoice', 'bankAccount'));
        }

        return view('payments.show', compact('invoice'));
    }

    public function simulate(Request $r, MockPaymentInvoice $invoice, MockPaymentService $service)
    {
        Gate::authorize('simulate', $invoice);
        $d = $r->validate(['result' => 'required|in:paid_simulated,failed,cancelled', 'event_id' => 'required|uuid']);
        $service->process($invoice->number, 'ui:'.$d['event_id'], $d['result'], hash('sha256', json_encode([$invoice->number, $d['result']])));

        return back()->with('status', 'Status invoice: '.$invoice->fresh()->status.'.');
    }

    public function webhook(Request $r, MockPaymentService $service)
    {
        $secret = config('nexavest.webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503);
        $timestamp = $r->header('X-Sandbox-Timestamp', '');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$r->getContent(), $secret);
        abort_unless(hash_equals($expected, $r->header('X-Sandbox-Signature', '')), 401);
        $d = $r->validate(['invoice' => 'required|uuid', 'event_id' => 'required|string|max:100', 'status' => 'required|in:paid_simulated,failed']);
        $i = $service->process($d['invoice'], 'webhook:'.$d['event_id'], $d['status'], hash('sha256', $r->getContent()));

        return response()->json(['status' => $i->status, 'simulation' => true]);
    }
}
