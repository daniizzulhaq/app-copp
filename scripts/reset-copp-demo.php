<?php

// One-time replacement of the original seeded workspace, with a full SQL backup.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

if (! app()->environment('local') || config('database.default') !== 'mysql') {
    throw new RuntimeException('Reset hanya untuk database MySQL lokal berisi demo lama.');
}
$users = App\Models\User::withTrashed()->get();
foreach ($users as $user) {
    if (! preg_match('/^(admin|auditor|member[0-9]*|cohort-[0-9-]+)@nexavest\.(test|invalid)$/', $user->email)) {
        throw new RuntimeException('Reset dibatalkan: ditemukan akun di luar demo lama.');
    }
}
if ($users->isEmpty()) {
    throw new RuntimeException('Demo lama tidak ditemukan.');
}
$database = config('database.connections.mysql');
$backup = 'backups/pre-copp-'.now()->format('Ymd-His').'.sql';
Storage::disk('local')->makeDirectory('backups');
$process = new Process([
    'C:/xampp/mysql/bin/mysqldump.exe', '--host='.$database['host'], '--port='.$database['port'],
    '--user='.$database['username'], '--single-transaction', '--routines', '--triggers',
    '--result-file='.Storage::disk('local')->path($backup), $database['database'],
], base_path(), ['MYSQL_PWD' => $database['password']], null, 120);
$process->mustRun();
if (Storage::disk('local')->size($backup) < 1000) {
    throw new RuntimeException('Backup tidak lengkap; reset dibatalkan.');
}
echo 'Backup: storage/app/private/'.$backup.PHP_EOL;
// Clear cached sessions only after the old demo dataset has been verified and backed up.
DB::disconnect();
$exit = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
echo Artisan::output();
exit($exit);
