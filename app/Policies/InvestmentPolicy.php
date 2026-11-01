<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

class InvestmentPolicy
{
    public function view(User $user, Investment $investment): bool
    {
        return $user->isStaff() || $investment->user_id === $user->id;
    }
}
