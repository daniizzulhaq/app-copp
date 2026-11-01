<?php

namespace App\Models;

use App\Support\Money;

class Investment extends Record
{
    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'principal' => 'integer', 'daily_rate_bps' => 'integer',
            'principal_returned_amount' => 'integer', 'principal_returned_at' => 'datetime', 'daily_roi_cap' => 'integer', 'total_roi_bps' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function package()
    {
        return $this->belongsTo(RoiPackage::class, 'roi_package_id');
    }

    public function obligations()
    {
        return $this->hasMany(RoiObligation::class);
    }

    public function scenario()
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }

    public function promised(): int
    {
        $total = Money::rate($this->principal, $this->daily_rate_bps, $this->duration_days);

        $total = $this->daily_roi_cap === null ? $total : min($total, $this->daily_roi_cap * $this->duration_days);

        return $this->total_roi_bps === null ? $total : min($total, Money::rate($this->principal, $this->total_roi_bps));
    }
}
