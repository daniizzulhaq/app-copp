<?php

namespace App\Models;

class AuditLog extends ImmutableRecord
{
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
