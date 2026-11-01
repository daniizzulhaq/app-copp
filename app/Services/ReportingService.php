<?php

namespace App\Services;

use App\Models\LedgerTransaction;
use App\Models\SimulationScenario;
use App\Support\UiText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportingService
{
    public function query(SimulationScenario $s, array $filters = [], ?int $user = null): Builder
    {
        $q = LedgerTransaction::where('simulation_scenario_id', $s->id)->with('user')->withMax('entries', 'amount');
        if ($user) {
            $q->where('user_id', $user);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('effective_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('effective_date', '<=', $filters['to']);
        }
        if (! empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        if (! empty($filters['q'])) {
            $q->where(function ($q) use ($filters) {
                $q->where('description', 'like', '%'.$filters['q'].'%')->orWhere('idempotency_key', 'like', '%'.$filters['q'].'%');
            });
        }

        return $q->orderBy('id', ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc');
    }

    public function export(SimulationScenario $s, array $filters, string $format)
    {
        $query = $this->query($s, $filters);
        if ($format === 'pdf' && (clone $query)->count() > 1000) {
            throw ValidationException::withMessages(['from' => 'Batasi periode PDF hingga maksimal 1.000 transaksi. CSV dan Excel mendukung 10.000 transaksi.']);
        }
        if ((clone $query)->count() > 10000) {
            throw ValidationException::withMessages(['from' => 'Batasi periode laporan hingga maksimal 10.000 transaksi per ekspor.']);
        }
        $rows = $query->get();
        $risk = app(RiskAnalysisService::class)->analyze($s);
        $period = ($filters['from'] ?? $s->start_date->toDateString()).' s.d. '.($filters['to'] ?? app(WorkspaceClock::class)->date($s)->toDateString());
        $generated = now()->format('d/m/Y H:i:s T');
        $name = 'nexavest-s'.$s->id.'-'.now()->format('Ymd-His');
        app(AuditService::class)->record('report.exported', $s, null, ['format' => $format, 'filters' => $filters, 'rows' => $rows->count()], $s->id);
        if ($format === 'pdf') {
            return Pdf::loadView('reports.pdf', compact('s', 'rows', 'risk', 'period', 'generated', 'filters'))->setPaper('a4', 'landscape')->download($name.'.pdf');
        }
        $data = [['COPP Luxury — Laporan transaksi'], ['COPP Luxury'], ['ID Skenario', $s->id, UiText::local($s->name)], ['Periode', $period], ['Dibuat', $generated],
            ['Jumlah transaksi', $rows->count()], ['Kas bebas saat ini', $risk['cash']], ['Tunggakan saat ini', $risk['outstanding']], ['Funding gap saat ini', $risk['gap']],
            ['Metrik', 'Kas: akun cash; tunggakan: ROI dan modal jatuh tempo dikurangi pembayaran; gap: maks(0,tunggakan-kas).'],
            ['Parameter', 'Modal dikembalikan otomatis saat jatuh tempo sesuai kas yang tersedia; tidak ada pendapatan eksternal; ROI sederhana tanpa compounding.'],
            ['Filter jenis', $filters['kind'] ?? 'semua'], ['Pencarian', $filters['q'] ?? ''], [], ['ID', 'Tanggal pencatatan', 'Jenis', 'Member', 'Deskripsi', 'Nominal (Rp)', 'Hash integritas']];
        if ($s->isLocal()) {
            $data = [['COPP Luxury - Laporan saldo web'], ['ID workspace', $s->id, UiText::local($s->name)],
                ['Periode', $period], ['Dibuat', $generated], ['Jumlah transaksi', $rows->count()],
                ['Saldo web member', $risk['wallet_available']], ['Saldo penarikan ditahan', $risk['wallet_pending']],
                ['Parameter', 'Modal dikembalikan penuh sesuai nominal pembelian tersimpan saat jatuh tempo. ROI diklaim member.'],
                [], ['ID', 'Tanggal pencatatan', 'Jenis', 'Member', 'Deskripsi', 'Nominal (Rp)', 'Hash integritas']];
        }
        foreach ($rows as $row) {
            $data[] = [$row->id, $row->effective_date->toDateString(), $row->kind, $row->user?->name ?? 'Sistem', UiText::local($row->description), (int) $row->entries_max_amount, $row->integrity_hash];
        }
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($data) {
                $h = fopen('php://output', 'w');
                fwrite($h, "\xEF\xBB\xBF");
                foreach ($data as $line) {
                    fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/u', $v) ? "'".$v : $v, $line), ';', '"', '');
                } fclose($h);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($data) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            foreach ($data as $ri => $line) {
                foreach ($line as $ci => $v) {
                    $sheet->setCellValueExplicit([$ci + 1, $ri + 1], $v, is_int($v) && abs($v) <= 999999999999999 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $sheet->freezePane('A16');
            foreach (range('A', 'G') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
