<?php

namespace App\Models;

class DailyProductTurnover extends Record
{
    protected function casts(): array
    {
        return [
            'period_date' => 'date', 'gross_amount' => 'integer',
            'purchase_count' => 'integer', 'roi_rate_bps' => 'integer', 'roi_amount' => 'integer',
        ];
    }
}
