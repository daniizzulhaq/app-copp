<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_scenarios', fn (Blueprint $t) => $t->string('plan_version')->default('legacy'));
        foreach (['roi_packages', 'investments'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->string('roi_method')->default('fixed');
                $t->unsignedInteger('roi_shares')->default(1);
            });
        }
        Schema::create('daily_roi_pools', function (Blueprint $t) {
            $t->id();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->date('period_date');
            $t->unsignedBigInteger('total_shares')->default(0);
            $t->unsignedBigInteger('pool_amount')->default(0);
            $t->timestamps();
            $t->unique(['simulation_scenario_id', 'period_date']);
        });
        Schema::create('daily_roi_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('daily_roi_pool_id')->constrained()->restrictOnDelete();
            $t->foreignId('investment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('shares');
            $t->unsignedBigInteger('remaining_cap');
            $t->unique(['daily_roi_pool_id', 'investment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_roi_shares');
        Schema::dropIfExists('daily_roi_pools');
        foreach (['roi_packages', 'investments'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn(['roi_method', 'roi_shares']));
        }
        Schema::table('simulation_scenarios', fn (Blueprint $t) => $t->dropColumn('plan_version'));
    }
};
