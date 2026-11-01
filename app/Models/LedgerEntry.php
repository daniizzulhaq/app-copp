<?php

namespace App\Models;

class LedgerEntry extends ImmutableRecord
{
    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function transaction()
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }
}
