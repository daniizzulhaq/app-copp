<?php

use App\Jobs\ProcessLocalRoi;
use App\Jobs\RunSimulationDay;
use App\Models\RoiObligation;
use App\Models\SimulationScenario;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LedgerIntegrityService;
use App\Services\LedgerService;
use App\Services\LocalOperationsService;
use App\Services\ManualDepositService;
use App\Services\MockPaymentService;
use App\Services\SimulationEngineService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('nexavest:simulate {scenario} {--days=1}', function () {
    $s = SimulationScenario::findOrFail($this->argument('scenario'));
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
    if (! $days || $days < 1 || $days > 365 || $s->current_day + $days > 365) {
        $this->error('Jumlah hari harus 1–365 dan hari akhir maksimum 365.');

        return 1;
    }
    for ($n = 0; $n < $days; $n++) {
        $s->refresh();
        $r = app(SimulationEngineService::class)->step($s, $s->current_day + 1);
        $this->info('Hari '.$r->day.' | kas '.$r->cash.' | tunggakan '.$r->outstanding);
    }
})->purpose('Jalankan periode simulasi berikutnya secara berurutan');

Artisan::command('nexavest:tick {--date=}', function () {
    if (! config('nexavest.scheduler_enabled')) {
        $this->info('Scheduler simulasi nonaktif. Set SIMULATION_SCHEDULER_ENABLED=true untuk mengaktifkan.');

        return 0;
    }
    $date = $this->option('date') ?: today()->toDateString();
    try {
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);
    } catch (Throwable) {
        $this->error('Tanggal tidak valid.');

        return 1;
    }
    if ($parsed->toDateString() !== $date) {
        $this->error('Tanggal harus YYYY-MM-DD.');

        return 1;
    }
    SimulationScenario::where('mode', 'simulation')->whereIn('status', ['running', 'defaulted'])->get()->each(function ($s) use ($date) {
        $day = (int) $s->start_date->diffInDays(Carbon::parse($date), false);
        if ($day >= 1 && $day <= 365 && $day === $s->current_day + 1) {
            RunSimulationDay::dispatch($s->id, $day);
        }
    });
    $this->info('Hari kalender yang jatuh tempo dikirim ke queue; pemanggilan ulang tetap idempoten.');
})->purpose('Scheduler satu hari kalender; mengejar periode tertinggal dilakukan eksplisit dengan nexavest:simulate');

Artisan::command('nexavest:verify-ledger {scenario?}', function () {
    $query = SimulationScenario::query();
    if ($this->argument('scenario')) {
        $query->whereKey($this->argument('scenario'));
    }
    $bad = false;
    foreach ($query->get() as $s) {
        $errors = app(LedgerIntegrityService::class)->check($s);
        foreach ($errors as $error) {
            $this->error('S'.$s->id.': '.$error);
        } $bad = $bad || count($errors) > 0;
        if (! $errors) {
            $this->info('S'.$s->id.': jurnal seimbang, rantai hash valid, saldo dan cadangan wallet konsisten.');
        }
    }

    return $bad ? 1 : 0;
})->purpose('Verifikasi hash chain, keseimbangan jurnal, saldo akun, dan cadangan wallet');

Artisan::command('nexavest:expire-payments', function () {
    $this->info((app(MockPaymentService::class)->expire() + app(ManualDepositService::class)->expire()).' invoice diproses.');
});
Artisan::command('nexavest:process-local-roi {--sync}', function () {
    if (! config('nexavest.local_roi_scheduler_enabled')) {
        $this->info('Scheduler ROI lokal nonaktif.');

        return 0;
    }
    foreach (SimulationScenario::where('mode', 'local')->whereIn('status', ['running', 'defaulted'])->get() as $workspace) {
        if ($this->option('sync')) {
            app(LocalOperationsService::class)->process($workspace);
        } else {
            ProcessLocalRoi::dispatch($workspace->id, today()->toDateString());
        }
    }
    $this->info('Hak klaim ROI hari ini disiapkan, ROI tidak diklaim dari hari sebelumnya dihanguskan, dan modal jatuh tempo diproses. ROI hanya masuk wallet setelah diklaim member.');
});
Schedule::command('nexavest:process-local-roi')->everyMinute()->withoutOverlapping();
Schedule::command('nexavest:tick')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('nexavest:expire-payments')->everyMinute()->withoutOverlapping();

Artisan::command('nexavest:demo-passwords', function () {
    if (! app()->environment('local') || ! is_string(config('nexavest.demo_password')) || config('nexavest.demo_password') === '') {
        $this->error('Khusus environment local; isi DEMO_PASSWORD pada .env.');

        return 1;
    }
    DB::transaction(function () {
        $users = User::where('is_cohort', false)->where('email', 'like', '%@nexavest.test')->get();
        $hash = Hash::make(config('nexavest.demo_password'));
        foreach ($users as $u) {
            $u->forceFill(['password' => $hash, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
        }
        app(AuditService::class)->record('demo.passwords_reset', null, null, ['accounts' => $users->count()]);
    });
    Storage::disk('local')->put('demo-accounts.txt', "NEXAVEST — AKUN DEMO LOKAL\nAdmin: admin@nexavest.test\nMember: member@nexavest.test\nKata sandi kedua akun: ".config('nexavest.demo_password')."\n");
    $this->info('Password akun demo diperbarui sesuai DEMO_PASSWORD.');
});

Artisan::command('nexavest:prepare-database', function () {
    if (! app()->environment('local')) {
        $this->error('Persiapan database otomatis hanya untuk environment local.');

        return 1;
    }
    $connection = config('database.connections.mysql');
    $name = $connection['database'];
    if (! preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
        $this->error('Nama database harus berupa huruf, angka, dan underscore.');

        return 1;
    }
    $pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $this->info('Database simulasi siap. Database yang sudah ada tidak dihapus.');
});

Artisan::command('nexavest:recognize-liabilities', function () {
    // Append an opening recognition for databases created before accrual journals were added.
    // No historical journal is edited. Repeated execution makes no additional posting.
    foreach (SimulationScenario::all() as $scenario) {
        DB::transaction(function () use ($scenario) {
            $s = SimulationScenario::whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $ledger = app(LedgerService::class);
            $ledger->initialize($s);
            $out = (int) RoiObligation::whereHas('investment', fn ($q) => $q->where('simulation_scenario_id', $s->id))->selectRaw('COALESCE(SUM(amount-paid_amount-expired_amount),0) AS total')->value('total');
            $gap = $out - $ledger->balance($s, 'roi-payable');
            if ($gap < 0) {
                throw new RuntimeException('Saldo kewajiban melebihi subledger. Periksa skenario '.$s->id);
            }
            if ($gap > 0) {
                $ledger->post($s, 'liability-opening:'.$s->id, 'liability_opening', 'Pengakuan saldo awal tunggakan historis', [['roi-expense', 'debit', $gap], ['roi-payable', 'credit', $gap]]);
            }
        });
    }
    $this->info('Kewajiban ledger dan subledger konsisten. Histori asli tidak diubah.');
});
