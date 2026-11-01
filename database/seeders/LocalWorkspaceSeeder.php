<?php

namespace Database\Seeders;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            SystemSetting::firstOrCreate(['key' => 'local_workspace'], ['value' => '']);
            $setting = SystemSetting::whereKey('local_workspace')->lockForUpdate()->firstOrFail();
            if ($setting->value && SimulationScenario::whereKey($setting->value)->where('mode', 'local')->exists()) {
                return;
            }
            $workspace = SimulationScenario::create([
                'name' => 'COPP Luxury', 'description' => 'Sistem jaringan matahari, produk ROI, bonus referral, dan misi member COPP.',
                'mode' => 'local', 'start_date' => today(), 'status' => 'running',
                'initial_members' => 0, 'daily_new_members' => 0, 'growth_bps' => 0, 'decline_bps' => 0, 'decline_after_day' => 0,
                'average_deposit' => 0, 'daily_rate_bps' => 500, 'duration_days' => 42, 'payment_frequency_days' => 1,
                'withdrawal_bps' => 0, 'initial_cash' => 0, 'current_day' => 0, 'participant_count' => 0,
            ]);
            app(LedgerService::class)->initialize($workspace);
            $setting->update(['value' => $workspace->id]);
            SystemSetting::updateOrCreate(['key' => 'default_scenario'], ['value' => $workspace->id]);
            app(AuditService::class)->record('local_workspace.created', $workspace, null, ['initial_balance' => 0, 'automatic_participants' => false], $workspace->id);
        }, 5);
    }
}
