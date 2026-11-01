<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestmentRequest;
use App\Models\Investment;
use App\Models\RoiPackage;
use App\Services\InvestmentService;
use App\Services\DailyRoiClaimService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PortfolioController extends Controller
{
    public function index(Request $r, ScenarioContext $context)
    {
        $s = $context->resolve($r);
        $claimScenario = $s;
        $roiClaims = $s->isLocal() && ! $r->user()->isStaff()
            ? app(DailyRoiClaimService::class)->forMember($s, $r->user()) : collect();
        $r->validate(['status' => 'nullable|in:pending,active,completed,suspended,defaulted', 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:asc,desc']);
        $investments = Investment::with('user')->withSum('obligations', 'amount')->withSum('obligations', 'paid_amount')->withSum('obligations', 'expired_amount')->where('simulation_scenario_id', $s->id)
            ->when(! $r->user()->isStaff(), fn ($q) => $q->where('user_id', $r->user()->id))->when($r->status, fn ($q) => $q->where('status', $r->status))
            ->when($r->q, fn ($q) => $q->where('number', 'like', '%'.$r->q.'%'))->orderBy('id', $r->sort === 'asc' ? 'asc' : 'desc')->paginate(12)->withQueryString();
        $packages = RoiPackage::where('active', true)->get();
        $purchaseCounts = Investment::where('user_id', $r->user()->id)->selectRaw('roi_package_id, COUNT(*) AS total')->groupBy('roi_package_id')->pluck('total', 'roi_package_id');

        return view('investments.index', compact('s', 'investments', 'packages', 'claimScenario', 'roiClaims', 'purchaseCounts'));
    }

    public function store(InvestmentRequest $r, ScenarioContext $context, InvestmentService $service)
    {
        $i = $service->open($r->user(), $context->resolve($r), RoiPackage::findOrFail($r->roi_package_id), (int) $r->amount, $r->request_key);

        return redirect('/investments/'.$i->id)->with('status', 'Portofolio simulasi diaktifkan.');
    }

    public function show(Investment $investment)
    {
        Gate::authorize('view', $investment);
        $investment->load(['user', 'scenario']);
        $claimScenario = $investment->scenario;
        $roiClaims = $claimScenario->isLocal() && ! auth()->user()->isStaff()
            ? app(DailyRoiClaimService::class)->forMember($claimScenario, auth()->user())->where('investment_id', $investment->id) : collect();
        $investment->refresh();
        $obligations = $investment->obligations()->orderByDesc('due_date')->paginate(20);
        $timeline = $investment->obligations()->orderBy('due_date')->get(['due_date', 'amount', 'paid_amount']);

        return view('investments.show', compact('investment', 'obligations', 'timeline', 'claimScenario', 'roiClaims'));
    }
}
