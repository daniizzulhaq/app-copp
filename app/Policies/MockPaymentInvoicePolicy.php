<?php

namespace App\Policies;

use App\Models\MockPaymentInvoice;
use App\Models\User;

class MockPaymentInvoicePolicy
{
    public function view(User $u, MockPaymentInvoice $i): bool
    {
        return $u->isStaff() || $i->user_id === $u->id;
    }

    public function simulate(User $u, MockPaymentInvoice $i): bool
    {
        return $u->hasRole('member') && $i->user_id === $u->id && $i->workflow !== 'manual_local' && ! $i->scenario->isLocal();
    }

    public function submit(User $u, MockPaymentInvoice $i): bool
    {
        return $u->active && $u->hasRole('member') && $i->user_id === $u->id && $i->workflow === 'manual_local';
    }
}
