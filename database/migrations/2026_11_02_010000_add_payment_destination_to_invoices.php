<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_payment_invoices', function (Blueprint $table) {
            $table->json('payment_destination')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mock_payment_invoices', function (Blueprint $table) {
            $table->dropColumn('payment_destination');
        });
    }
};
