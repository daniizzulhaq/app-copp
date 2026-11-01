<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_product_turnovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $table->date('period_date');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedInteger('purchase_count');
            $table->unsignedSmallInteger('roi_rate_bps')->default(3000);
            $table->unsignedBigInteger('roi_amount');
            $table->timestamps();
            $table->unique(['simulation_scenario_id', 'period_date'], 'daily_product_turnover_period_unique');
        });

        // Existing purchases retain their original purchase date and principal.
        $totals = DB::table('investments as i')
            ->join('simulation_scenarios as s', 's.id', '=', 'i.simulation_scenario_id')
            ->where('s.mode', 'local')
            ->selectRaw('i.simulation_scenario_id, i.starts_at as period_date, SUM(i.principal) as gross_amount, COUNT(*) as purchase_count')
            ->groupBy('i.simulation_scenario_id', 'i.starts_at')->get();
        foreach ($totals as $total) {
            DB::table('daily_product_turnovers')->insert([
                'simulation_scenario_id' => $total->simulation_scenario_id,
                'period_date' => $total->period_date,
                'gross_amount' => $total->gross_amount,
                'purchase_count' => $total->purchase_count,
                'roi_rate_bps' => 3000,
                'roi_amount' => intdiv((int) $total->gross_amount * 3000, 10000),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('mock_payment_invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('unique_code')->nullable();
            $table->unsignedSmallInteger('reserved_unique_code')->nullable()->unique();
            $table->unsignedBigInteger('transfer_amount')->nullable();
        });
        // A shared lock serializes allocation across every local workspace.
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'deposit_unique_code_cursor', 'value' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('mock_payment_invoices', function (Blueprint $table) {
            $table->dropUnique(['reserved_unique_code']);
            $table->dropColumn(['unique_code', 'reserved_unique_code', 'transfer_amount']);
        });
        DB::table('system_settings')->where('key', 'deposit_unique_code_cursor')->delete();
        Schema::dropIfExists('daily_product_turnovers');
    }
};
