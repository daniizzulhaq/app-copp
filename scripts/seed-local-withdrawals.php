<?php

// Optional sample data for testing the member withdrawal ticker locally.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\RoiPackage;
use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\InvestmentService;
use App\Services\LedgerIntegrityService;
use App\Services\WalletService;
use App\Services\WithdrawalSimulationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$connection = DB::connection();
if (! app()->environment('local') || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Data contoh hanya boleh ditambahkan di database lokal.');
}
$workspace = SimulationScenario::whereKey(SystemSetting::whereKey('local_workspace')->value('value'))
    ->where('mode', 'local')->where('status', '!=', 'archived')->firstOrFail();
$admin = User::role('super-admin')->where('active', true)->firstOrFail();
$package = RoiPackage::where('active', true)->orderBy('minimum_amount')->firstOrFail();
if (SystemSetting::minimumWithdrawalAmount() > 50000) {
    throw new RuntimeException('Minimum WD saat ini melebihi Rp50.000. Pengaturan tidak diubah.');
}

$rows = DB::transaction(function () use ($workspace, $admin, $package) {
    SimulationScenario::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
    $before = app(LedgerIntegrityService::class)->check($workspace);
    $rows = [];
    foreach ([['Budi Demo Lokal', 50000], ['Siti Demo Lokal', 100000], ['Andi Demo Lokal', 200000]] as $index => [$name, $amount]) {
        $marker = 'LOCAL-WD-DEMO-'.($index + 1);
        $member = User::withTrashed()->where('email', 'wd-demo-'.($index + 1).'@copp.test')->first();
        if ($member && ($member->member_code !== $marker || $member->trashed() || ! $member->active || $member->isStaff())) {
            throw new RuntimeException('Akun contoh berbenturan dengan akun yang sudah ada.');
        }
        if (! $member) {
            $member = new User(['name' => $name, 'email' => 'wd-demo-'.($index + 1).'@copp.test', 'password' => Str::random(48)]);
            $member->forceFill([
                'member_code' => $marker, 'active' => true, 'is_cohort' => false,
                'email_verified_at' => now(), 'bank_name' => 'BANK DEMO LOKAL',
                'bank_account_number' => '000000000'.($index + 1), 'bank_account_holder' => $name,
            ])->save();
            $member->assignRole('member');
        }
        $note = 'DATA CONTOH LOKAL - uji notifikasi WD - '.$marker;
        $withdrawal = WithdrawalRequest::where('user_id', $member->id)
            ->where('simulation_scenario_id', $workspace->id)->where('note', $note)->first();
        if (! $withdrawal) {
            app(WalletService::class)->creditDeposit($member, $workspace, $package->minimum_amount + $amount,
                'local-wd-demo:'.$workspace->id.':'.$member->id.':deposit');
            app(InvestmentService::class)->open($member, $workspace, $package, $package->minimum_amount, (string) Str::uuid());
            $withdrawal = app(WithdrawalSimulationService::class)->request($member, $workspace, $amount,
                'bank_transfer', $note, (string) Str::uuid());
        }
        app(WithdrawalSimulationService::class)->review($withdrawal, true, $admin, 'Data contoh untuk pengujian lokal; tanpa transfer bank.');
        $rows[] = ['name' => $name, 'amount' => $amount, 'withdrawal_id' => $withdrawal->id, 'status' => $withdrawal->fresh()->status];
    }
    $newErrors = array_diff(app(LedgerIntegrityService::class)->check($workspace), $before);
    if ($newErrors) {
        throw new RuntimeException('Pemeriksaan ledger gagal: '.implode('; ', $newErrors));
    }

    return $rows;
}, 5);

echo json_encode(['workspace_id' => $workspace->id, 'withdrawals' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
