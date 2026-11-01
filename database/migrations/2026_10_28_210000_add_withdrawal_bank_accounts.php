<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'withdrawal_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('bank_name', 100)->nullable();
                $table->string('bank_account_number', 34)->nullable();
                $table->string('bank_account_holder', 150)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['withdrawal_requests', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['bank_name', 'bank_account_number', 'bank_account_holder']);
            });
        }
    }
};
