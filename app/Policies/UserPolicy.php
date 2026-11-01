<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $u, User $member): bool
    {
        return $u->isStaff() || $u->id === $member->id;
    }

    public function update(User $u, User $member): bool
    {
        return $u->hasRole('super-admin') && $member->hasRole('member') && ! $member->is_cohort;
    }
}
