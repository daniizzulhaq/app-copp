<?php

namespace App\Models;

class MemberBonus extends Record
{
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
