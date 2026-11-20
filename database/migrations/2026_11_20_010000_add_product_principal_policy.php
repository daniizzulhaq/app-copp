<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing contracts; the seeder explicitly sets the new package policy.
        foreach (['roi_packages', 'investments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('returns_principal')->default(true));
        }
    }

    public function down(): void
    {
        foreach (['roi_packages', 'investments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('returns_principal'));
        }
    }
};
