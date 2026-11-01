<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $source = config('database.connections.mysql');
        // Tests use a separate database; no migrate:fresh or DDL on the workspace.
        config(['database.connections.claim_test_admin' => array_replace($source, ['database' => null])]);
        config(['database.connections.claim_test_admin.url' => null]);
        DB::connection('claim_test_admin')->statement('CREATE DATABASE IF NOT EXISTS nexavest_claims_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config([
            'database.default' => 'claim_tests',
            'database.connections.claim_tests' => array_replace($source, ['database' => 'nexavest_claims_test', 'url' => null]),
            'filesystems.disks.local.root' => storage_path('framework/testing/daily-roi'),
        ]);
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\RoleSeeder::class, '--force' => true]);

        return $app;
    }
}
