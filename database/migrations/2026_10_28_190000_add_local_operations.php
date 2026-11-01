<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('simulation_scenarios', 'mode')) {
            Schema::table('simulation_scenarios', function (Blueprint $table) {
                $table->string('mode', 20)->default('simulation')->index();
            });
        }
        if (! Schema::hasColumn('mock_payment_invoices', 'workflow')) {
            Schema::table('mock_payment_invoices', function (Blueprint $table) {
                $table->string('workflow', 20)->default('sandbox')->index();
                $table->string('submission_reference', 100)->nullable();
                $table->text('submission_note')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            });
        }
        if (! Schema::hasIndex('mock_payment_invoices', 'manual_invoice_queue_idx')) {
            Schema::table('mock_payment_invoices', function (Blueprint $table) {
                $table->index(['simulation_scenario_id', 'workflow', 'status'], 'manual_invoice_queue_idx');
            });
        }
        if (! Schema::hasColumn('withdrawal_requests', 'review_note')) {
            Schema::table('withdrawal_requests', function (Blueprint $table) {
                $table->text('review_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', fn (Blueprint $table) => $table->dropColumn('review_note'));
        Schema::table('mock_payment_invoices', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['ledger_transaction_id']);
            $table->dropUnique(['ledger_transaction_id']);
            $table->dropIndex('manual_invoice_queue_idx');
            $table->dropIndex(['workflow']);
            $table->dropColumn(['workflow', 'submission_reference', 'submission_note', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'ledger_transaction_id']);
        });
        Schema::table('simulation_scenarios', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};
