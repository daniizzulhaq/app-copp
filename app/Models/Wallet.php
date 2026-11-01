<?php

namespace App\Models;

class Wallet extends Record
{
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function accounts()
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function scenario()
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }

    public function balance(string $bucket = 'available'): int
    {
        return (int) $this->accounts()->where('code', 'wallet:'.$this->user_id.':'.$bucket)->value('balance');
    }
}
