<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\RoiObligation;
use App\Models\SimulationRun;
use App\Models\SimulationScenario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalOperationsService
{
    public function process(SimulationScenario $workspace, ?string $through = null): SimulationRun
    {
        $date = Carbon::createFromFormat('!Y-m-d', $through ?? today()->toDateString());
        if (! $date || $date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'ROI lokal hanya dapat diproses sampai tanggal hari ini.']);
        }
        // A delayed worker may carry yesterday's date. Cash postings always use
        // the execution date; only obligation due dates belong to past periods.
        $date = today();
        if (! $workspace->isLocal()) {
            throw ValidationException::withMessages(['scenario' => 'Proses ini khusus workspace operasional lokal.']);
        }
        $day = (int) $workspace->start_date->diffInDays($date, false);
        if ($day < 0) {
            throw ValidationException::withMessages(['date' => 'Tanggal mendahului pembukaan workspace.']);
        }
        $last = $workspace->runs()->max('day');
        if ($last !== null && $day < $last) {
            return $workspace->runs()->where('day', $day)->firstOrFail();
        }

        return $this->processDay($workspace, $day);
    }

    private function processDay(SimulationScenario $workspace, int $day): SimulationRun
    {
        return DB::transaction(function () use ($workspace, $day) {
            $s = SimulationScenario::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (! $s->isLocal() || $s->status === 'archived') {
                throw ValidationException::withMessages(['scenario' => 'Workspace lokal tidak aktif.']);
            }
            $date = $s->start_date->copy()->addDays($day)->toDateString();
            $old = $s->runs()->where('day', $day)->first();
            if ($day < $s->current_day && $old) {
                return $old;
            }
            app(LedgerService::class)->initialize($s);
            app(ROIObligationService::class)->accrue($s, Carbon::parse($date));
            app(SimulatedDistributionService::class)->pay($s, $date);
            $obligations = RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))->where('due_date', '<=', $date);
            $outstanding = (int) (clone $obligations)->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS total')->value('total');
            $principalOutstanding = app(PrincipalReturnService::class)->outstanding($s, $date);
            $outstanding += $principalOutstanding;
            $due = (int) (clone $obligations)->whereDate('due_date', $date)->sum('amount');
            $entries = LedgerEntry::join('ledger_transactions AS t', 't.id', '=', 'ledger_entries.ledger_transaction_id')
                ->join('ledger_accounts AS a', 'a.id', '=', 'ledger_entries.ledger_account_id')->where('t.simulation_scenario_id', $s->id)->whereDate('t.effective_date', $date);
            $paid = (int) (clone $entries)->where('t.kind', 'roi_paid')->where('a.code', 'roi-payable')->where('side', 'debit')->sum('amount');
            $deposits = (int) (clone $entries)->where('t.kind', 'deposit')->where('a.code', 'reserve')->where('side', 'debit')->sum('amount');
            $withdrawals = (int) (clone $entries)->where('t.kind', 'withdrawal_approved')->where('a.code', 'reserve')->where('side', 'credit')->sum('amount');
            $members = (int) $s->investments()->distinct('user_id')->count('user_id');
            $data = ['period_date' => $date, 'new_members' => 0, 'total_members' => $members, 'deposits' => $deposits, 'roi_due' => $due, 'roi_paid' => $paid,
                'withdrawals' => $withdrawals, 'cash' => 0, 'outstanding' => $outstanding, 'funding_gap' => 0,
                'principal_returned' => app(PrincipalReturnService::class)->paidOn($s, $date), 'principal_outstanding' => $principalOutstanding,
                'coverage_bps' => $paid + $outstanding > 0 ? min(10000, intdiv($paid * 10000, $paid + $outstanding)) : 10000,
                'dependency_bps' => 0,
                'status' => $principalOutstanding > 0 ? 'defaulted' : 'running'];
            $run = $old ?? new SimulationRun(['simulation_scenario_id' => $s->id, 'day' => $day]);
            $before = $old?->only(array_keys($data));
            $run->fill($data);
            if (! $run->exists || $run->isDirty()) {
                $run->save();
                app(AuditService::class)->record('local_roi.period_processed', $run, $before, $data, $s->id);
            }
            $s->update(['current_day' => max($s->current_day, $day), 'status' => $data['status']]);

            return $run;
        }, 5);
    }
}
