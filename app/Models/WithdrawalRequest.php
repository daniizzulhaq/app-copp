<?php

namespace App\Models;

class WithdrawalRequest extends Record
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'admin_fee' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function netAmount(): int
    {
        return $this->amount - $this->admin_fee;
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function hasBankAccount(): bool
    {
        return filled($this->bank_name) && filled($this->bank_account_number) && filled($this->bank_account_holder);
    }

    public function scenario()
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }
}
