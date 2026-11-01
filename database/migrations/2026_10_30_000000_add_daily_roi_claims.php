<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roi_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_roi_cap')->nullable();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_roi_cap')->nullable();
        });
        Schema::table('roi_obligations', function (Blueprint $table) {
            $table->unsignedBigInteger('expired_amount')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
        });
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // Existing requests retain their original fee-free terms.
            $table->unsignedBigInteger('admin_fee')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', fn (Blueprint $table) => $table->dropColumn('admin_fee'));
        Schema::table('roi_obligations', fn (Blueprint $table) => $table->dropColumn(['expired_amount', 'claimed_at', 'expired_at']));
        Schema::table('investments', fn (Blueprint $table) => $table->dropColumn('daily_roi_cap'));
        Schema::table('roi_packages', fn (Blueprint $table) => $table->dropColumn('daily_roi_cap'));
    }
};
