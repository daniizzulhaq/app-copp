<?php

namespace App\Console\Commands;

use App\Models\DailyProductTurnover;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckMemberRoi extends Command
{
    protected $signature = 'copp:roi-check {member : Username atau email member}';

    protected $description = 'Periksa penyebab klaim ROI member tanpa mengubah saldo atau hak klaim';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('member'));
        $member = User::where(fn ($query) => $query->where('username', $identifier)->orWhere('email', $identifier))->first();
        if (! $member) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }
        $workspace = SimulationScenario::find(SystemSetting::whereKey('local_workspace')->value('value'));
        if (! $workspace) {
            $this->error('Workspace operasional belum ditetapkan melalui local_workspace.');

            return self::FAILURE;
        }

        $sourceDate = $workspace->usesNextDayTurnoverRoi() ? today()->subDay() : today();
        $day = DailyProductTurnover::where('simulation_scenario_id', $workspace->id)->whereDate('period_date', $sourceDate)->first();
        $pool = DB::table($workspace->usesNextDayTurnoverRoi() ? 'daily_roi_claim_pools' : 'daily_roi_pools')->where('simulation_scenario_id', $workspace->id)
            ->whereDate('period_date', $sourceDate)->first();
        $this->table(['Pemeriksaan', 'Nilai'], [
            ['Waktu aplikasi', now()->format('Y-m-d H:i:s').' '.config('app.timezone')],
            ['Workspace', $workspace->id.' / '.$workspace->name],
            ['Mode / plan / status', $workspace->mode.' / '.$workspace->plan_version.' / '.$workspace->status],
            ['Akun aktif / admin', ($member->active ? 'ya' : 'tidak').' / '.($member->isStaff() ? 'ya' : 'tidak')],
            ['Tanggal sumber omzet', $sourceDate->toDateString()],
            ['Omzet sumber', Money::rupiah($day?->gross_amount ?? 0)],
            ['Anggaran ROI dari omzet sumber', Money::rupiah($day?->roi_amount ?? 0)],
            ['Sudah dibayar pada plan lama', Money::rupiah($pool->legacy_paid_amount ?? 0)],
            [$workspace->usesNextDayTurnoverRoi() ? 'Jumlah produk peserta tersimpan' : 'Bagian peserta tersimpan', $pool?->total_shares ?? 0],
        ]);

        $investments = $member->investments()->where('simulation_scenario_id', $workspace->id)
            ->withSum('obligations', 'amount')
            ->with(['obligations' => fn ($query) => $query->whereDate('due_date', today())])->orderBy('id')->get();
        $this->table(['Produk', 'Aktivasi', 'Metode', 'Status', 'Hak hari ini', 'Sisa klaim'], $investments->map(fn ($investment) => [
            $investment->number, $investment->starts_at->toDateString(), $investment->roi_method, $investment->status,
            Money::rupiah((int) $investment->obligations->sum('amount')),
            Money::rupiah((int) $investment->obligations->sum(fn ($claim) => $claim->remaining())),
        ])->all());

        if (! $workspace->isLocal() || $workspace->status === 'archived') {
            $this->warn('Workspace bukan operasional aktif; klaim ROI tidak tersedia.');
        }
        if (! $workspace->usesTurnoverRoi() && $investments->contains('roi_method', 'turnover')) {
            $this->warn('Plan workspace tidak cocok: produk turnover berada pada workspace legacy. Akrual dan bonus turnover tidak berjalan.');
        }
        if ($workspace->usesTurnoverRoi() && ! $day?->roi_amount) {
            $this->warn($workspace->usesNextDayTurnoverRoi()
                ? 'Tidak ada omzet pembelian produk kemarin; tidak ada tambahan pool untuk klaim hari ini.'
                : 'Tidak ada pool ROI hari ini. Kode saat ini memakai omzet hari berjalan; omzet kemarin tidak dibawa ke hari ini.');
        }
        if ($investments->isEmpty()) {
            $this->warn('Tidak ada pembelian produk akun ini pada workspace tersebut.');
        }
        foreach ($investments as $investment) {
            if ($investment->starts_at->greaterThanOrEqualTo(today())) {
                $this->warn($investment->number.': produk baru mulai ikut pembagian pada '.$investment->starts_at->copy()->addDay()->toDateString().'.');
            }
            if ($investment->roi_method === 'turnover' && $investment->obligations_sum_amount >= $investment->promised()) {
                $this->warn($investment->number.': batas alokasi ROI sudah tercapai, termasuk hak yang hangus.');
            }
        }
        $this->info('Pemeriksaan hanya membaca data; tidak menyiapkan, membayar, atau menghanguskan klaim.');

        return self::SUCCESS;
    }
}
