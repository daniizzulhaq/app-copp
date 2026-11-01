<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('member_code')->nullable()->unique();
            $t->string('referral_code')->nullable()->unique();
            $t->boolean('active')->default(true)->index();
            $t->boolean('is_cohort')->default(false)->index();
            $t->text('two_factor_secret')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedBigInteger('two_factor_last_counter')->nullable();
            $t->softDeletes();
        });
        Schema::create('roi_packages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('minimum_amount');
            $t->unsignedInteger('daily_rate_bps');
            $t->unsignedInteger('duration_days');
            $t->boolean('active')->default(true);
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('simulation_scenarios', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->date('start_date');
            $t->unsignedInteger('initial_members')->default(10);
            $t->unsignedInteger('daily_new_members')->default(2);
            $t->integer('growth_bps')->default(0);
            $t->unsignedInteger('decline_bps')->default(0);
            $t->unsignedInteger('decline_after_day')->default(10);
            $t->unsignedBigInteger('average_deposit')->default(1000000);
            $t->unsignedInteger('daily_rate_bps')->default(200);
            $t->unsignedInteger('duration_days')->default(60);
            $t->unsignedInteger('payment_frequency_days')->default(1);
            $t->unsignedInteger('withdrawal_bps')->default(8000);
            $t->unsignedBigInteger('initial_cash')->default(0);
            $t->unsignedInteger('current_day')->default(0);
            $t->unsignedInteger('participant_count')->default(0);
            $t->string('status')->default('draft')->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'simulation_scenario_id']);
        });
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->foreignId('wallet_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('name');
            $t->string('normal_side', 6);
            $t->bigInteger('balance')->default(0);
            $t->boolean('allow_negative')->default(false);
            $t->timestamps();
            $t->unique(['simulation_scenario_id', 'code']);
        });
        Schema::create('ledger_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 160)->unique();
            $t->string('kind')->index();
            $t->string('description');
            $t->date('effective_date')->index();
            $t->string('payload_hash', 64);
            $t->string('previous_hash', 64)->nullable();
            $t->string('integrity_hash', 64);
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $t->string('side', 6);
            $t->unsignedBigInteger('amount');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('investments', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->foreignId('roi_package_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('package_name');
            $t->unsignedBigInteger('principal');
            $t->unsignedInteger('daily_rate_bps');
            $t->unsignedInteger('duration_days');
            $t->unsignedInteger('payment_frequency_days')->default(1);
            $t->unsignedInteger('participant_count')->default(1);
            $t->date('starts_at');
            $t->date('ends_at');
            $t->string('status')->default('active')->index();
            $t->string('request_key')->unique();
            $t->timestamps();
            $t->index(['simulation_scenario_id', 'starts_at', 'ends_at']);
        });
        Schema::create('mock_payment_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('number')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->string('status')->default('pending')->index();
            $t->string('request_key')->unique();
            $t->timestamp('expires_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->foreignId('mock_payment_invoice_id')->constrained()->restrictOnDelete();
            $t->string('payload_hash', 64);
            $t->string('result');
            $t->timestamps();
        });
        Schema::create('roi_obligations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('investment_id')->constrained()->restrictOnDelete();
            $t->date('due_date')->index();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('paid_amount')->default(0);
            $t->string('status')->default('due')->index();
            $t->timestamps();
            $t->unique(['investment_id', 'due_date']);
        });
        Schema::create('roi_distributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('roi_obligation_id')->constrained()->restrictOnDelete();
            $t->foreignId('ledger_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });
        Schema::create('withdrawal_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('method');
            $t->text('note')->nullable();
            $t->string('status')->default('pending')->index();
            $t->string('request_key')->unique();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('simulated_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('withdrawal_request_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('ledger_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('reference')->unique();
            $t->timestamps();
        });
        Schema::create('referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('referred_id')->unique()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('points')->default(10);
            $t->timestamps();
        });
        Schema::create('simulation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('simulation_scenario_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('day');
            $t->date('period_date');
            $t->unsignedInteger('new_members');
            $t->unsignedInteger('total_members');
            $t->unsignedBigInteger('deposits');
            $t->unsignedBigInteger('roi_due');
            $t->unsignedBigInteger('roi_paid');
            $t->unsignedBigInteger('withdrawals');
            $t->unsignedBigInteger('cash');
            $t->unsignedBigInteger('outstanding');
            $t->unsignedBigInteger('funding_gap');
            $t->unsignedInteger('coverage_bps');
            $t->unsignedInteger('dependency_bps');
            $t->string('status');
            $t->timestamps();
            $t->unique(['simulation_scenario_id', 'day']);
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('simulation_scenario_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action')->index();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['system_settings', 'audit_logs', 'notifications', 'simulation_runs', 'referrals', 'simulated_payouts', 'withdrawal_requests', 'roi_distributions', 'roi_obligations', 'payment_webhook_events', 'mock_payment_invoices', 'investments', 'ledger_entries', 'ledger_transactions', 'ledger_accounts', 'wallets', 'simulation_scenarios', 'roi_packages'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['member_code', 'referral_code', 'active', 'is_cohort', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_counter', 'deleted_at']);
        });
    }
};
