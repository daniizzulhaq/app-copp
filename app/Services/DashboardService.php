<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\CarbonPeriod;

class DashboardService
{
    public function data(SimulationScenario $s, string $from, string $to, ?int $user = null): array
    {
        $i = Investment::where('simulation_scenario_id', $s->id)->when($user, fn ($q) => $q->where('user_id', $user));
        $o = RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id)->when($user, fn ($q) => $q->where('user_id', $user)));
        $promised = (int) (clone $i)->selectRaw('COALESCE(SUM(LEAST(FLOOR(principal * daily_rate_bps * duration_days / 10000), COALESCE(daily_roi_cap * duration_days, FLOOR(principal * daily_rate_bps * duration_days / 10000)), COALESCE(FLOOR(principal * total_roi_bps / 10000), FLOOR(principal * daily_rate_bps * duration_days / 10000)))),0) AS total')->value('total');
        $out = (int) (clone $o)->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS total')->value('total');
        $principalOutstanding = app(PrincipalReturnService::class)->outstanding($s, app(WorkspaceClock::class)->date($s)->toDateString(), $user);
        $base = LedgerEntry::query()->join('ledger_transactions AS t', 't.id', '=', 'ledger_entries.ledger_transaction_id')->join('ledger_accounts AS a', 'a.id', '=', 'ledger_entries.ledger_account_id')
            ->where('t.simulation_scenario_id', $s->id)->when($user, fn ($q) => $q->where('t.user_id', $user));
        $deposit = (int) (clone $base)->where('t.kind', 'deposit')->where('a.code', 'reserve')->where('side', 'debit')->sum('amount');
        $paid = (int) (clone $o)->sum('paid_amount');
        $withdrawn = (int) WithdrawalRequest::where('simulation_scenario_id', $s->id)->when($user, fn ($q) => $q->where('user_id', $user))->where('status', 'approved')->sum('amount');
        $risk = app(RiskAnalysisService::class)->analyze($s);
        $metrics = ['deposit' => $deposit, 'promised' => $promised, 'paid' => $paid, 'withdrawn' => $withdrawn, 'outstanding' => $out + $principalOutstanding,
            'roi_outstanding' => $out, 'principal_outstanding' => $principalOutstanding, 'principal_returned' => (int) (clone $i)->sum('principal_returned_amount'), 'principal' => (int) (clone $i)->sum('principal'),
            'wallet_available' => (int) $s->accounts()->whereNotNull('wallet_id')->where('code', 'like', '%:available')->sum('balance'),
            'wallet_pending' => (int) $s->accounts()->whereNotNull('wallet_id')->where('code', 'like', '%:pending')->sum('balance'),
            'investments' => (clone $i)->count(), 'active_investments' => (clone $i)->where('status', 'active')->count(),
            'members' => User::role('member')->where('is_cohort', false)->count(), 'new_members' => User::role('member')->where('is_cohort', false)->whereDate('created_at', today())->count(),
            'active_members' => User::role('member')->where('is_cohort', false)->where('active', true)->count()];
        $cashEntries = (clone $base)->whereBetween('t.effective_date', [$from, $to])->where('a.code', 'cash')->selectRaw("t.effective_date AS date, SUM(CASE WHEN side='debit' THEN amount ELSE 0 END) AS inflow, SUM(CASE WHEN side='credit' THEN amount ELSE 0 END) AS outflow")->groupBy('t.effective_date')->get()->keyBy('date');
        if ($user || $s->isLocal()) {
            $cashEntries = (clone $base)->whereBetween('t.effective_date', [$from, $to])
                ->whereNotNull('a.wallet_id')->when($user, fn ($q) => $q->where('a.code', 'wallet:'.$user.':available'), fn ($q) => $q->where('a.code', 'like', '%:available'))
                ->selectRaw("t.effective_date AS date, SUM(CASE WHEN side='credit' THEN amount ELSE 0 END) AS inflow, SUM(CASE WHEN side='debit' THEN amount ELSE 0 END) AS outflow")->groupBy('t.effective_date')->get()->keyBy('date');
        }
        $runs = $s->runs()->whereBetween('period_date', [$from, $to])->get()->keyBy(fn ($r) => $r->period_date->toDateString());
        $obligations = (clone $o)->whereBetween('due_date', [$from, $to])->selectRaw('due_date AS date, SUM(amount) AS due, SUM(paid_amount) AS paid')->groupBy('due_date')->get()->keyBy('date');
        $chart = ['labels' => [], 'inflow' => [], 'outflow' => [], 'cash' => [], 'due' => [], 'paid' => [], 'members' => [], 'gap' => [], 'outstanding' => [], 'deposits' => []];
        $balanceEntries = (clone $base)->where('t.effective_date', '<', $from);
        if ($user || $s->isLocal()) {
            $balanceEntries->whereNotNull('a.wallet_id')->when($user, fn ($q) => $q->where('a.code', 'wallet:'.$user.':available'), fn ($q) => $q->where('a.code', 'like', '%:available'));
        } else {
            $balanceEntries->where('a.code', 'cash');
        }
        $before = $balanceEntries->selectRaw("COALESCE(SUM(CASE WHEN side='".($user || $s->isLocal() ? 'credit' : 'debit')."' THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END),0) AS total")->value('total');
        $running = (int) $before;
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $key = $date->toDateString();
            $e = $cashEntries->get($key);
            $r = $runs->get($key);
            $ob = $obligations->get($key);
            $in = (int) ($e?->inflow ?? 0);
            $outflow = (int) ($e?->outflow ?? 0);
            $running += $in - $outflow;
            $chart['labels'][] = $date->format('d M');
            $chart['inflow'][] = $in;
            $chart['outflow'][] = $outflow;
            $chart['cash'][] = $running;
            $chart['due'][] = (int) ($ob?->due ?? 0);
            $chart['paid'][] = (int) ($ob?->paid ?? 0);
            $chart['members'][] = $user ? 0 : (int) ($r?->total_members ?? 0);
            $chart['gap'][] = $user ? 0 : (int) ($r?->funding_gap ?? 0);
            $chart['outstanding'][] = $user ? 0 : (int) ($r?->outstanding ?? 0);
            $chart['deposits'][] = $user ? 0 : (int) ($r?->deposits ?? 0);
        }
        $recent = app(ReportingService::class)->query($s, [], $user)->limit(6)->get();

        return compact('metrics', 'risk', 'chart', 'recent');
    }
}
