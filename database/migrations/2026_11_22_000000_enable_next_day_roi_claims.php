<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_roi_claim_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $table->date('period_date');
            $table->date('claim_date');
            $table->unsignedBigInteger('pool_amount');
            $table->unsignedBigInteger('legacy_paid_amount')->default(0);
            $table->unsignedBigInteger('distributable_amount');
            $table->unsignedBigInteger('total_shares')->default(0);
            $table->timestamps();
            $table->unique(['simulation_scenario_id', 'period_date']);
        });
        Schema::create('daily_roi_claim_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_roi_claim_pool_id')->constrained()->restrictOnDelete();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->foreignId('roi_obligation_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('shares');
            $table->unsignedBigInteger('remaining_cap');
            $table->unsignedBigInteger('amount');
            $table->unique(['daily_roi_claim_pool_id', 'investment_id'], 'roi_claim_share_unique');
        });
        Schema::table('simulation_scenarios', fn (Blueprint $table) => $table->date('roi_claims_start_date')->nullable());
        // Enable only after the new tables exist. Keep historical pools and wallet entries intact.
        DB::table('simulation_scenarios')->where('mode', 'local')->where('plan_version', 'turnover_v1')
            ->where('status', '!=', 'archived')->update([
                'plan_version' => 'turnover_v2', 'roi_claims_start_date' => today()->toDateString(),
            ]);
    }

    public function down(): void
    {
        if (DB::table('daily_roi_claim_pools')->exists()) {
            throw new LogicException('Hak ROI esok hari sudah terbentuk. Riwayat keuangan harus dipertahankan.');
        }
        DB::table('simulation_scenarios')->where('plan_version', 'turnover_v2')->update(['plan_version' => 'turnover_v1']);
        Schema::table('simulation_scenarios', fn (Blueprint $table) => $table->dropColumn('roi_claims_start_date'));
        Schema::dropIfExists('daily_roi_claim_shares');
        Schema::dropIfExists('daily_roi_claim_pools');
    }
};
