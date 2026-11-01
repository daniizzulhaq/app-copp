<?php

namespace App\Models;

class RoiObligation extends Record
{
    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'integer', 'paid_amount' => 'integer',
            'expired_amount' => 'integer', 'claimed_at' => 'datetime', 'expired_at' => 'datetime'];
    }

    public function remaining(): int
    {
        return max(0, $this->amount - $this->paid_amount - $this->expired_amount);
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function distributions()
    {
        return $this->hasMany(RoiDistribution::class);
    }
}
