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

    public function usesTurnoverRoi(): bool
    {
        return $this->isLocal() && in_array($this->plan_version, ['turnover_v1', 'turnover_v2'], true);
    }

    public function usesNextDayTurnoverRoi(): bool
    {
        return $this->isLocal() && $this->plan_version === 'turnover_v2';
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'roi_claims_start_date' => 'date'];
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
