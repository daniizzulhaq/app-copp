<?php

namespace App\Services;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use Database\Seeders\ProductPlanSeeder;
use Illuminate\Support\Facades\DB;

class MemberPlanResetService
{
    public function reset(): SimulationScenario
    {
        return DB::transaction(function () {
            SystemSetting::firstOrCreate(['key' => 'local_workspace'], ['value' => '']);
            $setting = SystemSetting::whereKey('local_workspace')->lockForUpdate()->firstOrFail();
            $current = SimulationScenario::find($setting->value);
            // Retrying this rollout must not zero accounts a second time.
            if ($current?->usesTurnoverRoi()) {
                return $current;
            }
            SimulationScenario::where('mode', 'local')->where('status', '!=', 'archived')->update(['status' => 'archived']);
            $workspace = SimulationScenario::create([
                'name' => 'COPP Gotong Royong', 'description' => 'Pool ROI 60% omzet, dua produk, dan bonus aktivasi hingga level 5.',
                'mode' => 'local', 'plan_version' => 'turnover_v1', 'start_date' => today(), 'status' => 'running',
                'initial_members' => 0, 'daily_new_members' => 0, 'growth_bps' => 0, 'decline_bps' => 0, 'decline_after_day' => 0,
                'average_deposit' => 0, 'daily_rate_bps' => 0, 'duration_days' => 0, 'payment_frequency_days' => 1,
                'withdrawal_bps' => 0, 'initial_cash' => 0, 'current_day' => 0, 'participant_count' => 0,
            ]);
            app(LedgerService::class)->initialize($workspace);
            $setting->update(['value' => (string) $workspace->id]);
            SystemSetting::updateOrCreate(['key' => 'default_scenario'], ['value' => (string) $workspace->id]);
            SystemSetting::updateOrCreate(['key' => 'minimum_withdrawal_amount'], ['value' => '30000']);
            (new ProductPlanSeeder)->run();
            DB::table('sessions')->whereNotNull('user_id')->delete();
            app(AuditService::class)->record('members.reset_to_turnover_plan', $workspace, null,
                ['previous_workspace' => $current?->id, 'starting_balance' => 0, 'accounts_and_referrals_retained' => true], $workspace->id);

            return $workspace;
        }, 5);
    }
}
