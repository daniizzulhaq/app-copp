<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $scenario = null): AuditLog
    {
        $clean = fn (?array $d) => $d === null ? null : array_diff_key($d, array_flip(['password', 'remember_token', 'two_factor_secret', 'two_factor_last_counter', 'token', 'secret']));

        return AuditLog::create(['actor_id' => auth()->id(), 'simulation_scenario_id' => $scenario, 'action' => $action,
            'subject_type' => $subject ? $subject::class : null, 'subject_id' => $subject?->id, 'before' => $clean($before), 'after' => $clean($after),
            'ip_address' => app()->runningInConsole() ? null : request()->ip()]);
    }
}
