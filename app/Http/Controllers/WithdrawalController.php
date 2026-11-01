<?php

namespace App\Http\Controllers;

use App\Http\Requests\WithdrawalFormRequest;
use App\Models\WithdrawalRequest;
use App\Services\ScenarioContext;
use App\Services\WithdrawalSimulationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WithdrawalController extends Controller
{
    public function index(Request $r, ScenarioContext $context)
    {
        $s = $context->resolve($r);
        $r->validate(['status' => 'nullable|in:pending,approved,rejected', 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:asc,desc']);
        $withdrawals = WithdrawalRequest::with('user')->where('simulation_scenario_id', $s->id)->when(! $r->user()->isStaff(), fn ($q) => $q->where('user_id', $r->user()->id))
            ->when($r->status, fn ($q) => $q->where('status', $r->status))
            ->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('note', 'like', '%'.$r->q.'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$r->q.'%'))))
            ->orderBy('id', $r->sort === 'asc' ? 'asc' : 'desc')->paginate(15)->withQueryString();

        return view('withdrawals', compact('s', 'withdrawals'));
    }

    public function store(WithdrawalFormRequest $r, ScenarioContext $context, WithdrawalSimulationService $service)
    {
        $service->request($r->user(), $context->resolve($r), (int) $r->amount, $r->method, $r->note, $r->request_key);

        return back()->with('status', 'Withdrawal diajukan. Saldo ditahan sementara hingga admin menyelesaikan transfer.');
    }

    public function review(Request $r, WithdrawalRequest $withdrawal, WithdrawalSimulationService $service)
    {
        Gate::authorize('manage');
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'review_note' => 'nullable|string|max:500']);
        $service->review($withdrawal, $d['decision'] === 'approved', $r->user(), $d['review_note'] ?? null);

        return back()->with('status', $d['decision'] === 'approved' ? 'Withdrawal ditandai selesai sesuai konfirmasi transfer admin.' : 'Withdrawal ditolak. Saldo dikembalikan ke wallet member.');
    }
}
