<?php

namespace App\Models;

class LedgerAccount extends Record
{
    protected function casts(): array
    {
        return ['balance' => 'integer', 'allow_negative' => 'boolean'];
    }

    public function entries()
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
