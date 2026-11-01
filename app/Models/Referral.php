<?php

namespace App\Models;

class Referral extends Record
{
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id')->withTrashed();
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id')->withTrashed();
    }
}
