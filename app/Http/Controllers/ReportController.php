<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\AuditLog;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\ReportingService;
use App\Services\RiskAnalysisService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function index(ReportRequest $r, ScenarioContext $context, ReportingService $reporting)
    {
        $s = $context->resolve($r);
        $filters = $r->validated();
        if ($r->filled('format')) {
            return $reporting->export($s, $filters, $r->format);
        }
        $transactions = $reporting->query($s, $filters)->paginate(20)->withQueryString();
        $risk = app(RiskAnalysisService::class)->analyze($s);

        return view('reports.index', compact('s', 'filters', 'transactions', 'risk'));
    }

    public function ledger(Request $r, ScenarioContext $context)
    {
        Gate::authorize('view-reports');
        $s = $context->resolve($r);
        $accounts = LedgerAccount::where('simulation_scenario_id', $s->id)
            ->when($s->isLocal(), fn ($q) => $q->whereNotIn('code', ['cash', 'seed-equity', 'wallet-offset']))
            ->orderBy('id')->paginate(20)->withQueryString();

        return view('reports.ledger', compact('s', 'accounts'));
    }

    public function transaction(LedgerTransaction $transaction)
    {
        Gate::authorize('view-reports');
        $transaction->load('entries.account', 'user');

        return view('reports.transaction', compact('transaction'));
    }

    public function reverse(Request $r, LedgerTransaction $transaction)
    {
        Gate::authorize('manage');
        $d = $r->validate(['reason' => 'required|string|min:10|max:200']);
        app(LedgerService::class)->reverse($transaction, $d['reason']);

        return back()->with('status', 'Transaksi pembalik dicatat.');
    }

    public function audit(Request $r, ScenarioContext $context)
    {
        Gate::authorize('view-reports');
        $s = $context->resolve($r);
        $r->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $logs = AuditLog::with('actor')->where(fn ($q) => $q->where('simulation_scenario_id', $s->id)->orWhereNull('simulation_scenario_id'))
            ->when($r->q, fn ($q) => $q->where('action', 'like', '%'.$r->q.'%'))->when($r->from, fn ($q) => $q->whereDate('created_at', '>=', $r->from))->when($r->to, fn ($q) => $q->whereDate('created_at', '<=', $r->to))
            ->latest('id')->paginate(20)->withQueryString();

        return view('reports.audit', compact('s', 'logs'));
    }

    public function settings()
    {
        Gate::authorize('manage');

        return view('settings', ['default' => SystemSetting::whereKey('default_scenario')->value('value'), 'scenarios' => SimulationScenario::where('status', '!=', 'archived')->get(), 'bankAccount' => SystemSetting::depositAccount()]);
    }

    public function saveSettings(Request $r)
    {
        Gate::authorize('manage');
        $d = $r->validate([
            'default_scenario' => 'required|integer|exists:simulation_scenarios,id',
            'deposit_bank_name' => 'nullable|required_with:deposit_account_number,deposit_account_holder|string|max:100',
            'deposit_account_number' => 'nullable|required_with:deposit_bank_name,deposit_account_holder|string|regex:/^[0-9 -]+$/|max:50',
            'deposit_account_holder' => 'nullable|required_with:deposit_bank_name,deposit_account_number|string|max:150',
        ]);
        DB::transaction(function () use ($d) {
            $old = SystemSetting::whereKey('default_scenario')->value('value');
            SystemSetting::updateOrCreate(['key' => 'default_scenario'], ['value' => $d['default_scenario']]);
            foreach (['deposit_bank_name', 'deposit_account_number', 'deposit_account_holder'] as $key) {
                if (array_key_exists($key, $d)) {
                    SystemSetting::updateOrCreate(['key' => $key], ['value' => $d[$key] ?? '']);
                }
            }
            app(AuditService::class)->record('settings.updated', null, ['default_scenario' => $old], $d);
        });

        return back()->with('status', 'Pengaturan disimpan.');
    }
}
