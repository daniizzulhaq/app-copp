<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScenarioRequest;
use App\Jobs\RunSimulationDay;
use App\Models\SimulationScenario;
use App\Services\AuditService;
use App\Services\RiskAnalysisService;
use App\Services\SimulationEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ScenarioController extends Controller
{
    public function index()
    {
        Gate::authorize('view-reports');
        if (! SimulationScenario::where('mode', 'simulation')->exists()) {
            return redirect('/operations');
        }
        $scenarios = SimulationScenario::where('mode', 'simulation')->withCount('runs')->orderBy('id')->paginate(12);
        $risks = [];
        foreach ($scenarios as $s) {
            $risks[$s->id] = app(RiskAnalysisService::class)->analyze($s);
        }

        return view('scenarios.index', compact('scenarios', 'risks'));
    }

    public function create()
    {
        Gate::authorize('manage');

        return view('scenarios.form', ['s' => new SimulationScenario(['start_date' => today(), 'initial_members' => 20, 'daily_new_members' => 2, 'growth_bps' => 0, 'decline_bps' => 0, 'decline_after_day' => 10, 'average_deposit' => 1000000, 'daily_rate_bps' => 200, 'duration_days' => 60, 'payment_frequency_days' => 1, 'withdrawal_bps' => 8000, 'initial_cash' => 0])]);
    }

    public function store(ScenarioRequest $r)
    {
        $s = DB::transaction(function () use ($r) {
            $s = SimulationScenario::create($r->validated() + ['created_by' => $r->user()->id]);
            app(AuditService::class)->record('scenario.created', $s, null, $s->toArray(), $s->id);

            return $s;
        });

        return redirect('/scenarios/'.$s->id)->with('status', 'Skenario siap dijalankan.');
    }

    public function show(SimulationScenario $scenario)
    {
        Gate::authorize('view-reports');
        if ($scenario->isLocal()) {
            return redirect('/operations');
        }
        $s = $scenario;
        $risk = app(RiskAnalysisService::class)->analyze($s);
        $runs = $s->runs()->orderByDesc('day')->paginate(15);
        $chart = $s->runs()->orderBy('day')->get();

        return view('scenarios.show', compact('s', 'risk', 'runs', 'chart'));
    }

    public function edit(SimulationScenario $scenario)
    {
        Gate::authorize('manage');
        abort_unless($scenario->status === 'draft', 422, 'Parameter skenario yang sudah berjalan dibekukan. Duplikasi untuk eksperimen baru.');

        return view('scenarios.form', ['s' => $scenario]);
    }

    public function update(ScenarioRequest $r, SimulationScenario $scenario)
    {
        DB::transaction(function () use ($r, $scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->status === 'draft', 422);
            $before = $s->toArray();
            $s->update($r->validated());
            app(AuditService::class)->record('scenario.updated', $s, $before, $s->toArray(), $s->id);
        });

        return redirect('/scenarios/'.$scenario->id)->with('status', 'Parameter diperbarui.');
    }

    public function run(Request $r, SimulationScenario $scenario, SimulationEngineService $engine)
    {
        Gate::authorize('manage');
        abort_if($scenario->isLocal(), 422, 'Gunakan menu Operasional Lokal untuk memproses ROI kalender.');
        $d = $r->validate(['target_day' => 'required|integer|min:1|max:365', 'mode' => 'required|in:sync,queue']);
        if ($d['mode'] === 'queue') {
            RunSimulationDay::dispatch($scenario->id, (int) $d['target_day']);
        } else {
            $engine->step($scenario, (int) $d['target_day']);
        }

        return back()->with('status', $d['mode'] === 'queue' ? 'Pekerjaan masuk antrean. Jalankan queue worker.' : 'Periode simulasi selesai; ledger dan analisis diperbarui.');
    }

    public function reset(Request $r, SimulationScenario $scenario)
    {
        Gate::authorize('manage');
        abort_if($scenario->isLocal(), 422, 'Histori operasional lokal tidak direset melalui mesin eksperimen.');
        $r->validate(['confirmation' => 'required|in:RESET']);
        $copy = DB::transaction(function () use ($scenario, $r) {
            $s = $scenario->replicate(['current_day', 'participant_count', 'status']);
            $s->name = Str::limit($scenario->name, 70, '').' — reset '.now()->format('His');
            $s->current_day = 0;
            $s->participant_count = 0;
            $s->status = 'draft';
            $s->created_by = $r->user()->id;
            $s->save();
            app(AuditService::class)->record('scenario.reset_as_new', $s, null, ['source_scenario' => $scenario->id], $s->id);

            return $s;
        });
        $r->session()->put('scenario_id', $copy->id);

        return redirect('/scenarios/'.$copy->id)->with('status', 'Eksperimen baru dibuat dari parameter awal. Histori skenario asal tetap tersedia.');
    }

    public function archive(SimulationScenario $scenario)
    {
        Gate::authorize('manage');
        DB::transaction(function () use ($scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $s->update(['status' => 'archived']);
            app(AuditService::class)->record('scenario.archived', $s, null, null, $s->id);
        });

        return redirect('/scenarios')->with('status', 'Skenario diarsipkan.');
    }
}
