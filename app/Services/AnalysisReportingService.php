<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\MockPaymentInvoice;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use App\Support\UiText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AnalysisReportingService
{
    public const TYPES = ['members' => 'Laporan member', 'investments' => 'ROI dijanjikan & portofolio', 'obligations' => 'Kewajiban & ROI dibayar', 'runs' => 'Pencatatan per periode', 'cash' => 'Saldo akun', 'withdrawals' => 'Withdrawal', 'invoices' => 'Deposit manual', 'audit' => 'Aktivitas sistem'];

    public function dataset(SimulationScenario $s, string $type, array $filter): array
    {
        $from = $filter['from'] ?? null;
        $to = $filter['to'] ?? null;
        $date = function ($q, $column) use ($from, $to) {
            if ($from) {
                $q->whereDate($column, '>=', $from);
            } if ($to) {
                $q->whereDate($column, '<=', $to);
            }

            return $q;
        };
        $note = '';
        $summary = [];
        switch ($type) {
            case 'members':
                $query = $date(User::role('member')->where('is_cohort', false), 'created_at')->withCount('referrals', 'investments');
                $headers = ['ID member', 'Nama', 'Email dummy', 'Terdaftar', 'Status', 'Portofolio', 'Referral'];
                $map = fn ($u) => [$u->member_code, $u->name, $u->email, $u->created_at->format('Y-m-d'), $u->active ? 'Aktif' : 'Nonaktif', $u->investments_count, $u->referrals_count];
                $note = 'Direktori akun member seluruh platform, tanpa peserta kohor. Filter berdasarkan tanggal pendaftaran; tidak terbatas satu skenario.';
                break;
            case 'investments':
                $query = $date($s->investments(), 'starts_at')->with('user')->withSum('obligations', 'amount')->withSum('obligations', 'paid_amount')->withSum('obligations', 'expired_amount');
                $headers = ['Nomor', 'Member', 'Produk Investasi snapshot', 'Modal (Rp)', 'ROI (bps/hari)', 'Durasi (hari)', 'Mulai', 'Selesai', 'ROI dijanjikan (Rp)', 'ROI jatuh tempo (Rp)', 'ROI dibayar (Rp)', 'ROI tertunggak (Rp)', 'Modal dikembalikan (Rp)', 'Sisa modal (Rp)', 'Total modal + ROI (Rp)', 'Status'];
                $map = fn ($i) => [$i->number, $i->user->name, $i->package_name, $i->principal, $i->daily_rate_bps, $i->duration_days, $i->starts_at->toDateString(), $i->ends_at->toDateString(), $i->promised(), (int) $i->obligations_sum_amount, (int) $i->obligations_sum_paid_amount, (int) $i->obligations_sum_amount - (int) $i->obligations_sum_paid_amount - (int) $i->obligations_sum_expired_amount, $i->principal_returned_amount, $i->principal - $i->principal_returned_amount, $i->principal + $i->promised(), $i->status];
                $note = 'Filter berdasarkan tanggal mulai portofolio. ROI dijanjikan = floor(modal × bps × durasi / 10.000). Snapshot syarat tidak berubah ketika produk investasi diedit.';
                break;
            case 'obligations':
                $query = $date(RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id)), 'due_date')->with('investment.user');
                $headers = ['ID', 'Portofolio', 'Member', 'Jatuh tempo', 'Kewajiban (Rp)', 'Dibayar (Rp)', 'Hangus (Rp)', 'Belum diselesaikan (Rp)', 'Status'];
                $map = fn ($o) => [$o->id, $o->investment->number, $o->investment->user->name, $o->due_date->toDateString(), $o->amount, $o->paid_amount, $o->expired_amount, $o->remaining(), $o->status];
                $note = 'Filter berdasarkan tanggal jatuh tempo. Sisa kewajiban = jumlah jatuh tempo dikurangi pembayaran aktual dan ROI yang hangus.';
                break;
            case 'runs':
                $query = $date($s->runs(), 'period_date');
                $headers = ['Hari', 'Tanggal pencatatan', 'Peserta baru', 'Total peserta', 'Setoran baru (Rp)', 'ROI jatuh tempo (Rp)', 'ROI dibayar (Rp)', 'Modal dikembalikan (Rp)', 'Modal tertunggak (Rp)', 'Withdrawal (Rp)', 'Kas (Rp)', 'Total tunggakan (Rp)', 'Funding gap (Rp)', 'Coverage (bps)', 'Dependency (bps)', 'Status'];
                $map = fn ($r) => [$r->day, $r->period_date->toDateString(), $r->new_members, $r->total_members, $r->deposits, $r->roi_due, $r->roi_paid, $r->principal_returned, $r->principal_outstanding, $r->withdrawals, $r->cash, $r->outstanding, $r->funding_gap, $r->coverage_bps, $r->dependency_bps, $r->status];
                $note = 'Filter berdasarkan tanggal periode. Gap = maks(0,tunggakan−kas). Coverage = ROI dibayar/(ROI dibayar+tunggakan akhir). Dependency = min(setoran baru,ROI dibayar)/ROI dibayar. 100 bps = 1%.';
                if ($s->isLocal()) {
                    $headers = ['Hari', 'Tanggal', 'Member', 'Deposit (Rp)', 'ROI jatuh tempo (Rp)', 'ROI masuk wallet (Rp)', 'Modal dikembalikan (Rp)', 'Withdrawal (Rp)', 'Status'];
                    $map = fn ($r) => [$r->day, $r->period_date->toDateString(), $r->total_members, $r->deposits, $r->roi_due, $r->roi_paid, $r->principal_returned, $r->withdrawals, $r->status];
                    $note = 'Pencatatan deposit, klaim ROI, pengembalian modal, dan penarikan saldo web per tanggal.';
                }
                break;
            case 'cash':
                $query = $s->accounts()->when($s->isLocal(), fn ($q) => $q->whereNotIn('code', ['cash', 'seed-equity', 'wallet-offset']));
                $headers = ['Kode akun', 'Sisi normal', 'Saldo awal (Rp)', 'Mutasi debit (Rp)', 'Mutasi kredit (Rp)', 'Saldo akhir (Rp)'];
                $map = function ($a) use ($from, $to) {
                    $entries = LedgerEntry::where('ledger_account_id', $a->id)->join('ledger_transactions as t', 't.id', '=', 'ledger_entries.ledger_transaction_id');
                    $opening = $from ? (int) (clone $entries)->where('t.effective_date', '<', $from)->selectRaw('COALESCE(SUM(CASE WHEN side=? THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END),0) as total', [$a->normal_side])->value('total') : 0;
                    $movements = (clone $entries)->when($from, fn ($q) => $q->whereDate('t.effective_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('t.effective_date', '<=', $to));
                    $dr = (int) (clone $movements)->where('side', 'debit')->sum('amount');
                    $cr = (int) (clone $movements)->where('side', 'credit')->sum('amount');

                    return [$a->code, $a->normal_side, $opening, $dr, $cr, $opening + ($a->normal_side === 'debit' ? $dr - $cr : $cr - $dr)];
                };
                $note = 'Saldo awal berasal dari seluruh jurnal sebelum tanggal awal; mutasi mengikuti tanggal efektif; saldo akhir dihitung sesuai sisi normal. Reserve adalah cadangan wallet, cash adalah kas bebas.';
                if ($s->isLocal()) {
                    $note = 'Saldo awal, mutasi, dan saldo akhir pembukuan wallet member sesuai periode yang dipilih.';
                }
                break;
            case 'withdrawals':
                $query = $date(WithdrawalRequest::where('simulation_scenario_id', $s->id), 'created_at')->with('user');
                $headers = ['ID', 'Member', 'Nominal (Rp)', 'Admin (Rp)', 'Diterima (Rp)', 'Metode penarikan', 'Diajukan', 'Status', 'Ditinjau'];
                $map = fn ($w) => [$w->id, $w->user->name, $w->amount, $w->admin_fee, $w->netAmount(), $w->method, $w->created_at->format('Y-m-d H:i:s'), $w->status, $w->reviewed_at?->format('Y-m-d H:i:s') ?? 'Belum'];
                $note = 'Filter berdasarkan tanggal pengajuan. Admin melakukan transfer manual ke rekening tujuan yang tersimpan pada pengajuan withdrawal.';
                break;
            case 'invoices':
                $query = $date(MockPaymentInvoice::where('simulation_scenario_id', $s->id), 'created_at')->with('user');
                $headers = ['Invoice', 'Member', 'Nominal (Rp)', 'Metode', 'Status', 'Dibuat', 'Dibayar', 'Kedaluwarsa'];
                $map = fn ($i) => [$i->number, $i->user->name, $i->amount, $i->method, $i->status, $i->created_at->format('Y-m-d H:i:s'), $i->paid_at?->format('Y-m-d H:i:s') ?? 'Belum', $i->expires_at->format('Y-m-d H:i:s')];
                $note = 'Filter tanggal pencatatan invoice. Setoran kohor mesin skenario tercatat langsung pada ledger, tanpa checkout invoice.';
                break;
            default:
                $query = $date(AuditLog::where(fn ($q) => $q->where('simulation_scenario_id', $s->id)->orWhereNull('simulation_scenario_id')), 'created_at')->with('actor');
                $headers = ['ID', 'Dicatat', 'Pelaku', 'Tindakan', 'Objek', 'Objek ID', 'Sebelum', 'Sesudah'];
                $map = fn ($a) => [$a->id, $a->created_at->format('Y-m-d H:i:s'), $a->actor?->name ?? 'Sistem', UiText::local($a->action), class_basename($a->subject_type ?? 'Sistem'), $a->subject_id ?? '', json_encode($a->before, JSON_UNESCAPED_UNICODE), json_encode($a->after, JSON_UNESCAPED_UNICODE)];
                $note = 'Filter berdasarkan waktu pencatatan aplikasi. Termasuk tindakan global dan skenario terpilih. Rahasia dan password tidak dicatat.';
        }
        if ((clone $query)->count() > 10000) {
            throw ValidationException::withMessages(['from' => 'Batasi periode hingga maksimum 10.000 baris.']);
        }
        $records = $query->orderBy('id')->get();
        $rows = $records->map($map)->all();
        $summary = ['Jumlah baris' => count($rows)];
        $summary += $s->isLocal()
            ? ['Saldo web member' => Money::rupiah((int) $s->accounts()->whereNotNull('wallet_id')->where('code', 'like', '%:available')->sum('balance'))]
            : ['Kas bebas saat ini' => Money::rupiah(app(LedgerService::class)->balance($s, 'cash'))];
        if ($type === 'obligations') {
            $summary += ['ROI jatuh tempo (Rp)' => $records->sum('amount'), 'ROI dibayar (Rp)' => $records->sum('paid_amount'), 'ROI hangus (Rp)' => $records->sum('expired_amount'), 'Tertunggak (Rp)' => $records->sum('amount') - $records->sum('paid_amount') - $records->sum('expired_amount')];
        }
        if ($type === 'investments') {
            $summary += ['Modal (Rp)' => $records->sum('principal'), 'Modal dikembalikan (Rp)' => $records->sum('principal_returned_amount'), 'ROI dijanjikan (Rp)' => $records->sum(fn ($i) => $i->promised())];
        }
        if ($type === 'runs') {
            $summary += ['Setoran baru (Rp)' => $records->sum('deposits'), 'ROI dibayar (Rp)' => $records->sum('roi_paid'), 'Modal dikembalikan (Rp)' => $records->sum('principal_returned'), 'Withdrawal (Rp)' => $records->sum('withdrawals')];
        }
        $title = UiText::local(self::TYPES[$type]);
        $headers = array_map(fn ($header) => UiText::local($header), $headers);
        $note = UiText::local($note);
        $period = ($from ?? 'Awal pencatatan').' s.d. '.($to ?? 'Seluruh periode');
        $generated = now()->format('d/m/Y H:i:s T');

        return compact('title', 'headers', 'rows', 'note', 'summary', 'period', 'generated', 's', 'type');
    }

    public function export(array $data, string $format)
    {
        if ($format === 'pdf' && count($data['rows']) > 1000) {
            throw ValidationException::withMessages(['from' => 'Batasi periode PDF hingga maksimal 1.000 baris. CSV dan Excel mendukung 10.000 baris.']);
        }
        app(AuditService::class)->record('analysis_report.exported', $data['s'], null, ['type' => $data['type'], 'format' => $format, 'rows' => count($data['rows'])], $data['s']->id);
        $name = 'nexavest-'.$data['type'].'-s'.$data['s']->id.'-'.now()->format('YmdHis');
        if ($format === 'pdf') {
            return Pdf::loadView('reports.analysis-pdf', $data)->setPaper('a4', 'landscape')->download($name.'.pdf');
        }
        $table = [[$data['title']], ['COPP Luxury'], ['ID skenario', $data['s']->id, UiText::local($data['s']->name)], ['Periode', $data['period']], ['Dibuat', $data['generated']], ['Penjelasan', $data['note']]];
        foreach ($data['summary'] as $label => $value) {
            $table[] = [$label, $value];
        }$table[] = [];
        $table[] = $data['headers'];
        $table = array_merge($table, $data['rows']);

        return response()->streamDownload(function () use ($table, $format) {
            if ($format === 'csv') {
                $h = fopen('php://output', 'w');
                fwrite($h, "\xEF\xBB\xBF");
                foreach ($table as $line) {
                    fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/u', $v) ? "'".$v : $v, $line), ';', '"', '');
                }fclose($h);

                return;
            }
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            foreach ($table as $ri => $line) {
                foreach ($line as $ci => $value) {
                    $sheet->setCellValueExplicit([$ci + 1, $ri + 1], $value, is_int($value) && abs($value) <= 999999999999999 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.'.$format, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
