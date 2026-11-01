<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            return;
        }
        foreach (['ledger_transactions', 'ledger_entries', 'audit_logs'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $name = 'immutable_'.$table.'_'.strtolower($action);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Histori permanen: gunakan transaksi pembalik'");
            }
        }
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_valid CHECK (amount > 0 AND side IN ('debit','credit'))");
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_nonnegative CHECK (allow_negative = 1 OR balance >= 0)');
        DB::statement('ALTER TABLE roi_obligations ADD CONSTRAINT obligations_paid_limit CHECK (paid_amount <= amount)');
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            return;
        }
        foreach (['ledger_transactions', 'ledger_entries', 'audit_logs'] as $table) {
            foreach (['update', 'delete'] as $action) {
                DB::unprepared('DROP TRIGGER IF EXISTS immutable_'.$table.'_'.$action);
            }
        }
        foreach (['ledger_entries' => 'ledger_entries_valid', 'ledger_accounts' => 'ledger_accounts_nonnegative', 'roi_obligations' => 'obligations_paid_limit'] as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }
    }
};
