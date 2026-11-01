<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class RoiPackage extends Record
{
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'minimum_amount' => 'integer', 'daily_rate_bps' => 'integer', 'duration_days' => 'integer', 'daily_roi_cap' => 'integer', 'total_roi_bps' => 'integer', 'purchase_limit' => 'integer', 'fixed_amount' => 'boolean'];
    }

    public function investments()
    {
        return $this->hasMany(Investment::class);
    }
}
