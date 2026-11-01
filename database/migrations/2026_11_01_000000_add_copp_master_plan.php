<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roi_packages', function (Blueprint $t) {
            $t->unsignedInteger('total_roi_bps')->nullable();
            $t->unsignedTinyInteger('purchase_limit')->default(3);
            $t->boolean('fixed_amount')->default(false);
        });
        Schema::table('investments', fn (Blueprint $t) => $t->unsignedInteger('total_roi_bps')->nullable());
        Schema::create('member_bonuses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->string('bonus_key')->unique();
            $t->string('kind');
            $t->string('description');
            $t->unsignedBigInteger('amount');
            $t->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_bonuses');
        Schema::table('investments', fn (Blueprint $t) => $t->dropColumn('total_roi_bps'));
        Schema::table('roi_packages', fn (Blueprint $t) => $t->dropColumn(['total_roi_bps', 'purchase_limit', 'fixed_amount']));
    }
};
