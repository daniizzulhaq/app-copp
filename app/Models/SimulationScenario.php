<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class SimulationScenario extends Record
{
    use HasFactory;

    public function isLocal(): bool
    {
        return $this->mode === 'local';
    }

    protected function casts(): array
    {
        return ['start_date' => 'date'];
    }

    public function runs()
    {
        return $this->hasMany(SimulationRun::class);
    }

    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    public function accounts()
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function transactions()
    {
        return $this->hasMany(LedgerTransaction::class);
    }
}
