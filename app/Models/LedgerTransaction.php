<?php

namespace App\Models;

class LedgerTransaction extends ImmutableRecord
{
    public function entries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }
}
