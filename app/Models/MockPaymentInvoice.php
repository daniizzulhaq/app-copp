<?php

namespace App\Models;

class MockPaymentInvoice extends Record
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
            'amount' => 'integer', 'transfer_amount' => 'integer', 'unique_code' => 'integer', 'reserved_unique_code' => 'integer', 'payment_destination' => 'array'];
    }

    public function methodLabel(): string
    {
        return SystemSetting::QRIS_METHODS[$this->method] ?? 'Transfer bank';
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function scenario()
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
