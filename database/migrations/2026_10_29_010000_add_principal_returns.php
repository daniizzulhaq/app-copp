<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->unsignedBigInteger('principal_returned_amount')->default(0);
            $table->timestamp('principal_returned_at')->nullable();
        });
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('principal_returned')->default(0);
            $table->unsignedBigInteger('principal_outstanding')->default(0);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE investments ADD CONSTRAINT investments_principal_return_limit CHECK (principal_returned_amount <= principal)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE investments DROP CONSTRAINT investments_principal_return_limit');
        }
        Schema::table('simulation_runs', fn (Blueprint $table) => $table->dropColumn(['principal_returned', 'principal_outstanding']));
        Schema::table('investments', fn (Blueprint $table) => $table->dropColumn(['principal_returned_amount', 'principal_returned_at']));
    }
};
